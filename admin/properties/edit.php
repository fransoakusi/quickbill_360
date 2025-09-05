<?php
/**
 * Properties Management - Edit Property (Updated with Relationship Handling)
 * QUICKBILL 305 - Admin Panel
 */

// Define application constant
define('QUICKBILL_305', true);

// Include configuration files
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Start session
session_start();

// Include auth and security
require_once '../../includes/auth.php';
require_once '../../includes/security.php';

// Initialize auth and security
initAuth();
initSecurity();

// Check authentication and permissions
requireLogin();
if (!hasPermission('properties.edit')) {
    setFlashMessage('error', 'Access denied. You do not have permission to edit properties.');
    header('Location: index.php');
    exit();
}

// Check session expiration
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > 5600)) {
    session_unset();
    session_destroy();
    setFlashMessage('error', 'Your session has expired. Please log in again.');
    header('Location: ../../index.php');
    exit();
}

$pageTitle = 'Edit Property';
$currentUser = getCurrentUser();
$userDisplayName = getUserDisplayName($currentUser);

// Get property ID from URL
$propertyId = intval($_GET['id'] ?? 0);

if (!$propertyId) {
    setFlashMessage('error', 'Invalid property ID.');
    header('Location: index.php');
    exit();
}

// Handle DELETE request with relationship checking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    header('Content-Type: application/json');
    
    // Check delete permission
    if (!hasPermission('properties.delete')) {
        echo json_encode(['success' => false, 'message' => 'Access denied. You do not have permission to delete properties.']);
        exit();
    }
    
    try {
        $db = new Database();
        
        // Get property data before deletion for audit log
        $propertyQuery = "SELECT * FROM properties WHERE property_id = ?";
        $property = $db->fetchRow($propertyQuery, [$propertyId]);
        
        if (!$property) {
            echo json_encode(['success' => false, 'message' => 'Property not found.']);
            exit();
        }
        
        // Check for related records and get counts
        $relatedData = checkPropertyRelationships($db, $propertyId);
        
        $db->beginTransaction();
        
        // Delete related records in proper order (respecting foreign key constraints)
        $deletionSummary = [];
        
        // 1. Delete payments first (they reference bills)
        if ($relatedData['payments'] > 0) {
            $deletePaymentsQuery = "DELETE p FROM payments p 
                                   INNER JOIN bills b ON p.bill_id = b.bill_id 
                                   WHERE b.bill_type = 'Property' AND b.reference_id = ?";
            $stmt = $db->getConnection()->prepare($deletePaymentsQuery);
            $result = $stmt->execute([$propertyId]);
            if ($result) {
                $deletionSummary[] = "{$relatedData['payments']} payment record(s)";
            }
        }
        
        // 2. Delete bill adjustments
        if ($relatedData['adjustments'] > 0) {
            $deleteAdjustmentsQuery = "DELETE FROM bill_adjustments 
                                      WHERE target_type = 'Property' AND target_id = ?";
            $stmt = $db->getConnection()->prepare($deleteAdjustmentsQuery);
            $result = $stmt->execute([$propertyId]);
            if ($result) {
                $deletionSummary[] = "{$relatedData['adjustments']} bill adjustment(s)";
            }
        }
        
        // 3. Delete bills
        if ($relatedData['bills'] > 0) {
            $deleteBillsQuery = "DELETE FROM bills WHERE bill_type = 'Property' AND reference_id = ?";
            $stmt = $db->getConnection()->prepare($deleteBillsQuery);
            $result = $stmt->execute([$propertyId]);
            if ($result) {
                $deletionSummary[] = "{$relatedData['bills']} bill record(s)";
            }
        }
        
        // 4. Finally delete the property
        $deletePropertyQuery = "DELETE FROM properties WHERE property_id = ?";
        $stmt = $db->getConnection()->prepare($deletePropertyQuery);
        $result = $stmt->execute([$propertyId]);
        
        if ($result) {
            // Create comprehensive audit log entry
            try {
                $oldValues = json_encode($property);
                $newValues = json_encode([
                    'deleted' => true, 
                    'deleted_by' => $currentUser['user_id'], 
                    'deleted_at' => date('Y-m-d H:i:s'),
                    'related_records_deleted' => $deletionSummary
                ]);
                
                $auditQuery = "INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at) 
                               VALUES (?, 'HARD_DELETE_PROPERTY', 'properties', ?, ?, ?, ?, ?, NOW())";
                
                $auditParams = [
                    $currentUser['user_id'],
                    $propertyId,
                    $oldValues,
                    $newValues,
                    $_SERVER['REMOTE_ADDR'] ?? '',
                    $_SERVER['HTTP_USER_AGENT'] ?? ''
                ];
                
                $auditStmt = $db->getConnection()->prepare($auditQuery);
                $auditStmt->execute($auditParams);
            } catch (Exception $auditError) {
                error_log("Audit log failed: " . $auditError->getMessage());
            }
            
            // Log the action
            try {
                $summaryText = empty($deletionSummary) ? '' : ' and related records: ' . implode(', ', $deletionSummary);
                writeLog("Property deleted: {$property['owner_name']} property (ID: $propertyId){$summaryText} by user {$currentUser['username']}", 'INFO');
            } catch (Exception $logError) {
                error_log("Write log failed: " . $logError->getMessage());
            }
            
            $db->commit();
            
            $message = 'Property deleted successfully!';
            if (!empty($deletionSummary)) {
                $message .= ' Related records also deleted: ' . implode(', ', $deletionSummary) . '.';
            }
            
            echo json_encode(['success' => true, 'message' => $message]);
        } else {
            throw new Exception('Failed to delete property');
        }
        
    } catch (Exception $e) {
        $db->rollback();
        error_log("Error deleting property: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'An error occurred while deleting the property. Please try again. Error: ' . $e->getMessage()]);
    }
    exit();
}

