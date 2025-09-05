<?php
/**
 * Billing Management - Generate Bills (UPDATED WITH PROPER ARREARS CALCULATION)
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
if (!hasPermission('billing.create')) {
    setFlashMessage('error', 'Access denied. You do not have permission to generate bills.');
    header('Location: index.php');
    exit();
}

// Check session expiration
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > 5600)) {
    // Session expired (30 minutes)
    session_unset();
    session_destroy();
    setFlashMessage('error', 'Your session has expired. Please log in again.');
    header('Location: ../../index.php');
    exit();
}

$pageTitle = 'Generate Bills';
$currentUser = getCurrentUser();
$userDisplayName = getUserDisplayName($currentUser);

// Initialize variables
$errors = [];
$success = false;
$generationResults = [];
$zones = [];
$businessTypes = [];

// Get specific business/property if requested
$specificType = sanitizeInput($_GET['type'] ?? '');
$specificId = intval($_GET['id'] ?? 0);
$specificRecord = null;

/**
 * Calculate arrears for a business from previous unpaid bills
 */
function calculateBusinessArrears($db, $businessId, $currentYear) {
    // Get last year's bill data
    $lastYearBill = $db->fetchRow("
        SELECT 
            current_bill as old_bill,
            COALESCE(
                (SELECT SUM(amount_paid) 
                 FROM payments p 
                 WHERE p.bill_id = b.bill_id 
                 AND p.payment_status = 'Successful'), 0
            ) as previous_payments
        FROM bills b
        WHERE bill_type = 'Business' 
        AND reference_id = ? 
        AND billing_year = ?
    ", [$businessId, $currentYear - 1]);
    
    if (!$lastYearBill) {
        return [
            'old_bill' => 0,
            'previous_payments' => 0,
            'arrears' => 0
        ];
    }
    
    $oldBill = $lastYearBill['old_bill'] ?? 0;
    $previousPayments = $lastYearBill['previous_payments'] ?? 0;
    $arrears = max(0, $oldBill - $previousPayments); // Outstanding from previous year
    
    return [
        'old_bill' => $oldBill,
        'previous_payments' => $previousPayments,
        'arrears' => $arrears
    ];
}

/**
 * Calculate arrears for a property from previous unpaid bills
 */
function calculatePropertyArrears($db, $propertyId, $currentYear) {
    // Get last year's bill data
    $lastYearBill = $db->fetchRow("
        SELECT 
            current_bill as old_bill,
            COALESCE(
                (SELECT SUM(amount_paid) 
                 FROM payments p 
                 WHERE p.bill_id = b.bill_id 
                 AND p.payment_status = 'Successful'), 0
            ) as previous_payments
        FROM bills b
        WHERE bill_type = 'Property' 
        AND reference_id = ? 
        AND billing_year = ?
    ", [$propertyId, $currentYear - 1]);
    
    if (!$lastYearBill) {
        return [
            'old_bill' => 0,
            'previous_payments' => 0,
            'arrears' => 0
        ];
    }
    
    $oldBill = $lastYearBill['old_bill'] ?? 0;
    $previousPayments = $lastYearBill['previous_payments'] ?? 0;
    $arrears = max(0, $oldBill - $previousPayments); // Outstanding from previous year
    
    return [
        'old_bill' => $oldBill,
        'previous_payments' => $previousPayments,
        'arrears' => $arrears
    ];
}

try {
    $db = new Database();
    
    // Get zones for filtering
    $zones = $db->fetchAll("SELECT * FROM zones ORDER BY zone_name");
    
    // Get business types for filtering
    $businessTypes = $db->fetchAll("
        SELECT DISTINCT business_type 
        FROM business_fee_structure 
        WHERE is_active = 1 
        ORDER BY business_type
    ");
    
    // Get specific record if requested
    if ($specificType && $specificId) {
        if ($specificType === 'business') {
            $specificRecord = $db->fetchRow("
                SELECT b.*, bfs.fee_amount, z.zone_name
                FROM businesses b 
                LEFT JOIN business_fee_structure bfs ON b.business_type = bfs.business_type AND b.category = bfs.category
                LEFT JOIN zones z ON b.zone_id = z.zone_id
                WHERE b.business_id = ? AND bfs.is_active = 1
            ", [$specificId]);
        } elseif ($specificType === 'property') {
            $specificRecord = $db->fetchRow("
                SELECT p.*, pfs.fee_per_room, z.zone_name
                FROM properties p 
                LEFT JOIN property_fee_structure pfs ON p.structure = pfs.structure AND p.property_use = pfs.property_use
                LEFT JOIN zones z ON p.zone_id = z.zone_id
                WHERE p.property_id = ? AND pfs.is_active = 1
            ", [$specificId]);
        }
        
        if (!$specificRecord) {
            setFlashMessage('error', 'Record not found or no fee structure defined.');
            header('Location: index.php');
            exit();
        }
    }
    
    // Handle bill generation
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $generationType = sanitizeInput($_POST['generation_type']);
        $billingYear = intval($_POST['billing_year']);
        $selectedZone = intval($_POST['zone_id'] ?? 0);
        $selectedBusinessType = sanitizeInput($_POST['business_type'] ?? '');
        
        // Validation
        if (empty($billingYear) || $billingYear < 2020 || $billingYear > date('Y') + 1) {
            $errors[] = 'Please enter a valid billing year.';
        }
        
        if (empty($generationType)) {
            $errors[] = 'Please select a generation type.';
        }
        
        // Process bill generation if no errors
        if (empty($errors)) {
            try {
                // Start transaction
                if (method_exists($db, 'beginTransaction')) {
                    $db->beginTransaction();
                }
                
                $businessBillsGenerated = 0;
                $propertyBillsGenerated = 0;
                $skippedRecords = 0;
                
                // Generate business bills
                if ($generationType === 'all' || $generationType === 'businesses' || ($generationType === 'specific' && $specificType === 'business')) {
                    $businessQuery = "
                        SELECT b.*, bfs.fee_amount 
                        FROM businesses b 
                        LEFT JOIN business_fee_structure bfs ON b.business_type = bfs.business_type AND b.category = bfs.category 
                        WHERE b.status = 'Active' AND bfs.is_active = 1
                    ";
                    
                    $businessParams = [];
                    
                    // Add filters
                    if ($generationType === 'specific' && $specificId) {
                        $businessQuery .= " AND b.business_id = ?";
                        $businessParams[] = $specificId;
                    } elseif ($selectedZone > 0) {
                        $businessQuery .= " AND b.zone_id = ?";
                        $businessParams[] = $selectedZone;
                    }
                    
                    if (!empty($selectedBusinessType)) {
                        $businessQuery .= " AND b.business_type = ?";
                        $businessParams[] = $selectedBusinessType;
                    }
                    
                    $businesses = $db->fetchAll($businessQuery, $businessParams);
                    
                    foreach ($businesses as $business) {
                        // Check if bill already exists for this year
                        $existingBill = $db->fetchRow("
                            SELECT bill_id FROM bills 
                            WHERE bill_type = 'Business' AND reference_id = ? AND billing_year = ?
                        ", [$business['business_id'], $billingYear]);
                        
                        if ($existingBill) {
                            $skippedRecords++;
                            continue;
                        }
                        
                        // Calculate arrears from previous year
                        $arrearsData = calculateBusinessArrears($db, $business['business_id'], $billingYear);
                        $oldBill = $arrearsData['old_bill'];
                        $previousPayments = $arrearsData['previous_payments'];
                        $arrears = $arrearsData['arrears'];
                        
                        // Current year's bill from fee structure
                        $currentBill = $business['fee_amount'] ?? 0;
                        
                        // Amount payable = arrears + current bill (CORRECTED FORMULA)
                        $amountPayable = $arrears + $currentBill;
                        
                        // Generate bill number
                        $billNumber = 'BILL' . $billingYear . 'B' . str_pad($business['business_id'], 6, '0', STR_PAD_LEFT);
                        
                        // Insert bill
                        $db->execute("
                            INSERT INTO bills (bill_number, bill_type, reference_id, billing_year, 
                                             old_bill, previous_payments, arrears, current_bill, amount_payable, 
                                             status, generated_by, generated_at)
                            VALUES (?, 'Business', ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, NOW())
                        ", [
                            $billNumber, $business['business_id'], $billingYear,
                            $oldBill, $previousPayments, $arrears,
                            $currentBill, $amountPayable, $currentUser['user_id']
                        ]);
                        
                        // Update business record with calculated values
                        $db->execute("
                            UPDATE businesses 
                            SET old_bill = ?, previous_payments = ?, arrears = ?, 
                                current_bill = ?, amount_payable = ?
                            WHERE business_id = ?
                        ", [$oldBill, $previousPayments, $arrears, $currentBill, $amountPayable, $business['business_id']]);
                        
                        $businessBillsGenerated++;
                    }
                }
                
                // Generate property bills
                if ($generationType === 'all' || $generationType === 'properties' || ($generationType === 'specific' && $specificType === 'property')) {
                    $propertyQuery = "
                        SELECT p.*, pfs.fee_per_room 
                        FROM properties p 
                        LEFT JOIN property_fee_structure pfs ON p.structure = pfs.structure AND p.property_use = pfs.property_use 
                        WHERE pfs.is_active = 1
                    ";
                    
                    $propertyParams = [];
                    
                    // Add filters
                    if ($generationType === 'specific' && $specificId) {
                        $propertyQuery .= " AND p.property_id = ?";
                        $propertyParams[] = $specificId;
                    } elseif ($selectedZone > 0) {
                        $propertyQuery .= " AND p.zone_id = ?";
                        $propertyParams[] = $selectedZone;
                    }
                    
                    $properties = $db->fetchAll($propertyQuery, $propertyParams);
                    
                    foreach ($properties as $property) {
                        // Check if bill already exists for this year
                        $existingBill = $db->fetchRow("
                            SELECT bill_id FROM bills 
                            WHERE bill_type = 'Property' AND reference_id = ? AND billing_year = ?
                        ", [$property['property_id'], $billingYear]);
                        
                        if ($existingBill) {
                            $skippedRecords++;
                            continue;
                        }
                        
                        // Calculate arrears from previous year
                        $arrearsData = calculatePropertyArrears($db, $property['property_id'], $billingYear);
                        $oldBill = $arrearsData['old_bill'];
                        $previousPayments = $arrearsData['previous_payments'];
                        $arrears = $arrearsData['arrears'];
                        
                        // Current year's bill calculation
                        $currentBill = ($property['fee_per_room'] ?? 0) * $property['number_of_rooms'];
                        
                        // Amount payable = arrears + current bill (CORRECTED FORMULA)
                        $amountPayable = $arrears + $currentBill;
                        
                        // Generate bill number
                        $billNumber = 'BILL' . $billingYear . 'P' . str_pad($property['property_id'], 6, '0', STR_PAD_LEFT);
                        
                        // Insert bill
                        $db->execute("
                            INSERT INTO bills (bill_number, bill_type, reference_id, billing_year, 
                                             old_bill, previous_payments, arrears, current_bill, amount_payable, 
                                             status, generated_by, generated_at)
                            VALUES (?, 'Property', ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, NOW())
                        ", [
                            $billNumber, $property['property_id'], $billingYear,
                            $oldBill, $previousPayments, $arrears,
                            $currentBill, $amountPayable, $currentUser['user_id']
                        ]);
                        
                        // Update property record with calculated values
                        $db->execute("
                            UPDATE properties 
                            SET old_bill = ?, previous_payments = ?, arrears = ?, 
                                current_bill = ?, amount_payable = ?
                            WHERE property_id = ?
                        ", [$oldBill, $previousPayments, $arrears, $currentBill, $amountPayable, $property['property_id']]);
                        
                        $propertyBillsGenerated++;
                    }
                }
                
                // Commit transaction
                if (method_exists($db, 'commit')) {
                    $db->commit();
                }
                
                // Set results
                $generationResults = [
                    'business_bills' => $businessBillsGenerated,
                    'property_bills' => $propertyBillsGenerated,
                    'skipped_records' => $skippedRecords,
                    'total_generated' => $businessBillsGenerated + $propertyBillsGenerated
                ];
                
                $success = true;
                
                // Log the action directly to audit_logs
                $db->execute("
                    INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ", [
                    $currentUser['user_id'],
                    'BILLS_GENERATED',
                    'bills',
                    null,
                    null,
                    json_encode([
                        'generation_type' => $generationType,
                        'billing_year' => $billingYear,
                        'business_bills' => $businessBillsGenerated,
                        'property_bills' => $propertyBillsGenerated,
                        'skipped_records' => $skippedRecords,
                        'total_generated' => $businessBillsGenerated + $propertyBillsGenerated
                    ]),
                    $_SERVER['REMOTE_ADDR'] ?? '::1',
                    $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
                ]);
                
                setFlashMessage('success', "Bills generated successfully! {$generationResults['total_generated']} bills created, {$skippedRecords} skipped (already exist).");
                
            } catch (Exception $e) {
                // Rollback transaction
                if (method_exists($db, 'rollback')) {
                    $db->rollback();
                }
                $errors[] = 'An error occurred while generating bills: ' . $e->getMessage();
                writeLog("Bill generation error: " . $e->getMessage(), 'ERROR');
            }
        }
    }
    
} catch (Exception $e) {
    writeLog("Bill generation page error: " . $e->getMessage(), 'ERROR');
    $errors[] = 'An error occurred while loading the page.';
}

// Get flash messages
$flashMessages = getFlashMessages();
$flashMessage = !empty($flashMessages) ? $flashMessages[0] : null;
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
        .icon-money::before { content: "💰"; }
        .icon-plus::before { content: "➕"; }
        .icon-server::before { content: "🖥️"; }
        .icon-database::before { content: "💾"; }
        .icon-shield::before { content: "🛡️"; }
        .icon-user-plus::before { content: "👤➕"; }
        .icon-history::before { content: "📜"; }
        .icon-question::before { content: "❓"; }
        
        /* Top Navigation */
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
        
        .user-profile:hover .user-avatar {
            transform: scale(1.05);
            border-color: rgba(255,255,255,0.4);
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
        
        .user-profile.active .dropdown-arrow {
            transform: rotate(180deg);
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
        .container-layout {
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
        
        .container {
            max-width: 1000px;
            margin: 0 auto;
        }
        
        .page-header {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .page-title {
            font-size: 28px;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 10px;
        }
        
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
        
        .errors {
            background: #fee2e2;
            border: 1px solid #fca5a5;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .errors ul {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        
        .errors li {
            color: #991b1b;
            margin-bottom: 5px;
        }
        
        .errors li:before {
            content: "❌ ";
            margin-right: 5px;
        }
        
        /* Generation Form */
        .generation-form {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .form-section {
            margin-bottom: 30px;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-icon {
            width: 30px;
            height: 30px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 14px;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
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
        }
        
        .form-input {
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #10b981;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
        }
        
        .form-input:disabled {
            background: #f1f5f9;
            color: #64748b;
        }
        
        /* Radio buttons */
        .radio-group {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .radio-option {
            position: relative;
            cursor: pointer;
        }
        
        .radio-option input[type="radio"] {
            position: absolute;
            opacity: 0;
        }
        
        .radio-card {
            padding: 20px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            transition: all 0.3s;
            text-align: center;
        }
        
        .radio-option input[type="radio"]:checked + .radio-card {
            border-color: #10b981;
            background: #ecfdf5;
        }
        
        .radio-card:hover {
            border-color: #10b981;
            background: #f0fdf4;
        }
        
        .radio-icon {
            font-size: 24px;
            color: #10b981;
            margin-bottom: 10px;
        }
        
        .radio-title {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 5px;
        }
        
        .radio-description {
            font-size: 12px;
            color: #64748b;
            line-height: 1.4;
        }
        
        /* Specific Record Info */
        .specific-info {
            background: #f0f9ff;
            border: 2px solid #3b82f6;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
        }
        
        .specific-title {
            font-weight: 600;
            color: #1e40af;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
        }
        
        .info-label {
            font-size: 12px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .info-value {
            font-weight: 600;
            color: #1e40af;
        }
        
        /* Buttons */
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
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
        
        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.3);
            color: white;
            text-decoration: none;
        }
        
        .btn-secondary {
            background: #64748b;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #475569;
            color: white;
            text-decoration: none;
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #e2e8f0;
        }
        
        /* Results */
        .results-card {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            border: 2px solid #10b981;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            text-align: center;
        }
        
        .results-icon {
            width: 80px;
            height: 80px;
            background: #10b981;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            color: white;
            font-size: 36px;
        }
        
        .results-title {
            font-size: 24px;
            font-weight: bold;
            color: #065f46;
            margin-bottom: 15px;
        }
        
        .results-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        
        .stat-item {
            background: rgba(255, 255, 255, 0.8);
            padding: 15px;
            border-radius: 10px;
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #065f46;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 12px;
            color: #047857;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        /* Preview section */
        .preview-section {
            background: #f8fafc;
            border: 2px dashed #cbd5e1;
            border-radius: 10px;
            padding: 30px;
            text-align: center;
            color: #64748b;
            margin: 20px 0;
        }
        
        .preview-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .preview-loading {
            display: none;
            font-size: 14px;
            color: #3b82f6;
            margin-top: 10px;
        }
        
        .preview-error {
            display: none;
            color: #991b1b;
            margin-top: 10px;
        }
        
        /* Progress Modal */
        .progress-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }
        
        .progress-content {
            background: white;
            padding: 30px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #10b981;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Calculation Info Box */
        .calculation-info {
            background: #fff3cd;
            border: 2px solid #ffc107;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
        }
        
        .calculation-title {
            font-weight: 600;
            color: #856404;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .calculation-formula {
            background: rgba(255,255,255,0.8);
            padding: 15px;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-weight: 600;
            color: #856404;
            text-align: center;
            margin: 10px 0;
        }
        
        /* Responsive */
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
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .radio-group {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .preview-stats {
                grid-template-columns: 1fr;
            }
        }
        
        /* Animations */
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
    </style>
</head>
<body>
    <!-- Progress Modal -->
    <div class="progress-modal" id="progressModal">
        <div class="progress-content">
            <div class="spinner"></div>
            <h3>Generating Bills...</h3>
            <p>Please wait while we process your request.</p>
        </div>
    </div>

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
                <a href="../notifications/index.php" style="
                    background: rgba(255,255,255,0.2);
                    border: none;
                    color: white;
                    font-size: 18px;
                    padding: 10px;
                    border-radius: 50%;
                    cursor: pointer;
                    transition: all 0.3s;
                    text-decoration: none;
                    display: inline-block;
                " onmouseover="this.style.background='rgba(255,255,255,0.3)'" 
                   onmouseout="this.style.background='rgba(255,255,255,0.2)'">
                    <i class="fas fa-bell"></i>
                    <span class="icon-bell" style="display: none;"></span>
                </a>
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
                        <a href="../users/view.php?id=<?php echo $currentUser['user_id']; ?>" class="dropdown-item">
                            <i class="fas fa-user"></i>
                            <span class="icon-users" style="display: none;"></span>
                            My Profile
                        </a>
                        <a href="../settings/index.php" class="dropdown-item">
                            <i class="fas fa-cog"></i>
                            <span class="icon-cog" style="display: none;"></span>
                            Account Settings
                        </a>
                        <a href="../logs/user_activity.php" class="dropdown-item">
                            <i class="fas fa-history"></i>
                            <span class="icon-history" style="display: none;"></span>
                            Activity Log
                        </a>
                        <a href="#" class="dropdown-item" onclick="alert('Help documentation coming soon!')">
                            <i class="fas fa-question-circle"></i>
                            <span class="icon-question" style="display: none;"></span>
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

    <div class="container-layout">
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
                       <a href="../properties/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-home"></i>
                                <span class="icon-home" style="display: none;"></span>
                            </span>
                            Properties
                        </a>
                    </div>
                    <div class="nav-item">
                         <a href="../zones/index.php" class="nav-link">
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
                        <a href="index.php" class="nav-link active">
                            <span class="nav-icon">
                                <i class="fas fa-file-invoice"></i>
                                <span class="icon-invoice" style="display: none;"></span>
                            </span>
                            Billing
                        </a>
                    </div>
                    <div class="nav-item">
                         <a href="../payments/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-credit-card"></i>
                                <span class="icon-credit" style="display: none;"></span>
                            </span>
                            Payments
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../fee_structure/business_fees.php" class="nav-link">
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
                        <a href="../reports/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-chart-bar"></i>
                                <span class="icon-chart" style="display: none;"></span>
                            </span>
                            Reports
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../notifications/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-bell"></i>
                                <span class="icon-bell" style="display: none;"></span>
                            </span>
                            Notifications
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../settings/index.php" class="nav-link">
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
                    <a href="index.php">Billing</a>
                    <span>/</span>
                    <span style="color: #2d3748; font-weight: 600;">Generate Bills</span>
                </div>
            </div>

            <div class="container">
                <!-- Page Header -->
                <div class="page-header">
                    <h1 class="page-title">
                        <i class="fas fa-plus-circle"></i>
                        Generate Bills (Updated with Proper Arrears)
                    </h1>
                    <p style="color: #64748b;">Create bills for businesses and properties with accurate arrears calculation</p>
                </div>

                <!-- Calculation Information -->
                <div class="calculation-info">
                    <div class="calculation-title">
                        <i class="fas fa-calculator"></i>
                        How Bills are Calculated (CORRECTED)
                    </div>
                    <p style="margin-bottom: 15px; color: #856404;">
                        <strong>Example:</strong> If a business was billed GHS 500 last year (current_bill) but only paid GHS 300:
                    </p>
                    <div class="calculation-formula">
                        Old Bill: GHS 500 (last year's current_bill) | Previous Payments: GHS 300 | Arrears: GHS 200
                    </div>
                    <div class="calculation-formula">
                        Amount Payable = Arrears + Current Bill = GHS 200 + GHS 600 = GHS 800
                    </div>
                    <p style="margin-top: 10px; color: #856404; font-size: 14px;">
                        <strong>Key Point:</strong> The old_bill is always the face value of last year's bill (current_bill), not what remained unpaid.
                    </p>
                </div>

                <!-- Flash Messages -->
                <?php if ($flashMessage): ?>
                    <div class="alert alert-<?php echo $flashMessage['type']; ?>">
                        <i class="fas fa-<?php echo $flashMessage['type'] === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                        <div><?php echo htmlspecialchars($flashMessage['message']); ?></div>
                    </div>
                <?php endif; ?>

                <!-- Errors -->
                <?php if (!empty($errors)): ?>
                    <div class="errors">
                        <ul>
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <!-- Success Results -->
                <?php if ($success && !empty($generationResults)): ?>
                    <div class="results-card">
                        <div class="results-icon">
                            <i class="fas fa-check"></i>
                        </div>
                        <div class="results-title">Bills Generated Successfully with Proper Arrears!</div>
                        <div class="results-stats">
                            <div class="stat-item">
                                <div class="stat-number"><?php echo $generationResults['business_bills']; ?></div>
                                <div class="stat-label">Business Bills</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?php echo $generationResults['property_bills']; ?></div>
                                <div class="stat-label">Property Bills</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?php echo $generationResults['total_generated']; ?></div>
                                <div class="stat-label">Total Generated</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?php echo $generationResults['skipped_records']; ?></div>
                                <div class="stat-label">Skipped (Exist)</div>
                            </div>
                        </div>
                        <div style="margin-top: 20px;">
                            <a href="list.php" class="btn btn-success">
                                <i class="fas fa-list"></i>
                                View Generated Bills
                            </a>
                            <a href="generate.php" class="btn btn-secondary">
                                <i class="fas fa-plus"></i>
                                Generate More Bills
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Generation Form -->
                <div class="generation-form">
                    <!-- Specific Record Info -->
                    <?php if ($specificRecord): ?>
                        <div class="specific-info">
                            <div class="specific-title">
                                <i class="fas fa-info-circle"></i>
                                Generating Bill for Specific <?php echo ucfirst($specificType); ?>
                            </div>
                            <div class="info-grid">
                                <?php if ($specificType === 'business'): ?>
                                    <div class="info-item">
                                        <div class="info-label">Business Name</div>
                                        <div class="info-value"><?php echo htmlspecialchars($specificRecord['business_name']); ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Account Number</div>
                                        <div class="info-value"><?php echo htmlspecialchars($specificRecord['account_number']); ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Business Type</div>
                                        <div class="info-value"><?php echo htmlspecialchars($specificRecord['business_type']); ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Annual Fee</div>
                                        <div class="info-value">GHS <?php echo number_format($specificRecord['fee_amount'], 2); ?></div>
                                    </div>
                                <?php else: ?>
                                    <div class="info-item">
                                        <div class="info-label">Owner Name</div>
                                        <div class="info-value"><?php echo htmlspecialchars($specificRecord['owner_name']); ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Property Number</div>
                                        <div class="info-value"><?php echo htmlspecialchars($specificRecord['property_number']); ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Structure</div>
                                        <div class="info-value"><?php echo htmlspecialchars($specificRecord['structure']); ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Estimated Bill</div>
                                        <div class="info-value">GHS <?php echo number_format($specificRecord['fee_per_room'] * $specificRecord['number_of_rooms'], 2); ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <form method="POST" id="generationForm">
                        <!-- Generation Type -->
                        <div class="form-section">
                            <div class="section-title">
                                <div class="section-icon">
                                    <i class="fas fa-cog"></i>
                                </div>
                                Generation Type
                            </div>

                            <div class="radio-group">
                                <?php if ($specificRecord): ?>
                                    <div class="radio-option">
                                        <input type="radio" id="specific" name="generation_type" value="specific" checked>
                                        <label for="specific" class="radio-card">
                                            <div class="radio-icon">
                                                <i class="fas fa-bullseye"></i>
                                            </div>
                                            <div class="radio-title">Specific <?php echo ucfirst($specificType); ?></div>
                                            <div class="radio-description">Generate bill for the selected <?php echo $specificType; ?> only</div>
                                        </label>
                                    </div>
                                <?php else: ?>
                                    <div class="radio-option">
                                        <input type="radio" id="all" name="generation_type" value="all" checked>
                                        <label for="all" class="radio-card">
                                            <div class="radio-icon">
                                                <i class="fas fa-globe"></i>
                                            </div>
                                            <div class="radio-title">All Records</div>
                                            <div class="radio-description">Generate bills for all businesses and properties</div>
                                        </label>
                                    </div>

                                    <div class="radio-option">
                                        <input type="radio" id="businesses" name="generation_type" value="businesses">
                                        <label for="businesses" class="radio-card">
                                            <div class="radio-icon">
                                                <i class="fas fa-building"></i>
                                            </div>
                                            <div class="radio-title">Businesses Only</div>
                                            <div class="radio-description">Generate bills for businesses only</div>
                                        </label>
                                    </div>

                                    <div class="radio-option">
                                        <input type="radio" id="properties" name="generation_type" value="properties">
                                        <label for="properties" class="radio-card">
                                            <div class="radio-icon">
                                                <i class="fas fa-home"></i>
                                            </div>
                                            <div class="radio-title">Properties Only</div>
                                            <div class="radio-description">Generate bills for properties only</div>
                                        </label>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Basic Settings -->
                        <div class="form-section">
                            <div class="section-title">
                                <div class="section-icon">
                                    <i class="fas fa-calendar"></i>
                                </div>
                                Billing Settings
                            </div>

                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">Billing Year *</label>
                                    <input type="number" name="billing_year" class="form-input" 
                                           value="<?php echo $_POST['billing_year'] ?? date('Y'); ?>" 
                                           min="2020" max="<?php echo date('Y') + 1; ?>" required>
                                </div>

                                <?php if (!$specificRecord): ?>
                                    <div class="form-group">
                                        <label class="form-label">Zone Filter (Optional)</label>
                                        <select name="zone_id" class="form-input">
                                            <option value="">All Zones</option>
                                            <?php foreach ($zones as $zone): ?>
                                                <option value="<?php echo $zone['zone_id']; ?>" 
                                                        <?php echo ($_POST['zone_id'] ?? '') == $zone['zone_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($zone['zone_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Business Type Filter (Optional)</label>
                                        <select name="business_type" class="form-input">
                                            <option value="">All Business Types</option>
                                            <?php foreach ($businessTypes as $type): ?>
                                                <option value="<?php echo htmlspecialchars($type['business_type']); ?>" 
                                                        <?php echo ($_POST['business_type'] ?? '') === $type['business_type'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($type['business_type']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Preview Section -->
                        <div class="preview-section">
                            <i class="fas fa-eye" style="font-size: 24px; margin-bottom: 10px;"></i>
                            <h3>Bill Preview</h3>
                            <p>Bills will be generated with proper arrears calculation from previous year's unpaid balances. 
                            Duplicate bills for the same year will be skipped automatically.</p>
                            <div class="preview-stats" id="previewStats">
                                <div class="stat-item">
                                    <div class="stat-number" id="previewBusinessBills">0</div>
                                    <div class="stat-label">Business Bills</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-number" id="previewPropertyBills">0</div>
                                    <div class="stat-label">Property Bills</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-number" id="previewTotalBills">0</div>
                                    <div class="stat-label">Total Bills</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-number" id="previewTotalAmount">GHS 0.00</div>
                                    <div class="stat-label">Total Amount</div>
                                </div>
                            </div>
                            <div class="preview-loading" id="previewLoading">Loading preview...</div>
                            <div class="preview-error" id="previewError"></div>
                        </div>

                        <!-- Form Actions -->
                        <div class="form-actions">
                            <a href="index.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i>
                                Cancel
                            </a>
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-plus-circle"></i>
                                Generate Bills with Proper Arrears
                            </button>
                        </div>

                        <!-- Hidden fields for specific record -->
                        <?php if ($specificRecord): ?>
                            <input type="hidden" name="specific_type" value="<?php echo htmlspecialchars($specificType); ?>">
                            <input type="hidden" name="specific_id" value="<?php echo $specificId; ?>">
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
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
        });

        // Sidebar toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('hidden');
            localStorage.setItem('sidebarHidden', sidebar.classList.contains('hidden'));
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

        // Mobile sidebar toggle
        function toggleMobileSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('show');
        }

        // Handle mobile responsiveness
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth <= 768) {
                sidebar.classList.remove('hidden');
            }
        });

        // Auto-update form and preview
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('generationForm');
            const radioButtons = document.querySelectorAll('input[name="generation_type"]');
            const zoneFilter = document.querySelector('select[name="zone_id"]');
            const businessTypeFilter = document.querySelector('select[name="business_type"]');
            const billingYearInput = document.querySelector('input[name="billing_year"]');
            const previewStats = document.getElementById('previewStats');
            const previewLoading = document.getElementById('previewLoading');
            const previewError = document.getElementById('previewError');
            const progressModal = document.getElementById('progressModal');

            function updateFilters() {
                const selectedType = document.querySelector('input[name="generation_type"]:checked').value;
                if (zoneFilter && businessTypeFilter) {
                    if (selectedType === 'properties') {
                        businessTypeFilter.disabled = true;
                        businessTypeFilter.value = '';
                    } else {
                        businessTypeFilter.disabled = false;
                    }
                }
                updatePreview();
            }

            function updatePreview() {
                const generationType = document.querySelector('input[name="generation_type"]:checked').value;
                const billingYear = billingYearInput.value;
                const zoneId = zoneFilter ? zoneFilter.value : '';
                const businessType = businessTypeFilter ? businessTypeFilter.value : '';
                const specificType = document.querySelector('input[name="specific_type"]')?.value || '';
                const specificId = document.querySelector('input[name="specific_id"]')?.value || '';

                previewStats.style.display = 'none';
                previewLoading.style.display = 'block';
                previewError.style.display = 'none';

                fetch('preview.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `generation_type=${encodeURIComponent(generationType)}&billing_year=${encodeURIComponent(billingYear)}&zone_id=${encodeURIComponent(zoneId)}&business_type=${encodeURIComponent(businessType)}&specific_type=${encodeURIComponent(specificType)}&specific_id=${encodeURIComponent(specificId)}`
                })
                .then(response => response.json())
                .then(data => {
                    previewLoading.style.display = 'none';
                    if (data.error) {
                        previewError.textContent = data.error;
                        previewError.style.display = 'block';
                        previewStats.style.display = 'none';
                    } else {
                        document.getElementById('previewBusinessBills').textContent = data.business_bills;
                        document.getElementById('previewPropertyBills').textContent = data.property_bills;
                        document.getElementById('previewTotalBills').textContent = data.total_bills;
                        document.getElementById('previewTotalAmount').textContent = `GHS ${data.total_amount.toFixed(2)}`;
                        previewStats.style.display = 'grid';
                        previewError.style.display = 'none';
                    }
                })
                .catch(error => {
                    previewLoading.style.display = 'none';
                    previewError.textContent = 'Error loading preview: ' + error.message;
                    previewError.style.display = 'block';
                    previewStats.style.display = 'none';
                });
            }

            // Initial preview
            updatePreview();

            // Event listeners for form changes
            radioButtons.forEach(radio => radio.addEventListener('change', updateFilters));
            if (zoneFilter) zoneFilter.addEventListener('change', updatePreview);
            if (businessTypeFilter) businessTypeFilter.addEventListener('change', updatePreview);
            billingYearInput.addEventListener('input', updatePreview);

            // Show progress modal on form submit
            form.addEventListener('submit', function() {
                progressModal.style.display = 'flex';
            });
        });
    </script>
</body>
</html>