<?php
/**
 * Officer - Edit Property
 * properties/edit.php
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

// Get property ID
$property_id = intval($_GET['id'] ?? 0);

if ($property_id <= 0) {
    setFlashMessage('error', 'Invalid property ID.');
    header('Location: index.php');
    exit();
}

try {
    $db = new Database();
    
    // Get property details
    $property = $db->fetchRow("
        SELECT * FROM properties WHERE property_id = ?
    ", [$property_id]);
    
    if (!$property) {
        setFlashMessage('error', 'Property not found.');
        header('Location: index.php');
        exit();
    }
    
    // Get zones and sub-zones for dropdowns
    $zones = $db->fetchAll("SELECT zone_id, zone_name FROM zones ORDER BY zone_name");
    $subZones = $db->fetchAll("SELECT sub_zone_id, sub_zone_name, zone_id FROM sub_zones ORDER BY sub_zone_name");
    
    // Get property structures
    $structures = $db->fetchAll("SELECT DISTINCT structure FROM property_fee_structure ORDER BY structure");
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Validate CSRF token
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            setFlashMessage('error', 'Invalid security token. Please try again.');
        } else {
            // Get form data
            $owner_name = trim($_POST['owner_name'] ?? '');
            $telephone = trim($_POST['telephone'] ?? '');
            $gender = $_POST['gender'] ?? '';
            $location = trim($_POST['location'] ?? '');
            $latitude = !empty($_POST['latitude']) ? floatval($_POST['latitude']) : null;
            $longitude = !empty($_POST['longitude']) ? floatval($_POST['longitude']) : null;
            $structure = trim($_POST['structure'] ?? '');
            $ownership_type = $_POST['ownership_type'] ?? 'Self';
            $property_type = $_POST['property_type'] ?? 'Modern';
            $number_of_rooms = intval($_POST['number_of_rooms'] ?? 1);
            $property_use = $_POST['property_use'] ?? 'Residential';
            $zone_id = !empty($_POST['zone_id']) ? intval($_POST['zone_id']) : null;
            $sub_zone_id = !empty($_POST['sub_zone_id']) ? intval($_POST['sub_zone_id']) : null;
            $batch = trim($_POST['batch'] ?? '');
            
            // Validation
            $errors = [];
            
            if (empty($owner_name)) {
                $errors[] = 'Owner name is required.';
            }
            
            if (empty($structure)) {
                $errors[] = 'Structure is required.';
            }
            
            if ($number_of_rooms < 1 || $number_of_rooms > 100) {
                $errors[] = 'Number of rooms must be between 1 and 100.';
            }
            
            if (!empty($telephone) && !preg_match('/^[\+]?[\d\s\-\(\)]+$/', $telephone)) {
                $errors[] = 'Invalid telephone number format.';
            }
            
            // Enhanced coordinate validation
            if (!empty($_POST['latitude']) && (!is_numeric($_POST['latitude']) || floatval($_POST['latitude']) < -90 || floatval($_POST['latitude']) > 90)) {
                $errors[] = 'Latitude must be a valid number between -90 and 90.';
            }
            
            if (!empty($_POST['longitude']) && (!is_numeric($_POST['longitude']) || floatval($_POST['longitude']) < -180 || floatval($_POST['longitude']) > 180)) {
                $errors[] = 'Longitude must be a valid number between -180 and 180.';
            }
            
            if (empty($errors)) {
                try {
                    // Update property
                    $db->execute("
                        UPDATE properties 
                        SET owner_name = ?, telephone = ?, gender = ?, location = ?, 
                            latitude = ?, longitude = ?, structure = ?, ownership_type = ?, 
                            property_type = ?, number_of_rooms = ?, property_use = ?, 
                            zone_id = ?, sub_zone_id = ?, batch = ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE property_id = ?
                    ", [
                        $owner_name, $telephone, $gender, $location,
                        $latitude, $longitude, $structure, $ownership_type,
                        $property_type, $number_of_rooms, $property_use,
                        $zone_id, $sub_zone_id, $batch, $property_id
                    ]);
                    
                    // Log the update
                    logUserAction('UPDATE_PROPERTY', 'properties', $property_id, 
                        $property, 
                        [
                            'owner_name' => $owner_name,
                            'telephone' => $telephone,
                            'gender' => $gender,
                            'location' => $location,
                            'latitude' => $latitude,
                            'longitude' => $longitude,
                            'structure' => $structure,
                            'ownership_type' => $ownership_type,
                            'property_type' => $property_type,
                            'number_of_rooms' => $number_of_rooms,
                            'property_use' => $property_use,
                            'zone_id' => $zone_id,
                            'sub_zone_id' => $sub_zone_id,
                            'batch' => $batch
                        ]
                    );
                    
                    setFlashMessage('success', 'Property updated successfully.');
                    header('Location: view.php?id=' . $property_id);
                    exit();
                    
                } catch (Exception $e) {
                    $errors[] = 'Database error: ' . $e->getMessage();
                }
            }
            
            if (!empty($errors)) {
                setFlashMessage('error', implode('<br>', $errors));
            }
        }
    }
    
} catch (Exception $e) {
    setFlashMessage('error', 'Database error: ' . $e->getMessage());
    header('Location: index.php');
    exit();
}

// Organize sub-zones by zone for JavaScript
$zones_with_subzones = [];
foreach ($subZones as $subZone) {
    if (!isset($zones_with_subzones[$subZone['zone_id']])) {
        $zones_with_subzones[$subZone['zone_id']] = [];
    }
    $zones_with_subzones[$subZone['zone_id']][] = $subZone;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Property - <?php echo htmlspecialchars($property['owner_name']); ?> - <?php echo APP_NAME; ?></title>
    
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
        .icon-money::before { content: "💰"; }
        .icon-receipt::before { content: "🧾"; }
        .icon-map::before { content: "🗺️"; }
        .icon-menu::before { content: "☰"; }
        .icon-logout::before { content: "🚪"; }
        .icon-plus::before { content: "➕"; }
        .icon-search::before { content: "🔍"; }
        .icon-user::before { content: "👤"; }
        .icon-edit::before { content: "✏️"; }
        .icon-arrow-left::before { content: "⬅️"; }
        .icon-save::before { content: "💾"; }
        .icon-question::before { content: "❓"; }
        .icon-map-marker::before { content: "📍"; }
        .icon-cash::before { content: "💵"; }
        .icon-file-invoice::before { content: "📄"; }
        .icon-location::before { content: "📍"; }
        .icon-male::before { content: "👨"; }
        .icon-female::before { content: "👩"; }
        .icon-bed::before { content: "🛏️"; }
        .icon-satellite-dish::before { content: "📡"; }
        .icon-exclamation-triangle::before { content: "⚠️"; }
        .icon-info-circle::before { content: "ℹ️"; }
        .icon-map-marker-alt::before { content: "📍"; }
        .icon-times::before { content: "❌"; }
        
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
        
        /* Page Header */
        .page-header {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .page-title {
            font-size: 28px;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 8px;
        }
        
        .page-subtitle {
            color: #718096;
            font-size: 16px;
        }
        
        /* Form Container */
        .form-container {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 25px;
        }
        
        .form-section {
            margin-bottom: 30px;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 20px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 10px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 8px;
            display: block;
        }
        
        .form-label.required::after {
            content: ' *';
            color: #e53e3e;
        }
        
        .form-control {
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px 15px;
            font-size: 14px;
            transition: all 0.3s;
            width: 100%;
        }
        
        .form-control:focus {
            border-color: #4299e1;
            box-shadow: 0 0 0 3px rgba(66, 153, 225, 0.1);
            outline: none;
        }
        
        .form-control.error {
            border-color: #e53e3e;
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
        }
        
        .btn-primary {
            background: #4299e1;
            color: white;
        }
        
        .btn-primary:hover {
            background: #3182ce;
            transform: translateY(-2px);
            color: white;
        }
        
        .btn-secondary {
            background: #718096;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #4a5568;
            color: white;
        }
        
        .btn-success {
            background: #38a169;
            color: white;
        }
        
        .btn-success:hover {
            background: #2f855a;
            color: white;
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
        
        /* Map Container */
        .map-container {
            height: 400px;
            border-radius: 10px;
            overflow: hidden;
            margin-top: 15px;
            border: 2px solid #e2e8f0;
        }
        
        .map-controls {
            margin-top: 15px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        /* Form Actions */
        .form-actions {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            border-top: 2px solid #e2e8f0;
            margin-top: 30px;
        }
        
        /* Gender Icons */
        .gender-option {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .gender-option:hover {
            border-color: #4299e1;
            background: #f7fafc;
        }
        
        .gender-option input[type="radio"] {
            display: none;
        }
        
        .gender-option input[type="radio"]:checked + .gender-option {
            border-color: #4299e1;
            background: #ebf8ff;
            color: #4299e1;
        }
        
        .gender-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 10px;
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
                <i class="fas fa-user-tie"></i>
                <span class="icon-user" style="display: none;"></span>
                Officer Portal
            </a>
        </div>
        
        <div class="user-section">
            <div class="user-profile" onclick="toggleUserDropdown()" id="userProfile">
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
                        <a href="../index.php" class="nav-link">
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
                        <a href="../businesses/add.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-plus-circle"></i>
                                <span class="icon-plus" style="display: none;"></span>
                            </span>
                            Register Business
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="add.php" class="nav-link">
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
                
                <!-- Payments & Bills -->
                <div class="nav-section">
                    <div class="nav-title">Payments & Bills</div>
                    <div class="nav-item">
                        <a href="../payments/record.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-cash-register"></i>
                                <span class="icon-cash" style="display: none;"></span>
                            </span>
                            Record Payment
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../payments/search.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-search"></i>
                                <span class="icon-search" style="display: none;"></span>
                            </span>
                            Search Accounts
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
                            Business Map
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../map/properties.php" class="nav-link">
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
            <!-- Page Header -->
            <div class="page-header fade-in">
                <h1 class="page-title">
                    <i class="fas fa-edit text-warning"></i>
                    Edit Property
                </h1>
                <p class="page-subtitle">Update property information and location details</p>
            </div>

            <!-- Flash Messages -->
            <?php if (getFlashMessages()): ?>
                <?php $flash = getFlashMessages(); ?>
                <div class="alert alert-<?php echo $flash['type']; ?> alert-dismissible fade show">
                    <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                    <?php echo $flash['message']; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Edit Form -->
            <form method="POST" class="form-container fade-in" id="propertyForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                
                <!-- Property Information -->
                <div class="form-section">
                    <h3 class="section-title">
                        <i class="fas fa-info-circle"></i>
                        Property Information
                    </h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required">Owner Name</label>
                            <input type="text" class="form-control" name="owner_name" 
                                   value="<?php echo htmlspecialchars($property['owner_name']); ?>" 
                                   required maxlength="100">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Property Number</label>
                            <input type="text" class="form-control" 
                                   value="<?php echo htmlspecialchars($property['property_number']); ?>" 
                                   readonly disabled style="background: #f8f9fa;">
                            <small class="text-muted">Property number cannot be changed</small>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Telephone</label>
                            <input type="tel" class="form-control" name="telephone" 
                                   value="<?php echo htmlspecialchars($property['telephone']); ?>" 
                                   placeholder="e.g., +233 24 123 4567">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Gender</label>
                            <div class="gender-options">
                                <label class="gender-option">
                                    <input type="radio" name="gender" value="Male" 
                                           <?php echo $property['gender'] === 'Male' ? 'checked' : ''; ?>>
                                    <i class="fas fa-mars"></i>
                                    <span class="icon-male" style="display: none;"></span>
                                    Male
                                </label>
                                <label class="gender-option">
                                    <input type="radio" name="gender" value="Female" 
                                           <?php echo $property['gender'] === 'Female' ? 'checked' : ''; ?>>
                                    <i class="fas fa-venus"></i>
                                    <span class="icon-female" style="display: none;"></span>
                                    Female
                                </label>
                                <label class="gender-option">
                                    <input type="radio" name="gender" value="Other" 
                                           <?php echo $property['gender'] === 'Other' ? 'checked' : ''; ?>>
                                    <i class="fas fa-user"></i>
                                    <span class="icon-user" style="display: none;"></span>
                                    Other
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required">Structure</label>
                            <select class="form-control" name="structure" required>
                                <option value="">Select Structure</option>
                                <?php foreach ($structures as $struct): ?>
                                    <option value="<?php echo htmlspecialchars($struct['structure']); ?>"
                                            <?php echo $property['structure'] === $struct['structure'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($struct['structure']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Property Type</label>
                            <select class="form-control" name="property_type">
                                <option value="Modern" <?php echo $property['property_type'] === 'Modern' ? 'selected' : ''; ?>>Modern</option>
                                <option value="Traditional" <?php echo $property['property_type'] === 'Traditional' ? 'selected' : ''; ?>>Traditional</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Ownership Type</label>
                            <select class="form-control" name="ownership_type">
                                <option value="Self" <?php echo $property['ownership_type'] === 'Self' ? 'selected' : ''; ?>>Self</option>
                                <option value="Family" <?php echo $property['ownership_type'] === 'Family' ? 'selected' : ''; ?>>Family</option>
                                <option value="Corporate" <?php echo $property['ownership_type'] === 'Corporate' ? 'selected' : ''; ?>>Corporate</option>
                                <option value="Others" <?php echo $property['ownership_type'] === 'Others' ? 'selected' : ''; ?>>Others</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Property Use</label>
                            <select class="form-control" name="property_use">
                                <option value="Residential" <?php echo $property['property_use'] === 'Residential' ? 'selected' : ''; ?>>Residential</option>
                                <option value="Commercial" <?php echo $property['property_use'] === 'Commercial' ? 'selected' : ''; ?>>Commercial</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required">Number of Rooms</label>
                            <input type="number" class="form-control" name="number_of_rooms" 
                                   value="<?php echo $property['number_of_rooms']; ?>" 
                                   required min="1" max="100">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Batch</label>
                            <input type="text" class="form-control" name="batch" 
                                   value="<?php echo htmlspecialchars($property['batch']); ?>" 
                                   placeholder="Optional batch identifier">
                        </div>
                    </div>
                </div>
                
                <!-- Location Information -->
                <div class="form-section">
                    <h3 class="section-title">
                        <i class="fas fa-map-marker-alt"></i>
                        Location Information
                    </h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Zone</label>
                            <select class="form-control" name="zone_id" id="zoneSelect">
                                <option value="">Select Zone</option>
                                <?php foreach ($zones as $zone): ?>
                                    <option value="<?php echo $zone['zone_id']; ?>"
                                            <?php echo $property['zone_id'] == $zone['zone_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($zone['zone_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Sub-Zone</label>
                            <select class="form-control" name="sub_zone_id" id="subZoneSelect">
                                <option value="">Select Sub-Zone</option>
                                <?php foreach ($subZones as $subZone): ?>
                                    <option value="<?php echo $subZone['sub_zone_id']; ?>" 
                                            data-zone="<?php echo $subZone['zone_id']; ?>"
                                            <?php echo $property['sub_zone_id'] == $subZone['sub_zone_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($subZone['sub_zone_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="location-section">
                        <div class="alert alert-info" style="margin-bottom: 15px;">
                            <i class="fas fa-info-circle"></i>
                            <span class="icon-info-circle" style="display: none;"></span>
                            <strong>GPS Tips:</strong> For best accuracy, ensure GPS is enabled, allow location access when prompted, and capture location while outdoors or near windows.
                        </div>
                        
                        <div class="location-input-group">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label class="form-label">Location Description</label>
                                <textarea class="form-control" name="location" rows="3" 
                                          placeholder="Detailed description of the property location..."><?php echo htmlspecialchars($property['location']); ?></textarea>
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
                                           value="<?php echo $property['latitude']; ?>" 
                                           placeholder="e.g., 5.614818">
                                </div>
                                <div class="coordinate-group">
                                    <div class="coordinate-label">Longitude</div>
                                    <input type="number" name="longitude" id="longitude" class="coordinate-input" 
                                           step="0.000001" min="-180" max="180"
                                           value="<?php echo $property['longitude']; ?>" 
                                           placeholder="e.g., -0.205874">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="map-controls">
                        <button type="button" class="btn btn-secondary" onclick="searchLocation()">
                            <i class="fas fa-search"></i>
                            <span class="icon-search" style="display: none;"></span>
                            Search Location
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="clearLocation()">
                            <i class="fas fa-times"></i>
                            Clear Location
                        </button>
                    </div>
                    
                    <div class="map-container" id="map"></div>
                </div>
                
                <!-- Form Actions -->
                <div class="form-actions">
                    <a href="view.php?id=<?php echo $property_id; ?>" class="btn btn-secondary">
                        <i class="fas fa-times"></i>
                        Cancel
                    </a>
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i>
                        <span class="icon-arrow-left" style="display: none;"></span>
                        Back to List
                    </a>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i>
                        <span class="icon-save" style="display: none;"></span>
                        Update Property
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        let map;
        let marker;
        let geocoder;
        let autocomplete;
        
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
            
            // Initialize map
            initMap();
            
            // Setup zone/sub-zone dependency
            setupZoneSubZone();
            
            // Setup gender radio buttons
            setupGenderRadios();
            
            // Add coordinate validation
            document.getElementById('latitude').addEventListener('input', validateCoordinates);
            document.getElementById('longitude').addEventListener('input', validateCoordinates);
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

        // Initialize map
        function initMap() {
            const lat = <?php echo $property['latitude'] ?: '5.593020'; ?>;
            const lng = <?php echo $property['longitude'] ?: '-0.077100'; ?>;
            
            map = new google.maps.Map(document.getElementById('map'), {
                zoom: 15,
                center: { lat: lat, lng: lng },
                mapTypeId: 'satellite'
            });
            
            geocoder = new google.maps.Geocoder();
            
            // Add existing marker if coordinates exist
            if (lat && lng) {
                addMarker(lat, lng);
            }
            
            // Map click event
            map.addListener('click', function(event) {
                const clickedLat = event.latLng.lat();
                const clickedLng = event.latLng.lng();
                
                addMarker(clickedLat, clickedLng);
                updateCoordinates(clickedLat, clickedLng);
            });
        }
        
        function addMarker(lat, lng) {
            if (marker) {
                marker.setMap(null);
            }
            
            marker = new google.maps.Marker({
                position: { lat: lat, lng: lng },
                map: map,
                draggable: true,
                title: 'Property Location',
                icon: {
                    url: 'https://maps.google.com/mapfiles/ms/icons/blue-dot.png'
                }
            });
            
            marker.addListener('dragend', function() {
                const position = marker.getPosition();
                updateCoordinates(position.lat(), position.lng());
            });
            
            map.setCenter({ lat: lat, lng: lng });
        }
        
        function updateCoordinates(lat, lng) {
            document.getElementById('latitude').value = lat.toFixed(6);
            document.getElementById('longitude').value = lng.toFixed(6);
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
            
            // Update coordinates and map
            addMarker(latitude, longitude);
            updateCoordinates(latitude, longitude);
            
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
                    
                    const locationField = document.querySelector('textarea[name="location"]');
                    
                    if (locationField.value.trim() === '') {
                        locationField.value = locationDescription;
                    } else if (confirm(`Update location with captured GPS location?\n\n${specificLocation}`)) {
                        locationField.value = locationDescription;
                    }
                    
                    // Show success message
                    showLocationSuccess(specificLocation);
                } else {
                    // Fallback: just use coordinates
                    const locationDescription = `GPS Coordinates: ${latitude.toFixed(6)}, ${longitude.toFixed(6)}`;
                    const locationField = document.querySelector('textarea[name="location"]');
                    
                    if (locationField.value.trim() === '') {
                        locationField.value = locationDescription;
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
        
        function searchLocation() {
            const address = prompt('Enter address or place name to search:');
            if (address) {
                geocoder.geocode({ address: address }, function(results, status) {
                    if (status === 'OK') {
                        const location = results[0].geometry.location;
                        const lat = location.lat();
                        const lng = location.lng();
                        
                        addMarker(lat, lng);
                        updateCoordinates(lat, lng);
                    } else {
                        alert('Location not found: ' + status);
                    }
                });
            }
        }
        
        function clearLocation() {
            if (marker) {
                marker.setMap(null);
                marker = null;
            }
            document.getElementById('latitude').value = '';
            document.getElementById('longitude').value = '';
        }
        
        // Setup zone/sub-zone dependency
        function setupZoneSubZone() {
            const zoneSelect = document.getElementById('zoneSelect');
            const subZoneSelect = document.getElementById('subZoneSelect');
            const zonesWithSubzones = <?php echo json_encode($zones_with_subzones); ?>;
            
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
            
            // Trigger zone change to filter sub-zones on page load
            if (zoneSelect.value) {
                zoneSelect.dispatchEvent(new Event('change'));
                
                // Restore selected sub-zone
                setTimeout(() => {
                    const savedSubZone = '<?php echo $property['sub_zone_id'] ?? ''; ?>';
                    if (savedSubZone) {
                        subZoneSelect.value = savedSubZone;
                    }
                }, 100);
            }
        }
        
        // Setup gender radio buttons
        function setupGenderRadios() {
            const genderOptions = document.querySelectorAll('.gender-option');
            genderOptions.forEach(function(option) {
                option.addEventListener('click', function() {
                    const radio = this.querySelector('input[type="radio"]');
                    if (radio) {
                        radio.checked = true;
                        
                        // Update visual states
                        genderOptions.forEach(function(opt) {
                            opt.classList.remove('selected');
                        });
                        this.classList.add('selected');
                    }
                });
            });
        }
        
        // Enhanced form validation before submit
        document.getElementById('propertyForm').addEventListener('submit', function(e) {
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
        
        // Auto-dismiss alerts
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                if (alert.classList.contains('alert-success')) {
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 300);
                }
            });
        }, 5000);
    </script>
</body>
</html>