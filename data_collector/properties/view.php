<?php
/**
 * Data Collector - View Property Profile with Map & Delivery Status
 * properties/view.php
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

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: ../../auth/login.php');
    exit();
}

// Check if user is data collector
$currentUser = getCurrentUser();
if (!isDataCollector() && !isAdmin()) {
    setFlashMessage('error', 'Access denied. Data Collector privileges required.');
    header('Location: ../../auth/login.php');
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

$userDisplayName = getUserDisplayName($currentUser);

// Get property ID
$property_id = intval($_GET['id'] ?? 0);

if ($property_id <= 0) {
    setFlashMessage('error', 'Invalid property ID.');
    header('Location: index.php');
    exit();
}

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
            writeLog("Property bill serving status updated - Bill ID: $billId, Status: $servedStatus", 'INFO');
            
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
        writeLog("Error updating property serving status: " . $e->getMessage(), 'ERROR');
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit();
}

try {
    $db = new Database();
    
    // Get property details with zone information
    $property = $db->fetchRow("
        SELECT 
            p.*,
            z.zone_name,
            u.first_name as creator_first_name,
            u.last_name as creator_last_name,
            CASE 
                WHEN p.amount_payable > 0 THEN 'Defaulter' 
                ELSE 'Up to Date' 
            END as payment_status
        FROM properties p
        LEFT JOIN zones z ON p.zone_id = z.zone_id
        LEFT JOIN users u ON p.created_by = u.user_id
        WHERE p.property_id = ?
    ", [$property_id]);
    
    if (!$property) {
        setFlashMessage('error', 'Property not found.');
        header('Location: index.php');
        exit();
    }
    
    // Get property bills with serving information
    $bills = $db->fetchAll("
        SELECT 
            b.bill_id,
            b.bill_number,
            b.billing_year,
            b.current_bill,
            b.amount_payable,
            b.status,
            b.served_status,
            b.served_at,
            b.delivery_notes,
            b.generated_at,
            b.due_date,
            u.first_name as served_by_first_name,
            u.last_name as served_by_last_name,
            u.username as served_by_username
        FROM bills b
        LEFT JOIN users u ON b.served_by = u.user_id
        WHERE b.bill_type = 'Property' AND b.reference_id = ?
        ORDER BY b.billing_year DESC, b.generated_at DESC
    ", [$property_id]);
    
    // Get payment history
    $payments = $db->fetchAll("
        SELECT 
            p.payment_id,
            p.payment_reference,
            p.amount_paid,
            p.payment_method,
            p.payment_status,
            p.payment_date,
            b.bill_number,
            b.billing_year
        FROM payments p
        JOIN bills b ON p.bill_id = b.bill_id
        WHERE b.bill_type = 'Property' AND b.reference_id = ?
        ORDER BY p.payment_date DESC
        LIMIT 10
    ", [$property_id]);
    
    // Get fee structure info for this property
    $fee_info = $db->fetchRow("
        SELECT fee_per_room 
        FROM property_fee_structure 
        WHERE structure = ? AND property_use = ? AND is_active = 1
    ", [$property['structure'], $property['property_use']]);
    
} catch (Exception $e) {
    setFlashMessage('error', 'Database error: ' . $e->getMessage());
    header('Location: index.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Property - <?php echo htmlspecialchars($property['owner_name']); ?> - <?php echo APP_NAME; ?></title>
    
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
        .icon-building::before { content: "🏢"; }
        .icon-home::before { content: "🏠"; }
        .icon-map::before { content: "🗺️"; }
        .icon-menu::before { content: "☰"; }
        .icon-logout::before { content: "🚪"; }
        .icon-plus::before { content: "➕"; }
        .icon-users::before { content: "👥"; }
        .icon-cog::before { content: "⚙️"; }
        .icon-history::before { content: "📜"; }
        .icon-question::before { content: "❓"; }
        .icon-user::before { content: "👤"; }
        .icon-location::before { content: "📍"; }
        .icon-edit::before { content: "✏️"; }
        .icon-eye::before { content: "👁️"; }
        .icon-arrow-left::before { content: "⬅️"; }
        .icon-info-circle::before { content: "ℹ️"; }
        .icon-map-marker-alt::before { content: "📍"; }
        .icon-file-invoice::before { content: "📄"; }
        .icon-money-bill-wave::before { content: "💸"; }
        .icon-credit-card::before { content: "💳"; }
        .icon-user-plus::before { content: "👤➕"; }
        .icon-calculator::before { content: "🧮"; }
        .icon-truck::before { content: "🚚"; }
        .icon-directions::before { content: "🧭"; }
        
        /* Top Navigation */
        .top-nav {
            background: linear-gradient(135deg, #38a169 0%, #2f855a 100%);
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
            background: linear-gradient(135deg, #38a169 0%, #2f855a 100%);
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
            color: #38a169;
            transform: translateX(5px);
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
            border-left-color: #38a169;
        }
        
        .nav-link.active {
            background: rgba(56, 161, 105, 0.3);
            color: white;
            border-left-color: #38a169;
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
        
        /* Profile Header */
        .profile-header {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .profile-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .title-left {
            flex: 1;
        }
        
        .property-name {
            font-size: 28px;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 8px;
        }
        
        .property-subtitle {
            color: #718096;
            font-size: 16px;
        }
        
        .profile-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: #38a169;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2f855a;
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: #718096;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #4a5568;
        }
        
        .btn-warning {
            background: #ed8936;
            color: white;
        }
        
        .btn-warning:hover {
            background: #dd6b20;
        }
        
        .btn-info {
            background: #4299e1;
            color: white;
        }
        
        .btn-info:hover {
            background: #3182ce;
        }
        
        .btn-success {
            background: #48bb78;
            color: white;
        }
        
        .btn-success:hover {
            background: #38a169;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        .btn-xs {
            padding: 4px 8px;
            font-size: 11px;
        }
        
        /* Status Badges */
        .status-badges {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap;
        }
        
        .badge {
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .badge-success {
            background: #c6f6d5;
            color: #22543d;
        }
        
        .badge-warning {
            background: #faf0e6;
            color: #c05621;
        }
        
        .badge-danger {
            background: #fed7d7;
            color: #742a2a;
        }
        
        .badge-info {
            background: #bee3f8;
            color: #2a4365;
        }
        
        .badge-teal {
            background: #b2f5ea;
            color: #234e52;
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
        
        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 25px;
            margin-bottom: 25px;
        }
        
        /* Cards */
        .card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .card-header {
            background: #f7fafc;
            padding: 20px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .card-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            margin: 0;
        }
        
        .card-body {
            padding: 25px;
        }
        
        /* Info Rows */
        .info-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 15px;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
        }
        
        .info-label {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 5px;
            font-size: 14px;
        }
        
        .info-value {
            color: #4a5568;
            font-size: 14px;
        }
        
        .info-value.large {
            font-size: 18px;
            font-weight: bold;
            color: #2d3748;
        }
        
        .info-value.currency {
            color: #38a169;
            font-weight: bold;
        }
        
        /* Map Container */
        .map-container {
            height: 300px;
            border-radius: 8px;
            overflow: hidden;
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
        
        /* Table Styles */
        .table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }
        
        .table th,
        .table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .table th {
            background: #f7fafc;
            font-weight: 600;
            color: #2d3748;
            font-size: 14px;
        }
        
        .table tr:hover {
            background: #f7fafc;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #718096;
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        .empty-state h4 {
            font-size: 18px;
            margin-bottom: 8px;
            color: #2d3748;
        }
        
        .empty-state p {
            font-size: 14px;
        }
        
        /* Bill Calculator Display */
        .bill-breakdown {
            background: #e6fffa;
            border: 2px solid #38b2ac;
            border-radius: 10px;
            padding: 15px;
            margin-top: 15px;
        }
        
        .breakdown-title {
            font-weight: bold;
            color: #234e52;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .breakdown-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
            padding: 6px 0;
            border-bottom: 1px solid rgba(56, 178, 172, 0.2);
        }
        
        .breakdown-row:last-child {
            border-bottom: none;
            font-weight: bold;
            background: rgba(56, 178, 172, 0.1);
            margin: 8px -10px -10px -10px;
            padding: 12px 10px;
            border-radius: 0 0 6px 6px;
        }
        
        /* Sync Message */
        .sync-message {
            position: fixed;
            top: 100px;
            right: 20px;
            max-width: 350px;
            padding: 15px 20px;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            z-index: 10000;
            font-weight: 600;
            transform: translateX(400px);
            transition: transform 0.3s ease-out;
        }
        
        .sync-message.show {
            transform: translateX(0);
        }
        
        .sync-message.success {
            background: #48bb78;
            color: white;
        }
        
        .sync-message.error {
            background: #f56565;
            color: white;
        }
        
        .sync-message.info {
            background: #4299e1;
            color: white;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                height: 100%;
                z-index: 999;
                transform: translateX(-100%);
                width: 280px;
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
                padding: 20px;
            }
            
            .content-grid {
                grid-template-columns: 1fr;
            }
            
            .info-row {
                grid-template-columns: 1fr;
            }
            
            .profile-title {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .profile-actions {
                flex-direction: column;
                width: 100%;
            }
            
            .serving-dropdown-content {
                left: 0;
                right: auto;
                min-width: 250px;
            }
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fadeIn 0.6s ease-out;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
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
                <i class="fas fa-clipboard-list"></i>
                <span class="icon-dashboard" style="display: none;"></span>
                Data Collector
            </a>
        </div>
        
        <div class="user-section">
            <div class="user-profile" onclick="toggleUserDropdown()" id="userProfile">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($currentUser['first_name'] ?? 'U', 0, 1)); ?>
                </div>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($userDisplayName); ?></div>
                    <div class="user-role">Data Collector</div>
                </div>
                <i class="fas fa-chevron-down dropdown-arrow"></i>
                
                <!-- User Dropdown -->
                <div class="user-dropdown" id="userDropdown">
                    <div class="dropdown-header">
                        <div class="dropdown-avatar">
                            <?php echo strtoupper(substr($currentUser['first_name'] ?? 'U', 0, 1)); ?>
                        </div>
                        <div class="dropdown-name"><?php echo htmlspecialchars($userDisplayName); ?></div>
                        <div class="dropdown-role">Data Collector</div>
                    </div>
                    <div class="dropdown-menu">
                        <a href="#" class="dropdown-item" onclick="alert('Profile management coming soon!')">
                            <i class="fas fa-user"></i>
                            <span class="icon-user" style="display: none;"></span>
                            My Profile
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

    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar hidden" id="sidebar">
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
                
                <!-- Data Collection -->
                <div class="nav-section">
                    <div class="nav-title">Data Collection</div>
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
                </div>
                
                <!-- Maps & Locations -->
                <div class="nav-section">
                    <div class="nav-title">Maps & Locations</div>
                    <div class="nav-item">
                        <a href="../map/businesses.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-map-marked-alt"></i>
                                <span class="icon-map" style="display: none;"></span>
                            </span>
                            Business Locations
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../map/properties.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-map-marker-alt"></i>
                                <span class="icon-location" style="display: none;"></span>
                            </span>
                            Property Locations
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Profile Header -->
            <div class="profile-header fade-in">
                <div class="profile-title">
                    <div class="title-left">
                        <h1 class="property-name"><?php echo htmlspecialchars($property['owner_name']); ?>'s Property</h1>
                        <p class="property-subtitle">
                            Property: <strong><?php echo htmlspecialchars($property['property_number']); ?></strong> 
                            | Structure: <strong><?php echo htmlspecialchars($property['structure']); ?></strong>
                            | Rooms: <strong><?php echo $property['number_of_rooms']; ?></strong>
                        </p>
                        
                        <div class="status-badges">
                            <span class="badge <?php echo $property['payment_status'] == 'Up to Date' ? 'badge-success' : 'badge-danger'; ?>">
                                <i class="fas fa-money-bill"></i>
                                <span class="icon-money-bill-wave" style="display: none;"></span>
                                <?php echo $property['payment_status']; ?>
                            </span>
                            <span class="badge <?php echo $property['property_use'] == 'Residential' ? 'badge-info' : 'badge-teal'; ?>">
                                <i class="fas fa-home"></i>
                                <span class="icon-home" style="display: none;"></span>
                                <?php echo $property['property_use']; ?>
                            </span>
                            <span class="badge badge-warning">
                                <i class="fas fa-building"></i>
                                <span class="icon-building" style="display: none;"></span>
                                <?php echo htmlspecialchars($property['structure']); ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="profile-actions">
                        <?php if ($property['latitude'] && $property['longitude']): ?>
                        <a href="#" class="btn btn-success" onclick="getDirections(); return false;">
                            <i class="fas fa-directions"></i>
                            <span class="icon-directions" style="display: none;"></span>
                            Get Directions
                        </a>
                        <?php endif; ?>
                        <a href="edit.php?id=<?php echo $property['property_id']; ?>" class="btn btn-primary">
                            <i class="fas fa-edit"></i>
                            <span class="icon-edit" style="display: none;"></span>
                            Edit Property
                        </a>
                        <a href="index.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i>
                            <span class="icon-arrow-left" style="display: none;"></span>
                            Back to List
                        </a>
                    </div>
                </div>
            </div>

            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Basic Information -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-info-circle"></i>
                                <span class="icon-info-circle" style="display: none;"></span>
                                Property Information
                            </h3>
                        </div>
                        <div class="card-body">
                            <div class="info-row">
                                <div class="info-item">
                                    <div class="info-label">Property Number</div>
                                    <div class="info-value large"><?php echo htmlspecialchars($property['property_number']); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Owner Name</div>
                                    <div class="info-value large"><?php echo htmlspecialchars($property['owner_name']); ?></div>
                                </div>
                            </div>
                            
                            <div class="info-row">
                                <div class="info-item">
                                    <div class="info-label">Telephone</div>
                                    <div class="info-value">
                                        <?php echo $property['telephone'] ? htmlspecialchars($property['telephone']) : 'Not provided'; ?>
                                    </div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Gender</div>
                                    <div class="info-value">
                                        <?php echo $property['gender'] ? htmlspecialchars($property['gender']) : 'Not specified'; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="info-row">
                                <div class="info-item">
                                    <div class="info-label">Structure</div>
                                    <div class="info-value"><?php echo htmlspecialchars($property['structure']); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Property Use</div>
                                    <div class="info-value">
                                        <span class="badge <?php echo $property['property_use'] == 'Residential' ? 'badge-info' : 'badge-teal'; ?>">
                                            <?php echo $property['property_use']; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="info-row">
                                <div class="info-item">
                                    <div class="info-label">Ownership Type</div>
                                    <div class="info-value"><?php echo htmlspecialchars($property['ownership_type']); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Property Type</div>
                                    <div class="info-value"><?php echo htmlspecialchars($property['property_type']); ?></div>
                                </div>
                            </div>
                            
                            <div class="info-row">
                                <div class="info-item">
                                    <div class="info-label">Number of Rooms</div>
                                    <div class="info-value large"><?php echo $property['number_of_rooms']; ?> rooms</div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Batch</div>
                                    <div class="info-value">
                                        <?php echo $property['batch'] ? htmlspecialchars($property['batch']) : 'Not assigned'; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Location Information -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-map-marker-alt"></i>
                                <span class="icon-map-marker-alt" style="display: none;"></span>
                                Location Information
                            </h3>
                        </div>
                        <div class="card-body">
                            <div class="info-row">
                                <div class="info-item">
                                    <div class="info-label">Zone</div>
                                    <div class="info-value"><?php echo htmlspecialchars($property['zone_name'] ?? 'Not assigned'); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Coordinates</div>
                                    <div class="info-value"><?php echo $property['latitude']; ?>, <?php echo $property['longitude']; ?></div>
                                </div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label">Location</div>
                                <div class="info-value"><?php echo nl2br(htmlspecialchars($property['location'])); ?></div>
                            </div>
                            
                            <?php if ($property['latitude'] && $property['longitude']): ?>
                                <div class="map-container" id="propertyMap">
                                    <div class="map-loading" id="mapLoading">
                                        <i class="fas fa-spinner" style="animation: spin 1s linear infinite; font-size: 24px; margin-bottom: 10px;"></i>
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
                                        <button class="map-control-btn" onclick="getDirections()" title="Get Directions">
                                            <i class="fas fa-directions"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Billing History & Delivery Status -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-file-invoice"></i>
                                <span class="icon-file-invoice" style="display: none;"></span>
                                Billing History & Delivery Status
                            </h3>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($bills)): ?>
                                <div style="overflow-x: auto;">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Year</th>
                                                <th>Bill Number</th>
                                                <th>Amount</th>
                                                <th>Status</th>
                                                <th>Delivery Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($bills as $bill): ?>
                                                <tr>
                                                    <td><?php echo $bill['billing_year']; ?></td>
                                                    <td><?php echo htmlspecialchars($bill['bill_number']); ?></td>
                                                    <td>₵ <?php echo number_format($bill['amount_payable'], 2); ?></td>
                                                    <td>
                                                        <span class="badge <?php 
                                                            echo $bill['status'] == 'Paid' ? 'badge-success' : 
                                                                ($bill['status'] == 'Partially Paid' ? 'badge-warning' : 'badge-danger'); 
                                                        ?>">
                                                            <?php echo $bill['status']; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div class="serving-badge <?php echo strtolower(str_replace(' ', '-', $bill['served_status'] ?? 'Not Served')); ?>" 
                                                             id="serving-badge-<?php echo $bill['bill_id']; ?>">
                                                            <?php 
                                                            $servedStatus = $bill['served_status'] ?? 'Not Served';
                                                            $statusIcons = [
                                                                'Served' => '<i class="fas fa-check"></i>',
                                                                'Not Served' => '<i class="fas fa-times"></i>',
                                                                'Attempted' => '<i class="fas fa-exclamation"></i>',
                                                                'Returned' => '<i class="fas fa-undo"></i>'
                                                            ];
                                                            echo ($statusIcons[$servedStatus] ?? '') . ' ' . $servedStatus;
                                                            ?>
                                                        </div>
                                                        <?php if ($bill['served_at'] && $servedStatus !== 'Not Served'): ?>
                                                            <small style="display: block; color: #64748b; margin-top: 2px; font-size: 11px;">
                                                                <?php echo date('M d, Y g:i A', strtotime($bill['served_at'])); ?>
                                                            </small>
                                                            <?php if ($bill['served_by_first_name']): ?>
                                                                <small style="display: block; color: #64748b; font-size: 11px;">
                                                                    by <?php echo htmlspecialchars($bill['served_by_first_name'] . ' ' . $bill['served_by_last_name']); ?>
                                                                </small>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="serving-actions">
                                                            <button class="btn btn-xs btn-success" 
                                                                    onclick="quickServe(<?php echo $bill['bill_id']; ?>)"
                                                                    <?php echo $servedStatus === 'Served' ? 'disabled' : ''; ?>
                                                                    title="Mark as served">
                                                                <i class="fas fa-check"></i>
                                                            </button>
                                                            
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
                                                                            <option value="Not Served" <?php echo $servedStatus === 'Not Served' ? 'selected' : ''; ?>>Not Served</option>
                                                                            <option value="Served" <?php echo $servedStatus === 'Served' ? 'selected' : ''; ?>>Served</option>
                                                                            <option value="Attempted" <?php echo $servedStatus === 'Attempted' ? 'selected' : ''; ?>>Attempted</option>
                                                                            <option value="Returned" <?php echo $servedStatus === 'Returned' ? 'selected' : ''; ?>>Returned</option>
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
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-file-invoice"></i>
                                    <span class="icon-file-invoice" style="display: none;"></span>
                                    <h4>No Bills Found</h4>
                                    <p>No bills have been generated for this property yet.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Right Column -->
                <div class="right-column">
                    <!-- Financial Summary -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-money-bill-wave"></i>
                                <span class="icon-money-bill-wave" style="display: none;"></span>
                                Financial Summary
                            </h3>
                        </div>
                        <div class="card-body">
                            <div class="info-item" style="margin-bottom: 15px;">
                                <div class="info-label">Old Bill</div>
                                <div class="info-value currency">₵ <?php echo number_format($property['old_bill'], 2); ?></div>
                            </div>
                            
                            <div class="info-item" style="margin-bottom: 15px;">
                                <div class="info-label">Previous Payments</div>
                                <div class="info-value currency">₵ <?php echo number_format($property['previous_payments'], 2); ?></div>
                            </div>
                            
                            <div class="info-item" style="margin-bottom: 15px;">
                                <div class="info-label">Arrears</div>
                                <div class="info-value currency">₵ <?php echo number_format($property['arrears'], 2); ?></div>
                            </div>
                            
                            <div class="info-item" style="margin-bottom: 15px;">
                                <div class="info-label">Current Bill</div>
                                <div class="info-value currency">₵ <?php echo number_format($property['current_bill'], 2); ?></div>
                            </div>
                            
                            <hr style="margin: 20px 0; border: 1px solid #e2e8f0;">
                            
                            <div class="info-item">
                                <div class="info-label">Total Amount Payable</div>
                                <div class="info-value large currency" style="font-size: 24px;">
                                    ₵ <?php echo number_format($property['amount_payable'], 2); ?>
                                </div>
                            </div>
                            
                            <!-- Bill Calculation Breakdown -->
                            <?php if ($fee_info): ?>
                                <div class="bill-breakdown">
                                    <div class="breakdown-title">
                                        <i class="fas fa-calculator"></i>
                                        <span class="icon-calculator" style="display: none;"></span>
                                        Bill Calculation
                                    </div>
                                    <div class="breakdown-row">
                                        <span>Fee per room:</span>
                                        <span>₵ <?php echo number_format($fee_info['fee_per_room'], 2); ?></span>
                                    </div>
                                    <div class="breakdown-row">
                                        <span>Number of rooms:</span>
                                        <span><?php echo $property['number_of_rooms']; ?></span>
                                    </div>
                                    <div class="breakdown-row">
                                        <span>Current Bill:</span>
                                        <span>₵ <?php echo number_format($property['current_bill'], 2); ?></span>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Recent Payments -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-credit-card"></i>
                                <span class="icon-credit-card" style="display: none;"></span>
                                Recent Payments
                            </h3>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($payments)): ?>
                                <?php foreach ($payments as $payment): ?>
                                    <div style="border-bottom: 1px solid #e2e8f0; padding: 15px 0;">
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                                            <strong>₵ <?php echo number_format($payment['amount_paid'], 2); ?></strong>
                                            <span class="badge <?php echo $payment['payment_status'] == 'Successful' ? 'badge-success' : 'badge-danger'; ?>">
                                                <?php echo $payment['payment_status']; ?>
                                            </span>
                                        </div>
                                        <div style="font-size: 12px; color: #718096;">
                                            <?php echo htmlspecialchars($payment['payment_method']); ?> - 
                                            <?php echo date('M j, Y', strtotime($payment['payment_date'])); ?>
                                        </div>
                                        <div style="font-size: 12px; color: #718096;">
                                            Ref: <?php echo htmlspecialchars($payment['payment_reference']); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-credit-card"></i>
                                    <span class="icon-credit-card" style="display: none;"></span>
                                    <h4>No Payments</h4>
                                    <p>No payments recorded yet.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Registration Info -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-user-plus"></i>
                                <span class="icon-user-plus" style="display: none;"></span>
                                Registration Info
                            </h3>
                        </div>
                        <div class="card-body">
                            <div class="info-item" style="margin-bottom: 15px;">
                                <div class="info-label">Registered By</div>
                                <div class="info-value">
                                    <?php 
                                    if ($property['creator_first_name']) {
                                        echo htmlspecialchars($property['creator_first_name'] . ' ' . $property['creator_last_name']);
                                    } else {
                                        echo 'System';
                                    }
                                    ?>
                                </div>
                            </div>
                            
                            <div class="info-item" style="margin-bottom: 15px;">
                                <div class="info-label">Registration Date</div>
                                <div class="info-value"><?php echo date('M j, Y g:i A', strtotime($property['created_at'])); ?></div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label">Last Updated</div>
                                <div class="info-value"><?php echo date('M j, Y g:i A', strtotime($property['updated_at'])); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Load Google Maps JavaScript API -->
    <script async defer src="https://maps.googleapis.com/maps/api/js?key=AIzaSyDg1CWNtJ8BHeclYP7VfltZZLIcY3TVHaI&callback=initMapCallback&libraries=geometry"></script>
    
    <script>
        // Global variables
        let propertyMap = null;
        let propertyMarker = null;
        let currentMapType = 'roadmap';
        
        const propertyData = {
            lat: <?php echo $property['latitude'] ?? 0; ?>,
            lng: <?php echo $property['longitude'] ?? 0; ?>,
            owner: '<?php echo addslashes($property['owner_name']); ?>',
            propertyNumber: '<?php echo addslashes($property['property_number']); ?>',
            structure: '<?php echo addslashes($property['structure']); ?>',
            rooms: <?php echo $property['number_of_rooms']; ?>,
            propertyUse: '<?php echo addslashes($property['property_use']); ?>'
        };
        
        // Google Maps callback
        window.initMapCallback = function() {
            console.log('Google Maps API loaded successfully');
            if (propertyData.lat && propertyData.lng) {
                setTimeout(() => initPropertyMap(), 500);
            }
        };
        
        // Initialize Google Map
        function initPropertyMap() {
            try {
                console.log('Initializing Google Map for property coordinates:', propertyData.lat, propertyData.lng);
                
                const mapElement = document.getElementById('propertyMap');
                if (!mapElement) {
                    console.warn('Map element not found');
                    return;
                }
                
                // Hide loading indicator
                const loadingElement = document.getElementById('mapLoading');
                if (loadingElement) {
                    loadingElement.style.display = 'none';
                }
                
                // Show controls
                const controlsElement = document.getElementById('mapControls');
                if (controlsElement) {
                    controlsElement.style.display = 'flex';
                }
                
                // Initialize the Google Map
                const mapOptions = {
                    center: { lat: propertyData.lat, lng: propertyData.lng },
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
                
                propertyMap = new google.maps.Map(mapElement, mapOptions);
                
                // Create custom property marker
                propertyMarker = new google.maps.Marker({
                    position: { lat: propertyData.lat, lng: propertyData.lng },
                    map: propertyMap,
                    title: propertyData.owner + "'s Property",
                    icon: {
                        path: google.maps.SymbolPath.CIRCLE,
                        scale: 20,
                        fillColor: '#3182ce',
                        fillOpacity: 1,
                        strokeColor: '#ffffff',
                        strokeWeight: 3
                    },
                    animation: google.maps.Animation.BOUNCE
                });
                
                // Stop bouncing after 3 seconds
                setTimeout(() => {
                    if (propertyMarker) {
                        propertyMarker.setAnimation(null);
                    }
                }, 3000);
                
                // Create info window content
                const infoWindowContent = `
                    <div style="min-width: 200px; text-align: center; padding: 10px;">
                        <h4 style="margin: 0 0 10px 0; color: #2d3748; font-size: 16px;">🏠 ${propertyData.owner}'s Property</h4>
                        <p style="margin: 5px 0; color: #64748b; font-size: 14px;"><strong>Property:</strong> ${propertyData.propertyNumber}</p>
                        <p style="margin: 5px 0; color: #64748b; font-size: 14px;"><strong>Structure:</strong> ${propertyData.structure}</p>
                        <p style="margin: 5px 0; color: #64748b; font-size: 14px;"><strong>Rooms:</strong> ${propertyData.rooms}</p>
                        <p style="margin: 5px 0; color: #64748b; font-size: 14px;"><strong>Use:</strong> ${propertyData.propertyUse}</p>
                        <div style="background: #f8fafc; padding: 8px; border-radius: 6px; margin-top: 10px; font-family: monospace; font-size: 12px;">
                            <strong>Coordinates:</strong><br>
                            Lat: ${propertyData.lat}<br>
                            Lng: ${propertyData.lng}
                        </div>
                        <div style="margin-top: 10px;">
                            <a href="#" onclick="getDirections(); return false;" 
                               style="
                                   background: #3182ce; 
                                   color: white; 
                                   padding: 6px 12px; 
                                   border-radius: 6px; 
                                   text-decoration: none; 
                                   font-size: 12px;
                                   display: inline-block;
                                   margin-top: 8px;
                               ">
                                🧭 Get Directions
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
                    strokeColor: '#3182ce',
                    strokeOpacity: 0.8,
                    strokeWeight: 2,
                    fillColor: '#3182ce',
                    fillOpacity: 0.1,
                    map: propertyMap,
                    center: { lat: propertyData.lat, lng: propertyData.lng },
                    radius: 50
                });
                
                console.log('✅ Google Map initialized successfully');
                
            } catch (error) {
                console.error('❌ Error initializing map:', error);
                showMapError('Failed to load map. Please check your internet connection.');
            }
        }
        
        // Center map function
        function centerMap() {
            if (propertyMap && propertyData.lat && propertyData.lng) {
                propertyMap.setCenter({ lat: propertyData.lat, lng: propertyData.lng });
                propertyMap.setZoom(16);
                if (propertyMarker) {
                    propertyMarker.setAnimation(google.maps.Animation.BOUNCE);
                    setTimeout(() => {
                        if (propertyMarker) {
                            propertyMarker.setAnimation(null);
                        }
                    }, 2000);
                }
            }
        }
        
        // Toggle map type
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
        
        // Fullscreen map
        function fullscreenMap() {
            if (!propertyData.lat || !propertyData.lng) return;
            
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
            closeBtn.innerHTML = '✕ Close';
            closeBtn.style.cssText = `
                position: absolute; top: 20px; right: 20px; z-index: 1001;
                background: rgba(0,0,0,0.7); color: white; border: none;
                padding: 12px 20px; border-radius: 8px; cursor: pointer;
                font-weight: 600; backdrop-filter: blur(10px);
            `;
            
            const directionsBtn = document.createElement('button');
            directionsBtn.innerHTML = '🧭 Directions';
            directionsBtn.onclick = getDirections;
            directionsBtn.style.cssText = `
                position: absolute; top: 20px; left: 20px; z-index: 1001;
                background: #3182ce; color: white; border: none;
                padding: 12px 20px; border-radius: 8px; cursor: pointer;
                font-weight: 600;
            `;
            
            const fullMapDiv = document.createElement('div');
            fullMapDiv.style.cssText = 'width: 100%; height: 100%;';
            fullMapDiv.id = 'fullscreenMap';
            
            mapContainer.appendChild(closeBtn);
            mapContainer.appendChild(directionsBtn);
            mapContainer.appendChild(fullMapDiv);
            modal.appendChild(mapContainer);
            document.body.appendChild(modal);
            
            // Initialize fullscreen map
            setTimeout(() => {
                const fullscreenMap = new google.maps.Map(fullMapDiv, {
                    center: { lat: propertyData.lat, lng: propertyData.lng },
                    zoom: 18,
                    mapTypeId: google.maps.MapTypeId.ROADMAP
                });
                
                const fullscreenMarker = new google.maps.Marker({
                    position: { lat: propertyData.lat, lng: propertyData.lng },
                    map: fullscreenMap,
                    title: propertyData.owner + "'s Property",
                    icon: {
                        path: google.maps.SymbolPath.CIRCLE,
                        scale: 25,
                        fillColor: '#3182ce',
                        fillOpacity: 1,
                        strokeColor: '#ffffff',
                        strokeWeight: 4
                    }
                });
                
                const fullscreenInfoWindow = new google.maps.InfoWindow({
                    content: `<h4>${propertyData.owner}'s Property</h4><p>Property: ${propertyData.propertyNumber}</p>`
                });
                
                fullscreenInfoWindow.open(fullscreenMap, fullscreenMarker);
            }, 100);
            
            closeBtn.onclick = () => modal.remove();
            modal.onclick = (e) => {
                if (e.target === modal) modal.remove();
            };
        }
        
        // Get Directions function
        function getDirections() {
            if (!propertyData.lat || !propertyData.lng) {
                alert('Location coordinates not available for this property.');
                return;
            }
            
            // Check if geolocation is available
            if ('geolocation' in navigator) {
                // Show loading message
                showSyncMessage('info', 'Getting your location...');
                
                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        const userLat = position.coords.latitude;
                        const userLng = position.coords.longitude;
                        
                        // Open Google Maps with directions
                        const directionsUrl = `https://www.google.com/maps/dir/${userLat},${userLng}/${propertyData.lat},${propertyData.lng}`;
                        window.open(directionsUrl, '_blank');
                    },
                    (error) => {
                        console.log('Geolocation error:', error);
                        // If user denies location or error occurs, open Google Maps without origin
                        const directionsUrl = `https://www.google.com/maps/dir//${propertyData.lat},${propertyData.lng}`;
                        window.open(directionsUrl, '_blank');
                    },
                    {
                        enableHighAccuracy: true,
                        timeout: 5000,
                        maximumAge: 0
                    }
                );
            } else {
                // Geolocation not available, open Google Maps without origin
                const directionsUrl = `https://www.google.com/maps/dir//${propertyData.lat},${propertyData.lng}`;
                window.open(directionsUrl, '_blank');
            }
        }
        
        // Show map error
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
                        <button onclick="location.reload()" style="
                            background: #3182ce; 
                            color: white; 
                            border: none; 
                            padding: 8px 16px; 
                            border-radius: 6px; 
                            cursor: pointer;
                        ">
                            🔄 Try Again
                        </button>
                    </div>
                `;
            }
        }
        
        // Quick serve function
        function quickServe(billId) {
            const confirmed = confirm('Mark this bill as served?');
            if (!confirmed) return;
            
            const button = event.target.closest('button');
            const originalHtml = button.innerHTML;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
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
            submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
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
                    showSyncMessage('success', 'Serving status updated successfully!');
                    
                    // Close dropdown if open
                    const dropdown = document.getElementById('serving-dropdown-' + billId);
                    if (dropdown) {
                        dropdown.closest('.serving-dropdown').classList.remove('active');
                    }
                    
                } else {
                    showSyncMessage('error', 'Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showSyncMessage('error', 'An error occurred while updating serving status.');
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
            if (!badge) return;
            
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
                timeElement.style.cssText = 'display: block; color: #64748b; margin-top: 2px; font-size: 11px;';
                timeElement.textContent = servedAt;
                parentTd.appendChild(timeElement);
                
                if (servedBy) {
                    const userElement = document.createElement('small');
                    userElement.style.cssText = 'display: block; color: #64748b; font-size: 11px;';
                    userElement.textContent = 'by ' + servedBy;
                    parentTd.appendChild(userElement);
                }
            }
        }
        
        // Show sync messages
        function showSyncMessage(type, message) {
            const messageDiv = document.createElement('div');
            messageDiv.className = `sync-message ${type}`;
            messageDiv.innerHTML = `
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                    <div>${message}</div>
                </div>
            `;
            
            document.body.appendChild(messageDiv);
            
            setTimeout(() => {
                messageDiv.classList.add('show');
            }, 100);
            
            setTimeout(() => {
                messageDiv.classList.remove('show');
                setTimeout(() => {
                    if (messageDiv.parentNode) {
                        messageDiv.parentNode.removeChild(messageDiv);
                    }
                }, 300);
            }, 5000);
        }
        
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
            const isMobile = window.innerWidth <= 768;
            
            if (isMobile) {
                sidebar.classList.toggle('show');
                sidebar.classList.toggle('hidden');
            } else {
                sidebar.classList.toggle('hidden');
            }
            
            const isHidden = sidebar.classList.contains('hidden');
            localStorage.setItem('sidebarHidden', isHidden);
        }

        // Restore sidebar state
        document.addEventListener('DOMContentLoaded', function() {
            const sidebarHidden = localStorage.getItem('sidebarHidden');
            const sidebar = document.getElementById('sidebar');
            const isMobile = window.innerWidth <= 768;
            
            if (isMobile) {
                sidebar.classList.add('hidden');
                sidebar.classList.remove('show');
            } else if (sidebarHidden === 'true') {
                sidebar.classList.add('hidden');
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

        // Close sidebar when clicking outside in mobile view
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const toggleBtn = document.getElementById('toggleBtn');
            const isMobile = window.innerWidth <= 768;
            
            if (isMobile && !sidebar.contains(event.target) && !toggleBtn.contains(event.target)) {
                sidebar.classList.remove('show');
                sidebar.classList.add('hidden');
                localStorage.setItem('sidebarHidden', true);
            }
        });
        
        // Mobile responsiveness for map
        window.addEventListener('resize', function() {
            if (propertyMap) {
                setTimeout(() => {
                    google.maps.event.trigger(propertyMap, 'resize');
                    if (propertyData.lat && propertyData.lng) {
                        propertyMap.setCenter({ 
                            lat: propertyData.lat, 
                            lng: propertyData.lng 
                        });
                    }
                }, 300);
            }
        });
        
        console.log('Property view with map and delivery status initialized');
    </script>
</body>
</html>