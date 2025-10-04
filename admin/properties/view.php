<?php
/**
 * Properties Management - View Property Profile with Bill Serving Status - FIXED VERSION
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
if (!hasPermission('properties.view')) {
    setFlashMessage('error', 'Access denied. You do not have permission to view properties.');
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
// Update last activity time
$_SESSION['LAST_ACTIVITY'] = time();

$pageTitle = 'Property Profile';
$currentUser = getCurrentUser();
$userDisplayName = getUserDisplayName($currentUser);

// Handle AJAX request for updating serving status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_serving_status') {
    header('Content-Type: application/json');
    
    try {
        $billId = intval($_POST['bill_id'] ?? 0);
        $servedStatus = $_POST['served_status'] ?? 'Not Served';
        $deliveryNotes = trim($_POST['delivery_notes'] ?? '');
        
        if (!$billId) {
            throw new Exception('Invalid bill ID');
        }
        
        $db = new Database();
        
        // Validate served status
        $validStatuses = ['Not Served', 'Served', 'Attempted', 'Returned'];
        if (!in_array($servedStatus, $validStatuses)) {
            throw new Exception('Invalid served status');
        }
        
        // Update serving status
        $updateQuery = "UPDATE bills SET 
                        served_status = ?, 
                        served_by = ?, 
                        served_at = CASE WHEN ? != 'Not Served' THEN NOW() ELSE NULL END,
                        delivery_notes = ?
                        WHERE bill_id = ?";
        
        $result = $db->execute($updateQuery, [
            $servedStatus,
            $servedStatus !== 'Not Served' ? $currentUser['user_id'] : null,
            $servedStatus,
            $deliveryNotes,
            $billId
        ]);
        
        if ($result) {
            // Log the action
            writeLog("Bill serving status updated - Bill ID: $billId, Status: $servedStatus", 'INFO');
            
            echo json_encode([
                'success' => true,
                'message' => 'Serving status updated successfully',
                'status' => $servedStatus,
                'served_at' => $servedStatus !== 'Not Served' ? date('M d, Y g:i A') : null,
                'served_by' => $servedStatus !== 'Not Served' ? $userDisplayName : null
            ]);
        } else {
            throw new Exception('Failed to update serving status');
        }
        
    } catch (Exception $e) {
        writeLog("Error updating serving status: " . $e->getMessage(), 'ERROR');
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit();
}

// Get property ID from URL
$propertyId = intval($_GET['id'] ?? 0);

if (!$propertyId) {
    setFlashMessage('error', 'Invalid property ID.');
    header('Location: index.php');
    exit();
}

try {
    $db = new Database();
    
    // Get property details with related data
    $propertyQuery = "SELECT p.*, z.zone_name, sz.sub_zone_name, sz.sub_zone_code,
                             u.first_name, u.last_name, u.username,
                             pf.fee_per_room as fee_structure_amount
                      FROM properties p 
                      LEFT JOIN zones z ON p.zone_id = z.zone_id 
                      LEFT JOIN sub_zones sz ON p.sub_zone_id = sz.sub_zone_id
                      LEFT JOIN users u ON p.created_by = u.user_id
                      LEFT JOIN property_fee_structure pf ON p.structure = pf.structure 
                                                          AND p.property_use = pf.property_use 
                                                          AND pf.is_active = 1
                      WHERE p.property_id = ?";
    
    $property = $db->fetchRow($propertyQuery, [$propertyId]);
    
    if (!$property) {
        setFlashMessage('error', 'Property not found.');
        header('Location: index.php');
        exit();
    }
    
    // FIX: Calculate remaining balance correctly - get current year's bill first
    $currentYear = date('Y');
    $currentBill = $db->fetchRow("
        SELECT bill_id, amount_payable, billing_year, status
        FROM bills 
        WHERE bill_type = 'Property' AND reference_id = ? AND billing_year = ?
        ORDER BY generated_at DESC 
        LIMIT 1
    ", [$propertyId, $currentYear]);
    
    if ($currentBill) {
        // FIX: Use the bill's amount_payable directly - this is the correct remaining balance for the current year
        $remainingBalance = floatval($currentBill['amount_payable']);
        $hasCurrentBill = true;
        $currentBillYear = $currentBill['billing_year'];
    } else {
        // No bill for current year, use property's amount payable
        $remainingBalance = floatval($property['amount_payable']);
        $hasCurrentBill = false;
        $currentBillYear = $currentYear;
    }
    
    // Get total paid across all years for reference
    $totalPaymentsQuery = "SELECT COALESCE(SUM(p.amount_paid), 0) as total_paid
                          FROM payments p 
                          INNER JOIN bills b ON p.bill_id = b.bill_id 
                          WHERE b.bill_type = 'Property' AND b.reference_id = ? 
                          AND p.payment_status = 'Successful'";
    $totalPaymentsResult = $db->fetchRow($totalPaymentsQuery, [$propertyId]);
    $totalPaid = $totalPaymentsResult['total_paid'] ?? 0;
    
    // Get billing history with serving information
    $billsQuery = "SELECT b.*, 
                          u.first_name as served_by_first_name, 
                          u.last_name as served_by_last_name,
                          u.username as served_by_username
                   FROM bills b
                   LEFT JOIN users u ON b.served_by = u.user_id
                   WHERE b.bill_type = 'Property' AND b.reference_id = ? 
                   ORDER BY b.billing_year DESC, b.generated_at DESC";
    $bills = $db->fetchAll($billsQuery, [$propertyId]);
    
    // Get payment history
    $paymentsQuery = "SELECT p.*, b.bill_number, b.billing_year 
                      FROM payments p 
                      INNER JOIN bills b ON p.bill_id = b.bill_id 
                      WHERE b.bill_type = 'Property' AND b.reference_id = ? 
                      ORDER BY p.payment_date DESC";
    $payments = $db->fetchAll($paymentsQuery, [$propertyId]);
    
    // Get recent audit logs for this property
    $auditQuery = "SELECT al.*, u.first_name, u.last_name 
                   FROM audit_logs al 
                   LEFT JOIN users u ON al.user_id = u.user_id 
                   WHERE al.table_name = 'properties' AND al.record_id = ? 
                   ORDER BY al.created_at DESC 
                   LIMIT 10";
    $auditLogs = $db->fetchAll($auditQuery, [$propertyId]);
    
    // Calculate statistics
    $stats = [
        'total_bills' => count($bills),
        'total_payments' => count($payments),
        'total_paid' => array_sum(array_column($payments, 'amount_paid')),
        'remaining_balance' => $remainingBalance,
        'current_bill_year' => $currentBillYear,
        'has_current_bill' => $hasCurrentBill,
        'last_payment' => !empty($payments) ? $payments[0]['payment_date'] : null,
        'served_bills' => count(array_filter($bills, fn($b) => $b['served_status'] === 'Served')),
        'pending_delivery' => count(array_filter($bills, fn($b) => $b['served_status'] === 'Not Served'))
    ];
    
} catch (Exception $e) {
    writeLog("Property view error: " . $e->getMessage(), 'ERROR');
    setFlashMessage('error', 'An error occurred while loading property details.');
    header('Location: index.php');
    exit();
}
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
        .icon-edit::before { content: "✏️"; }
        .icon-print::before { content: "🖨️"; }
        .icon-money::before { content: "💰"; }
        .icon-phone::before { content: "📞"; }
        .icon-location::before { content: "📍"; }
        .icon-house::before { content: "🏘️"; }
        .icon-person::before { content: "👤"; }
        .icon-balance::before { content: "⚖️"; }
        
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
        
        /* Property Header */
        .property-header {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
        }
        
        .property-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #38a169 0%, #48bb78 100%);
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
        }
        
        .property-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .property-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, #38a169 0%, #48bb78 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 32px;
            box-shadow: 0 8px 25px rgba(56, 161, 105, 0.3);
        }
        
        .property-details h1 {
            font-size: 28px;
            font-weight: bold;
            color: #2d3748;
            margin: 0 0 8px 0;
        }
        
        .property-details .property-number {
            font-size: 16px;
            color: #38a169;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .property-details .property-meta {
            display: flex;
            gap: 20px;
            margin-bottom: 10px;
        }
        
        .meta-item {
            font-size: 14px;
            color: #64748b;
        }
        
        .meta-item strong {
            color: #2d3748;
        }
        
        .action-buttons {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 12px 20px;
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
            background: linear-gradient(135deg, #38a169 0%, #48bb78 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(56, 161, 105, 0.3);
            color: white;
        }
        
        .btn-success {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(72, 187, 120, 0.3);
            color: white;
        }
        
        .btn-warning {
            background: linear-gradient(135deg, #ed8936 0%, #dd6b20 100%);
            color: white;
        }
        
        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(237, 137, 54, 0.3);
            color: white;
        }
        
        .btn-info {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
        }
        
        .btn-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(66, 153, 225, 0.3);
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
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        .btn-xs {
            padding: 4px 8px;
            font-size: 11px;
        }
        
        /* Serving Status Badges */
        .serving-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .serving-badge.served {
            background: #d1fae5;
            color: #065f46;
        }
        
        .serving-badge.not-served {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .serving-badge.attempted {
            background: #fef3c7;
            color: #92400e;
        }
        
        .serving-badge.returned {
            background: #fecaca;
            color: #991b1b;
        }
        
        /* Serving Actions */
        .serving-actions {
            display: flex;
            gap: 5px;
            align-items: center;
        }
        
        .serving-dropdown {
            position: relative;
            display: inline-block;
        }
        
        .serving-dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            top: 100%;
            background: white;
            min-width: 200px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            z-index: 1000;
            padding: 10px;
        }
        
        .serving-dropdown.active .serving-dropdown-content {
            display: block;
        }
        
        .serving-form {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .serving-form select,
        .serving-form input,
        .serving-form textarea {
            padding: 8px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 12px;
        }
        
        .serving-form textarea {
            resize: vertical;
            min-height: 60px;
        }
        
        .serving-form-buttons {
            display: flex;
            gap: 5px;
            justify-content: flex-end;
        }
        
        /* Loading Spinner */
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #38a169;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        /* Content Cards */
        .content-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .card-title {
            font-size: 20px;
            font-weight: 600;
            color: #2d3748;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .card-icon {
            width: 35px;
            height: 35px;
            background: linear-gradient(135deg, #38a169 0%, #48bb78 100%);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
        }
        
        /* Details List */
        .details-list {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .detail-label {
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .detail-value {
            font-size: 14px;
            font-weight: 500;
            color: #2d3748;
        }
        
        .detail-value.amount {
            font-size: 18px;
            font-weight: bold;
        }
        
        .detail-value.positive {
            color: #dc2626;
        }
        
        .detail-value.zero {
            color: #059669;
        }
        
        .detail-value.balance {
            color: #7c2d12;
            font-size: 20px;
            font-weight: bold;
        }
        
        .detail-value.balance.zero {
            color: #059669;
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: transform 0.3s;
            text-align: center;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-card.primary {
            background: linear-gradient(135deg, #38a169 0%, #48bb78 100%);
            color: white;
        }
        
        .stat-card.success {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
        }
        
        .stat-card.warning {
            background: linear-gradient(135deg, #ed8936 0%, #dd6b20 100%);
            color: white;
        }
        
        .stat-card.info {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
        }
        
        .stat-card.danger {
            background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%);
            color: white;
        }
        
        .stat-icon {
            font-size: 32px;
            margin-bottom: 15px;
            opacity: 0.8;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 8px;
        }
        
        .stat-label {
            font-size: 14px;
            opacity: 0.9;
            font-weight: 500;
        }
        
        /* Map Styles */
        .map-container {
            height: 350px;
            border-radius: 12px;
            overflow: hidden;
            border: 2px solid #e2e8f0;
            margin-top: 15px;
            position: relative;
            background: #f8fafc;
        }
        
        .map-loading {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
            font-size: 16px;
            z-index: 1000;
            flex-direction: column;
            gap: 10px;
        }
        
        .map-error {
            background: #fee2e2;
            color: #991b1b;
            border: 2px dashed #f87171;
        }
        
        .map-controls {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .map-control-btn {
            background: white;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 8px;
            cursor: pointer;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: all 0.3s;
            color: #374151;
            font-size: 14px;
        }
        
        .map-control-btn:hover {
            background: #f3f4f6;
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        /* No Location State */
        .no-location {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            height: 200px;
            color: #64748b;
            font-size: 16px;
            text-align: center;
            border: 2px dashed #e2e8f0;
            border-radius: 12px;
            margin-top: 15px;
        }
        
        .no-location i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        /* Balance Highlight */
        .balance-highlight {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: 2px solid #f59e0b;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
            text-align: center;
        }
        
        .balance-highlight.paid {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            border-color: #10b981;
        }
        
        .balance-highlight h4 {
            margin: 0 0 10px 0;
            font-size: 18px;
            color: #92400e;
        }
        
        .balance-highlight.paid h4 {
            color: #065f46;
        }
        
        .balance-amount {
            font-size: 32px;
            font-weight: bold;
            color: #92400e;
            margin: 10px 0;
        }
        
        .balance-highlight.paid .balance-amount {
            color: #065f46;
        }
        
        /* Tables */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        
        .data-table th {
            background: #f8fafc;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            color: #2d3748;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
        }
        
        .data-table td {
            padding: 15px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            font-size: 14px;
        }
        
        .data-table tr:hover {
            background: #f8fafc;
        }
        
        .table-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }
        
        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }
        
        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .badge-info {
            background: #dbeafe;
            color: #1e40af;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #64748b;
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        .empty-state h3 {
            margin-bottom: 10px;
            color: #2d3748;
        }
        
        /* Alert */
        .alert {
            background: #d1fae5;
            border: 1px solid #9ae6b4;
            border-radius: 10px;
            padding: 15px;
            color: #065f46;
            margin-bottom: 20px;
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
            
            .content-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .header-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 20px;
            }
            
            .property-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .property-details .property-meta {
                flex-direction: column;
                gap: 10px;
            }
            
            .action-buttons {
                width: 100%;
                justify-content: stretch;
            }
            
            .action-buttons .btn {
                flex: 1;
            }
            
            .details-list {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .serving-dropdown-content {
                left: 0;
                right: auto;
                min-width: 250px;
            }
        }
        
        /* Animations */
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .content-card {
            animation: fadeIn 0.6s ease forwards;
        }
        
        .content-card:nth-child(2) {
            animation-delay: 0.1s;
        }
        
        .content-card:nth-child(3) {
            animation-delay: 0.2s;
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
                            <span class="icon-user" style="display: none;"></span>
                            My Profile
                        </a>
                        <a href="../settings/index.php" class="dropdown-item">
                            <i class="fas fa-cog"></i>
                            <span class="icon-cog" style="display: none;"></span>
                            Account Settings
                        </a>
                        <a href="../logs/audit_logs.php" class="dropdown-item">
                            <i class="fas fa-history"></i>
                            <span class="icon-chart" style="display: none;"></span>
                            Activity Log
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
                        <a href="../billing/index.php" class="nav-link">
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
                        <a href="../fee_structure/property_fees.php" class="nav-link">
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
                    <a href="index.php">Property Management</a>
                    <span>/</span>
                    <span class="breadcrumb-current"><?php echo htmlspecialchars($property['owner_name']); ?> Property</span>
                </div>
            </div>

            <!-- Flash Messages -->
            <?php 
            $flashMessages = getFlashMessages();
            if (!empty($flashMessages)): 
            ?>
                <?php foreach ($flashMessages as $message): ?>
                    <div class="alert alert-<?php echo $message['type']; ?>">
                        <?php echo htmlspecialchars($message['message']); ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Property Header -->
            <div class="property-header">
                <div class="header-content">
                    <div class="property-info">
                        <div class="property-avatar">
                            <i class="fas fa-home"></i>
                            <span class="icon-house" style="display: none;"></span>
                        </div>
                        <div class="property-details">
                            <h1><?php echo htmlspecialchars($property['owner_name']); ?> Property</h1>
                            <div class="property-number"><?php echo htmlspecialchars($property['property_number']); ?></div>
                            <div class="property-meta">
                                <div class="meta-item">
                                    <strong>Structure:</strong> <?php echo htmlspecialchars($property['structure']); ?>
                                </div>
                                <?php if ($property['telephone']): ?>
                                    <div class="meta-item">
                                        <strong>Phone:</strong> <?php echo htmlspecialchars($property['telephone']); ?>
                                    </div>
                                <?php endif; ?>
                                <div class="meta-item">
                                    <strong>Zone:</strong> <?php echo htmlspecialchars($property['zone_name'] ?? 'Not assigned'); ?>
                                </div>
                                <div class="meta-item">
                                    <strong>Rooms:</strong> <?php echo htmlspecialchars($property['number_of_rooms']); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="action-buttons">
                        <a href="edit.php?id=<?php echo $property['property_id']; ?>" class="btn btn-primary">
                            <i class="fas fa-edit"></i>
                            <span class="icon-edit" style="display: none;"></span>
                            Edit Property
                        </a>
                        
                        <a href="../billing/generate.php?property_id=<?php echo $property['property_id']; ?>" class="btn btn-success">
                            <i class="fas fa-file-invoice"></i>
                            <span class="icon-invoice" style="display: none;"></span>
                            Generate Bill
                        </a>
                        
                        <a href="../payments/record.php?property_number=<?php echo urlencode($property['property_number']); ?>" class="btn btn-warning">
                            <i class="fas fa-credit-card"></i>
                            <span class="icon-credit" style="display: none;"></span>
                            Record Payment
                        </a>
                        
                        <?php if ($property['latitude'] && $property['longitude']): ?>
                            <button class="btn btn-info" onclick="showOnMap(<?php echo $property['latitude']; ?>, <?php echo $property['longitude']; ?>, '<?php echo htmlspecialchars($property['owner_name']); ?> Property')">
                                <i class="fas fa-map-marker-alt"></i>
                                <span class="icon-location" style="display: none;"></span>
                                View on Map
                            </button>
                        <?php endif; ?>
                        
                        <a href="index.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i>
                            Back to List
                        </a>
                    </div>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="stat-icon">
                        <i class="fas fa-file-invoice"></i>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_bills']); ?></div>
                    <div class="stat-label">Total Bills</div>
                </div>

                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-truck"></i>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['served_bills']); ?></div>
                    <div class="stat-label">Bills Served</div>
                </div>

                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['pending_delivery']); ?></div>
                    <div class="stat-label">Pending Delivery</div>
                </div>

                <div class="stat-card info">
                    <div class="stat-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-value">₵ <?php echo number_format($stats['total_paid'], 2); ?></div>
                    <div class="stat-label">Amount Paid</div>
                </div>

                <div class="stat-card <?php echo $remainingBalance > 0 ? 'danger' : 'success'; ?>">
                    <div class="stat-icon">
                        <i class="fas fa-balance-scale"></i>
                        <span class="icon-balance" style="display: none;"></span>
                    </div>
                    <div class="stat-value">₵ <?php echo number_format($remainingBalance, 2); ?></div>
                    <div class="stat-label"><?php echo $currentBillYear; ?> Bill Balance</div>
                </div>

                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div class="stat-value">
                        <?php echo $stats['last_payment'] ? date('M d, Y', strtotime($stats['last_payment'])) : 'No payments'; ?>
                    </div>
                    <div class="stat-label">Last Payment</div>
                </div>
            </div>

            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Left Column - Property Details -->
                <div>
                    <!-- Property Information -->
                    <div class="content-card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <div class="card-icon">
                                    <i class="fas fa-info-circle"></i>
                                </div>
                                Property Information
                            </h3>
                        </div>
                        
                        <div class="details-list">
                            <div class="detail-item">
                                <div class="detail-label">Owner Name</div>
                                <div class="detail-value"><?php echo htmlspecialchars($property['owner_name']); ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Telephone</div>
                                <div class="detail-value"><?php echo htmlspecialchars($property['telephone'] ?: 'Not provided'); ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Gender</div>
                                <div class="detail-value"><?php echo htmlspecialchars($property['gender'] ?: 'Not specified'); ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Structure</div>
                                <div class="detail-value"><?php echo htmlspecialchars($property['structure']); ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Property Use</div>
                                <div class="detail-value"><?php echo htmlspecialchars($property['property_use']); ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Number of Rooms</div>
                                <div class="detail-value"><?php echo htmlspecialchars($property['number_of_rooms']); ?> room<?php echo $property['number_of_rooms'] != 1 ? 's' : ''; ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Ownership Type</div>
                                <div class="detail-value"><?php echo htmlspecialchars($property['ownership_type']); ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Property Type</div>
                                <div class="detail-value"><?php echo htmlspecialchars($property['property_type']); ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Zone</div>
                                <div class="detail-value"><?php echo htmlspecialchars($property['zone_name'] ?: 'Not assigned'); ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Sub-Zone</div>
                                <div class="detail-value"><?php echo htmlspecialchars($property['sub_zone_name'] ?: 'Not assigned'); ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Batch</div>
                                <div class="detail-value"><?php echo htmlspecialchars($property['batch'] ?: 'Not assigned'); ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Created By</div>
                                <div class="detail-value"><?php echo htmlspecialchars(($property['first_name'] ?? '') . ' ' . ($property['last_name'] ?? '')); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Location Information -->
                    <div class="content-card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <div class="card-icon">
                                    <i class="fas fa-map-marker-alt"></i>
                                </div>
                                Location Information
                            </h3>
                        </div>
                        
                        <div class="details-list">
                            <div class="detail-item" style="grid-column: 1 / -1;">
                                <div class="detail-label">Property Location</div>
                                <div class="detail-value"><?php echo htmlspecialchars($property['location'] ?: 'Not provided'); ?></div>
                            </div>
                            
                            <?php if ($property['latitude'] && $property['longitude']): ?>
                                <div class="detail-item">
                                    <div class="detail-label">Latitude</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($property['latitude']); ?></div>
                                </div>
                                
                                <div class="detail-item">
                                    <div class="detail-label">Longitude</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($property['longitude']); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Map Container -->
                        <?php if ($property['latitude'] && $property['longitude']): ?>
                            <div class="map-container" id="propertyMap">
                                <div class="map-loading" id="mapLoading">
                                    <i class="fas fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 10px;"></i>
                                    <span>Loading interactive map...</span>
                                </div>
                                <div class="map-controls" style="display: none;" id="mapControls">
                                    <button class="map-control-btn" onclick="centerMap()" title="Center on Property">
                                        <i class="fas fa-crosshairs"></i>
                                    </button>
                                    <button class="map-control-btn" onclick="toggleMapType()" title="Toggle Map Type">
                                        <i class="fas fa-layer-group"></i>
                                    </button>
                                    <button class="map-control-btn" onclick="fullscreenMap()" title="Fullscreen">
                                        <i class="fas fa-expand"></i>
                                    </button>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="no-location">
                                <i class="fas fa-map-marker-alt"></i>
                                <h3>No Location Data</h3>
                                <p>GPS coordinates not available for this property</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Billing History with Serving Status -->
                    <div class="content-card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <div class="card-icon">
                                    <i class="fas fa-file-invoice"></i>
                                </div>
                                Billing History & Delivery Status
                            </h3>
                        </div>
                        
                        <?php if (empty($bills)): ?>
                            <div class="empty-state">
                                <i class="fas fa-file-invoice"></i>
                                <h3>No Bills Generated</h3>
                                <p>No bills have been generated for this property yet.</p>
                                <a href="../billing/generate.php?property_id=<?php echo $property['property_id']; ?>" class="btn btn-primary" style="margin-top: 15px;">
                                    <i class="fas fa-plus"></i> Generate First Bill
                                </a>
                            </div>
                        <?php else: ?>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Bill Number</th>
                                        <th>Year</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Delivery Status</th>
                                        <th>Generated</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($bills as $bill): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($bill['bill_number']); ?></td>
                                            <td><?php echo htmlspecialchars($bill['billing_year']); ?></td>
                                            <td>₵ <?php echo number_format($bill['amount_payable'], 2); ?></td>
                                            <td>
                                                <span class="table-badge <?php 
                                                    echo $bill['status'] === 'Paid' ? 'badge-success' : 
                                                        ($bill['status'] === 'Partially Paid' ? 'badge-warning' : 
                                                        ($bill['status'] === 'Overdue' ? 'badge-danger' : 'badge-info')); 
                                                ?>">
                                                    <?php echo htmlspecialchars($bill['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="serving-badge <?php echo strtolower(str_replace(' ', '-', $bill['served_status'])); ?>" 
                                                     id="serving-badge-<?php echo $bill['bill_id']; ?>">
                                                    <?php
                                                    $statusIcons = [
                                                        'Served' => '<i class="fas fa-check"></i>',
                                                        'Not Served' => '<i class="fas fa-times"></i>',
                                                        'Attempted' => '<i class="fas fa-exclamation"></i>',
                                                        'Returned' => '<i class="fas fa-undo"></i>'
                                                    ];
                                                    echo ($statusIcons[$bill['served_status']] ?? '') . ' ' . htmlspecialchars($bill['served_status']);
                                                    ?>
                                                </div>
                                                
                                                <?php if ($bill['served_at'] && $bill['served_status'] !== 'Not Served'): ?>
                                                    <small style="display: block; color: #64748b; margin-top: 2px;">
                                                        <?php echo date('M d, Y g:i A', strtotime($bill['served_at'])); ?>
                                                    </small>
                                                    <?php if ($bill['served_by_first_name']): ?>
                                                        <small style="display: block; color: #64748b;">
                                                            by <?php echo htmlspecialchars($bill['served_by_first_name'] . ' ' . $bill['served_by_last_name']); ?>
                                                        </small>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo date('M d, Y', strtotime($bill['generated_at'])); ?></td>
                                            <td>
                                                <div class="serving-actions">
                                                    <!-- Quick Serve Button -->
                                                    <button class="btn btn-xs btn-success" 
                                                            onclick="quickServe(<?php echo $bill['bill_id']; ?>)"
                                                            <?php echo $bill['served_status'] === 'Served' ? 'disabled' : ''; ?>
                                                            title="Mark as served">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                    
                                                    <!-- Serving Dropdown -->
                                                    <div class="serving-dropdown">
                                                        <button class="btn btn-xs btn-secondary" 
                                                                onclick="toggleServingDropdown(<?php echo $bill['bill_id']; ?>)"
                                                                title="Update delivery status">
                                                            <i class="fas fa-ellipsis-v"></i>
                                                        </button>
                                                        
                                                        <div class="serving-dropdown-content" id="serving-dropdown-<?php echo $bill['bill_id']; ?>">
                                                            <form class="serving-form" onsubmit="updateServingStatus(event, <?php echo $bill['bill_id']; ?>)">
                                                                <label style="font-size: 12px; font-weight: 600;">Delivery Status:</label>
                                                                <select name="served_status" required>
                                                                    <option value="Not Served" <?php echo $bill['served_status'] === 'Not Served' ? 'selected' : ''; ?>>Not Served</option>
                                                                    <option value="Served" <?php echo $bill['served_status'] === 'Served' ? 'selected' : ''; ?>>Served</option>
                                                                    <option value="Attempted" <?php echo $bill['served_status'] === 'Attempted' ? 'selected' : ''; ?>>Attempted</option>
                                                                    <option value="Returned" <?php echo $bill['served_status'] === 'Returned' ? 'selected' : ''; ?>>Returned</option>
                                                                </select>
                                                                
                                                                <label style="font-size: 12px; font-weight: 600;">Notes:</label>
                                                                <textarea name="delivery_notes" placeholder="Optional delivery notes..."><?php echo htmlspecialchars($bill['delivery_notes'] ?? ''); ?></textarea>
                                                                
                                                                <div class="serving-form-buttons">
                                                                    <button type="button" class="btn btn-xs btn-secondary" 
                                                                            onclick="toggleServingDropdown(<?php echo $bill['bill_id']; ?>)">
                                                                        Cancel
                                                                    </button>
                                                                    <button type="submit" class="btn btn-xs btn-primary">
                                                                        Update
                                                                    </button>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>
                                                    
                                                    <!-- View Bill Button -->
                                                    <a href="../billing/view.php?id=<?php echo $bill['bill_id']; ?>" 
                                                       class="btn btn-xs btn-info" title="View bill">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right Column - Billing Summary & Actions -->
                <div>
                    <!-- Billing Summary -->
                    <div class="content-card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <div class="card-icon">
                                    <i class="fas fa-money-bill-wave"></i>
                                </div>
                                Billing Summary
                            </h3>
                        </div>
                        
                        <div class="details-list" style="grid-template-columns: 1fr;">
                            <div class="detail-item">
                                <div class="detail-label">Old Bill</div>
                                <div class="detail-value amount">₵ <?php echo number_format($property['old_bill'], 2); ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Previous Payments</div>
                                <div class="detail-value amount">₵ <?php echo number_format($property['previous_payments'], 2); ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Arrears</div>
                                <div class="detail-value amount <?php echo $property['arrears'] > 0 ? 'positive' : 'zero'; ?>">
                                    ₵ <?php echo number_format($property['arrears'], 2); ?>
                                </div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Current Bill</div>
                                <div class="detail-value amount">₵ <?php echo number_format($property['current_bill'], 2); ?></div>
                            </div>
                            
                            <div style="border-top: 2px solid #e2e8f0; margin: 15px 0; padding-top: 15px;">
                                <div class="detail-item">
                                    <div class="detail-label">Amount Payable</div>
                                    <div class="detail-value amount <?php echo $property['amount_payable'] > 0 ? 'positive' : 'zero'; ?>" style="font-size: 24px;">
                                        ₵ <?php echo number_format($property['amount_payable'], 2); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Remaining Balance Highlight -->
                        <div class="balance-highlight <?php echo $remainingBalance <= 0 ? 'paid' : ''; ?>">
                            <h4>
                                <i class="fas fa-balance-scale"></i>
                                <span class="icon-balance" style="display: none;"></span>
                                <?php echo $remainingBalance <= 0 ? 'Property Fully Paid' : 'Current Bill Balance (' . $currentBillYear . ')'; ?>
                            </h4>
                            <div class="balance-amount">
                                ₵ <?php echo number_format($remainingBalance, 2); ?>
                            </div>
                            <?php if ($remainingBalance > 0): ?>
                                <p style="margin: 10px 0 0 0; font-size: 14px; color: #92400e;">
                                    Outstanding for <?php echo $currentBillYear; ?> bill
                                </p>
                            <?php else: ?>
                                <p style="margin: 10px 0 0 0; font-size: 14px; color: #065f46;">
                                    <?php echo $hasCurrentBill ? $currentBillYear . ' bill fully settled' : 'All bills have been settled'; ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($property['fee_structure_amount']): ?>
                            <div style="background: #f8fafc; padding: 15px; border-radius: 8px; margin-top: 15px;">
                                <div class="detail-label">Fee Structure</div>
                                <div class="detail-value">
                                    ₵ <?php echo number_format($property['fee_structure_amount'], 2); ?> per room<br>
                                    <small style="color: #64748b;">
                                        <?php echo $property['number_of_rooms']; ?> room<?php echo $property['number_of_rooms'] != 1 ? 's' : ''; ?> 
                                        × ₵ <?php echo number_format($property['fee_structure_amount'], 2); ?> 
                                        = ₵ <?php echo number_format($property['fee_structure_amount'] * $property['number_of_rooms'], 2); ?>
                                    </small>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Payment History -->
                    <div class="content-card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <div class="card-icon">
                                    <i class="fas fa-credit-card"></i>
                                </div>
                                Recent Payments
                            </h3>
                        </div>
                        
                        <?php if (empty($payments)): ?>
                            <div class="empty-state">
                                <i class="fas fa-credit-card"></i>
                                <h3>No Payments Recorded</h3>
                                <p>No payments have been recorded for this property.</p>
                                <a href="../payments/record.php?property_number=<?php echo urlencode($property['property_number']); ?>" class="btn btn-warning" style="margin-top: 15px;">
                                    <i class="fas fa-plus"></i> Record Payment
                                </a>
                            </div>
                        <?php else: ?>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($payments, 0, 5) as $payment): ?>
                                        <tr>
                                            <td><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                                            <td>₵ <?php echo number_format($payment['amount_paid'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($payment['payment_method']); ?></td>
                                            <td>
                                                <span class="table-badge <?php 
                                                    echo $payment['payment_status'] === 'Successful' ? 'badge-success' : 
                                                        ($payment['payment_status'] === 'Pending' ? 'badge-warning' : 'badge-danger'); 
                                                ?>">
                                                    <?php echo htmlspecialchars($payment['payment_status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            
                            <?php if (count($payments) > 5): ?>
                                <div style="text-align: center; margin-top: 15px;">
                                    <a href="../payments/index.php?property_number=<?php echo urlencode($property['property_number']); ?>" class="btn btn-secondary">
                                        View All Payments (<?php echo count($payments); ?>)
                                    </a>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <!-- System Information -->
                    <div class="content-card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <div class="card-icon">
                                    <i class="fas fa-info-circle"></i>
                                </div>
                                System Information
                            </h3>
                        </div>
                        
                        <div class="details-list" style="grid-template-columns: 1fr;">
                            <div class="detail-item">
                                <div class="detail-label">Created Date</div>
                                <div class="detail-value"><?php echo date('M d, Y g:i A', strtotime($property['created_at'])); ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Last Updated</div>
                                <div class="detail-value"><?php echo date('M d, Y g:i A', strtotime($property['updated_at'])); ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Created By</div>
                                <div class="detail-value"><?php echo htmlspecialchars(trim(($property['first_name'] ?? '') . ' ' . ($property['last_name'] ?? '')) ?: 'System'); ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Property ID</div>
                                <div class="detail-value">#<?php echo $property['property_id']; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Load Google Maps JavaScript API -->
    <script async defer src="https://maps.googleapis.com/maps/api/js?key=AIzaSyDg1CWNtJ8BHeclYP7VfltZZLIcY3TVHaI&callback=initMap&libraries=geometry"></script>

    <script>
        // Global map variables
        let propertyMap = null;
        let propertyMarker = null;
        let currentMapType = 'roadmap';
        
        // Property coordinates from PHP
        const propertyLat = <?php echo $property['latitude'] ? $property['latitude'] : 'null'; ?>;
        const propertyLng = <?php echo $property['longitude'] ? $property['longitude'] : 'null'; ?>;
        const propertyName = <?php echo json_encode($property['owner_name']); ?>;
        const propertyNumber = <?php echo json_encode($property['property_number']); ?>;
        const remainingBalance = <?php echo $remainingBalance; ?>;
        const currentBillYear = <?php echo $currentBillYear; ?>;
        const hasCurrentBill = <?php echo $hasCurrentBill ? 'true' : 'false'; ?>;
        
        // Google Maps callback function
        window.initMap = function() {
            if (propertyLat && propertyLng) {
                initializePropertyMap();
            }
        };
        
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
            
            // Initialize map if coordinates are available and Google Maps is loaded
            if (propertyLat && propertyLng && window.google && window.google.maps) {
                initializePropertyMap();
            }
            
            // Display balance notification
            displayBalanceNotification();
        });
        
        function initializePropertyMap() {
            try {
                console.log('Initializing Google Map for coordinates:', propertyLat, propertyLng);
                
                // Hide loading indicator after a brief delay
                setTimeout(() => {
                    const loadingElement = document.getElementById('mapLoading');
                    if (loadingElement) {
                        loadingElement.style.display = 'none';
                    }
                    
                    const controlsElement = document.getElementById('mapControls');
                    if (controlsElement) {
                        controlsElement.style.display = 'flex';
                    }
                }, 1500);
                
                // Initialize the Google Map
                const mapOptions = {
                    center: { lat: propertyLat, lng: propertyLng },
                    zoom: 16,
                    mapTypeId: google.maps.MapTypeId.ROADMAP,
                    zoomControl: true,
                    zoomControlOptions: {
                        position: google.maps.ControlPosition.BOTTOM_RIGHT
                    },
                    streetViewControl: true,
                    streetViewControlOptions: {
                        position: google.maps.ControlPosition.BOTTOM_RIGHT
                    },
                    fullscreenControl: false,
                    mapTypeControl: false
                };
                
                propertyMap = new google.maps.Map(document.getElementById('propertyMap'), mapOptions);
                
                // Create custom property marker
                propertyMarker = new google.maps.Marker({
                    position: { lat: propertyLat, lng: propertyLng },
                    map: propertyMap,
                    title: `${propertyName} Property`,
                    icon: {
                        path: google.maps.SymbolPath.CIRCLE,
                        scale: 20,
                        fillColor: '#38a169',
                        fillOpacity: 1,
                        strokeColor: '#ffffff',
                        strokeWeight: 3
                    },
                    animation: google.maps.Animation.BOUNCE
                });
                
                // Stop bouncing after 3 seconds
                setTimeout(() => {
                    propertyMarker.setAnimation(null);
                }, 3000);
                
                // Create info window content
                const infoWindowContent = `
                    <div class="property-popup" style="min-width: 200px; text-align: center;">
                        <h4 style="margin: 0 0 10px 0; color: #2d3748; font-size: 16px;">${propertyName} Property</h4>
                        <p style="margin: 5px 0; color: #64748b; font-size: 14px;"><strong>Property Number:</strong> ${propertyNumber}</p>
                        <p style="margin: 5px 0; color: #64748b; font-size: 14px;"><strong>Structure:</strong> <?php echo htmlspecialchars($property['structure']); ?></p>
                        <p style="margin: 5px 0; color: #64748b; font-size: 14px;"><strong>Rooms:</strong> <?php echo htmlspecialchars($property['number_of_rooms']); ?></p>
                        <p style="margin: 5px 0; font-size: 14px; ${remainingBalance > 0 ? 'color: #dc2626; font-weight: bold;' : 'color: #059669; font-weight: bold;'}">
                            <strong>${currentBillYear} Balance:</strong> ₵ ${remainingBalance.toLocaleString('en-US', {minimumFractionDigits: 2})}
                        </p>
                        <div style="background: #f8fafc; padding: 8px; border-radius: 6px; margin-top: 10px; font-family: monospace; font-size: 12px;">
                            <strong>Coordinates:</strong><br>
                            Lat: ${propertyLat}<br>
                            Lng: ${propertyLng}
                        </div>
                        <div style="margin-top: 10px;">
                            <a href="https://www.google.com/maps?q=${propertyLat},${propertyLng}" 
                               target="_blank" 
                               style="
                                   background: #38a169; 
                                   color: white; 
                                   padding: 6px 12px; 
                                   border-radius: 6px; 
                                   text-decoration: none; 
                                   font-size: 12px;
                                   display: inline-block;
                                   margin-top: 8px;
                               ">
                                Open in Google Maps
                            </a>
                        </div>
                    </div>
                `;
                
                const infoWindow = new google.maps.InfoWindow({
                    content: infoWindowContent
                });
                
                // Open info window by default
                infoWindow.open(propertyMap, propertyMarker);
                
                // Add click listener to marker
                propertyMarker.addListener('click', () => {
                    infoWindow.open(propertyMap, propertyMarker);
                });
                
                // Add circle around property
                new google.maps.Circle({
                    strokeColor: '#38a169',
                    strokeOpacity: 0.8,
                    strokeWeight: 2,
                    fillColor: '#38a169',
                    fillOpacity: 0.1,
                    map: propertyMap,
                    center: { lat: propertyLat, lng: propertyLng },
                    radius: 50
                });
                
                // Add map click listener
                propertyMap.addListener('click', (e) => {
                    const clickedLat = e.latLng.lat();
                    const clickedLng = e.latLng.lng();
                    
                    // Calculate distance using Google Maps geometry library
                    const distance = google.maps.geometry.spherical.computeDistanceBetween(
                        new google.maps.LatLng(propertyLat, propertyLng),
                        new google.maps.LatLng(clickedLat, clickedLng)
                    );
                    
                    const clickInfoWindow = new google.maps.InfoWindow({
                        content: `
                            <div style="text-align: center;">
                                <p><strong>Clicked Location</strong></p>
                                <p>Lat: ${clickedLat.toFixed(6)}<br>
                                   Lng: ${clickedLng.toFixed(6)}</p>
                                <p style="margin-top: 10px; font-size: 12px; color: #666;">
                                    Distance from property: ${Math.round(distance)}m
                                </p>
                            </div>
                        `,
                        position: e.latLng
                    });
                    
                    clickInfoWindow.open(propertyMap);
                });
                
                console.log('Google Map initialized successfully');
                
            } catch (error) {
                console.error('Error initializing map:', error);
                showMapError('Failed to load map. Please check your internet connection.');
            }
        }
        
        function displayBalanceNotification() {
            // Show balance status notification on page load
            if (remainingBalance > 0) {
                setTimeout(() => {
                    showNotification(`${currentBillYear} bill balance: ₵ ${remainingBalance.toLocaleString('en-US', {minimumFractionDigits: 2})}`, 'warning');
                }, 2000);
            } else {
                setTimeout(() => {
                    showNotification(`${hasCurrentBill ? currentBillYear + ' bill fully paid' : 'Property fully paid'} - No outstanding balance`, 'success');
                }, 2000);
            }
        }function centerMap() {
            if (propertyMap && propertyLat && propertyLng) {
                propertyMap.setCenter({ lat: propertyLat, lng: propertyLng });
                propertyMap.setZoom(16);
                if (propertyMarker) {
                    // Bounce the marker briefly
                    propertyMarker.setAnimation(google.maps.Animation.BOUNCE);
                    setTimeout(() => {
                        propertyMarker.setAnimation(null);
                    }, 2000);
                }
            }
        }
        
        function toggleMapType() {
            if (!propertyMap) return;
            
            if (currentMapType === 'roadmap') {
                propertyMap.setMapTypeId(google.maps.MapTypeId.SATELLITE);
                currentMapType = 'satellite';
            } else {
                propertyMap.setMapTypeId(google.maps.MapTypeId.ROADMAP);
                currentMapType = 'roadmap';
            }
        }
        
        function fullscreenMap() {
            if (!propertyLat || !propertyLng) return;
            
            const modal = document.createElement('div');
            modal.style.cssText = `
                position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(0,0,0,0.9); z-index: 10000;
                display: flex; align-items: center; justify-content: center;
                animation: fadeIn 0.3s ease; cursor: pointer;
            `;
            
            const mapContainer = document.createElement('div');
            mapContainer.style.cssText = `
                width: 95%; height: 90%; background: white;
                border-radius: 15px; overflow: hidden; position: relative;
                box-shadow: 0 25px 80px rgba(0,0,0,0.4);
            `;
            
            const closeBtn = document.createElement('button');
            closeBtn.innerHTML = 'Close';
            closeBtn.style.cssText = `
                position: absolute; top: 20px; right: 20px; z-index: 1001;
                background: rgba(0,0,0,0.7); color: white; border: none;
                padding: 12px 20px; border-radius: 8px; cursor: pointer;
                font-weight: 600; backdrop-filter: blur(10px);
            `;
            
            const fullMapDiv = document.createElement('div');
            fullMapDiv.style.cssText = 'width: 100%; height: 100%;';
            fullMapDiv.id = 'fullscreenMap';
            
            mapContainer.appendChild(closeBtn);
            mapContainer.appendChild(fullMapDiv);
            modal.appendChild(mapContainer);
            document.body.appendChild(modal);
            
            // Initialize fullscreen map
            setTimeout(() => {
                const fullscreenMap = new google.maps.Map(fullMapDiv, {
                    center: { lat: propertyLat, lng: propertyLng },
                    zoom: 18,
                    mapTypeId: google.maps.MapTypeId.ROADMAP
                });
                
                const fullscreenMarker = new google.maps.Marker({
                    position: { lat: propertyLat, lng: propertyLng },
                    map: fullscreenMap,
                    title: `${propertyName} Property`,
                    icon: {
                        path: google.maps.SymbolPath.CIRCLE,
                        scale: 25,
                        fillColor: '#38a169',
                        fillOpacity: 1,
                        strokeColor: '#ffffff',
                        strokeWeight: 4
                    }
                });
                
                const fullscreenInfoWindow = new google.maps.InfoWindow({
                    content: `<h4>${propertyName} Property</h4><p>${currentBillYear} Balance: ₵ ${remainingBalance.toLocaleString('en-US', {minimumFractionDigits: 2})}</p>`
                });
                
                fullscreenInfoWindow.open(fullscreenMap, fullscreenMarker);
            }, 100);
            
            closeBtn.onclick = () => modal.remove();
            modal.onclick = (e) => {
                if (e.target === modal) modal.remove();
            };
        }
        
        function showMapError(message) {
            const mapContainer = document.getElementById('propertyMap');
            if (mapContainer) {
                mapContainer.innerHTML = `
                    <div class="map-loading map-error">
                        <i class="fas fa-exclamation-triangle" style="font-size: 32px; margin-bottom: 10px;"></i>
                        <span>⚠️</span>
                        <br>
                        ${message}
                        <br><br>
                        <button onclick="initializePropertyMap()" style="
                            background: #38a169; 
                            color: white; 
                            border: none; 
                            padding: 8px 16px; 
                            border-radius: 6px; 
                            cursor: pointer;
                        ">
                            Try Again
                        </button>
                    </div>
                `;
            }
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
            
            // Close serving dropdowns when clicking outside
            const activeDropdowns = document.querySelectorAll('.serving-dropdown.active');
            activeDropdowns.forEach(dropdown => {
                if (!dropdown.contains(event.target)) {
                    dropdown.classList.remove('active');
                }
            });
        });

        function showOnMap(lat, lng, name) {
            // Create enhanced map modal
            const mapModal = document.createElement('div');
            mapModal.style.cssText = `
                position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(0,0,0,0.6); backdrop-filter: blur(8px); z-index: 10000;
                display: flex; align-items: center; justify-content: center;
                animation: fadeIn 0.3s ease; cursor: pointer;
            `;
            
            const mapContent = document.createElement('div');
            mapContent.style.cssText = `
                background: white; padding: 30px; border-radius: 15px; max-width: 500px; width: 90%;
                box-shadow: 0 25px 80px rgba(0,0,0,0.4); text-align: center;
                animation: modalSlideIn 0.4s ease; cursor: default;
            `;
            
            mapContent.innerHTML = `
                <h3 style="margin: 0 0 20px 0; color: #2d3748; display: flex; align-items: center; gap: 10px; justify-content: center;">
                    <i class="fas fa-map-marker-alt" style="color: #38a169;"></i>
                    Property Location
                </h3>
                <div style="background: #f8fafc; padding: 20px; border-radius: 10px; margin: 20px 0;">
                    <h4 style="margin: 0 0 15px 0; color: #38a169; font-size: 18px;">${name}</h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; text-align: left;">
                        <div>
                            <strong style="color: #2d3748;">Latitude:</strong>
                            <div style="color: #64748b; font-family: monospace; background: #f1f5f9; padding: 5px 8px; border-radius: 4px; margin-top: 3px;">${lat}</div>
                        </div>
                        <div>
                            <strong style="color: #2d3748;">Longitude:</strong>
                            <div style="color: #64748b; font-family: monospace; background: #f1f5f9; padding: 5px 8px; border-radius: 4px; margin-top: 3px;">${lng}</div>
                        </div>
                    </div>
                    <div style="margin-top: 15px; padding: 10px; border-radius: 8px; ${remainingBalance > 0 ? 'background: #fef3c7; color: #92400e;' : 'background: #d1fae5; color: #065f46;'}">
                        <strong>${currentBillYear} Bill Balance:</strong> ₵ ${remainingBalance.toLocaleString('en-US', {minimumFractionDigits: 2})}
                    </div>
                </div>
                <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
                    <a href="https://www.google.com/maps?q=${lat},${lng}" target="_blank" style="
                        background: #38a169; color: white; padding: 12px 20px; border: none; border-radius: 8px;
                        text-decoration: none; font-weight: 600; transition: all 0.3s; display: inline-flex;
                        align-items: center; gap: 8px; font-size: 14px;">
                        <i class="fas fa-external-link-alt"></i> Open in Google Maps
                    </a>
                    <a href="https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}" target="_blank" style="
                        background: #38a169; color: white; padding: 12px 20px; border: none; border-radius: 8px;
                        text-decoration: none; font-weight: 600; transition: all 0.3s; display: inline-flex;
                        align-items: center; gap: 8px; font-size: 14px;">
                        <i class="fas fa-route"></i> Get Directions
                    </a>
                    <button onclick="this.closest('.map-modal').remove()" style="
                        background: #64748b; color: white; padding: 12px 20px; border: none; border-radius: 8px;
                        cursor: pointer; font-weight: 600; transition: all 0.3s; display: inline-flex;
                        align-items: center; gap: 8px; font-size: 14px;">
                        <i class="fas fa-times"></i> Close
                    </button>
                </div>
            `;
            
            mapModal.className = 'map-modal';
            mapModal.appendChild(mapContent);
            document.body.appendChild(mapModal);
            
            // Close modal when clicking backdrop
            mapModal.addEventListener('click', function(e) {
                if (e.target === mapModal) {
                    mapModal.remove();
                }
            });
        }

        // Quick serve function
        function quickServe(billId) {
            const confirmed = confirm('Mark this bill as served?');
            if (!confirmed) return;
            
            const button = event.target.closest('button');
            const originalHtml = button.innerHTML;
            button.innerHTML = '<div class="loading-spinner"></div>';
            button.disabled = true;
            
            updateServingStatusAjax(billId, 'Served', '', button, originalHtml);
        }

        // Toggle serving dropdown
        function toggleServingDropdown(billId) {
            const dropdown = document.getElementById('serving-dropdown-' + billId).closest('.serving-dropdown');
            const isActive = dropdown.classList.contains('active');
            
            // Close all other dropdowns
            document.querySelectorAll('.serving-dropdown.active').forEach(d => {
                if (d !== dropdown) d.classList.remove('active');
            });
            
            // Toggle current dropdown
            dropdown.classList.toggle('active');
        }

        // Update serving status form submission
        function updateServingStatus(event, billId) {
            event.preventDefault();
            
            const form = event.target;
            const formData = new FormData(form);
            const servedStatus = formData.get('served_status');
            const deliveryNotes = formData.get('delivery_notes');
            
            // Show loading state
            const submitButton = form.querySelector('button[type="submit"]');
            const originalHtml = submitButton.innerHTML;
            submitButton.innerHTML = '<div class="loading-spinner"></div>';
            submitButton.disabled = true;
            
            updateServingStatusAjax(billId, servedStatus, deliveryNotes, submitButton, originalHtml);
        }

        // AJAX function to update serving status
        function updateServingStatusAjax(billId, servedStatus, deliveryNotes, buttonElement, originalHtml) {
            const formData = new FormData();
            formData.append('action', 'update_serving_status');
            formData.append('bill_id', billId);
            formData.append('served_status', servedStatus);
            formData.append('delivery_notes', deliveryNotes);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update the badge
                    updateServingBadge(billId, data.status, data.served_at, data.served_by);
                    
                    // Show success message
                    showNotification('Serving status updated successfully!', 'success');
                    
                    // Close dropdown if open
                    const dropdown = document.getElementById('serving-dropdown-' + billId).closest('.serving-dropdown');
                    dropdown.classList.remove('active');
                    
                    // Update statistics
                    updateDeliveryStats();
                    
                } else {
                    showNotification('Error: ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('An error occurred while updating serving status.', 'error');
            })
            .finally(() => {
                // Restore button state
                if (buttonElement) {
                    buttonElement.innerHTML = originalHtml;
                    buttonElement.disabled = servedStatus === 'Served';
                }
            });
        }

        // Update serving badge in the table
        function updateServingBadge(billId, status, servedAt, servedBy) {
            const badge = document.getElementById('serving-badge-' + billId);
            const parentTd = badge.closest('td');
            
            // Update badge class and content
            badge.className = 'serving-badge ' + status.toLowerCase().replace(' ', '-');
            
            const statusIcons = {
                'Served': '<i class="fas fa-check"></i>',
                'Not Served': '<i class="fas fa-times"></i>',
                'Attempted': '<i class="fas fa-exclamation"></i>',
                'Returned': '<i class="fas fa-undo"></i>'
            };
            
            badge.innerHTML = (statusIcons[status] || '') + ' ' + status;
            
            // Remove existing timestamp and user info
            const existingSmall = parentTd.querySelectorAll('small');
            existingSmall.forEach(el => el.remove());
            
            // Add new timestamp and user info if served
            if (status !== 'Not Served' && servedAt) {
                const timeElement = document.createElement('small');
                timeElement.style.cssText = 'display: block; color: #64748b; margin-top: 2px;';
                timeElement.textContent = servedAt;
                parentTd.appendChild(timeElement);
                
                if (servedBy) {
                    const userElement = document.createElement('small');
                    userElement.style.cssText = 'display: block; color: #64748b;';
                    userElement.textContent = 'by ' + servedBy;
                    parentTd.appendChild(userElement);
                }
            }
        }

        // Update delivery statistics
        function updateDeliveryStats() {
            // Recalculate stats from the table
            const badges = document.querySelectorAll('.serving-badge');
            let served = 0, notServed = 0;
            
            badges.forEach(badge => {
                if (badge.classList.contains('served')) {
                    served++;
                } else if (badge.classList.contains('not-served')) {
                    notServed++;
                }
            });
            
            // Update stat cards
            const servedCard = document.querySelector('.stat-card.success .stat-value');
            const pendingCard = document.querySelector('.stat-card.danger .stat-value');
            
            if (servedCard) servedCard.textContent = served.toLocaleString();
            if (pendingCard) pendingCard.textContent = notServed.toLocaleString();
        }

        // Mobile responsiveness
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth <= 768) {
                sidebar.classList.remove('hidden');
            }
            
            // Resize map if it exists (Google Maps)
            if (propertyMap) {
                setTimeout(() => {
                    google.maps.event.trigger(propertyMap, 'resize');
                    if (propertyLat && propertyLng) {
                        propertyMap.setCenter({ lat: propertyLat, lng: propertyLng });
                    }
                }, 300);
            }
            
            // Adjust dropdown positioning for mobile
            if (window.innerWidth <= 768) {
                document.querySelectorAll('.serving-dropdown-content').forEach(dropdown => {
                    dropdown.style.left = '0';
                    dropdown.style.right = 'auto';
                    dropdown.style.minWidth = '250px';
                });
            } else {
                document.querySelectorAll('.serving-dropdown-content').forEach(dropdown => {
                    dropdown.style.left = 'auto';
                    dropdown.style.right = '0';
                    dropdown.style.minWidth = '200px';
                });
            }
        });

        // Notification system
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed; top: 100px; right: 20px; z-index: 10001;
                background: ${type === 'success' ? '#d1fae5' : type === 'error' ? '#fee2e2' : type === 'warning' ? '#fef3c7' : '#dbeafe'};
                color: ${type === 'success' ? '#065f46' : type === 'error' ? '#991b1b' : type === 'warning' ? '#92400e' : '#1e40af'};
                border: 1px solid ${type === 'success' ? '#9ae6b4' : type === 'error' ? '#f87171' : type === 'warning' ? '#fbbf24' : '#93c5fd'};
                border-radius: 10px; padding: 15px 20px; max-width: 300px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.1); font-weight: 500;
                animation: slideInRight 0.3s ease, slideOutRight 0.3s ease 2.7s forwards;
            `;
            
            notification.textContent = message;
            document.body.appendChild(notification);
            
            // Add animations if not already present
            if (!document.getElementById('notificationAnimations')) {
                const style = document.createElement('style');
                style.id = 'notificationAnimations';
                style.textContent = `
                    @keyframes slideInRight { 
                        from { transform: translateX(100%); opacity: 0; } 
                        to { transform: translateX(0); opacity: 1; } 
                    }
                    @keyframes slideOutRight { 
                        from { transform: translateX(0); opacity: 1; } 
                        to { transform: translateX(100%); opacity: 0; } 
                    }
                `;
                document.head.appendChild(style);
            }
            
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 3000);
        }

        // Session timeout check
        let lastActivity = <?php echo $_SESSION['LAST_ACTIVITY']; ?>;
        const SESSION_TIMEOUT = 1800; // 30 minutes in seconds

        function checkSessionTimeout() {
            const currentTime = Math.floor(Date.now() / 1000);
            if (currentTime - lastActivity > SESSION_TIMEOUT) {
                showNotification('Session expired. Redirecting to login...', 'error');
                setTimeout(() => {
                    window.location.href = '../../index.php';
                }, 2000);
            }
        }

        // Check session every minute
        setInterval(checkSessionTimeout, 60000);

        // Update last activity on user interaction
        document.addEventListener('click', () => {
            lastActivity = Math.floor(Date.now() / 1000);
        });

        console.log('✅ Property profile with FIXED balance calculation initialized successfully');
        console.log('Current bill year:', currentBillYear);
        console.log('Current bill balance: ₵' + remainingBalance.toLocaleString('en-US', {minimumFractionDigits: 2}));
        console.log('Has current bill:', hasCurrentBill);
        console.log('Pending deliveries:', <?php echo $stats['pending_delivery']; ?>);
        console.log('Bills served:', <?php echo $stats['served_bills']; ?>);
    </script>
</body>
</html>