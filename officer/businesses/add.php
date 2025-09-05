<?php
/**
 * Officer  - Add Business Form (Enhanced with Type-ahead)
 * businesses/add.php
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

// Check if user is officer or admin
$currentUser = getCurrentUser();
if (!isOfficer() && !isAdmin()) {
    setFlashMessage('error', 'Access denied. Officer privileges required.');
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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $db = new Database();
        
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
        
        // If no errors, insert business
        if (empty($errors)) {
            // Calculate amount payable
            $amount_payable = $old_bill + $arrears + $current_bill - $previous_payments;
            
            // Insert business
            $stmt = $db->execute(
                "INSERT INTO businesses (
                    business_name, owner_name, business_type, category, telephone, 
                    exact_location, latitude, longitude, old_bill, previous_payments, 
                    arrears, current_bill, amount_payable, batch, zone_id, sub_zone_id, 
                    created_by, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                [
                    $business_name, $owner_name, $business_type, $category, $telephone,
                    $exact_location, $latitude, $longitude, $old_bill, $previous_payments,
                    $arrears, $current_bill, $amount_payable, $batch, $zone_id, 
                    $sub_zone_id > 0 ? $sub_zone_id : null, $currentUser['user_id'] // Fixed: changed from 'id' to 'user_id'
                ]
            );
            
            if ($stmt) {
                // Get the last inserted ID
                $business_id = $db->lastInsertId();
                
                if ($business_id) {
                    // Log the action
                    $db->execute(
                        "INSERT INTO audit_logs (user_id, action, table_name, record_id, new_values, ip_address, user_agent, created_at)
                         VALUES (?, 'CREATE_BUSINESS', 'businesses', ?, ?, ?, ?, NOW())",
                        [
                            $currentUser['user_id'], $business_id, // Fixed: changed from 'id' to 'user_id'
                            json_encode(['business_name' => $business_name, 'owner_name' => $owner_name]),
                            $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']
                        ]
                    );
                    
                    setFlashMessage('success', 'Business registered successfully!');
                    header('Location: view.php?id=' . $business_id);
                    exit();
                } else {
                    $errors[] = "Failed to get business ID. Please try again.";
                }
            } else {
                $errors[] = "Failed to register business. Please try again.";
            }
        }
        
    } catch (Exception $e) {
        $errors[] = "Database error: " . $e->getMessage();
    }
}

// Get reference data
try {
    $db = new Database();
    
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
}

// Organize fee structure by business type
$business_types = [];
foreach ($fee_structure as $fee) {
    if (!isset($business_types[$fee['business_type']])) {
        $business_types[$fee['business_type']] = [];
    }
    $business_types[$fee['business_type']][] = [
        'category' => $fee['category'],
        'fee_amount' => $fee['fee_amount']
    ];
}

// Organize sub-zones by zone
$zones_with_subzones = [];
foreach ($sub_zones as $sub_zone) {
    if (!isset($zones_with_subzones[$sub_zone['zone_id']])) {
        $zones_with_subzones[$sub_zone['zone_id']] = [];
    }
    $zones_with_subzones[$sub_zone['zone_id']][] = $sub_zone;
}

// Set current directory and page for active link
$currentDir = 'businesses';
$currentPage = 'record.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Business - Officer Portal - <?php echo APP_NAME; ?></title>
    
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
        .icon-dashboard::before { content: "⚡"; }
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
        .icon-exclamation-triangle::before { content: "⚠️"; }
        .icon-info-circle::before { content: "ℹ️"; }
        .icon-map-marker-alt::before { content: "📍"; }
        .icon-calculator::before { content: "🧮"; }
        .icon-save::before { content: "💾"; }
        .icon-times::before { content: "❌"; }
        .icon-money::before { content: "💰"; }
        .icon-search::before { content: "🔍"; }
        .icon-receipt::before { content: "🧾"; }
        .icon-print::before { content: "🖨️"; }
        .icon-satellite-dish::before { content: "📡"; }
        
        /* Top Navigation */
        .top-nav {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
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
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
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
            color: #4299e1;
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
            border-left-color: #4299e1;
        }
        
        .nav-link.active {
            background: rgba(66, 153, 225, 0.3);
            color: white;
            border-left-color: #4299e1;
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
            position: relative;
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
            border-color: #4299e1;
            box-shadow: 0 0 0 3px rgba(66, 153, 225, 0.1);
        }
        
        .form-control.error {
            border-color: #e53e3e;
        }
        
        .form-help {
            font-size: 12px;
            color: #718096;
            margin-top: 5px;
        }
        
        /* Type-ahead Autocomplete Styles */
        .typeahead-container {
            position: relative;
            width: 100%;
        }
        
        .typeahead-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: white;
        }
        
        .typeahead-input:focus {
            outline: none;
            border-color: #4299e1;
            box-shadow: 0 0 0 3px rgba(66, 153, 225, 0.1);
        }
        
        .typeahead-input.error {
            border-color: #e53e3e;
        }
        
        .typeahead-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 2px solid #e2e8f0;
            border-top: none;
            border-radius: 0 0 8px 8px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            display: none;
        }
        
        .typeahead-dropdown.show {
            display: block;
        }
        
        .typeahead-item {
            padding: 12px 16px;
            cursor: pointer;
            border-bottom: 1px solid #f7fafc;
            transition: all 0.2s ease;
            font-size: 14px;
        }
        
        .typeahead-item:last-child {
            border-bottom: none;
        }
        
        .typeahead-item:hover,
        .typeahead-item.highlighted {
            background: #f7fafc;
            color: #4299e1;
        }
        
        .typeahead-item.selected {
            background: #4299e1;
            color: white;
        }
        
        .typeahead-no-results {
            padding: 12px 16px;
            color: #a0aec0;
            font-style: italic;
            text-align: center;
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
            background: #4299e1;
            color: white;
        }
        
        .btn-primary:hover {
            background: #3182ce;
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
            background: #38a169;
            color: white;
        }
        
        .btn-location:hover {
            background: #2f855a;
        }
        
        .btn-location:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
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
            border: 1px solid #68d391;
        }
        
        .alert-info {
            background: #bee3f8;
            color: #2a4365;
            border: 1px solid #90cdf4;
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
        
        /* Bill Calculator */
        .bill-calculator {
            background: #e6fffa;
            border: 2px solid #4fd1c7;
            border-radius: 10px;
            padding: 20px;
            margin-top: 15px;
        }
        
        .calculator-title {
            font-weight: bold;
            color: #234e52;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .calculation-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            padding: 8px 0;
            border-bottom: 1px solid rgba(66, 153, 225, 0.2);
        }
        
        .calculation-row:last-child {
            border-bottom: none;
            font-weight: bold;
            font-size: 16px;
            background: rgba(66, 153, 225, 0.1);
            margin: 10px -10px -10px -10px;
            padding: 15px 10px;
            border-radius: 0 0 6px 6px;
        }
        
        /* Actions */
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
            <button class="toggle-btn" id="toggleBtn">
                <i class="fas fa-bars"></i>
                <span class="icon-menu" style="display: none;"></span>
            </button>
            
            <a href="index.php" class="brand">
                <i class="fas fa-user-tie"></i>
                <span class="icon-user" style="display: none;"></span>
                Officer Portal
            </a>
        </div>
        
        <div class="user-section">
            <div class="user-profile" id="userProfile">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($currentUser['first_name'] ?? 'U', 0, 1)); ?>
                </div>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($userDisplayName); ?></div>
                    <div class="user-role">Officer</div>
                </div>
                <i class="fas fa-chevron-down dropdown-arrow"></i>
                
                <!-- User Dropdown -->
                <div class="user-dropdown" id="userDropdown">
                    <div class="dropdown-header">
                        <div class="dropdown-avatar">
                            <?php echo strtoupper(substr($currentUser['first_name'] ?? 'U', 0, 1)); ?>
                        </div>
                        <div class="dropdown-name"><?php echo htmlspecialchars($userDisplayName); ?></div>
                        <div class="dropdown-role">Officer</div>
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
                        <a href="../index.php" class="nav-link <?php echo ($currentDir === 'index' && $currentPage === 'index.php') ? 'active' : ''; ?>">
                            <span class="nav-icon">
                                <i class="fas fa-tachometer-alt"></i>
                                <span class="icon-dashboard" style="display: none;"></span>
                            </span>
                            Dashboard
                        </a>
                    </div>
                </div>
                
                <!-- Registration -->
                <div class="nav-section">
                    <div class="nav-title">Registration</div>
                    <div class="nav-item">
                        <a href="record.php" class="nav-link <?php echo ($currentDir === 'businesses' && $currentPage === 'record.php') ? 'active' : ''; ?>">
                            <span class="nav-icon">
                                <i class="fas fa-plus-circle"></i>
                                <span class="icon-plus" style="display: none;"></span>
                            </span>
                            Register Business
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../properties/add.php" class="nav-link <?php echo ($currentDir === 'properties' && $currentPage === 'add.php') ? 'active' : ''; ?>">
                            <span class="nav-icon">
                                <i class="fas fa-plus-circle"></i>
                                <span class="icon-plus" style="display: none;"></span>
                            </span>
                            Register Property
                        </a>
                    </div>
                </div>
                
                <!-- Management -->
                <div class="nav-section">
                    <div class="nav-title">Management</div>
                    <div class="nav-item">
                        <a href="index.php" class="nav-link <?php echo ($currentDir === 'businesses' && $currentPage === 'index.php') ? 'active' : ''; ?>">
                            <span class="nav-icon">
                                <i class="fas fa-building"></i>
                                <span class="icon-building" style="display: none;"></span>
                            </span>
                            Businesses
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../properties/index.php" class="nav-link <?php echo ($currentDir === 'properties' && $currentPage === 'index.php') ? 'active' : ''; ?>">
                            <span class="nav-icon">
                                <i class="fas fa-home"></i>
                                <span class="icon-home" style="display: none;"></span>
                            </span>
                            Properties
                        </a>
                    </div>
                </div>
                
                <!-- Payments & Bills -->
                <div class="nav-section">
                    <div class="nav-title">Payments & Bills</div>
                    <div class="nav-item">
                        <a href="../payments/record.php" class="nav-link <?php echo ($currentDir === 'payments' && $currentPage === 'record.php') ? 'active' : ''; ?>">
                            <span class="nav-icon">
                                <i class="fas fa-cash-register"></i>
                                <span class="icon-money" style="display: none;"></span>
                            </span>
                            Record Payment
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../payments/search.php" class="nav-link <?php echo ($currentDir === 'payments' && $currentPage === 'search.php') ? 'active' : ''; ?>">
                            <span class="nav-icon">
                                <i class="fas fa-search"></i>
                                <span class="icon-search" style="display: none;"></span>
                            </span>
                            Search Accounts
                        </a>
                    </div>
                 <div class="nav-item">
                        <a href="print.php" class="nav-link <?php echo ($currentDir === 'bills' && $currentPage === 'print.php') ? 'active' : ''; ?>">
                            <span class="nav-icon">
                                <i class="fas fa-print"></i>
                                <span class="icon-print" style="display: none;"></span>
                            </span>
                            Print Bills
                        </a>
                 </div>
                   
                </div>
                
                <!-- Maps & Locations -->
                <div class="nav-section">
                    <div class="nav-title">Maps & Locations</div>
                    <div class="nav-item">
                        <a href="../map/businesses.php" class="nav-link <?php echo ($currentDir === 'map' && $currentPage === 'businesses.php') ? 'active' : ''; ?>">
                            <span class="nav-icon">
                                <i class="fas fa-map-marked-alt"></i>
                                <span class="icon-map" style="display: none;"></span>
                            </span>
                            Business Map
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../map/properties.php" class="nav-link <?php echo ($currentDir === 'map' && $currentPage === 'properties.php') ? 'active' : ''; ?>">
                            <span class="nav-icon">
                                <i class="fas fa-map-marker-alt"></i>
                                <span class="icon-map" style="display: none;"></span>
                            </span>
                            Property Map
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="form-container fade-in">
                <div class="form-header">
                    <h1 class="form-title">Register New Business</h1>
                    <p class="form-subtitle">Enter business information to register it in the billing system</p>
                </div>
                
                <!-- Error Messages -->
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <h4>
                            <i class="fas fa-exclamation-triangle"></i>
                            <span class="icon-exclamation-triangle" style="display: none;"></span>
                            Please fix the following errors:
                        </h4>
                        <ul style="margin: 10px 0 0 20px;">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <!-- Registration Form -->
                <form method="POST" action="" id="businessForm">
                    <!-- Owner Information -->
                    <div class="form-section">
                        <h3 class="section-title">Owner Information</h3>
                        
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
                                <label class="form-label">Batch</label>
                                <input type="text" name="batch" class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['batch'] ?? ''); ?>" 
                                       placeholder="e.g., BATCH001">
                                <div class="form-help">Optional batch identifier for bulk operations</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Business Classification -->
                    <div class="form-section">
                        <h3 class="section-title">Business Classification</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label required">Business Type</label>
                                <div class="typeahead-container">
                                    <input type="text" 
                                           id="businessTypeInput" 
                                           class="typeahead-input" 
                                           placeholder="Start typing business type..." 
                                           autocomplete="off"
                                           value="<?php echo htmlspecialchars($_POST['business_type'] ?? ''); ?>" 
                                           required>
                                    <input type="hidden" name="business_type" id="businessTypeHidden" 
                                           value="<?php echo htmlspecialchars($_POST['business_type'] ?? ''); ?>">
                                    <div class="typeahead-dropdown" id="businessTypeDropdown">
                                        <!-- Dropdown items will be populated by JavaScript -->
                                    </div>
                                </div>
                                <div class="form-help">Start typing to search business types</div>
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
                        
                        <div class="location-section">
                            <div class="alert alert-info" style="margin-bottom: 15px;">
                                <i class="fas fa-info-circle"></i>
                                <span class="icon-info-circle" style="display: none;"></span>
                                <strong>GPS Tips:</strong> For best accuracy, ensure GPS is enabled, allow location access when prompted, and capture location while outdoors or near windows.
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
                                    Capture GPS Coordinates
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
                                <div class="form-help">Select the zone where business is located</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Sub-Zone</label>
                                <select name="sub_zone_id" class="form-control" id="subZoneSelect">
                                    <option value="">Select Sub-Zone</option>
                                </select>
                                <div class="form-help">Optional sub-zone for more specific location</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Billing Information -->
                    <div class="form-section">
                        <h3 class="section-title">Billing Information</h3>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <span class="icon-info-circle" style="display: none;"></span>
                            <strong>Current Bill</strong> will be automatically calculated based on business type and category.
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
                                <label class="form-label required" style="display: none;">Current Bill (₵)</label>
                                <input type="hidden" name="current_bill" id="currentBill" 
                                       value="<?php echo $_POST['current_bill'] ?? '0'; ?>">
                            </div>
                        </div>
                        
                        <!-- Bill Calculator -->
                        <div class="bill-calculator" id="billCalculator" style="display: none;">
                            <div class="calculator-title">
                                <i class="fas fa-calculator"></i>
                                <span class="icon-calculator" style="display: none;"></span>
                                Bill Calculation
                            </div>
                            <div class="calculation-row">
                                <span>Fee Amount:</span>
                                <span id="feeAmount">₵ 0.00</span>
                            </div>
                            <div class="calculation-row">
                                <span>Current Bill:</span>
                                <span id="calculatedBill">₵ 0.00</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Form Actions -->
                    <div class="form-actions">
                        <a href="index.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i>
                            <span class="icon-times" style="display: none;"></span>
                            Cancel
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i>
                            <span class="icon-save" style="display: none;"></span>
                            Register Business
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // === TYPE-AHEAD AUTOCOMPLETE FUNCTIONALITY ===
        
        // Type-ahead autocomplete class
        class TypeAhead {
            constructor(inputElement, dropdownElement, options = {}) {
                this.input = inputElement;
                this.dropdown = dropdownElement;
                this.options = {
                    minLength: 1,
                    maxResults: 10,
                    caseSensitive: false,
                    highlightMatches: true,
                    onSelect: null,
                    ...options
                };
                
                this.data = [];
                this.filteredData = [];
                this.selectedIndex = -1;
                this.isOpen = false;
                
                this.init();
            }
            
            init() {
                // Set up event listeners
                this.input.addEventListener('input', this.handleInput.bind(this));
                this.input.addEventListener('keydown', this.handleKeyDown.bind(this));
                this.input.addEventListener('focus', this.handleFocus.bind(this));
                this.input.addEventListener('blur', this.handleBlur.bind(this));
                
                this.dropdown.addEventListener('mousedown', this.handleMouseDown.bind(this));
                this.dropdown.addEventListener('click', this.handleClick.bind(this));
                
                // Close dropdown when clicking outside
                document.addEventListener('click', (e) => {
                    if (!this.input.contains(e.target) && !this.dropdown.contains(e.target)) {
                        this.close();
                    }
                });
            }
            
            setData(data) {
                this.data = Array.isArray(data) ? data : [];
            }
            
            handleInput(e) {
                const value = e.target.value.trim();
                
                if (value.length >= this.options.minLength) {
                    this.filter(value);
                    this.render();
                    this.open();
                } else {
                    this.close();
                }
            }
            
            handleKeyDown(e) {
                if (!this.isOpen) return;
                
                switch (e.key) {
                    case 'ArrowDown':
                        e.preventDefault();
                        this.moveSelection(1);
                        break;
                    case 'ArrowUp':
                        e.preventDefault();
                        this.moveSelection(-1);
                        break;
                    case 'Enter':
                        e.preventDefault();
                        if (this.selectedIndex >= 0) {
                            this.selectItem(this.filteredData[this.selectedIndex]);
                        }
                        break;
                    case 'Escape':
                        e.preventDefault();
                        this.close();
                        break;
                }
            }
            
            handleFocus(e) {
                const value = e.target.value.trim();
                if (value.length >= this.options.minLength) {
                    this.filter(value);
                    this.render();
                    this.open();
                }
            }
            
            handleBlur(e) {
                // Delay close to allow for click events
                setTimeout(() => {
                    this.close();
                }, 150);
            }
            
            handleMouseDown(e) {
                e.preventDefault(); // Prevent input blur
            }
            
            handleClick(e) {
                const item = e.target.closest('.typeahead-item');
                if (item && item.dataset.value) {
                    this.selectItem(item.dataset.value);
                }
            }
            
            filter(query) {
                const searchQuery = this.options.caseSensitive ? query : query.toLowerCase();
                
                this.filteredData = this.data.filter(item => {
                    const searchText = this.options.caseSensitive ? item : item.toLowerCase();
                    return searchText.includes(searchQuery);
                }).slice(0, this.options.maxResults);
                
                this.selectedIndex = -1;
            }
            
            render() {
                this.dropdown.innerHTML = '';
                
                if (this.filteredData.length === 0) {
                    this.dropdown.innerHTML = '<div class="typeahead-no-results">No matching business types found</div>';
                    return;
                }
                
                this.filteredData.forEach((item, index) => {
                    const div = document.createElement('div');
                    div.className = 'typeahead-item';
                    div.dataset.value = item;
                    
                    if (this.options.highlightMatches) {
                        const query = this.input.value.trim();
                        const regex = new RegExp(`(${this.escapeRegex(query)})`, 'gi');
                        const highlightedText = item.replace(regex, '<strong>$1</strong>');
                        div.innerHTML = highlightedText;
                    } else {
                        div.textContent = item;
                    }
                    
                    this.dropdown.appendChild(div);
                });
            }
            
            moveSelection(direction) {
                const maxIndex = this.filteredData.length - 1;
                
                // Remove previous highlight
                const previousItem = this.dropdown.children[this.selectedIndex];
                if (previousItem) {
                    previousItem.classList.remove('highlighted');
                }
                
                // Calculate new index
                this.selectedIndex += direction;
                
                if (this.selectedIndex < 0) {
                    this.selectedIndex = maxIndex;
                } else if (this.selectedIndex > maxIndex) {
                    this.selectedIndex = 0;
                }
                
                // Highlight new item
                const newItem = this.dropdown.children[this.selectedIndex];
                if (newItem) {
                    newItem.classList.add('highlighted');
                    newItem.scrollIntoView({ block: 'nearest' });
                }
            }
            
            selectItem(value) {
                this.input.value = value;
                this.close();
                
                // Trigger change event
                const changeEvent = new Event('change', { bubbles: true });
                this.input.dispatchEvent(changeEvent);
                
                // Call onSelect callback if provided
                if (this.options.onSelect && typeof this.options.onSelect === 'function') {
                    this.options.onSelect(value);
                }
            }
            
            open() {
                this.isOpen = true;
                this.dropdown.classList.add('show');
            }
            
            close() {
                this.isOpen = false;
                this.dropdown.classList.remove('show');
                this.selectedIndex = -1;
                
                // Remove all highlights
                const items = this.dropdown.querySelectorAll('.typeahead-item');
                items.forEach(item => item.classList.remove('highlighted'));
            }
            
            escapeRegex(string) {
                return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            }
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

            // Sidebar toggle
            const toggleBtn = document.getElementById('toggleBtn');
            const sidebar = document.getElementById('sidebar');
            const isMobile = window.innerWidth <= 768;
            
            if (toggleBtn) {
                toggleBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    if (isMobile) {
                        sidebar.classList.toggle('show');
                        sidebar.classList.toggle('hidden');
                    } else {
                        sidebar.classList.toggle('hidden');
                    }
                    localStorage.setItem('sidebarHidden', sidebar.classList.contains('hidden'));
                });
            }

            // Restore sidebar state
            const sidebarHidden = localStorage.getItem('sidebarHidden');
            if (isMobile) {
                sidebar.classList.add('hidden');
                sidebar.classList.remove('show');
            } else if (sidebarHidden === 'true') {
                sidebar.classList.add('hidden');
            }

            // User dropdown toggle
            const userProfile = document.getElementById('userProfile');
            const userDropdown = document.getElementById('userDropdown');
            
            if (userProfile) {
                userProfile.addEventListener('click', function(e) {
                    e.stopPropagation();
                    userDropdown.classList.toggle('show');
                    userProfile.classList.toggle('active');
                });
            }

            // Close dropdown and sidebar when clicking outside
            document.addEventListener('click', function(e) {
                if (userDropdown && !userProfile.contains(e.target)) {
                    userDropdown.classList.remove('show');
                    userProfile.classList.remove('active');
                }
                if (isMobile && sidebar && !sidebar.contains(e.target) && !toggleBtn.contains(e.target)) {
                    sidebar.classList.remove('show');
                    sidebar.classList.add('hidden');
                    localStorage.setItem('sidebarHidden', true);
                }
            });

            // Prevent clicks inside sidebar from closing it
            if (sidebar) {
                sidebar.addEventListener('click', function(e) {
                    e.stopPropagation();
                });
            }

            // Business types and categories data
            const businessTypes = <?php echo json_encode($business_types); ?>;
            const zonesWithSubzones = <?php echo json_encode($zones_with_subzones); ?>;
            
            // Initialize type-ahead for business type
            const businessTypeInput = document.getElementById('businessTypeInput');
            const businessTypeDropdown = document.getElementById('businessTypeDropdown');
            const businessTypeHidden = document.getElementById('businessTypeHidden');
            const businessCategorySelect = document.getElementById('businessCategory');
            const billCalculator = document.getElementById('billCalculator');
            
            // Create type-ahead instance
            const businessTypeAhead = new TypeAhead(businessTypeInput, businessTypeDropdown, {
                minLength: 1,
                maxResults: 8,
                caseSensitive: false,
                highlightMatches: true,
                onSelect: function(selectedType) {
                    // Update hidden field
                    businessTypeHidden.value = selectedType;
                    
                    // Update categories
                    updateBusinessCategories(selectedType);
                    
                    // Hide calculator
                    billCalculator.style.display = 'none';
                }
            });
            
            // Set business types data
            const businessTypesList = Object.keys(businessTypes);
            businessTypeAhead.setData(businessTypesList);
            
            // Handle input changes for validation
            businessTypeInput.addEventListener('input', function() {
                const inputValue = this.value.trim();
                const isValidType = businessTypesList.includes(inputValue);
                
                if (isValidType) {
                    businessTypeHidden.value = inputValue;
                    this.classList.remove('error');
                } else {
                    businessTypeHidden.value = '';
                    if (inputValue.length > 0) {
                        this.classList.add('error');
                    } else {
                        this.classList.remove('error');
                    }
                }
            });
            
            // Function to update business categories
            function updateBusinessCategories(selectedType) {
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
            }
            
            // Initialize category change handler
            function updateBillCalculation() {
                const selectedCategoryOption = businessCategorySelect.options[businessCategorySelect.selectedIndex];
                const feeAmount = parseFloat(selectedCategoryOption.dataset.feeAmount || 0);
                
                if (feeAmount > 0) {
                    document.getElementById('feeAmount').textContent = '₵ ' + feeAmount.toFixed(2);
                    document.getElementById('calculatedBill').textContent = '₵ ' + feeAmount.toFixed(2);
                    document.getElementById('currentBill').value = feeAmount.toFixed(2);
                    billCalculator.style.display = 'block';
                } else {
                    billCalculator.style.display = 'none';
                    document.getElementById('currentBill').value = '0';
                }
            }
            
            if (businessCategorySelect) {
                businessCategorySelect.addEventListener('change', updateBillCalculation);
            }
            
            // Initialize zone change handler
            const zoneSelect = document.getElementById('zoneSelect');
            const subZoneSelect = document.getElementById('subZoneSelect');
            
            if (zoneSelect) {
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
            }
            
            // Add coordinate validation
            document.getElementById('latitude').addEventListener('input', validateCoordinates);
            document.getElementById('longitude').addEventListener('input', validateCoordinates);
            
            // Restore selections on page load
            const savedBusinessType = '<?php echo $_POST['business_type'] ?? ''; ?>';
            if (savedBusinessType) {
                businessTypeInput.value = savedBusinessType;
                businessTypeHidden.value = savedBusinessType;
                updateBusinessCategories(savedBusinessType);
                
                setTimeout(() => {
                    const savedCategory = '<?php echo $_POST['category'] ?? ''; ?>';
                    if (savedCategory) {
                        businessCategorySelect.value = savedCategory;
                        updateBillCalculation();
                    }
                }, 100);
            }
            
            if (zoneSelect && zoneSelect.value) {
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
                    
                    if (exactLocationField.value.trim() === '') {
                        exactLocationField.value = locationDescription;
                    } else if (confirm(`Update location with captured GPS location?\n\n${specificLocation}`)) {
                        exactLocationField.value = locationDescription;
                    }
                    
                    // Show success message
                    showLocationSuccess(specificLocation);
                } else {
                    // Fallback: just use coordinates
                    const locationDescription = `GPS Coordinates: ${latitude.toFixed(6)}, ${longitude.toFixed(6)}`;
                    const exactLocationField = document.querySelector('textarea[name="exact_location"]');
                    
                    if (exactLocationField.value.trim() === '') {
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
                        <div>Location Captured!</div>
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
            
            // Special validation for business type
            const businessTypeHidden = document.getElementById('businessTypeHidden');
            const businessTypeInput = document.getElementById('businessTypeInput');
            if (!businessTypeHidden.value.trim()) {
                businessTypeInput.classList.add('error');
                isValid = false;
                alert('Please select a valid business type from the dropdown.');
                e.preventDefault();
                return false;
            } else {
                businessTypeInput.classList.remove('error');
            }
            
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