<?php
/**
 * Business Management - Add New Business (Enhanced with Advanced GPS and Type-ahead)
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
if (!hasPermission('businesses.create')) {
    setFlashMessage('error', 'Access denied. You do not have permission to add businesses.');
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

$pageTitle = 'Register New Business';
$currentUser = getCurrentUser();
$userDisplayName = getUserDisplayName($currentUser);

// Initialize variables
$errors = [];
$formData = [
    'business_name' => '',
    'owner_name' => '',
    'business_type' => '',
    'category' => '',
    'telephone' => '',
    'exact_location' => '',
    'latitude' => '',
    'longitude' => '',
    'old_bill' => '0.00',
    'previous_payments' => '0.00',
    'arrears' => '0.00',
    'current_bill' => '0.00',
    'batch' => '',
    'status' => 'Active',
    'zone_id' => '',
    'sub_zone_id' => ''
];

try {
    $db = new Database();
    
    // Get business fee structure for dynamic billing
    $businessFees = $db->fetchAll("SELECT * FROM business_fee_structure WHERE is_active = 1 ORDER BY business_type, category");
    
    // Get zones for dropdown
    $zones = $db->fetchAll("SELECT * FROM zones ORDER BY zone_name");
    
    // Get sub-zones for dropdown
    $subZones = $db->fetchAll("SELECT sz.*, z.zone_name FROM sub_zones sz 
                               LEFT JOIN zones z ON sz.zone_id = z.zone_id 
                               ORDER BY z.zone_name, sz.sub_zone_name");
    
    // Process form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Validate and sanitize input
        $formData['business_name'] = sanitizeInput($_POST['business_name'] ?? '');
        $formData['owner_name'] = sanitizeInput($_POST['owner_name'] ?? '');
        $formData['business_type'] = sanitizeInput($_POST['business_type'] ?? '');
        $formData['category'] = sanitizeInput($_POST['category'] ?? '');
        $formData['telephone'] = sanitizeInput($_POST['telephone'] ?? '');
        $formData['exact_location'] = sanitizeInput($_POST['exact_location'] ?? '');
        $formData['latitude'] = sanitizeInput($_POST['latitude'] ?? '');
        $formData['longitude'] = sanitizeInput($_POST['longitude'] ?? '');
        $formData['old_bill'] = floatval($_POST['old_bill'] ?? 0);
        $formData['previous_payments'] = floatval($_POST['previous_payments'] ?? 0);
        $formData['arrears'] = floatval($_POST['arrears'] ?? 0);
        $formData['current_bill'] = floatval($_POST['current_bill'] ?? 0);
        $formData['batch'] = sanitizeInput($_POST['batch'] ?? '');
        $formData['status'] = sanitizeInput($_POST['status'] ?? 'Active');
        $formData['zone_id'] = intval($_POST['zone_id'] ?? 0);
        $formData['sub_zone_id'] = intval($_POST['sub_zone_id'] ?? 0);
        
        // Validation
        if (empty($formData['business_name'])) {
            $errors[] = 'Business name is required.';
        }
        
        if (empty($formData['owner_name'])) {
            $errors[] = 'Owner name is required.';
        }
        
        if (empty($formData['business_type'])) {
            $errors[] = 'Business type is required.';
        }
        
        if (empty($formData['category'])) {
            $errors[] = 'Business category is required.';
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
        
        // Check if business name already exists
        if (empty($errors)) {
            $existingBusiness = $db->fetchRow(
                "SELECT business_id FROM businesses WHERE business_name = ?", 
                [$formData['business_name']]
            );
            
            if ($existingBusiness) {
                $errors[] = 'A business with this name already exists.';
            }
        }
        
        // If no errors, save the business
        if (empty($errors)) {
            try {
                $db->beginTransaction();
                
                // Calculate amount payable
                $amount_payable = $formData['old_bill'] + $formData['arrears'] + $formData['current_bill'] - $formData['previous_payments'];
                
                // Insert business
                $query = "INSERT INTO businesses (
                    business_name, owner_name, business_type, category, telephone, 
                    exact_location, latitude, longitude, old_bill, previous_payments, 
                    arrears, current_bill, amount_payable, batch, status, zone_id, 
                    sub_zone_id, created_by, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                
                $params = [
                    $formData['business_name'],
                    $formData['owner_name'],
                    $formData['business_type'],
                    $formData['category'],
                    $formData['telephone'],
                    $formData['exact_location'],
                    !empty($formData['latitude']) ? $formData['latitude'] : null,
                    !empty($formData['longitude']) ? $formData['longitude'] : null,
                    $formData['old_bill'],
                    $formData['previous_payments'],
                    $formData['arrears'],
                    $formData['current_bill'],
                    $amount_payable,
                    $formData['batch'],
                    $formData['status'],
                    $formData['zone_id'] > 0 ? $formData['zone_id'] : null,
                    $formData['sub_zone_id'] > 0 ? $formData['sub_zone_id'] : null,
                    $currentUser['user_id']
                ];
                
                // Alternative approach - direct PDO usage
                try {
                    $pdo = $db->getConnection();
                    $stmt = $pdo->prepare($query);
                    $stmt->execute($params);
                    $businessId = $pdo->lastInsertId();
                } catch (Exception $e) {
                    // Fallback if getConnection() doesn't exist
                    $businessId = 1; // temporary - replace with actual insert method
                    // You'll need to replace this with the correct Database class method
                    throw new Exception("Please update insert method for your Database class");
                }
                
                // Log the action
                writeLog("Business created: {$formData['business_name']} (ID: $businessId) by user {$currentUser['username']}", 'INFO');
                
                $db->commit();
                
                setFlashMessage('success', 'Business registered successfully!');
                header('Location: view.php?id=' . $businessId);
                exit();
                
            } catch (Exception $e) {
                $db->rollback();
                writeLog("Error creating business: " . $e->getMessage(), 'ERROR');
                $errors[] = 'An error occurred while registering the business. Please try again.';
            }
        }
    }
    
} catch (Exception $e) {
    writeLog("Business add page error: " . $e->getMessage(), 'ERROR');
    $errors[] = 'An error occurred while loading the page. Please try again.';
}

// Prepare business types for JavaScript
$businessTypesJson = json_encode($businessFees);

// Organize business types and categories for type-ahead
$business_types = [];
foreach ($businessFees as $fee) {
    if (!isset($business_types[$fee['business_type']])) {
        $business_types[$fee['business_type']] = [];
    }
    $business_types[$fee['business_type']][] = [
        'category' => $fee['category'],
        'fee_amount' => $fee['fee_amount']
    ];
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
    <link rel="stylesheet" href="https://use.fontawesome.com/releases/v6.4.0/css/all.css">
    
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
        
        /* Top Navigation - Same as index.php */
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
        
        /* User Dropdown - Same as index.php */
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
        
        /* Sidebar - Same as index.php */
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
        
        .form-row.quad {
            grid-template-columns: 2fr 1fr 1fr 1fr;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            position: relative;
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
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
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
        
        /* Type-ahead Autocomplete Styles */
        .typeahead-container {
            position: relative;
            width: 100%;
        }
        
        .typeahead-input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: white;
        }
        
        .typeahead-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .typeahead-input.error {
            border-color: #e53e3e;
            background: #fef2f2;
        }
        
        .typeahead-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 2px solid #e2e8f0;
            border-top: none;
            border-radius: 0 0 10px 10px;
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
            padding: 12px 15px;
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
            color: #667eea;
        }
        
        .typeahead-item.selected {
            background: #667eea;
            color: white;
        }
        
        .typeahead-no-results {
            padding: 12px 15px;
            color: #a0aec0;
            font-style: italic;
            text-align: center;
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
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
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
            box-shadow: 0 8px 25px rgba(72, 187, 120, 0.3);
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
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            margin-top: 15px;
            display: none;
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
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
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(72, 187, 120, 0.3);
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
            
            .form-row.triple, .form-row.quad {
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
                        <a href="../settings/index.php" class="dropdown-item">
                            <i class="fas fa-user"></i>
                            <span class="icon-user" style="display: none;"></span>
                            My Profile
                        </a>
                        <a href="../settings/index.php" class="dropdown-item">
                            <i class="fas fa-cog"></i>
                            <span class="icon-cog" style="display: none;"></span>
                            Account Settings
                        </a>
                        <a href="../logs/user_activity.php" class="dropdown-item">
                            <i class="fas fa-history"></i>
                            <span class="icon-chart" style="display: none;"></span>
                            Activity Log
                        </a>
                        <a href="../docs/user_manual.md" class="dropdown-item">
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
                        <a href="../fee_structure/index.php" class="nav-link">
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
                    <a href="index.php">Business Management</a>
                    <span>/</span>
                    <span class="breadcrumb-current">Register New Business</span>
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

            <!-- Page Header -->
            <div class="page-header">
                <div class="header-content">
                    <div>
                        <h1 class="page-title">
                            <i class="fas fa-plus-circle"></i>
                            Register New Business
                        </h1>
                        <p style="color: #64748b; margin: 5px 0 0 0;">Add a new business to the municipal billing system</p>
                    </div>
                    <a href="index.php" class="back-btn">
                        <i class="fas fa-arrow-left"></i>
                        Back to Businesses
                    </a>
                </div>
            </div>

            <!-- Registration Form -->
            <form method="POST" action="" id="businessForm">
                <!-- Basic Information Section -->
                <div class="form-card">
                    <div class="form-section">
                        <h3 class="section-title">
                            <div class="section-icon">
                                <i class="fas fa-info-circle"></i>
                            </div>
                            Basic Information
                        </h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-building"></i>
                                    Business Name <span class="required">*</span>
                                </label>
                                <input type="text" name="business_name" class="form-control" 
                                       value="<?php echo htmlspecialchars($formData['business_name']); ?>" 
                                       placeholder="Enter business name" required>
                                <div class="form-help">Official name of the business</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-user"></i>
                                    Owner Name <span class="required">*</span>
                                </label>
                                <input type="text" name="owner_name" class="form-control" 
                                       value="<?php echo htmlspecialchars($formData['owner_name']); ?>" 
                                       placeholder="Enter owner full name" required>
                                <div class="form-help">Full name of the business owner</div>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-tag"></i>
                                    Business Type <span class="required">*</span>
                                </label>
                                <div class="typeahead-container">
                                    <input type="text" 
                                           id="businessTypeInput" 
                                           class="typeahead-input" 
                                           placeholder="Start typing business type..." 
                                           autocomplete="off"
                                           value="<?php echo htmlspecialchars($formData['business_type']); ?>" 
                                           required>
                                    <input type="hidden" name="business_type" id="businessTypeHidden" 
                                           value="<?php echo htmlspecialchars($formData['business_type']); ?>">
                                    <div class="typeahead-dropdown" id="businessTypeDropdown">
                                        <!-- Dropdown items will be populated by JavaScript -->
                                    </div>
                                </div>
                                <div class="form-help">Start typing to search business types</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-layer-group"></i>
                                    Category <span class="required">*</span>
                                </label>
                                <select name="category" id="businessCategory" class="form-control" required>
                                    <option value="">Select Category</option>
                                </select>
                                <div class="form-help">Category will auto-populate based on business type</div>
                            </div>
                        </div>
                        
                        <div class="form-row">
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
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-flag"></i>
                                    Status
                                </label>
                                <select name="status" class="form-control">
                                    <option value="Active" <?php echo $formData['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="Inactive" <?php echo $formData['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="Suspended" <?php echo $formData['status'] === 'Suspended' ? 'selected' : ''; ?>>Suspended</option>
                                </select>
                                <div class="form-help">Current business status</div>
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
                                        Business Address/Location
                                    </label>
                                    <div class="location-input-group">
                                        <div class="form-group" style="margin-bottom: 0;">
                                            <input type="text" name="exact_location" id="exactLocation" class="form-control" 
                                                   value="<?php echo htmlspecialchars($formData['exact_location']); ?>" 
                                                   placeholder="Enter detailed business address or use GPS capture button...">
                                            <div class="form-help">Detailed business address, landmark descriptions, or street location</div>
                                        </div>
                                        <button type="button" class="location-btn" onclick="getCurrentLocation()" id="locationBtn">
                                            <i class="fas fa-crosshairs"></i>
                                            <span class="icon-location" style="display: none;"></span>
                                            Capture GPS Location
                                        </button>
                                    </div>
                                    <div class="location-status" id="locationStatus"></div>
                                </div>
                            </div>
                            
                            <div class="coordinates-display">
                                <div class="coordinate-item">
                                    <div class="coordinate-item-label">Latitude</div>
                                    <input type="number" name="latitude" id="latitude" class="coordinate-input" 
                                           step="0.000001" min="-90" max="90"
                                           value="<?php echo htmlspecialchars($formData['latitude']); ?>" 
                                           placeholder="e.g., 5.614818"
                                           style="width: 100%; text-align: center; font-weight: bold;">
                                </div>
                                <div class="coordinate-item">
                                    <div class="coordinate-item-label">Longitude</div>
                                    <input type="number" name="longitude" id="longitude" class="coordinate-input" 
                                           step="0.000001" min="-180" max="180"
                                           value="<?php echo htmlspecialchars($formData['longitude']); ?>" 
                                           placeholder="e.g., -0.205874"
                                           style="width: 100%; text-align: center; font-weight: bold;">
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
                                <div class="form-help">Administrative zone where business is located</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-map-marked"></i>
                                    Sub-Zone
                                </label>
                                <select name="sub_zone_id" id="subZoneSelect" class="form-control">
                                    <option value="">Select Sub-Zone</option>
                                </select>
                                <div class="form-help">Specific sub-zone within the selected zone</div>
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
                            <div class="fee-amount" id="feeAmount">₵ 0.00</div>
                            <div class="fee-description" id="feeDescription">Select business type and category to see fee</div>
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
                                <div class="form-help">Auto-calculated based on business type and category</div>
                            </div>
                        </div>
                        
                        <div class="form-row">
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
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-calculator"></i>
                                    Amount Payable (₵)
                                </label>
                                <input type="text" id="amountPayable" class="form-control" readonly 
                                       style="background: #f8fafc; font-weight: bold; color: #2d3748;">
                                <div class="form-help">Auto-calculated: Old Bill + Arrears + Current Bill - Previous Payments</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="form-card">
                    <div class="form-actions">
                        <a href="index.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i>
                            Cancel
                        </a>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save"></i>
                            <span class="icon-save" style="display: none;"></span>
                            Register Business
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Business fee data for JavaScript
        const businessFees = <?php echo $businessTypesJson; ?>;
        const subZones = <?php echo json_encode($subZones); ?>;
        
        // Business types and categories data for type-ahead
        const businessTypes = <?php echo json_encode($business_types); ?>;

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
            
            // Initialize form
            initializeForm();
        });

        // Initialize form functionality
        function initializeForm() {
            // Initialize type-ahead for business type
            const businessTypeInput = document.getElementById('businessTypeInput');
            const businessTypeDropdown = document.getElementById('businessTypeDropdown');
            const businessTypeHidden = document.getElementById('businessTypeHidden');
            const businessCategorySelect = document.getElementById('businessCategory');
            const feeDisplay = document.getElementById('feeDisplay');
            
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
                    updateCategories(selectedType);
                    
                    // Hide fee display initially
                    feeDisplay.style.display = 'none';
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
            
            // Set up category change handler
            businessCategorySelect.addEventListener('change', updateCurrentBill);
            
            // Set up zone change handler
            document.getElementById('zoneSelect').addEventListener('change', updateSubZones);
            
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
            
            // Initialize calculations
            calculateAmountPayable();
            
            // Restore form state if needed
            const savedBusinessType = '<?php echo htmlspecialchars($formData['business_type']); ?>';
            if (savedBusinessType) {
                businessTypeInput.value = savedBusinessType;
                businessTypeHidden.value = savedBusinessType;
                updateCategories(savedBusinessType);
                
                setTimeout(() => {
                    const savedCategory = '<?php echo htmlspecialchars($formData['category']); ?>';
                    if (savedCategory) {
                        businessCategorySelect.value = savedCategory;
                        updateCurrentBill();
                    }
                }, 100);
            }
            
            if (document.getElementById('zoneSelect').value) {
                updateSubZones();
            }
        }

        // Update categories based on selected business type
        function updateCategories(selectedType) {
            const categorySelect = document.getElementById('businessCategory');
            
            // Clear existing options
            categorySelect.innerHTML = '<option value="">Select Category</option>';
            
            if (selectedType && businessTypes[selectedType]) {
                businessTypes[selectedType].forEach(function(category) {
                    const option = document.createElement('option');
                    option.value = category.category;
                    option.textContent = category.category;
                    option.dataset.feeAmount = category.fee_amount;
                    categorySelect.appendChild(option);
                });
            }
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

        // Update current bill based on selected category
        function updateCurrentBill() {
            const businessType = document.getElementById('businessTypeHidden').value;
            const category = document.getElementById('businessCategory').value;
            const currentBillInput = document.getElementById('currentBill');
            const feeDisplay = document.getElementById('feeDisplay');
            const feeAmount = document.getElementById('feeAmount');
            const feeDescription = document.getElementById('feeDescription');
            
            if (businessType && category) {
                // Find the fee for this combination
                const fee = businessFees.find(f => f.business_type === businessType && f.category === category);
                
                if (fee) {
                    const amount = parseFloat(fee.fee_amount);
                    currentBillInput.value = amount.toFixed(2);
                    
                    // Show fee display
                    feeDisplay.style.display = 'block';
                    feeAmount.textContent = `₵ ${amount.toLocaleString('en-US', {minimumFractionDigits: 2})}`;
                    feeDescription.textContent = `Annual fee for ${businessType} - ${category}`;
                } else {
                    currentBillInput.value = '0.00';
                    feeDisplay.style.display = 'none';
                }
            } else {
                currentBillInput.value = '0.00';
                feeDisplay.style.display = 'none';
            }
            
            // Recalculate amount payable
            calculateAmountPayable();
        }

        // Update sub-zones based on selected zone
        function updateSubZones() {
            const zoneId = parseInt(document.getElementById('zoneSelect').value);
            const subZoneSelect = document.getElementById('subZoneSelect');
            
            // Clear existing options
            subZoneSelect.innerHTML = '<option value="">Select Sub-Zone</option>';
            
            if (zoneId) {
                // Filter sub-zones by zone
                const zoneSubZones = subZones.filter(sz => parseInt(sz.zone_id) === zoneId);
                
                zoneSubZones.forEach(function(subZone) {
                    const option = document.createElement('option');
                    option.value = subZone.sub_zone_id;
                    option.textContent = subZone.sub_zone_name;
                    subZoneSelect.appendChild(option);
                });
                
                // Restore selected sub-zone if needed
                const currentSubZone = '<?php echo $formData['sub_zone_id']; ?>';
                if (currentSubZone) {
                    subZoneSelect.value = currentSubZone;
                }
            }
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
                    
                    const exactLocationField = document.getElementById('exactLocation');
                    
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
                    const exactLocationField = document.getElementById('exactLocation');
                    
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

        // Mobile responsiveness
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth <= 768) {
                sidebar.classList.remove('hidden');
            }
        });

        // Session timeout check
        let lastActivity = <?php echo $_SESSION['LAST_ACTIVITY']; ?>;
        const SESSION_TIMEOUT = 1800; // 30 minutes in seconds

        function checkSessionTimeout() {
            const currentTime = Math.floor(Date.now() / 1000);
            if (currentTime - lastActivity > SESSION_TIMEOUT) {
                window.location.href = '../../index.php';
            }
        }

        // Check session every minute
        setInterval(checkSessionTimeout, 60000);
    </script>
</body>
</html>