// Handle CHECK RELATIONSHIPS request (for confirmation modal)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'check_relationships') {
    header('Content-Type: application/json');
    
    try {
        $db = new Database();
        $relatedData = checkPropertyRelationships($db, $propertyId);
        echo json_encode(['success' => true, 'data' => $relatedData]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

/**
 * Check property relationships and return counts
 */
function checkPropertyRelationships($db, $propertyId) {
    try {
        // Check bills
        $billsQuery = "SELECT COUNT(*) as count FROM bills WHERE bill_type = 'Property' AND reference_id = ?";
        $billsCount = $db->fetchRow($billsQuery, [$propertyId])['count'];
        
        // Check payments (through bills)
        $paymentsQuery = "SELECT COUNT(p.payment_id) as count FROM payments p 
                         INNER JOIN bills b ON p.bill_id = b.bill_id 
                         WHERE b.bill_type = 'Property' AND b.reference_id = ?";
        $paymentsCount = $db->fetchRow($paymentsQuery, [$propertyId])['count'];
        
        // Check bill adjustments
        $adjustmentsQuery = "SELECT COUNT(*) as count FROM bill_adjustments 
                            WHERE target_type = 'Property' AND target_id = ?";
        $adjustmentsCount = $db->fetchRow($adjustmentsQuery, [$propertyId])['count'];
        
        // Calculate total payment amount
        $paymentAmountQuery = "SELECT COALESCE(SUM(p.amount_paid), 0) as total FROM payments p 
                              INNER JOIN bills b ON p.bill_id = b.bill_id 
                              WHERE b.bill_type = 'Property' AND b.reference_id = ? AND p.payment_status = 'Successful'";
        $totalPayments = $db->fetchRow($paymentAmountQuery, [$propertyId])['total'];
        
        return [
            'bills' => $billsCount,
            'payments' => $paymentsCount,
            'adjustments' => $adjustmentsCount,
            'total_payment_amount' => $totalPayments,
            'has_relationships' => ($billsCount > 0 || $paymentsCount > 0 || $adjustmentsCount > 0)
        ];
    } catch (Exception $e) {
        throw new Exception("Error checking relationships: " . $e->getMessage());
    }
}

// Initialize variables
$errors = [];
$formData = [];

try {
    $db = new Database();
    
    // Get existing property data
    $propertyQuery = "SELECT * FROM properties WHERE property_id = ?";
    $property = $db->fetchRow($propertyQuery, [$propertyId]);
    
    if (!$property) {
        setFlashMessage('error', 'Property not found.');
        header('Location: index.php');
        exit();
    }
    
    // Initialize form data with existing property data
    $formData = [
        'owner_name' => $property['owner_name'],
        'telephone' => $property['telephone'],
        'gender' => $property['gender'],
        'location' => $property['location'],
        'latitude' => $property['latitude'],
        'longitude' => $property['longitude'],
        'structure' => $property['structure'],
        'ownership_type' => $property['ownership_type'],
        'property_type' => $property['property_type'],
        'number_of_rooms' => $property['number_of_rooms'],
        'property_use' => $property['property_use'],
        'old_bill' => $property['old_bill'],
        'previous_payments' => $property['previous_payments'],
        'arrears' => $property['arrears'],
        'current_bill' => $property['current_bill'],
        'batch' => $property['batch'],
        'zone_id' => $property['zone_id']
    ];
    
    // Get property fee structure for dynamic billing
    $propertyFees = $db->fetchAll("SELECT * FROM property_fee_structure WHERE is_active = 1 ORDER BY structure, property_use");
    
    // Get zones for dropdown
    $zones = $db->fetchAll("SELECT * FROM zones ORDER BY zone_name");
    
    // Process form submission (UPDATE)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] === 'update')) {
        // Store old values for audit log
        $oldValues = json_encode($property);
        
        // Validate and sanitize input
        $formData['owner_name'] = sanitizeInput($_POST['owner_name'] ?? '');
        $formData['telephone'] = sanitizeInput($_POST['telephone'] ?? '');
        $formData['gender'] = sanitizeInput($_POST['gender'] ?? '');
        $formData['location'] = sanitizeInput($_POST['location'] ?? '');
        $formData['latitude'] = sanitizeInput($_POST['latitude'] ?? '');
        $formData['longitude'] = sanitizeInput($_POST['longitude'] ?? '');
        $formData['structure'] = sanitizeInput($_POST['structure'] ?? '');
        $formData['ownership_type'] = sanitizeInput($_POST['ownership_type'] ?? 'Self');
        $formData['property_type'] = sanitizeInput($_POST['property_type'] ?? 'Modern');
        $formData['number_of_rooms'] = intval($_POST['number_of_rooms'] ?? 1);
        $formData['property_use'] = sanitizeInput($_POST['property_use'] ?? 'Residential');
        $formData['old_bill'] = floatval($_POST['old_bill'] ?? 0);
        $formData['previous_payments'] = floatval($_POST['previous_payments'] ?? 0);
        $formData['arrears'] = floatval($_POST['arrears'] ?? 0);
        $formData['current_bill'] = floatval($_POST['current_bill'] ?? 0);
        $formData['batch'] = sanitizeInput($_POST['batch'] ?? '');
        $formData['zone_id'] = intval($_POST['zone_id'] ?? 0);
        
        // Validation
        if (empty($formData['owner_name'])) {
            $errors[] = 'Owner name is required.';
        }
        
        if (empty($formData['structure'])) {
            $errors[] = 'Property structure is required.';
        }
        
        if (empty($formData['property_use'])) {
            $errors[] = 'Property use is required.';
        }
        
        if ($formData['number_of_rooms'] < 1) {
            $errors[] = 'Number of rooms must be at least 1.';
        }
        
        if (!empty($formData['telephone']) && !preg_match('/^[\+]?[0-9\-\s\(\)]+$/', $formData['telephone'])) {
            $errors[] = 'Please enter a valid telephone number.';
        }
        
        // Validate latitude and longitude if provided
        if (!empty($formData['latitude']) && (!is_numeric($formData['latitude']) || $formData['latitude'] < -90 || $formData['latitude'] > 90)) {
            $errors[] = 'Latitude must be a valid number between -90 and 90.';
        }
        
        if (!empty($formData['longitude']) && (!is_numeric($formData['longitude']) || $formData['longitude'] < -180 || $formData['longitude'] > 180)) {
            $errors[] = 'Longitude must be a valid number between -180 and 180.';
        }
        
        if ($formData['zone_id'] <= 0) {
            $errors[] = 'Please select a zone.';
        }
        
        // If no errors, update the property
        if (empty($errors)) {
            try {
                $db->beginTransaction();
                
                // Calculate amount payable
                $amount_payable = $formData['old_bill'] + $formData['arrears'] + $formData['current_bill'] - $formData['previous_payments'];
                
                // Update property
                $query = "UPDATE properties SET 
                            owner_name = ?, telephone = ?, gender = ?, location = ?, 
                            latitude = ?, longitude = ?, structure = ?, ownership_type = ?, 
                            property_type = ?, number_of_rooms = ?, property_use = ?,
                            old_bill = ?, previous_payments = ?, arrears = ?, current_bill = ?, 
                            amount_payable = ?, batch = ?, zone_id = ?, updated_at = NOW()
                          WHERE property_id = ?";
                
                $params = [
                    $formData['owner_name'],
                    $formData['telephone'],
                    $formData['gender'],
                    $formData['location'],
                    !empty($formData['latitude']) ? $formData['latitude'] : null,
                    !empty($formData['longitude']) ? $formData['longitude'] : null,
                    $formData['structure'],
                    $formData['ownership_type'],
                    $formData['property_type'],
                    $formData['number_of_rooms'],
                    $formData['property_use'],
                    $formData['old_bill'],
                    $formData['previous_payments'],
                    $formData['arrears'],
                    $formData['current_bill'],
                    $amount_payable,
                    $formData['batch'],
                    $formData['zone_id'] > 0 ? $formData['zone_id'] : null,
                    $propertyId
                ];
                
                // Execute update
                $stmt = $db->getConnection()->prepare($query);
                $result = $stmt->execute($params);
                
                if ($result) {
                    // Create audit log entry
                    $newValues = json_encode($formData);
                    $auditQuery = "INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at) 
                                   VALUES (?, 'UPDATE', 'properties', ?, ?, ?, ?, ?, NOW())";
                    
                    $auditParams = [
                        $currentUser['user_id'],
                        $propertyId,
                        $oldValues,
                        $newValues,
                        $_SERVER['REMOTE_ADDR'] ?? '',
                        $_SERVER['HTTP_USER_AGENT'] ?? ''
                    ];
                    
                    $auditStmt = $db->getConnection()->prepare($auditQuery);
                    $auditStmt->execute($auditParams);
                    
                    // Log the action
                    writeLog("Property updated: {$formData['owner_name']} property (ID: $propertyId) by user {$currentUser['username']}", 'INFO');
                    
                    $db->commit();
                    
                    setFlashMessage('success', 'Property updated successfully!');
                    header('Location: view.php?id=' . $propertyId);
                    exit();
                } else {
                    throw new Exception('Failed to update property');
                }
                
            } catch (Exception $e) {
                $db->rollback();
                writeLog("Error updating property: " . $e->getMessage(), 'ERROR');
                $errors[] = 'An error occurred while updating the property. Please try again.';
            }
        }
    }
    
} catch (Exception $e) {
    writeLog("Property edit page error: " . $e->getMessage(), 'ERROR');
    $errors[] = 'An error occurred while loading the page. Please try again.';
}

// Prepare property fees for JavaScript
$propertyFeesJson = json_encode($propertyFees);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - <?php echo APP_NAME; ?></title>
    
    <!-- Multiple Icon Sources -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://use.fontawesome.com/releases/v6.4.0/css/all.css">
    
    <!-- Bootstrap for backup -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
            overflow-x: hidden;
        }
        
        /* Custom Icons (fallback if Font Awesome fails) */
        .icon-dashboard::before { content: "📊"; }
        .icon-users::before { content: "👥"; }
        .icon-building::before { content: "🏢"; }
        .icon-home::before { content: "🏠"; }
        .icon-map::before { content: "🗺️"; }
        .icon-invoice::before { content: "📄"; }
        .icon-credit::before { content: "💳"; }
        .icon-tags::before { content: "🏷️"; }
        .icon-chart::before { content: "📈"; }
        .icon-bell::before { content: "🔔"; }
        .icon-cog::before { content: "⚙️"; }
        .icon-receipt::before { content: "🧾"; }
        .icon-menu::before { content: "☰"; }
        .icon-logout::before { content: "🚪"; }
        .icon-user::before { content: "👤"; }
        .icon-plus::before { content: "➕"; }
        .icon-save::before { content: "💾"; }
        .icon-location::before { content: "📍"; }
        .icon-phone::before { content: "📞"; }
        .icon-money::before { content: "💰"; }
        .icon-edit::before { content: "✏️"; }
        .icon-house::before { content: "🏘️"; }
        .icon-trash::before { content: "🗑️"; }
        .icon-warning::before { content: "⚠️"; }
        
        /* Top Navigation - Same as business pages */
        .top-nav {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 20px;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .nav-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .toggle-btn {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            font-size: 18px;
            padding: 10px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .toggle-btn:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .brand {
            font-size: 24px;
            font-weight: bold;
            color: white;
            text-decoration: none;
        }
        
        .user-section {
            display: flex;
            align-items: center;
            gap: 15px;
            position: relative;
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            padding: 8px 12px;
            border-radius: 10px;
            transition: all 0.3s;
            position: relative;
        }
        
        .user-profile:hover {
            background: rgba(255,255,255,0.1);
        }
        
        .user-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(255,255,255,0.3) 0%, rgba(255,255,255,0.1) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            border: 2px solid rgba(255,255,255,0.2);
            transition: all 0.3s;
        }
        
        .user-info {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }
        
        .user-name {
            font-weight: 600;
            font-size: 14px;
            color: white;
        }
        
        .user-role {
            font-size: 12px;
            opacity: 0.8;
            color: rgba(255,255,255,0.8);
        }
        
        .dropdown-arrow {
            margin-left: 8px;
            font-size: 12px;
            transition: transform 0.3s;
        }
        
        /* User Dropdown */
        .user-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            min-width: 220px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            z-index: 1001;
            overflow: hidden;
            margin-top: 10px;
        }
        
        .user-dropdown.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }
        
        .dropdown-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            text-align: center;
        }
        
        .dropdown-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 24px;
            margin: 0 auto 10px;
            border: 3px solid rgba(255,255,255,0.3);
        }
        
        .dropdown-name {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .dropdown-role {
            font-size: 12px;
            opacity: 0.9;
            background: rgba(255,255,255,0.2);
            padding: 4px 12px;
            border-radius: 15px;
            display: inline-block;
        }
        
        .dropdown-menu {
            padding: 0;
        }
        
        .dropdown-item {
            display: flex;
            align-items: center;
            padding: 15px 20px;
            color: #2d3748;
            text-decoration: none;
            transition: all 0.3s;
            border-bottom: 1px solid #f7fafc;
        }
        
        .dropdown-item:hover {
            background: #f7fafc;
            color: #667eea;
            transform: translateX(5px);
        }
        
        .dropdown-item:last-child {
            border-bottom: none;
        }
        
        .dropdown-item i {
            width: 20px;
            margin-right: 12px;
            text-align: center;
        }
        
        .dropdown-item.logout {
            color: #e53e3e;
            border-top: 2px solid #fed7d7;
        }
        
        .dropdown-item.logout:hover {
            background: #fed7d7;
            color: #c53030;
        }
        
        /* Layout */
        .container {
            margin-top: 80px;
            display: flex;
            min-height: calc(100vh - 80px);
        }
        
        /* Sidebar */
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, #2d3748 0%, #1a202c 100%);
            color: white;
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        .sidebar.hidden {
            width: 0;
            min-width: 0;
        }
        
        .sidebar-content {
            width: 280px;
            padding: 20px 0;
        }
        
        .nav-section {
            margin-bottom: 30px;
        }
        
        .nav-title {
            color: #a0aec0;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
            padding: 0 20px;
            margin-bottom: 10px;
            letter-spacing: 1px;
        }
        
        .nav-item {
            margin-bottom: 2px;
        }
        
        .nav-link {
            color: #e2e8f0;
            text-decoration: none;
            padding: 12px 20px;
            display: block;
            transition: all 0.3s;
            border-left: 3px solid transparent;
        }
        
        .nav-link:hover {
            background: rgba(255,255,255,0.1);
            color: white;
            border-left-color: #667eea;
        }
        
        .nav-link.active {
            background: rgba(102, 126, 234, 0.3);
            color: white;
            border-left-color: #667eea;
        }
        
        .nav-icon {
            display: inline-block;
            width: 20px;
            margin-right: 12px;
            text-align: center;
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            padding: 30px;
            background: #f8f9fa;
            transition: all 0.3s ease;
        }
        
        /* Breadcrumb */
        .breadcrumb-nav {
            background: white;
            border-radius: 15px;
            padding: 20px 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
            color: #64748b;
        }
        
        .breadcrumb a {
            color: #667eea;
            text-decoration: none;
        }
        
        .breadcrumb-current {
            color: #2d3748;
            font-weight: 600;
        }
        
        /* Page Header */
        .page-header {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
        }
        
        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .page-title {
            font-size: 28px;
            font-weight: bold;
            color: #2d3748;
            margin: 0;
        }
        
        .property-info {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-top: 10px;
        }
        
        .property-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 20px;
        }
        
        .property-details h6 {
            margin: 0;
            font-weight: 600;
            color: #2d3748;
        }
        
        .property-details p {
            margin: 2px 0 0 0;
            color: #64748b;
            font-size: 14px;
        }
        
        .back-btn {
            background: #64748b;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }
        
        .back-btn:hover {
            background: #475569;
            transform: translateY(-2px);
            color: white;
        }
        
        /* Form Styles */
        .form-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .form-section {
            margin-bottom: 40px;
        }
        
        .form-section:last-child {
            margin-bottom: 0;
        }
        
        .section-title {
            font-size: 20px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-icon {
            width: 35px;
            height: 35px;
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-row.single {
            grid-template-columns: 1fr;
        }
        
        .form-row.triple {
            grid-template-columns: 1fr 1fr 1fr;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-label {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 8px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .required {
            color: #e53e3e;
        }
        
        .form-control {
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
            background: white;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #f59e0b;
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.1);
        }
        
        .form-control:invalid {
            border-color: #e53e3e;
        }
        
        .form-control.error {
            border-color: #e53e3e;
            background: #fef2f2;
        }
        
        .form-help {
            font-size: 12px;
            color: #64748b;
            margin-top: 5px;
        }
        
        /* Enhanced Location Section */
        .location-section {
            background: #f7fafc;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .location-picker {
            position: relative;
        }
        
        .location-input-group {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }
        
        .location-input-group .form-group {
            flex: 1;
        }
        
        .location-btn {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            white-space: nowrap;
            height: fit-content;
        }
        
        .location-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(66, 153, 225, 0.3);
        }
        
        .location-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        .location-status {
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            margin-top: 10px;
            display: none;
        }
        
        .location-status.success {
            background: #d1fae5;
            color: #065f46;
        }
        
        .location-status.error {
            background: #fee2e2;
            color: #991b1b;
        }
        
        /* GPS Coordinates Styling Enhanced */
        .gps-coordinates {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border: 2px solid #bae6fd;
            border-radius: 12px;
            padding: 15px;
            margin-top: 15px;
        }
        
        .gps-title {
            font-size: 14px;
            font-weight: 600;
            color: #0369a1;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .coordinate-inputs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .coordinate-group {
            display: flex;
            flex-direction: column;
        }
        
        .coordinate-label {
            font-size: 12px;
            font-weight: 600;
            color: #0369a1;
            margin-bottom: 5px;
        }
        
        .coordinate-input {
            padding: 10px 12px;
            border: 1px solid #bae6fd;
            border-radius: 8px;
            font-size: 14px;
            background: white;
            transition: all 0.3s;
        }
        
        .coordinate-input:focus {
            outline: none;
            border-color: #0284c7;
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.1);
        }
        
        .coordinates-display {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 10px;
        }
        
        .coordinate-item {
            background: white;
            padding: 10px;
            border-radius: 6px;
            text-align: center;
        }
        
        .coordinate-item-label {
            font-size: 12px;
            color: #718096;
            margin-bottom: 5px;
        }
        
        .coordinate-value {
            font-weight: bold;
            color: #2d3748;
        }
        
        /* Dynamic Fee Display */
        .fee-display {
            background: linear-gradient(135deg, #9f7aea 0%, #805ad5 100%);
            color: white;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            margin-top: 15px;
            display: block; /* Show by default since we have existing data */
        }
        
        .fee-amount {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .fee-description {
            font-size: 14px;
            opacity: 0.9;
        }
        
        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background: #d1fae5;
            border: 1px solid #9ae6b4;
            color: #065f46;
        }
        
        .alert-error {
            background: #fee2e2;
            border: 1px solid #fca5a5;
            color: #991b1b;
        }
        
        .alert-info {
            background: #bee3f8;
            color: #2a4365;
            border: 1px solid #90cdf4;
        }
        
        .alert-warning {
            background: #fef3cd;
            border: 1px solid #fbbf24;
            color: #92400e;
        }
        
        /* Buttons */
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            justify-content: center;
            font-size: 14px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(245, 158, 11, 0.3);
            color: white;
        }
        
        .btn-secondary {
            background: #64748b;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #475569;
            color: white;
        }
        
        .btn-success {
            background: linear-gradient(135deg, #38a169 0%, #48bb78 100%);
            color: white;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(56, 161, 105, 0.3);
            color: white;
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #f56565 0%, #e53e3e 100%);
            color: white;
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(245, 101, 101, 0.3);
            color: white;
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            padding-top: 20px;
            border-top: 2px solid #e2e8f0;
            margin-top: 30px;
        }
        
        /* Changes Indicator */
        .changes-indicator {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: none;
            align-items: center;
            gap: 10px;
            animation: slideDown 0.3s ease;
        }
        
        .changes-indicator.show {
            display: flex;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Enhanced Delete Confirmation Modal */
        .delete-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(8px);
            z-index: 10000;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
        }
        
        .delete-content {
            background: white;
            padding: 40px;
            border-radius: 20px;
            max-width: 600px;
            width: 90%;
            box-shadow: 0 25px 80px rgba(0,0,0,0.4);
            text-align: center;
            animation: modalSlideIn 0.4s ease;
            position: relative;
        }
        
        .delete-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #f56565 0%, #e53e3e 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2.5rem;
            margin: 0 auto 25px;
            animation: shake 0.5s ease-in-out;
        }
        
        .delete-title {
            font-size: 24px;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 15px;
        }
        
        .delete-message {
            color: #64748b;
            margin-bottom: 10px;
            line-height: 1.6;
        }
        
        .property-name-highlight {
            font-weight: bold;
            color: #e53e3e;
            background: #fef2f2;
            padding: 2px 8px;
            border-radius: 6px;
        }
        
        .delete-warning {
            background: #fef2f2;
            border: 2px solid #fca5a5;
            color: #991b1b;
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
            font-size: 14px;
            text-align: left;
        }
        
        .relationships-info {
            background: #fff3cd;
            border: 2px solid #fbbf24;
            color: #92400e;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
            text-align: left;
        }
        
        .relationships-title {
            font-weight: bold;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .relationships-list {
            list-style: none;
            padding: 0;
            margin: 10px 0 0 0;
        }
        
        .relationships-list li {
            padding: 5px 0;
            border-bottom: 1px solid rgba(251, 191, 36, 0.2);
        }
        
        .relationships-list li:last-child {
            border-bottom: none;
        }
        
        .delete-actions {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 30px;
        }
        
        .confirm-delete-btn {
            background: linear-gradient(135deg, #f56565 0%, #e53e3e 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .confirm-delete-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(245, 101, 101, 0.3);
        }
        
        .cancel-btn {
            background: #64748b;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .cancel-btn:hover {
            background: #475569;
        }
        
        /* Room Counter */
        .room-counter {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .room-btn {
            width: 40px;
            height: 40px;
            border: 2px solid #e2e8f0;
            background: white;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: bold;
            font-size: 18px;
        }
        
        .room-btn:hover {
            border-color: #f59e0b;
            background: #fef3c7;
        }
        
        .room-count {
            font-size: 18px;
            font-weight: bold;
            min-width: 50px;
            text-align: center;
            padding: 8px 15px;
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
        }
        
        /* Loading State */
        .loading {
            pointer-events: none;
            opacity: 0.7;
        }
        
        .loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 20px;
            height: 20px;
            margin: -10px 0 0 -10px;
            border: 2px solid #fff;
            border-top-color: transparent;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes modalSlideIn {
            from {
                transform: translateY(-30px) scale(0.9);
                opacity: 0;
            }
            to {
                transform: translateY(0) scale(1);
                opacity: 1;
            }
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                height: 100%;
                z-index: 999;
                transform: translateX(-100%);
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            .sidebar.hidden {
                transform: translateX(-100%);
                width: 280px;
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .form-row {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .form-row.triple {
                grid-template-columns: 1fr;
            }
            
            .header-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .location-input-group {
                flex-direction: column;
            }
            
            .coordinate-inputs {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .property-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .delete-actions {
                flex-direction: column;
            }
            
            .delete-content {
                padding: 30px 20px;
            }
        }
        
        /* Animations */
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(100%); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        @keyframes slideOut {
            from { opacity: 1; transform: translateX(0); }
            to { opacity: 0; transform: translateX(100%); }
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <div class="top-nav">
        <div class="nav-left">
            <button class="toggle-btn" onclick="toggleSidebar()" id="toggleBtn">
                <i class="fas fa-bars"></i>
                <span class="icon-menu" style="display: none;"></span>
            </button>
            
            <a href="../index.php" class="brand">
                <i class="fas fa-receipt"></i>
                <span class="icon-receipt" style="display: none;"></span>
                <?php echo APP_NAME; ?>
            </a>
        </div>
        
        <div class="user-section">
            <!-- Notification Bell -->
            <div style="position: relative; margin-right: 10px;">
                <button style="
                    background: rgba(255,255,255,0.2);
                    border: none;
                    color: white;
                    font-size: 18px;
                    padding: 10px;
                    border-radius: 50%;
                    cursor: pointer;
                    transition: all 0.3s;
                " onmouseover="this.style.background='rgba(255,255,255,0.3)'" 
                   onmouseout="this.style.background='rgba(255,255,255,0.2)'"
                   onclick="showComingSoon('Notifications')">
                    <i class="fas fa-bell"></i>
                    <span class="icon-bell" style="display: none;"></span>
                </button>
                <span class="notification-badge" style="
                    position: absolute;
                    top: -2px;
                    right: -2px;
                    background: #ef4444;
                    color: white;
                    border-radius: 50%;
                    width: 20px;
                    height: 20px;
                    font-size: 11px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-weight: bold;
                    animation: pulse 2s infinite;
                ">3</span>
            </div>
            
            <div class="user-profile" onclick="toggleUserDropdown()" id="userProfile">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($currentUser['first_name'], 0, 1)); ?>
                </div>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($userDisplayName); ?></div>
                    <div class="user-role"><?php echo htmlspecialchars(getCurrentUserRole()); ?></div>
                </div>
                <i class="fas fa-chevron-down dropdown-arrow"></i>
                
                <!-- User Dropdown -->
                <div class="user-dropdown" id="userDropdown">
                    <div class="dropdown-header">
                        <div class="dropdown-avatar">
                            <?php echo strtoupper(substr($currentUser['first_name'], 0, 1)); ?>
                        </div>
                        <div class="dropdown-name"><?php echo htmlspecialchars($userDisplayName); ?></div>
                        <div class="dropdown-role"><?php echo htmlspecialchars(getCurrentUserRole()); ?></div>
                    </div>
                    <div class="dropdown-menu">
                        <a href="#" class="dropdown-item" onclick="showComingSoon('User Profile')">
                            <i class="fas fa-user"></i>
                            <span class="icon-user" style="display: none;"></span>
                            My Profile
                        </a>
                        <a href="#" class="dropdown-item" onclick="showComingSoon('Account Settings')">
                            <i class="fas fa-cog"></i>
                            <span class="icon-cog" style="display: none;"></span>
                            Account Settings
                        </a>
                        <a href="#" class="dropdown-item" onclick="showComingSoon('Activity Log')">
                            <i class="fas fa-history"></i>
                            <span class="icon-chart" style="display: none;"></span>
                            Activity Log
                        </a>
                        <a href="#" class="dropdown-item" onclick="showComingSoon('Help & Support')">
                            <i class="fas fa-question-circle"></i>
                            <span class="icon-bell" style="display: none;"></span>
                            Help & Support
                        </a>
                        <div style="height: 1px; background: #e2e8f0; margin: 10px 0;"></div>
                        <a href="../../auth/logout.php" class="dropdown-item logout">
                            <i class="fas fa-sign-out-alt"></i>
                            <span class="icon-logout" style="display: none;"></span>
                            Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-content">
                <!-- Dashboard -->
                <div class="nav-section">
                    <div class="nav-item">
                        <a href="../index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-tachometer-alt"></i>
                                <span class="icon-dashboard" style="display: none;"></span>
                            </span>
                            Dashboard
                        </a>
                    </div>
                </div>
                
                <!-- Core Management -->
                <div class="nav-section">
                    <div class="nav-title">Core Management</div>
                    <div class="nav-item">
                        <a href="../users/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-users"></i>
                                <span class="icon-users" style="display: none;"></span>
                            </span>
                            Users
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../businesses/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-building"></i>
                                <span class="icon-building" style="display: none;"></span>
                            </span>
                            Businesses
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="index.php" class="nav-link active">
                            <span class="nav-icon">
                                <i class="fas fa-home"></i>
                                <span class="icon-home" style="display: none;"></span>
                            </span>
                            Properties
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="#" class="nav-link" onclick="showComingSoon('Zones')">
                            <span class="nav-icon">
                                <i class="fas fa-map-marked-alt"></i>
                                <span class="icon-map" style="display: none;"></span>
                            </span>
                            Zones & Areas
                        </a>
                    </div>
                </div>
                
                <!-- Billing & Payments -->
                <div class="nav-section">
                    <div class="nav-title">Billing & Payments</div>
                    <div class="nav-item">
                        <a href="#" class="nav-link" onclick="showComingSoon('Billing')">
                            <span class="nav-icon">
                                <i class="fas fa-file-invoice"></i>
                                <span class="icon-invoice" style="display: none;"></span>
                            </span>
                            Billing
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="#" class="nav-link" onclick="showComingSoon('Payments')">
                            <span class="nav-icon">
                                <i class="fas fa-credit-card"></i>
                                <span class="icon-credit" style="display: none;"></span>
                            </span>
                            Payments
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="#" class="nav-link" onclick="showComingSoon('Fee Structure')">
                            <span class="nav-icon">
                                <i class="fas fa-tags"></i>
                                <span class="icon-tags" style="display: none;"></span>
                            </span>
                            Fee Structure
                        </a>
                    </div>
                </div>
                
                <!-- Reports & System -->
                <div class="nav-section">
                    <div class="nav-title">Reports & System</div>
                    <div class="nav-item">
                        <a href="#" class="nav-link" onclick="showComingSoon('Reports')">
                            <span class="nav-icon">
                                <i class="fas fa-chart-bar"></i>
                                <span class="icon-chart" style="display: none;"></span>
                            </span>
                            Reports
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="#" class="nav-link" onclick="showComingSoon('Notifications')">
                            <span class="nav-icon">
                                <i class="fas fa-bell"></i>
                                <span class="icon-bell" style="display: none;"></span>
                            </span>
                            Notifications
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="#" class="nav-link" onclick="showComingSoon('Settings')">
                            <span class="nav-icon">
                                <i class="fas fa-cog"></i>
                                <span class="icon-cog" style="display: none;"></span>
                            </span>
                            Settings
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Breadcrumb -->
            <div class="breadcrumb-nav">
                <div class="breadcrumb">
                    <a href="../index.php">Dashboard</a>
                    <span>/</span>
                    <a href="index.php">Property Management</a>
                    <span>/</span>
                    <a href="view.php?id=<?php echo $propertyId; ?>"><?php echo htmlspecialchars($property['owner_name']); ?> Property</a>
                    <span>/</span>
                    <span class="breadcrumb-current">Edit</span>
                </div>
            </div>

            <!-- Flash Messages -->
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <strong>Please fix the following errors:</strong>
                        <ul style="margin: 10px 0 0 0; padding-left: 20px;">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Changes Indicator -->
            <div class="changes-indicator" id="changesIndicator">
                <i class="fas fa-info-circle"></i>
                <span>You have unsaved changes. Don't forget to save your modifications!</span>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <div class="header-content">
                    <div>
                        <h1 class="page-title">
                            <i class="fas fa-edit"></i>
                            <span class="icon-edit" style="display: none;"></span>
                            Edit Property
                        </h1>
                        <div class="property-info">
                            <div class="property-avatar">
                                <i class="fas fa-home"></i>
                                <span class="icon-house" style="display: none;"></span>
                            </div>
                            <div class="property-details">
                                <h6><?php echo htmlspecialchars($property['owner_name']); ?> Property</h6>
                                <p><?php echo htmlspecialchars($property['property_number']); ?></p>
                            </div>
                        </div>
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <a href="view.php?id=<?php echo $propertyId; ?>" class="back-btn">
                            <i class="fas fa-eye"></i>
                            View Profile
                        </a>
                        <a href="index.php" class="back-btn">
                            <i class="fas fa-arrow-left"></i>
                            Back to List
                        </a>
                    </div>
                </div>
            </div>

            <!-- Edit Form -->
            <form method="POST" action="" id="propertyForm">
                <input type="hidden" name="action" value="update">
                
                <!-- Owner Information Section -->
                <div class="form-card">
                    <div class="form-section">
                        <h3 class="section-title">
                            <div class="section-icon">
                                <i class="fas fa-user"></i>
                            </div>
                            Owner Information
                        </h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-user-circle"></i>
                                    Owner Name <span class="required">*</span>
                                </label>
                                <input type="text" name="owner_name" class="form-control" 
                                       value="<?php echo htmlspecialchars($formData['owner_name']); ?>" 
                                       placeholder="Enter owner full name" required>
                                <div class="form-help">Full name of the property owner</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-phone"></i>
                                    Telephone
                                </label>
                                <input type="tel" name="telephone" class="form-control" 
                                       value="<?php echo htmlspecialchars($formData['telephone']); ?>" 
                                       placeholder="e.g., +233 24 123 4567">
                                <div class="form-help">Contact phone number</div>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-venus-mars"></i>
                                    Gender
                                </label>
                                <select name="gender" class="form-control">
                                    <option value="">Select Gender</option>
                                    <option value="Male" <?php echo $formData['gender'] === 'Male' ? 'selected' : ''; ?>>Male</option>
                                    <option value="Female" <?php echo $formData['gender'] === 'Female' ? 'selected' : ''; ?>>Female</option>
                                    <option value="Other" <?php echo $formData['gender'] === 'Other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                                <div class="form-help">Owner's gender (optional)</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-users"></i>
                                    Ownership Type
                                </label>
                                <select name="ownership_type" class="form-control">
                                    <option value="Self" <?php echo $formData['ownership_type'] === 'Self' ? 'selected' : ''; ?>>Self</option>
                                    <option value="Family" <?php echo $formData['ownership_type'] === 'Family' ? 'selected' : ''; ?>>Family</option>
                                    <option value="Corporate" <?php echo $formData['ownership_type'] === 'Corporate' ? 'selected' : ''; ?>>Corporate</option>
                                    <option value="Others" <?php echo $formData['ownership_type'] === 'Others' ? 'selected' : ''; ?>>Others</option>
                                </select>
                                <div class="form-help">Type of ownership</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Property Details Section -->
                <div class="form-card">
                    <div class="form-section">
                        <h3 class="section-title">
                            <div class="section-icon">
                                <i class="fas fa-home"></i>
                            </div>
                            Property Details
                        </h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-building"></i>
                                    Structure <span class="required">*</span>
                                </label>
                                <select name="structure" id="structureSelect" class="form-control" required>
                                    <option value="">Select Structure</option>
                                    <?php 
                                    $structures = array_unique(array_column($propertyFees, 'structure'));
                                    foreach ($structures as $structure): 
                                    ?>
                                        <option value="<?php echo htmlspecialchars($structure); ?>" 
                                                <?php echo $formData['structure'] === $structure ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($structure); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-help">Type of building structure</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-home"></i>
                                    Property Use <span class="required">*</span>
                                </label>
                                <select name="property_use" id="propertyUseSelect" class="form-control" required>
                                    <option value="">Select Use</option>
                                </select>
                                <div class="form-help">Purpose of the property</div>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-th-large"></i>
                                    Number of Rooms <span class="required">*</span>
                                </label>
                                <div class="room-counter">
                                    <button type="button" class="room-btn" onclick="changeRooms(-1)">−</button>
                                    <input type="number" name="number_of_rooms" id="roomCount" class="room-count" 
                                           value="<?php echo $formData['number_of_rooms']; ?>" min="1" readonly>
                                    <button type="button" class="room-btn" onclick="changeRooms(1)">+</button>
                                </div>
                                <div class="form-help">Total number of rooms in the property</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-layer-group"></i>
                                    Property Type
                                </label>
                                <select name="property_type" class="form-control">
                                    <option value="Modern" <?php echo $formData['property_type'] === 'Modern' ? 'selected' : ''; ?>>Modern</option>
                                    <option value="Traditional" <?php echo $formData['property_type'] === 'Traditional' ? 'selected' : ''; ?>>Traditional</option>
                                </select>
                                <div class="form-help">Classification of property type</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Location Information Section -->
                <div class="form-card">
                    <div class="form-section">
                        <h3 class="section-title">
                            <div class="section-icon">
                                <i class="fas fa-map-marker-alt"></i>
                            </div>
                            Location Information
                        </h3>
                        
                        <div class="location-section">
                            <div class="alert alert-info" style="margin-bottom: 15px;">
                                <i class="fas fa-info-circle"></i>
                                <strong>GPS Tips:</strong> For best accuracy, ensure GPS is enabled, allow location access when prompted, and capture location while outdoors or near windows.
                            </div>
                            
                            <div class="form-row single">
                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-map-pin"></i>
                                        Property Address/Location
                                    </label>
                                    <div class="location-input-group">
                                        <div class="form-group" style="margin-bottom: 0;">
                                            <input type="text" name="location" id="propertyLocation" class="form-control" 
                                                   value="<?php echo htmlspecialchars($formData['location']); ?>" 
                                                   placeholder="Enter detailed property address or use GPS capture button...">
                                            <div class="form-help">Detailed property address, landmark descriptions, or street location</div>
                                        </div>
                                        <button type="button" class="location-btn" onclick="getCurrentLocation()" id="locationBtn">
                                            <i class="fas fa-crosshairs"></i>
                                            <span class="icon-location" style="display: none;"></span>
                                            Update GPS Location
                                        </button>
                                    </div>
                                    <div class="location-status" id="locationStatus"></div>
                                </div>
                            </div>
                            
                            <div class="gps-coordinates">
                                <div class="gps-title">
                                    <i class="fas fa-satellite-dish"></i>
                                    GPS Coordinates
                                </div>
                                <div class="coordinate-inputs">
                                    <div class="coordinate-group">
                                        <div class="coordinate-label">Latitude</div>
                                        <input type="number" name="latitude" id="latitude" class="coordinate-input" 
                                               step="0.000001" min="-90" max="90"
                                               value="<?php echo htmlspecialchars($formData['latitude']); ?>" 
                                               placeholder="e.g., 5.614818">
                                    </div>
                                    <div class="coordinate-group">
                                        <div class="coordinate-label">Longitude</div>
                                        <input type="number" name="longitude" id="longitude" class="coordinate-input" 
                                               step="0.000001" min="-180" max="180"
                                               value="<?php echo htmlspecialchars($formData['longitude']); ?>" 
                                               placeholder="e.g., -0.205874">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-map"></i>
                                    Zone <span class="required">*</span>
                                </label>
                                <select name="zone_id" id="zoneSelect" class="form-control" required>
                                    <option value="">Select Zone</option>
                                    <?php foreach ($zones as $zone): ?>
                                        <option value="<?php echo $zone['zone_id']; ?>" 
                                                <?php echo $formData['zone_id'] == $zone['zone_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($zone['zone_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-help">Administrative zone where property is located</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-folder"></i>
                                    Batch
                                </label>
                                <input type="text" name="batch" class="form-control" 
                                       value="<?php echo htmlspecialchars($formData['batch']); ?>" 
                                       placeholder="e.g., BATCH2025-01">
                                <div class="form-help">Group identifier for batch processing</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Billing Information Section -->
                <div class="form-card">
                    <div class="form-section">
                        <h3 class="section-title">
                            <div class="section-icon">
                                <i class="fas fa-money-bill-wave"></i>
                            </div>
                            Billing Information
                        </h3>
                        
                        <!-- Dynamic Fee Display -->
                        <div class="fee-display" id="feeDisplay">
                            <div class="fee-amount" id="feeAmount">₵ <?php echo number_format($formData['current_bill'], 2); ?></div>
                            <div class="fee-description" id="feeDescription">Current fee for <?php echo htmlspecialchars($formData['structure'] . ' - ' . $formData['property_use'] . ' (' . $formData['number_of_rooms'] . ' room' . ($formData['number_of_rooms'] != 1 ? 's' : '') . ')'); ?></div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-history"></i>
                                    Old Bill (₵)
                                </label>
                                <input type="number" name="old_bill" class="form-control" step="0.01" min="0"
                                       value="<?php echo $formData['old_bill']; ?>" 
                                       placeholder="0.00">
                                <div class="form-help">Previous outstanding amount</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-credit-card"></i>
                                    Previous Payments (₵)
                                </label>
                                <input type="number" name="previous_payments" class="form-control" step="0.01" min="0"
                                       value="<?php echo $formData['previous_payments']; ?>" 
                                       placeholder="0.00">
                                <div class="form-help">Amount already paid from previous bills</div>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    Arrears (₵)
                                </label>
                                <input type="number" name="arrears" class="form-control" step="0.01" min="0"
                                       value="<?php echo $formData['arrears']; ?>" 
                                       placeholder="0.00">
                                <div class="form-help">Outstanding arrears from previous periods</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-receipt"></i>
                                    Current Bill (₵)
                                </label>
                                <input type="number" name="current_bill" id="currentBill" class="form-control" step="0.01" min="0"
                                       value="<?php echo $formData['current_bill']; ?>" 
                                       placeholder="0.00" readonly>
                                <div class="form-help">Auto-calculated: Fee per room × Number of rooms</div>
                            </div>
                        </div>
                        
                        <div class="form-row single">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-calculator"></i>
                                    Amount Payable (₵)
                                </label>
                                <input type="text" id="amountPayable" class="form-control" readonly 
                                       style="background: #f8fafc; font-weight: bold; color: #2d3748; font-size: 18px;">
                                <div class="form-help">Auto-calculated: Old Bill + Arrears + Current Bill - Previous Payments</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="form-card">
                    <div class="form-actions">
                        <a href="view.php?id=<?php echo $propertyId; ?>" class="btn btn-secondary">
                            <i class="fas fa-times"></i>
                            Cancel
                        </a>
                        <?php if (hasPermission('properties.delete')): ?>
                        <button type="button" class="btn btn-danger" onclick="confirmDelete(<?php echo $propertyId; ?>)">
                            <i class="fas fa-trash"></i>
                            <span class="icon-trash" style="display: none;"></span>
                            Delete Property
                        </button>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i>
                            <span class="icon-save" style="display: none;"></span>
                            Save Changes
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Property fee data for JavaScript
        const propertyFees = <?php echo $propertyFeesJson; ?>;
        let originalFormData = {};
        let formChanged = false;

        // Check if Font Awesome loaded, if not show emoji icons
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                const testIcon = document.querySelector('.fas.fa-bars');
                if (!testIcon || getComputedStyle(testIcon, ':before').content === 'none') {
                    document.querySelectorAll('.fas, .far').forEach(function(icon) {
                        icon.style.display = 'none';
                    });
                    document.querySelectorAll('[class*="icon-"]').forEach(function(emoji) {
                        emoji.style.display = 'inline';
                    });
                }
            }, 100);
            
            // Initialize form
            initializeForm();
            
            // Store original form data
            storeOriginalFormData();
            
            // Set up change detection
            setupChangeDetection();
        });

        // Store original form data for change detection
        function storeOriginalFormData() {
            const formElements = document.querySelectorAll('#propertyForm input, #propertyForm select');
            formElements.forEach(function(element) {
                originalFormData[element.name] = element.value;
            });
        }

        // Set up change detection
        function setupChangeDetection() {
            const formElements = document.querySelectorAll('#propertyForm input, #propertyForm select');
            formElements.forEach(function(element) {
                element.addEventListener('input', checkForChanges);
                element.addEventListener('change', checkForChanges);
            });
        }

        // Check if form has changes
        function checkForChanges() {
            const formElements = document.querySelectorAll('#propertyForm input, #propertyForm select');
            let hasChanges = false;
            
            formElements.forEach(function(element) {
                if (originalFormData[element.name] !== element.value) {
                    hasChanges = true;
                }
            });
            
            const indicator = document.getElementById('changesIndicator');
            if (hasChanges && !formChanged) {
                indicator.classList.add('show');
                formChanged = true;
            } else if (!hasChanges && formChanged) {
                indicator.classList.remove('show');
                formChanged = false;
            }
        }

        // Initialize form functionality
        function initializeForm() {
            // Set up structure change handler
            document.getElementById('structureSelect').addEventListener('change', updatePropertyUse);
            
            // Set up property use change handler
            document.getElementById('propertyUseSelect').addEventListener('change', updateCurrentBill);
            
            // Set up room count change handler
            document.getElementById('roomCount').addEventListener('input', updateCurrentBill);
            
            // Set up billing calculation handlers
            ['old_bill', 'previous_payments', 'arrears', 'current_bill'].forEach(function(field) {
                const element = document.querySelector(`[name="${field}"]`);
                if (element) {
                    element.addEventListener('input', calculateAmountPayable);
                }
            });
            
            // Add coordinate validation
            document.getElementById('latitude').addEventListener('input', validateCoordinates);
            document.getElementById('longitude').addEventListener('input', validateCoordinates);
            
            // Initialize with existing data
            updatePropertyUse();
            calculateAmountPayable();
        }

        // Validate coordinate inputs
        function validateCoordinates() {
            const latInput = document.getElementById('latitude');
            const lngInput = document.getElementById('longitude');
            
            const lat = parseFloat(latInput.value);
            const lng = parseFloat(lngInput.value);
            
            // Validate latitude
            if (latInput.value && (isNaN(lat) || lat < -90 || lat > 90)) {
                latInput.style.borderColor = '#e53e3e';
                latInput.style.backgroundColor = '#fef2f2';
            } else {
                latInput.style.borderColor = '#bae6fd';
                latInput.style.backgroundColor = 'white';
            }
            
            // Validate longitude
            if (lngInput.value && (isNaN(lng) || lng < -180 || lng > 180)) {
                lngInput.style.borderColor = '#e53e3e';
                lngInput.style.backgroundColor = '#fef2f2';
            } else {
                lngInput.style.borderColor = '#bae6fd';
                lngInput.style.backgroundColor = 'white';
            }
        }

        // Update property use options based on selected structure
        function updatePropertyUse() {
            const structure = document.getElementById('structureSelect').value;
            const propertyUseSelect = document.getElementById('propertyUseSelect');
            const currentUse = '<?php echo htmlspecialchars($formData['property_use']); ?>';
            
            // Clear existing options
            propertyUseSelect.innerHTML = '<option value="">Select Use</option>';
            
            if (structure) {
                // Filter fees by structure
                const structureUses = propertyFees.filter(fee => fee.structure === structure);
                const uniqueUses = [...new Set(structureUses.map(fee => fee.property_use))];
                
                uniqueUses.forEach(function(use) {
                    const option = document.createElement('option');
                    option.value = use;
                    option.textContent = use;
                    
                    // Restore selected use
                    if (use === currentUse) {
                        option.selected = true;
                    }
                    
                    propertyUseSelect.appendChild(option);
                });
            }
            
            // Update current bill
            updateCurrentBill();
        }

        // Update current bill based on structure, use, and room count
        function updateCurrentBill() {
            const structure = document.getElementById('structureSelect').value;
            const propertyUse = document.getElementById('propertyUseSelect').value;
            const roomCount = parseInt(document.getElementById('roomCount').value) || 1;
            const currentBillInput = document.getElementById('currentBill');
            const feeDisplay = document.getElementById('feeDisplay');
            const feeAmount = document.getElementById('feeAmount');
            const feeDescription = document.getElementById('feeDescription');
            
            if (structure && propertyUse) {
                // Find the fee for this combination
                const fee = propertyFees.find(f => f.structure === structure && f.property_use === propertyUse);
                
                if (fee) {
                    const feePerRoom = parseFloat(fee.fee_per_room);
                    const totalBill = feePerRoom * roomCount;
                    
                    currentBillInput.value = totalBill.toFixed(2);
                    
                    // Update fee display
                    feeAmount.textContent = `₵ ${totalBill.toLocaleString('en-US', {minimumFractionDigits: 2})}`;
                    feeDescription.textContent = `₵${feePerRoom}/room × ${roomCount} room${roomCount !== 1 ? 's' : ''} for ${structure} - ${propertyUse}`;
                } else {
                    currentBillInput.value = '0.00';
                    feeDescription.textContent = 'Fee not found for this combination';
                }
            }
            
            // Recalculate amount payable
            calculateAmountPayable();
        }

        // Room counter functions
        function changeRooms(delta) {
            const roomInput = document.getElementById('roomCount');
            const currentCount = parseInt(roomInput.value) || 1;
            const newCount = Math.max(1, currentCount + delta);
            
            roomInput.value = newCount;
            updateCurrentBill();
        }

        // Calculate amount payable
        function calculateAmountPayable() {
            const oldBill = parseFloat(document.querySelector('[name="old_bill"]').value) || 0;
            const previousPayments = parseFloat(document.querySelector('[name="previous_payments"]').value) || 0;
            const arrears = parseFloat(document.querySelector('[name="arrears"]').value) || 0;
            const currentBill = parseFloat(document.getElementById('currentBill').value) || 0;
            
            const amountPayable = oldBill + arrears + currentBill - previousPayments;
            document.getElementById('amountPayable').value = `₵ ${amountPayable.toLocaleString('en-US', {minimumFractionDigits: 2})}`;
        }

        // Enhanced GPS location function with high precision and fallback
        function getCurrentLocation() {
            if (!navigator.geolocation) {
                alert('Geolocation is not supported by this browser.');
                return;
            }
            
            // Show loading state
            const locationBtn = document.getElementById('locationBtn');
            const originalText = locationBtn.innerHTML;
            locationBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Capturing Location...';
            locationBtn.disabled = true;
            
            // First attempt with high accuracy
            navigator.geolocation.getCurrentPosition(
                function(position) {
                    handleLocationSuccess(position, locationBtn, originalText);
                },
                function(error) {
                    console.warn('High accuracy failed, trying standard accuracy:', error.message);
                    
                    // Second attempt with standard accuracy
                    navigator.geolocation.getCurrentPosition(
                        function(position) {
                            handleLocationSuccess(position, locationBtn, originalText);
                        },
                        function(error) {
                            handleLocationError(error, locationBtn, originalText);
                        },
                        {
                            enableHighAccuracy: false,
                            timeout: 15000,
                            maximumAge: 60000
                        }
                    );
                },
                {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 0
                }
            );
        }
        
        // Handle successful location capture
        function handleLocationSuccess(position, locationBtn, originalText) {
            const latitude = position.coords.latitude;
            const longitude = position.coords.longitude;
            const accuracy = position.coords.accuracy;
            
            // Update input fields directly
            document.getElementById('latitude').value = latitude.toFixed(6);
            document.getElementById('longitude').value = longitude.toFixed(6);
            
            // Reset button
            locationBtn.innerHTML = originalText;
            locationBtn.disabled = false;
            
            // Show accuracy info
            console.log(`Location captured with ${accuracy}m accuracy`);
            
            // Show success message
            showLocationSuccess(`GPS: ${latitude.toFixed(6)}, ${longitude.toFixed(6)}`);
        }
        
        // Handle location capture error
        function handleLocationError(error, locationBtn, originalText) {
            let errorMessage = 'Error getting location: ';
            let solution = '';
            
            switch(error.code) {
                case error.PERMISSION_DENIED:
                    errorMessage += "Location access denied.";
                    solution = "Please enable location access in your browser settings and try again.";
                    break;
                case error.POSITION_UNAVAILABLE:
                    errorMessage += "Location information is unavailable.";
                    solution = "Please ensure GPS is enabled and you have a stable internet connection.";
                    break;
                case error.TIMEOUT:
                    errorMessage += "Location request timed out.";
                    solution = "Please try again. Make sure you're in an area with good GPS signal.";
                    break;
                default:
                    errorMessage += "An unknown error occurred.";
                    solution = "Please try again or enter the location manually.";
                    break;
            }
            
            // Reset button
            locationBtn.innerHTML = originalText;
            locationBtn.disabled = false;
            
            // Show detailed error
            alert(errorMessage + '\n\n' + solution);
        }
        
        // Show location capture success
        function showLocationSuccess(locationName) {
            // Create temporary success message
            const successDiv = document.createElement('div');
            successDiv.style.cssText = `
                position: fixed;
                top: 100px;
                right: 20px;
                background: #48bb78;
                color: white;
                padding: 15px 20px;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.2);
                z-index: 10000;
                font-weight: 600;
                animation: slideIn 0.3s ease-out;
            `;
            successDiv.innerHTML = `
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-map-marker-alt" style="color: #68d391;"></i>
                    <div>
                        <div>Location Updated!</div>
                        <div style="font-size: 12px; opacity: 0.9;">${locationName}</div>
                    </div>
                </div>
            `;
            
            document.body.appendChild(successDiv);
            
            // Remove after 4 seconds
            setTimeout(() => {
                successDiv.style.animation = 'slideOut 0.3s ease-in forwards';
                setTimeout(() => {
                    if (successDiv.parentNode) {
                        successDiv.parentNode.removeChild(successDiv);
                    }
                }, 300);
            }, 4000);
        }

        // Enhanced confirm delete function with relationship checking
        async function confirmDelete(propertyId) {
            try {
                // First check for relationships
                const relationshipResponse = await fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=check_relationships'
                });
                
                const relationshipData = await relationshipResponse.json();
                
                if (!relationshipData.success) {
                    alert('Error checking property relationships: ' + relationshipData.message);
                    return;
                }
                
                const relationships = relationshipData.data;
                
                // Create delete confirmation modal with relationship info
                const modal = document.createElement('div');
                modal.className = 'delete-modal';
                
                let relationshipWarning = '';
                if (relationships.has_relationships) {
                    relationshipWarning = `
                        <div class="relationships-info">
                            <div class="relationships-title">
                                <i class="fas fa-link"></i>
                                <span class="icon-warning" style="display: none;">⚠️</span>
                                Related Records Found
                            </div>
                            <p>This property has the following related records that will also be deleted:</p>
                            <ul class="relationships-list">
                                ${relationships.bills > 0 ? `<li><strong>${relationships.bills}</strong> Bill record${relationships.bills > 1 ? 's' : ''}</li>` : ''}
                                ${relationships.payments > 0 ? `<li><strong>${relationships.payments}</strong> Payment record${relationships.payments > 1 ? 's' : ''} (Total: ₵${parseFloat(relationships.total_payment_amount).toLocaleString('en-US', {minimumFractionDigits: 2})})</li>` : ''}
                                ${relationships.adjustments > 0 ? `<li><strong>${relationships.adjustments}</strong> Bill adjustment${relationships.adjustments > 1 ? 's' : ''}</li>` : ''}
                            </ul>
                            <p><strong>All related records will be permanently deleted along with the property.</strong></p>
                        </div>
                    `;
                }
                
                modal.innerHTML = `
                    <div class="delete-content">
                        <div class="delete-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                            <span class="icon-trash" style="display: none;">🗑️</span>
                        </div>
                        <h3 class="delete-title">Delete Property</h3>
                        <p class="delete-message">Are you sure you want to delete 
                            <span class="property-name-highlight"><?php echo htmlspecialchars($property['owner_name']); ?>'s Property</span>?
                        </p>
                        ${relationshipWarning}
                        <div class="delete-warning">
                            <strong>⚠️ Warning:</strong> This action will permanently delete the property record${relationships.has_relationships ? ' and all related records' : ''}. 
                            This action cannot be undone. Please make sure you want to proceed.
                        </div>
                        <div class="delete-actions">
                            <button type="button" class="cancel-btn" onclick="closeDeleteModal()">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                            <button type="button" class="confirm-delete-btn" onclick="deleteProperty(${propertyId})">
                                <i class="fas fa-trash"></i>
                                <span class="icon-trash" style="display: none;">🗑️</span>
                                Delete Property${relationships.has_relationships ? ' & Related Records' : ''}
                            </button>
                        </div>
                    </div>
                `;
                
                document.body.appendChild(modal);
                
                // Focus trap for accessibility
                const confirmBtn = modal.querySelector('.confirm-delete-btn');
                confirmBtn.focus();
                
                // Close on escape key
                const escapeHandler = function(e) {
                    if (e.key === 'Escape') {
                        closeDeleteModal();
                        document.removeEventListener('keydown', escapeHandler);
                    }
                };
                document.addEventListener('keydown', escapeHandler);
                
                // Close on backdrop click
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        closeDeleteModal();
                        document.removeEventListener('keydown', escapeHandler);
                    }
                });
                
                // Store the escape handler for cleanup
                window.currentEscapeHandler = escapeHandler;
                
            } catch (error) {
                console.error('Error checking relationships:', error);
                alert('Error checking property relationships. Please try again.');
            }
        }

        // Close delete modal
        function closeDeleteModal() {
            const modal = document.querySelector('.delete-modal');
            if (modal) {
                modal.style.animation = 'fadeOut 0.3s ease forwards';
                setTimeout(() => modal.remove(), 300);
                
                // Clean up escape handler
                if (window.currentEscapeHandler) {
                    document.removeEventListener('keydown', window.currentEscapeHandler);
                    delete window.currentEscapeHandler;
                }
            }
        }

        // Enhanced delete property function
        function deleteProperty(propertyId) {
            const confirmBtn = document.querySelector('.confirm-delete-btn');
            const originalText = confirmBtn.innerHTML;
            
            // Show loading state
            confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
            confirmBtn.disabled = true;
            confirmBtn.classList.add('loading');
            
            // Create form data for delete request
            const formData = new FormData();
            formData.append('action', 'delete');
            
            // Send delete request
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    // Show success message
                    confirmBtn.innerHTML = '<i class="fas fa-check"></i> Deleted!';
                    confirmBtn.style.background = 'linear-gradient(135deg, #48bb78 0%, #38a169 100%)';
                    
                    // Show success notification
                    showSuccessNotification('Property and related records deleted successfully!');
                    
                    // Redirect to property list after short delay
                    setTimeout(() => {
                        window.location.href = 'index.php?message=' + encodeURIComponent(data.message);
                    }, 2000);
                } else {
                    // Show error message
                    showErrorNotification('Error: ' + data.message);
                    confirmBtn.innerHTML = originalText;
                    confirmBtn.disabled = false;
                    confirmBtn.classList.remove('loading');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showErrorNotification('An error occurred while deleting the property. Please try again.');
                confirmBtn.innerHTML = originalText;
                confirmBtn.disabled = false;
                confirmBtn.classList.remove('loading');
            });
        }

        // Show success notification
        function showSuccessNotification(message) {
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                top: 100px;
                right: 20px;
                background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
                color: white;
                padding: 20px 25px;
                border-radius: 12px;
                box-shadow: 0 8px 25px rgba(72, 187, 120, 0.3);
                z-index: 10000;
                font-weight: 600;
                animation: slideIn 0.3s ease-out;
                max-width: 400px;
            `;
            notification.innerHTML = `
                <div style="display: flex; align-items: center; gap: 12px;">
                    <i class="fas fa-check-circle" style="color: #68d391; font-size: 24px;"></i>
                    <div>
                        <div style="font-size: 16px; margin-bottom: 4px;">Success!</div>
                        <div style="font-size: 14px; opacity: 0.9;">${message}</div>
                    </div>
                </div>
            `;
            
            document.body.appendChild(notification);
            
            // Remove after 5 seconds
            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease-in forwards';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }, 5000);
        }

        // Show error notification
        function showErrorNotification(message) {
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                top: 100px;
                right: 20px;
                background: linear-gradient(135deg, #f56565 0%, #e53e3e 100%);
                color: white;
                padding: 20px 25px;
                border-radius: 12px;
                box-shadow: 0 8px 25px rgba(245, 101, 101, 0.3);
                z-index: 10000;
                font-weight: 600;
                animation: slideIn 0.3s ease-out;
                max-width: 400px;
            `;
            notification.innerHTML = `
                <div style="display: flex; align-items: center; gap: 12px;">
                    <i class="fas fa-exclamation-circle" style="color: #fca5a5; font-size: 24px;"></i>
                    <div>
                        <div style="font-size: 16px; margin-bottom: 4px;">Error</div>
                        <div style="font-size: 14px; opacity: 0.9;">${message}</div>
                    </div>
                </div>
            `;
            
            document.body.appendChild(notification);
            
            // Remove after 6 seconds
            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease-in forwards';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }, 6000);
        }

        // Sidebar toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('hidden');
            
            const isHidden = sidebar.classList.contains('hidden');
            localStorage.setItem('sidebarHidden', isHidden);
        }

        // Restore sidebar state
        document.addEventListener('DOMContentLoaded', function() {
            const sidebarHidden = localStorage.getItem('sidebarHidden');
            if (sidebarHidden === 'true') {
                document.getElementById('sidebar').classList.add('hidden');
            }
        });

        // User dropdown toggle
        function toggleUserDropdown() {
            const dropdown = document.getElementById('userDropdown');
            const profile = document.getElementById('userProfile');
            
            dropdown.classList.toggle('show');
            profile.classList.toggle('active');
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('userDropdown');
            const profile = document.getElementById('userProfile');
            
            if (!profile.contains(event.target)) {
                dropdown.classList.remove('show');
                profile.classList.remove('active');
            }
        });

        // Coming soon modal (only for remaining features)
        function showComingSoon(feature) {
            const backdrop = document.createElement('div');
            backdrop.style.cssText = `
                position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(0,0,0,0.6); backdrop-filter: blur(8px); z-index: 10000;
                display: flex; align-items: center; justify-content: center;
                animation: fadeIn 0.3s ease; cursor: pointer;
            `;
            
            const modal = document.createElement('div');
            modal.style.cssText = `
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white; padding: 50px 40px; border-radius: 25px; text-align: center;
                box-shadow: 0 25px 80px rgba(0,0,0,0.4); max-width: 450px; width: 90%;
                animation: modalSlideIn 0.4s ease; cursor: default; position: relative; overflow: hidden;
            `;
            
            modal.innerHTML = `
                <div style="position: absolute; top: -50%; right: -50%; width: 200%; height: 200%;
                    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
                    animation: rotate 20s linear infinite; pointer-events: none;"></div>
                
                <div style="position: relative; z-index: 2;">
                    <div style="width: 80px; height: 80px; background: rgba(255,255,255,0.2);
                        border-radius: 50%; display: flex; align-items: center; justify-content: center;
                        margin: 0 auto 25px; animation: bounce 2s ease-in-out infinite;">
                        <i class="fas fa-rocket" style="font-size: 2.5rem; color: white;"></i>
                        <span style="font-size: 2.5rem; display: none;">🚀</span>
                    </div>
                    
                    <h3 style="margin: 0 0 15px 0; font-weight: 700; font-size: 1.8rem;
                        text-shadow: 0 2px 4px rgba(0,0,0,0.3);">${feature}</h3>
                    
                    <p style="margin: 0 0 30px 0; opacity: 0.9; font-size: 1.1rem; line-height: 1.6;">
                        This amazing feature is coming soon! 🎉<br>We're working hard to bring you the best experience.</p>
                    
                    <button onclick="closeModal()" style="background: rgba(255,255,255,0.2);
                        border: 2px solid rgba(255,255,255,0.3); color: white; padding: 12px 30px;
                        border-radius: 25px; cursor: pointer; font-weight: 600; font-size: 1rem;
                        transition: all 0.3s ease; backdrop-filter: blur(10px);">
                        Awesome! Let's Go 🚀
                    </button>
                    
                    <div style="margin-top: 20px; font-size: 0.9rem; opacity: 0.7;">
                        Click anywhere outside to close</div>
                </div>
            `;
            
            backdrop.appendChild(modal);
            document.body.appendChild(backdrop);
            
            if (!document.getElementById('modalAnimations')) {
                const style = document.createElement('style');
                style.id = 'modalAnimations';
                style.textContent = `
                    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
                    @keyframes modalSlideIn { from { transform: translateY(-30px) scale(0.9); opacity: 0; } 
                        to { transform: translateY(0) scale(1); opacity: 1; } }
                    @keyframes bounce { 0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
                        40% { transform: translateY(-10px); } 60% { transform: translateY(-5px); } }
                    @keyframes rotate { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
                    @keyframes fadeOut { from { opacity: 1; } to { opacity: 0; } }
                    @keyframes modalSlideOut { from { transform: translateY(0) scale(1); opacity: 1; }
                        to { transform: translateY(-30px) scale(0.9); opacity: 0; } }
                    @keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.1); } 100% { transform: scale(1); } }
                `;
                document.head.appendChild(style);
            }
            
            window.closeModal = function() {
                backdrop.style.animation = 'fadeOut 0.3s ease forwards';
                modal.style.animation = 'modalSlideOut 0.3s ease forwards';
                setTimeout(() => backdrop.remove(), 300);
            };
            
            backdrop.addEventListener('click', function(e) {
                if (e.target === backdrop) closeModal();
            });
        }

        // Enhanced form validation before submit
        document.getElementById('propertyForm').addEventListener('submit', function(e) {
            const requiredFields = this.querySelectorAll('[required]');
            let isValid = true;
            let firstInvalidField = null;
            
            requiredFields.forEach(function(field) {
                if (!field.value.trim()) {
                    field.classList.add('error');
                    if (!firstInvalidField) {
                        firstInvalidField = field;
                    }
                    isValid = false;
                } else {
                    field.classList.remove('error');
                }
            });
            
            // Validate coordinates if provided
            const lat = document.getElementById('latitude').value;
            const lng = document.getElementById('longitude').value;
            
            if (lat && (isNaN(parseFloat(lat)) || parseFloat(lat) < -90 || parseFloat(lat) > 90)) {
                alert('Please enter a valid latitude between -90 and 90.');
                document.getElementById('latitude').focus();
                isValid = false;
            }
            
            if (lng && (isNaN(parseFloat(lng)) || parseFloat(lng) < -180 || parseFloat(lng) > 180)) {
                alert('Please enter a valid longitude between -180 and 180.');
                document.getElementById('longitude').focus();
                isValid = false;
            }
            
            if (!isValid) {
                e.preventDefault();
                
                if (firstInvalidField) {
                    firstInvalidField.focus();
                    firstInvalidField.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                
                showErrorNotification('Please fix the validation errors and try again.');
                return false;
            }
            
            // Show loading state for submit button
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalSubmitText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving Changes...';
            submitBtn.disabled = true;
            
            // Re-enable button after a delay in case of errors
            setTimeout(() => {
                if (submitBtn.disabled) {
                    submitBtn.innerHTML = originalSubmitText;
                    submitBtn.disabled = false;
                }
            }, 30000);
        });

        // Warn user about unsaved changes
        window.addEventListener('beforeunload', function(e) {
            if (formChanged) {
                e.preventDefault();
                e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
                return e.returnValue;
            }
        });

        // Mobile responsiveness
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth <= 768) {
                sidebar.classList.remove('hidden');
            }
        });

        // Auto-save draft functionality (optional enhancement)
        let autoSaveTimer;
        function scheduleAutoSave() {
            clearTimeout(autoSaveTimer);
            autoSaveTimer = setTimeout(() => {
                if (formChanged) {
                    saveDraft();
                }
            }, 30000); // Auto-save after 30 seconds of inactivity
        }

        function saveDraft() {
            const formData = new FormData(document.getElementById('propertyForm'));
            const draftData = {};
            for (let [key, value] of formData.entries()) {
                draftData[key] = value;
            }
            
            localStorage.setItem('propertyEditDraft_<?php echo $propertyId; ?>', JSON.stringify(draftData));
            console.log('Draft saved automatically');
        }

        // Load draft on page load
        function loadDraft() {
            const draftData = localStorage.getItem('propertyEditDraft_<?php echo $propertyId; ?>');
            if (draftData) {
                try {
                    const data = JSON.parse(draftData);
                    let hasChanges = false;
                    
                    Object.keys(data).forEach(key => {
                        const field = document.querySelector(`[name="${key}"]`);
                        if (field && field.value !== data[key]) {
                            field.value = data[key];
                            hasChanges = true;
                        }
                    });
                    
                    if (hasChanges) {
                        // Re-trigger calculations and updates
                        updatePropertyUse();
                        calculateAmountPayable();
                        
                        // Show draft loaded message
                        setTimeout(() => {
                            const draftNotice = document.createElement('div');
                            draftNotice.className = 'alert alert-info';
                            draftNotice.innerHTML = `
                                <i class="fas fa-info-circle"></i>
                                <div>
                                    <strong>Draft Loaded:</strong> Your unsaved changes have been restored.
                                    <button type="button" onclick="clearDraft()" style="margin-left: 15px; padding: 4px 12px; background: #3182ce; color: white; border: none; border-radius: 4px; font-size: 12px;">Clear Draft</button>
                                </div>
                            `;
                            
                            const firstCard = document.querySelector('.form-card');
                            firstCard.parentNode.insertBefore(draftNotice, firstCard);
                            
                            // Remove after 10 seconds
                            setTimeout(() => {
                                if (draftNotice.parentNode) {
                                    draftNotice.remove();
                                }
                            }, 10000);
                        }, 1000);
                    }
                } catch (e) {
                    console.error('Error loading draft:', e);
                    localStorage.removeItem('propertyEditDraft_<?php echo $propertyId; ?>');
                }
            }
        }

        function clearDraft() {
            localStorage.removeItem('propertyEditDraft_<?php echo $propertyId; ?>');
            location.reload();
        }

        // Initialize draft functionality
        document.addEventListener('DOMContentLoaded', function() {
            loadDraft();
            
            // Set up auto-save triggers
            const formElements = document.querySelectorAll('#propertyForm input, #propertyForm select, #propertyForm textarea');
            formElements.forEach(element => {
                element.addEventListener('input', scheduleAutoSave);
                element.addEventListener('change', scheduleAutoSave);
            });
        });

        // Clear draft on successful form submission
        document.getElementById('propertyForm').addEventListener('submit', function() {
            localStorage.removeItem('propertyEditDraft_<?php echo $propertyId; ?>');
        });
    </script>
</body>
</html>