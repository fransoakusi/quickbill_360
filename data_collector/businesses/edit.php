<?php
/**
 * Data Collector - Edit Business Form
 * businesses/edit.php
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
    // Session expired (30 minutes)
    session_unset();
    session_destroy();
    setFlashMessage('error', 'Your session has expired. Please log in again.');
    header('Location: ../../index.php');
    exit();
}

$userDisplayName = getUserDisplayName($currentUser);
$errors = [];
$success = false;

// Get business ID
$business_id = intval($_GET['id'] ?? 0);

if ($business_id <= 0) {
    setFlashMessage('error', 'Invalid business ID.');
    header('Location: index.php');
    exit();
}

try {
    $db = new Database();
    
    // Get current business data
    $business = $db->fetchRow("
        SELECT * FROM businesses WHERE business_id = ?
    ", [$business_id]);
    
    if (!$business) {
        setFlashMessage('error', 'Business not found.');
        header('Location: index.php');
        exit();
    }
    
} catch (Exception $e) {
    setFlashMessage('error', 'Database error: ' . $e->getMessage());
    header('Location: index.php');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Validate required fields
        $business_name = trim($_POST['business_name'] ?? '');
        $owner_name = trim($_POST['owner_name'] ?? '');
        $business_type = trim($_POST['business_type'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $telephone = trim($_POST['telephone'] ?? '');
        $exact_location = trim($_POST['exact_location'] ?? '');
        $latitude = floatval($_POST['latitude'] ?? 0);
        $longitude = floatval($_POST['longitude'] ?? 0);
        $zone_id = intval($_POST['zone_id'] ?? 0);
        $sub_zone_id = intval($_POST['sub_zone_id'] ?? 0);
        $old_bill = floatval($_POST['old_bill'] ?? 0);
        $previous_payments = floatval($_POST['previous_payments'] ?? 0);
        $arrears = floatval($_POST['arrears'] ?? 0);
        $current_bill = floatval($_POST['current_bill'] ?? 0);
        $batch = trim($_POST['batch'] ?? '');
        $status = $_POST['status'] ?? 'Active';
        
        // Validation
        if (empty($business_name)) {
            $errors[] = "Business name is required.";
        }
        
        if (empty($owner_name)) {
            $errors[] = "Owner name is required.";
        }
        
        if (empty($business_type)) {
            $errors[] = "Business type is required.";
        }
        
        if (empty($category)) {
            $errors[] = "Business category is required.";
        }
        
        if (empty($exact_location)) {
            $errors[] = "Exact location is required.";
        }
        
        // Validate latitude and longitude if provided
        if (!empty($_POST['latitude']) && (!is_numeric($_POST['latitude']) || floatval($_POST['latitude']) < -90 || floatval($_POST['latitude']) > 90)) {
            $errors[] = 'Latitude must be a valid number between -90 and 90.';
        }
        
        if (!empty($_POST['longitude']) && (!is_numeric($_POST['longitude']) || floatval($_POST['longitude']) < -180 || floatval($_POST['longitude']) > 180)) {
            $errors[] = 'Longitude must be a valid number between -180 and 180.';
        }
        
        if ($zone_id == 0) {
            $errors[] = "Zone is required.";
        }
        
        if ($current_bill <= 0) {
            $errors[] = "Current bill must be greater than 0.";
        }
        
        // Check if business type and category combination exists in fee structure
        $fee_check = $db->fetchRow(
            "SELECT fee_amount FROM business_fee_structure 
             WHERE business_type = ? AND category = ? AND is_active = 1",
            [$business_type, $category]
        );
        
        if (!$fee_check) {
            $errors[] = "Invalid business type and category combination.";
        }
        
        // Store old values for audit log
        $old_values = [
            'business_name' => $business['business_name'],
            'owner_name' => $business['owner_name'],
            'business_type' => $business['business_type'],
            'category' => $business['category']
        ];
        
        // If no errors, update business
        if (empty($errors)) {
            // Calculate amount payable
            $amount_payable = $old_bill + $arrears + $current_bill - $previous_payments;
            
            // Update business using execute method
            $update_result = $db->execute(
                "UPDATE businesses SET 
                    business_name = ?, owner_name = ?, business_type = ?, category = ?, 
                    telephone = ?, exact_location = ?, latitude = ?, longitude = ?, 
                    old_bill = ?, previous_payments = ?, arrears = ?, current_bill = ?, 
                    amount_payable = ?, batch = ?, zone_id = ?, sub_zone_id = ?, 
                    status = ?, updated_at = NOW()
                WHERE business_id = ?",
                [
                    $business_name, $owner_name, $business_type, $category, $telephone,
                    $exact_location, $latitude, $longitude, $old_bill, $previous_payments,
                    $arrears, $current_bill, $amount_payable, $batch, $zone_id, 
                    $sub_zone_id > 0 ? $sub_zone_id : null, $status, $business_id
                ]
            );
            
            if ($update_result) {
                // Log the action using execute method
                $new_values = [
                    'business_name' => $business_name,
                    'owner_name' => $owner_name,
                    'business_type' => $business_type,
                    'category' => $category
                ];
                
                $audit_result = $db->execute(
                    "INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at)
                     VALUES (?, 'UPDATE_BUSINESS', 'businesses', ?, ?, ?, ?, ?, NOW())",
                    [
                        $currentUser['user_id'], $business_id, 
                        json_encode($old_values), json_encode($new_values),
                        $_SERVER['REMOTE_ADDR'] ?? 'Unknown', 
                        $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
                    ]
                );
                
                setFlashMessage('success', 'Business updated successfully!');
                header('Location: view.php?id=' . $business_id);
                exit();
            } else {
                $errors[] = "Failed to update business. Please try again.";
            }
        }
        
    } catch (Exception $e) {
        $errors[] = "Database error: " . $e->getMessage();
        error_log("Business update error: " . $e->getMessage());
    }
} else {
    // Pre-populate form with existing data
    $_POST = [
        'business_name' => $business['business_name'],
        'owner_name' => $business['owner_name'],
        'business_type' => $business['business_type'],
        'category' => $business['category'],
        'telephone' => $business['telephone'],
        'exact_location' => $business['exact_location'],
        'latitude' => $business['latitude'],
        'longitude' => $business['longitude'],
        'zone_id' => $business['zone_id'],
        'sub_zone_id' => $business['sub_zone_id'],
        'old_bill' => $business['old_bill'],
        'previous_payments' => $business['previous_payments'],
        'arrears' => $business['arrears'],
        'current_bill' => $business['current_bill'],
        'batch' => $business['batch'],
        'status' => $business['status']
    ];
}

// Get reference data
try {
    // Get fee structure for business types
    $fee_structure = $db->fetchAll(
        "SELECT business_type, category, fee_amount 
         FROM business_fee_structure 
         WHERE is_active = 1 
         ORDER BY business_type, category"
    );
    
    // Get zones
    $zones = $db->fetchAll("SELECT zone_id, zone_name FROM zones ORDER BY zone_name");
    
    // Get sub-zones
    $sub_zones = $db->fetchAll("SELECT sub_zone_id, zone_id, sub_zone_name FROM sub_zones ORDER BY sub_zone_name");
    
} catch (Exception $e) {
    $fee_structure = [];
    $zones = [];
    $sub_zones = [];
    error_log("Error fetching reference data: " . $e->getMessage());
}

// Organize fee structure by business type
$business_types = [];
if ($fee_structure) {
    foreach ($fee_structure as $fee) {
        if (!isset($business_types[$fee['business_type']])) {
            $business_types[$fee['business_type']] = [];
        }
        $business_types[$fee['business_type']][] = [
            'category' => $fee['category'],
            'fee_amount' => $fee['fee_amount']
        ];
    }
}

// Organize sub-zones by zone
$zones_with_subzones = [];
if ($sub_zones) {
    foreach ($sub_zones as $sub_zone) {
        if (!isset($zones_with_subzones[$sub_zone['zone_id']])) {
            $zones_with_subzones[$sub_zone['zone_id']] = [];
        }
        $zones_with_subzones[$sub_zone['zone_id']][] = $sub_zone;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Business - <?php echo htmlspecialchars($business['business_name']); ?> - <?php echo APP_NAME; ?></title>
    
    <!-- Multiple Icon Sources -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    
    <!-- Bootstrap for backup -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Google Maps API -->
    <script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyDg1CWNtJ8BHeclYP7VfltZZLIcY3TVHaI&libraries=places"></script>
    
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
        .icon-save::before { content: "💾"; }
        .icon-times::before { content: "❌"; }
        .icon-exclamation::before { content: "❗"; }
        .icon-info::before { content: "ℹ️"; }
        .icon-map-marker-alt::before { content: "📍"; }
        .icon-satellite-dish::before { content: "📡"; }
        
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
        
        /* Form Styles */
        .form-container {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .form-header {
            margin-bottom: 30px;
            text-align: center;
        }
        
        .form-title {
            font-size: 28px;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 8px;
        }
        
        .form-subtitle {
            color: #718096;
            font-size: 16px;
        }
        
        .form-section {
            margin-bottom: 30px;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-label {
            font-weight: 600;
            margin-bottom: 8px;
            color: #2d3748;
        }
        
        .form-label.required::after {
            content: " *";
            color: #e53e3e;
        }
        
        .form-control {
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #38a169;
            box-shadow: 0 0 0 3px rgba(56, 161, 105, 0.1);
        }
        
        .form-control.error {
            border-color: #e53e3e;
        }
        
        .form-help {
            font-size: 12px;
            color: #718096;
            margin-top: 5px;
        }
        
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
        
        .btn-location {
            background: #4299e1;
            color: white;
        }
        
        .btn-location:hover {
            background: #3182ce;
        }
        
        .btn-location:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        .btn-warning {
            background: #ed8936;
            color: white;
        }
        
        .btn-warning:hover {
            background: #dd6b20;
        }
        
        /* Alert Styles */
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-danger {
            background: #fed7d7;
            color: #742a2a;
            border: 1px solid #feb2b2;
        }
        
        .alert-success {
            background: #c6f6d5;
            color: #22543d;
            border: 1px solid #9ae6b4;
        }
        
        .alert-info {
            background: #bee3f8;
            color: #2a4365;
            border: 1px solid #90cdf4;
        }
        
        .alert-warning {
            background: #faf0e6;
            color: #c05621;
            border: 1px solid #f6ad55;
        }
        
        /* Enhanced Location Section */
        .location-section {
            background: #f7fafc;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .location-input-group {
            display: flex;
            gap: 10px;
            align-items: flex-end;
            margin-bottom: 15px;
        }
        
        .location-input-group .form-group {
            flex: 1;
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
        
        /* Form Actions */
        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
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
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .coordinate-inputs {
                grid-template-columns: 1fr;
            }
            
            .location-input-group {
                flex-direction: column;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .container {
                flex-direction: column;
            }
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(100%); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        @keyframes slideOut {
            from { opacity: 1; transform: translateX(0); }
            to { opacity: 0; transform: translateX(100%); }
        }
        
        .fade-in {
            animation: fadeIn 0.6s ease-out;
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
                        <a href="index.php" class="nav-link active">
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
            <div class="form-container fade-in">
                <div class="form-header">
                    <h1 class="form-title">Edit Business</h1>
                    <p class="form-subtitle">Update business information - <?php echo htmlspecialchars($business['business_name']); ?></p>
                </div>
                
                <!-- Alert for editing existing business -->
                <div class="alert alert-warning">
                    <i class="fas fa-edit"></i>
                    <span class="icon-edit" style="display: none;"></span>
                    <h4>Editing Existing Business</h4>
                    <p><strong>Account Number:</strong> <?php echo htmlspecialchars($business['account_number']); ?></p>
                    <p>You are editing an existing business record. Changes will be logged for audit purposes.</p>
                </div>
                
                <!-- Error Messages -->
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <h4>
                            <i class="fas fa-exclamation-triangle"></i>
                            <span class="icon-exclamation" style="display: none;"></span>
                            Please fix the following errors:
                        </h4>
                        <ul style="margin: 10px 0 0 20px;">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <!-- Edit Form -->
                <form method="POST" action="" id="businessForm">
                    <!-- Basic Information -->
                    <div class="form-section">
                        <h3 class="section-title">Basic Information</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label required">Business Name</label>
                                <input type="text" name="business_name" class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['business_name'] ?? ''); ?>" 
                                       required>
                                <div class="form-help">Enter the full business name as it appears on documents</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">Owner Name</label>
                                <input type="text" name="owner_name" class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['owner_name'] ?? ''); ?>" 
                                       required>
                                <div class="form-help">Enter the full name of the business owner</div>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Telephone</label>
                                <input type="tel" name="telephone" class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['telephone'] ?? ''); ?>" 
                                       placeholder="0XX XXX XXXX">
                                <div class="form-help">Contact number for the business owner</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-control">
                                    <option value="Active" <?php echo ($_POST['status'] ?? '') == 'Active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="Inactive" <?php echo ($_POST['status'] ?? '') == 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="Suspended" <?php echo ($_POST['status'] ?? '') == 'Suspended' ? 'selected' : ''; ?>>Suspended</option>
                                </select>
                                <div class="form-help">Current status of the business</div>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Batch</label>
                                <input type="text" name="batch" class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['batch'] ?? ''); ?>" 
                                       placeholder="e.g., BATCH001">
                                <div class="form-help">Optional batch identifier for bulk operations</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Account Number</label>
                                <input type="text" class="form-control" 
                                       value="<?php echo htmlspecialchars($business['account_number']); ?>" 
                                       readonly style="background: #f7fafc;">
                                <div class="form-help">Account number cannot be changed</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Business Type & Category -->
                    <div class="form-section">
                        <h3 class="section-title">Business Classification</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label required">Business Type</label>
                                <select name="business_type" class="form-control" id="businessType" required>
                                    <option value="">Select Business Type</option>
                                    <?php foreach ($business_types as $type => $categories): ?>
                                        <option value="<?php echo htmlspecialchars($type); ?>"
                                                <?php echo (($_POST['business_type'] ?? '') == $type) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($type); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-help">Select the primary business type</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">Category</label>
                                <select name="category" class="form-control" id="businessCategory" required>
                                    <option value="">Select Category</option>
                                </select>
                                <div class="form-help">Category will be populated based on business type</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Location Information -->
                    <div class="form-section">
                        <h3 class="section-title">Location Information</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label required">Zone</label>
                                <select name="zone_id" class="form-control" id="zoneSelect" required>
                                    <option value="">Select Zone</option>
                                    <?php foreach ($zones as $zone): ?>
                                        <option value="<?php echo $zone['zone_id']; ?>"
                                                <?php echo (($_POST['zone_id'] ?? '') == $zone['zone_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($zone['zone_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Sub-Zone</label>
                                <select name="sub_zone_id" class="form-control" id="subZoneSelect">
                                    <option value="">Select Sub-Zone</option>
                                </select>
                                <div class="form-help">Optional sub-zone for more specific location</div>
                            </div>
                        </div>
                        
                        <div class="location-section">
                            <div class="alert alert-info" style="margin-bottom: 15px;">
                                <i class="fas fa-info-circle"></i>
                                <span class="icon-info" style="display: none;"></span>
                                <strong>GPS Tips:</strong> For best accuracy when updating location, ensure GPS is enabled, allow location access when prompted, and capture location while outdoors or near windows.
                            </div>
                            
                            <div class="location-input-group">
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label class="form-label required">Exact Location</label>
                                    <textarea name="exact_location" class="form-control" rows="3" 
                                              placeholder="Enter detailed location description or use GPS capture button..." required><?php echo htmlspecialchars($_POST['exact_location'] ?? ''); ?></textarea>
                                    <div class="form-help">Provide detailed directions, landmarks, or use GPS to capture precise location</div>
                                </div>
                                <button type="button" class="btn btn-location" onclick="getCurrentLocation()" id="locationBtn">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <span class="icon-map-marker-alt" style="display: none;"></span>
                                    Update GPS Coordinates
                                </button>
                            </div>
                            
                            <div class="gps-coordinates">
                                <div class="gps-title">
                                    <i class="fas fa-satellite-dish"></i>
                                    <span class="icon-satellite-dish" style="display: none;"></span>
                                    GPS Coordinates
                                </div>
                                <div class="coordinate-inputs">
                                    <div class="coordinate-group">
                                        <div class="coordinate-label">Latitude</div>
                                        <input type="number" name="latitude" id="latitude" class="coordinate-input" 
                                               step="0.000001" min="-90" max="90"
                                               value="<?php echo htmlspecialchars($_POST['latitude'] ?? ''); ?>" 
                                               placeholder="e.g., 5.614818">
                                    </div>
                                    <div class="coordinate-group">
                                        <div class="coordinate-label">Longitude</div>
                                        <input type="number" name="longitude" id="longitude" class="coordinate-input" 
                                               step="0.000001" min="-180" max="180"
                                               value="<?php echo htmlspecialchars($_POST['longitude'] ?? ''); ?>" 
                                               placeholder="e.g., -0.205874">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Billing Information -->
                    <div class="form-section">
                        <h3 class="section-title">Billing Information</h3>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <span class="icon-info" style="display: none;"></span>
                            <strong>Current Bill</strong> will be automatically updated based on the selected business type and category.
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Old Bill (₵)</label>
                                <input type="number" name="old_bill" class="form-control" 
                                       value="<?php echo $_POST['old_bill'] ?? '0'; ?>" 
                                       step="0.01" min="0">
                                <div class="form-help">Previous outstanding bill amount</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Previous Payments (₵)</label>
                                <input type="number" name="previous_payments" class="form-control" 
                                       value="<?php echo $_POST['previous_payments'] ?? '0'; ?>" 
                                       step="0.01" min="0">
                                <div class="form-help">Amount previously paid</div>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Arrears (₵)</label>
                                <input type="number" name="arrears" class="form-control" 
                                       value="<?php echo $_POST['arrears'] ?? '0'; ?>" 
                                       step="0.01" min="0">
                                <div class="form-help">Outstanding arrears amount</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">Current Bill (₵)</label>
                                <input type="number" name="current_bill" class="form-control" 
                                       id="currentBill" value="<?php echo $_POST['current_bill'] ?? '0'; ?>" 
                                       step="0.01" min="0" required readonly>
                                <div class="form-help">Automatically calculated based on business type and category</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Form Actions -->
                    <div class="form-actions">
                        <a href="view.php?id=<?php echo $business_id; ?>" class="btn btn-secondary">
                            <i class="fas fa-times"></i>
                            <span class="icon-times" style="display: none;"></span>
                            Cancel
                        </a>
                        <a href="view.php?id=<?php echo $business_id; ?>" class="btn btn-warning">
                            <i class="fas fa-eye"></i>
                            <span class="icon-eye" style="display: none;"></span>
                            View Business
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i>
                            <span class="icon-save" style="display: none;"></span>
                            Update Business
                        </button>
                    </div>
                </form>
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

        // Business types and categories data
        const businessTypes = <?php echo json_encode($business_types); ?>;
        const zonesWithSubzones = <?php echo json_encode($zones_with_subzones); ?>;
        
        // Initialize form
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize business type change handler
            const businessTypeSelect = document.getElementById('businessType');
            const businessCategorySelect = document.getElementById('businessCategory');
            
            businessTypeSelect.addEventListener('change', function() {
                const selectedType = this.value;
                
                // Clear categories
                businessCategorySelect.innerHTML = '<option value="">Select Category</option>';
                
                if (selectedType && businessTypes[selectedType]) {
                    businessTypes[selectedType].forEach(function(category) {
                        const option = document.createElement('option');
                        option.value = category.category;
                        option.textContent = category.category;
                        option.dataset.feeAmount = category.fee_amount;
                        businessCategorySelect.appendChild(option);
                    });
                }
                
                // Clear current bill if type changed
                document.getElementById('currentBill').value = '0';
            });
            
            // Initialize category change handler
            businessCategorySelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const feeAmount = selectedOption.dataset.feeAmount || '0';
                document.getElementById('currentBill').value = feeAmount;
            });
            
            // Initialize zone change handler
            const zoneSelect = document.getElementById('zoneSelect');
            const subZoneSelect = document.getElementById('subZoneSelect');
            
            zoneSelect.addEventListener('change', function() {
                const selectedZone = this.value;
                
                // Clear sub-zones
                subZoneSelect.innerHTML = '<option value="">Select Sub-Zone</option>';
                
                if (selectedZone && zonesWithSubzones[selectedZone]) {
                    zonesWithSubzones[selectedZone].forEach(function(subZone) {
                        const option = document.createElement('option');
                        option.value = subZone.sub_zone_id;
                        option.textContent = subZone.sub_zone_name;
                        subZoneSelect.appendChild(option);
                    });
                }
            });
            
            // Add coordinate validation
            document.getElementById('latitude').addEventListener('input', validateCoordinates);
            document.getElementById('longitude').addEventListener('input', validateCoordinates);
            
            // Restore selections on page load
            if (businessTypeSelect.value) {
                businessTypeSelect.dispatchEvent(new Event('change'));
                setTimeout(() => {
                    const savedCategory = '<?php echo $_POST['category'] ?? ''; ?>';
                    if (savedCategory) {
                        businessCategorySelect.value = savedCategory;
                        businessCategorySelect.dispatchEvent(new Event('change'));
                    }
                }, 100);
            }
            
            if (zoneSelect.value) {
                zoneSelect.dispatchEvent(new Event('change'));
                setTimeout(() => {
                    const savedSubZone = '<?php echo $_POST['sub_zone_id'] ?? ''; ?>';
                    if (savedSubZone) {
                        subZoneSelect.value = savedSubZone;
                    }
                }, 100);
            }
        });
        
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
        
        // Enhanced GPS location function with high precision and fallback
        function getCurrentLocation() {
            if (!navigator.geolocation) {
                alert('Geolocation is not supported by this browser.');
                return;
            }
            
            // Show loading state
            const locationBtn = document.getElementById('locationBtn');
            const originalText = locationBtn.innerHTML;
            locationBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating Location...';
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
            console.log(`Location updated with ${accuracy}m accuracy`);
            
            // Get detailed address
            getAddressFromCoordinates(latitude, longitude);
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
        
        // Get address from coordinates with precise local area detection
        function getAddressFromCoordinates(latitude, longitude) {
            const geocoder = new google.maps.Geocoder();
            const latLng = new google.maps.LatLng(latitude, longitude);
            
            // Use more precise geocoding settings
            geocoder.geocode({ 
                location: latLng,
                region: 'GH' // Ghana region for better local results
            }, function(results, status) {
                if (status === 'OK' && results.length > 0) {
                    let specificLocation = '';
                    
                    // Look through all results to find the most specific location
                    for (let i = 0; i < Math.min(results.length, 3); i++) {
                        const result = results[i];
                        const components = result.address_components;
                        
                        // Extract specific area information
                        let neighborhood = '';
                        let sublocality = '';
                        let locality = '';
                        let adminArea = '';
                        
                        components.forEach(function(component) {
                            const types = component.types;
                            
                            if (types.includes('neighborhood') || types.includes('sublocality_level_2')) {
                                neighborhood = component.long_name;
                            } else if (types.includes('sublocality_level_1') || types.includes('sublocality')) {
                                sublocality = component.long_name;
                            } else if (types.includes('locality')) {
                                locality = component.long_name;
                            } else if (types.includes('administrative_area_level_2')) {
                                adminArea = component.long_name;
                            }
                        });
                        
                        // Build the most specific location string
                        if (neighborhood) {
                            specificLocation = neighborhood;
                            if (sublocality && sublocality !== neighborhood) {
                                specificLocation += ', ' + sublocality;
                            }
                            break;
                        } else if (sublocality) {
                            specificLocation = sublocality;
                            break;
                        } else if (locality) {
                            specificLocation = locality;
                            break;
                        }
                    }
                    
                    // If no specific location found, use the first result's formatted address
                    if (!specificLocation) {
                        specificLocation = results[0].formatted_address;
                    }
                    
                    // Create location description
                    const locationDescription = `Location: ${specificLocation}\nGPS: ${latitude.toFixed(6)}, ${longitude.toFixed(6)}`;
                    
                    const exactLocationField = document.querySelector('textarea[name="exact_location"]');
                    
                    // Ask user if they want to update location with new address
                    if (confirm(`Update location with captured GPS location?\n\n${specificLocation}`)) {
                        exactLocationField.value = locationDescription;
                    }
                    
                    // Show success message
                    showLocationSuccess(specificLocation);
                } else {
                    // Fallback: just use coordinates
                    const locationDescription = `GPS Coordinates: ${latitude.toFixed(6)}, ${longitude.toFixed(6)}`;
                    const exactLocationField = document.querySelector('textarea[name="exact_location"]');
                    
                    if (confirm('Update location with new GPS coordinates?')) {
                        exactLocationField.value = locationDescription;
                    }
                    
                    console.warn('Geocoding failed:', status);
                }
            });
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

        // Enhanced form validation before submit
        document.getElementById('businessForm').addEventListener('submit', function(e) {
            const requiredFields = this.querySelectorAll('[required]');
            let isValid = true;
            
            requiredFields.forEach(function(field) {
                if (!field.value.trim()) {
                    field.classList.add('error');
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
                isValid = false;
            }
            
            if (lng && (isNaN(parseFloat(lng)) || parseFloat(lng) < -180 || parseFloat(lng) > 180)) {
                alert('Please enter a valid longitude between -180 and 180.');
                isValid = false;
            }
            
            if (!isValid) {
                e.preventDefault();
                alert('Please fix the validation errors and try again.');
                return false;
            }
        });
    </script>
</body>
</html>