<?php
/**
 * Data Collector - Property Locations Map
 * map/properties.php - FIXED VERSION
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

// Handle filtering
$zone_filter = $_GET['zone'] ?? '';
$property_use_filter = $_GET['property_use'] ?? '';
$structure_filter = $_GET['structure'] ?? '';
$my_properties_only = isset($_GET['my_properties']) ? 1 : 0;

try {
    $db = new Database();
    
    // Build query conditions
    $where_conditions = ["p.latitude IS NOT NULL", "p.longitude IS NOT NULL", "p.latitude != 0", "p.longitude != 0"];
    $params = [];
    
    if (!empty($zone_filter)) {
        $where_conditions[] = "p.zone_id = ?";
        $params[] = $zone_filter;
    }
    
    if (!empty($property_use_filter)) {
        $where_conditions[] = "p.property_use = ?";
        $params[] = $property_use_filter;
    }
    
    if (!empty($structure_filter)) {
        $where_conditions[] = "p.structure = ?";
        $params[] = $structure_filter;
    }
    
    if ($my_properties_only) {
        $where_conditions[] = "p.created_by = ?";
        $params[] = $currentUser['user_id']; // FIXED: Changed from 'id' to 'user_id'
    }
    
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
    
    // Get properties with coordinates
    $properties = $db->fetchAll("
        SELECT 
            p.property_id,
            p.property_number,
            p.owner_name,
            p.telephone,
            p.location,
            p.latitude,
            p.longitude,
            p.structure,
            p.ownership_type,
            p.property_type,
            p.number_of_rooms,
            p.property_use,
            p.amount_payable,
            z.zone_name,
            CASE 
                WHEN p.amount_payable > 0 THEN 'Defaulter' 
                ELSE 'Up to Date' 
            END as payment_status
        FROM properties p
        LEFT JOIN zones z ON p.zone_id = z.zone_id
        {$where_clause}
        ORDER BY p.owner_name
    ", $params);
    
    // Get filter options
    $zones = $db->fetchAll("SELECT zone_id, zone_name FROM zones ORDER BY zone_name");
    $structures = $db->fetchAll("SELECT DISTINCT structure FROM property_fee_structure WHERE is_active = 1 ORDER BY structure");
    
    // Get summary stats
    $stats = $db->fetchRow("
        SELECT 
            COUNT(*) as total_with_coordinates,
            SUM(CASE WHEN property_use = 'Residential' THEN 1 ELSE 0 END) as residential_count,
            SUM(CASE WHEN property_use = 'Commercial' THEN 1 ELSE 0 END) as commercial_count,
            SUM(CASE WHEN amount_payable > 0 THEN 1 ELSE 0 END) as defaulters_count,
            SUM(CASE WHEN created_by = ? THEN 1 ELSE 0 END) as my_count
        FROM properties 
        WHERE latitude IS NOT NULL AND longitude IS NOT NULL AND latitude != 0 AND longitude != 0
    ", [$currentUser['user_id']]); // FIXED: Changed from 'id' to 'user_id'
    
} catch (Exception $e) {
    $properties = [];
    $zones = [];
    $structures = [];
    $stats = ['total_with_coordinates' => 0, 'residential_count' => 0, 'commercial_count' => 0, 'defaulters_count' => 0, 'my_count' => 0];
    
    // Log the error for debugging
    error_log("Property map error: " . $e->getMessage());
}

// Get current directory and page for active link highlighting
$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pathParts = explode('/', trim($currentPath, '/'));
$currentDir = !empty($pathParts[0]) ? $pathParts[0] : '';
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Property Locations Map - <?php echo APP_NAME; ?></title>
    
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
        .icon-chart::before { content: "📊"; }
        .icon-user::before { content: "👤"; }
        .icon-print::before { content: "🖨️"; }
        .icon-edit::before { content: "✏️"; }
        .icon-eye::before { content: "👁️"; }
        .icon-question::before { content: "❓"; }
        .icon-location::before { content: "📍"; }
        .icon-filter::before { content: "🔍"; }
        .icon-times::before { content: "❌"; }
        .icon-layer-group::before { content: "🗺️"; }
        .icon-crosshairs::before { content: "🎯"; }
        .icon-map-marker-alt::before { content: "📍"; }
        
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
            height: calc(100vh - 80px);
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
        
        /* Stats Section */
        .stats-section {
            padding: 20px;
            border-bottom: 1px solid #e2e8f0;
            background: #f7fafc;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        
        .stat-item {
            text-align: center;
            padding: 10px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .stat-value {
            font-size: 18px;
            font-weight: bold;
            color: #2d3748;
        }
        
        .stat-label {
            font-size: 12px;
            color: #718096;
            margin-top: 2px;
        }
        
        /* Filters Section */
        .filters-section {
            padding: 20px;
            border-bottom: 1px solid #e2e8f0;
            background: white;
        }
        
        .section-title {
            font-size: 14px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 15px;
            text-transform: uppercase;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-label {
            font-weight: 600;
            margin-bottom: 5px;
            color: #2d3748;
            font-size: 12px;
            display: block;
        }
        
        .form-control {
            width: 100%;
            padding: 8px 12px;
            border: 2px solid #e2e8f0;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #4299e1;
            box-shadow: 0 0 0 3px rgba(66, 153, 225, 0.1);
        }
        
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .checkbox-item input[type="checkbox"] {
            margin: 0;
        }
        
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
        }
        
        .btn-primary {
            background: #4299e1;
            color: white;
            width: 100%;
        }
        
        .btn-primary:hover {
            background: #3182ce;
        }
        
        .btn-secondary {
            background: #718096;
            color: white;
            width: 100%;
        }
        
        .btn-secondary:hover {
            background: #4a5568;
        }
        
        /* Property List */
        .property-list {
            flex: 1;
            overflow-y: auto;
            background: white;
        }
        
        .property-item {
            padding: 15px 20px;
            border-bottom: 1px solid #e2e8f0;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .property-item:hover {
            background: #f7fafc;
        }
        
        .property-item.active {
            background: #e6f0fa;
            border-left: 4px solid #4299e1;
        }
        
        .property-name {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 4px;
        }
        
        .property-details {
            font-size: 12px;
            color: #718096;
            margin-bottom: 6px;
        }
        
        .property-status {
            display: flex;
            gap: 6px;
        }
        
        .badge {
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 10px;
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
        
        /* Map Container */
        .map-container {
            flex: 1;
            position: relative;
        }
        
        #map {
            width: 100%;
            height: 100%;
        }
        
        /* Map Controls */
        .map-controls {
            position: absolute;
            top: 20px;
            right: 20px;
            z-index: 100;
            display: flex;
            gap: 10px;
        }
        
        .map-control {
            background: white;
            border: none;
            border-radius: 8px;
            padding: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .map-control:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }
        
        .map-legend {
            position: absolute;
            bottom: 20px;
            left: 20px;
            background: white;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            min-width: 200px;
        }
        
        .legend-title {
            font-weight: 600;
            margin-bottom: 10px;
            color: #2d3748;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 6px;
        }
        
        .legend-marker {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }
        
        .legend-marker.residential {
            background: #4299e1;
        }
        
        .legend-marker.commercial {
            background: #38b2ac;
        }
        
        .legend-marker.defaulter {
            background: #e53e3e;
        }
        
        .legend-text {
            font-size: 12px;
            color: #4a5568;
        }
        
        /* Empty State */
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: #718096;
            text-align: center;
            padding: 40px;
        }
        
        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .empty-state h3 {
            font-size: 24px;
            margin-bottom: 10px;
            color: #2d3748;
        }
        
        .empty-state p {
            font-size: 16px;
            margin-bottom: 20px;
        }
        
        /* Debug Info */
        .debug-info {
            position: fixed;
            top: 100px;
            right: 20px;
            background: rgba(0,0,0,0.8);
            color: white;
            padding: 10px;
            border-radius: 5px;
            font-size: 12px;
            z-index: 1000;
            display: none;
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
            
            .map-container {
                width: 100%;
            }
            
            .map-controls {
                top: 10px;
                right: 10px;
            }
            
            .map-legend {
                bottom: 10px;
                left: 10px;
                right: 10px;
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
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        .pulse {
            animation: pulse 2s infinite;
        }
        
        @keyframes slideIn {
            from { transform: translateX(-20px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        .slide-in {
            animation: slideIn 0.6s ease-out;
        }
    </style>
</head>
<body>
    <!-- Debug Info (only shown in development) -->
    <?php if (isset($_GET['debug'])): ?>
    <div class="debug-info" style="display: block;">
        <strong>Debug Info:</strong><br>
        User ID: <?php echo $currentUser['user_id'] ?? 'Not set'; ?><br>
        Current User Keys: <?php echo implode(', ', array_keys($currentUser)); ?><br>
        Properties Count: <?php echo count($properties); ?><br>
        My Properties Count: <?php echo $stats['my_count'] ?? 0; ?>
    </div>
    <?php endif; ?>

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
                        <a href="../index.php" class="nav-link <?php echo ($currentPage === 'index.php') ? 'active' : ''; ?>">
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
                        <a href="../properties/add.php" class="nav-link">
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
                        <a href="../properties/index.php" class="nav-link">
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


                </div>
                
                <!-- Maps & Locations -->
                <div class="nav-section">
                    <div class="nav-title">Maps & Locations</div>
                    <div class="nav-item">
                        <a href="businesses.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-map-marked-alt"></i>
                                <span class="icon-map" style="display: none;"></span>
                            </span>
                            Business Map
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="properties.php" class="nav-link active">
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
        <div class="map-container fade-in">
            <?php if (empty($properties)): ?>
                <div class="empty-state">
                    <i class="fas fa-map"></i>
                    <span class="icon-map" style="display: none;"></span>
                    <h3>No Property Locations</h3>
                    <p>No properties with GPS coordinates found. Register properties with location data to see them on the map.</p>
                    <a href="../properties/add.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i>
                        <span class="icon-plus" style="display: none;"></span>
                        Register Property
                    </a>
                </div>
            <?php else: ?>
                <div id="map"></div>
                
                <!-- Map Controls -->
                <div class="map-controls">
                    <button class="map-control" onclick="toggleMapType()" title="Toggle Map Type">
                        <i class="fas fa-layer-group"></i>
                        <span class="icon-layer-group" style="display: none;"></span>
                    </button>
                    <button class="map-control" onclick="centerMap()" title="Center Map">
                        <i class="fas fa-crosshairs"></i>
                        <span class="icon-crosshairs" style="display: none;"></span>
                    </button>
                    <button class="map-control d-md-none" onclick="toggleSidebar()" title="Toggle Sidebar">
                        <i class="fas fa-bars"></i>
                        <span class="icon-menu" style="display: none;"></span>
                    </button>
                </div>
                
                <!-- Map Legend -->
                <div class="map-legend">
                    <div class="legend-title">Legend</div>
                    <div class="legend-item">
                        <div class="legend-marker residential"></div>
                        <div class="legend-text">Residential Property</div>
                    </div>
                    <div class="legend-item">
                        <div class="legend-marker commercial"></div>
                        <div class="legend-text">Commercial Property</div>
                    </div>
                    <div class="legend-item">
                        <div class="legend-marker defaulter"></div>
                        <div class="legend-text">Has Outstanding Bill</div>
                    </div>
                </div>
                
                <!-- Property List -->
                <div class="sidebar show d-none d-md-block" id="propertySidebar">
                    <div class="sidebar-content">
                        <!-- Statistics -->
                        <div class="stats-section">
                            <div class="stats-grid">
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo number_format($stats['total_with_coordinates']); ?></div>
                                    <div class="stat-label">Total</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo number_format($stats['residential_count']); ?></div>
                                    <div class="stat-label">Residential</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo number_format($stats['commercial_count']); ?></div>
                                    <div class="stat-label">Commercial</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo number_format($stats['my_count']); ?></div>
                                    <div class="stat-label">My Properties</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Filters -->
                        <div class="filters-section">
                            <h4 class="section-title">Filters</h4>
                            <form method="GET" action="">
                                <div class="form-group">
                                    <label class="form-label">Zone</label>
                                    <select name="zone" class="form-control">
                                        <option value="">All Zones</option>
                                        <?php foreach ($zones as $zone): ?>
                                            <option value="<?php echo $zone['zone_id']; ?>" 
                                                    <?php echo $zone_filter == $zone['zone_id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($zone['zone_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Property Use</label>
                                    <select name="property_use" class="form-control">
                                        <option value="">All Uses</option>
                                        <option value="Residential" <?php echo $property_use_filter == 'Residential' ? 'selected' : ''; ?>>Residential</option>
                                        <option value="Commercial" <?php echo $property_use_filter == 'Commercial' ? 'selected' : ''; ?>>Commercial</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Structure</label>
                                    <select name="structure" class="form-control">
                                        <option value="">All Structures</option>
                                        <?php foreach ($structures as $structure): ?>
                                            <option value="<?php echo htmlspecialchars($structure['structure']); ?>" 
                                                    <?php echo $structure_filter == $structure['structure'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($structure['structure']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <div class="checkbox-item">
                                        <input type="checkbox" name="my_properties" id="myProperties" 
                                               <?php echo $my_properties_only ? 'checked' : ''; ?>>
                                        <label for="myProperties" class="form-label" style="margin: 0;">Show only my registrations</label>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-filter"></i>
                                        <span class="icon-filter" style="display: none;"></span>
                                        Apply Filters
                                    </button>
                                </div>
                                
                                <div class="form-group">
                                    <a href="?" class="btn btn-secondary">
                                        <i class="fas fa-times"></i>
                                        <span class="icon-times" style="display: none;"></span>
                                        Clear Filters
                                    </a>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Property List -->
                        <div class="property-list" id="propertyList">
                            <?php if (empty($properties)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <span class="icon-map-marker-alt" style="display: none;"></span>
                                    <h4>No Properties Found</h4>
                                    <p>No properties with GPS coordinates match your filters.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($properties as $property): ?>
                                    <div class="property-item" onclick="focusOnProperty(<?php echo $property['property_id']; ?>)" 
                                         id="property-<?php echo $property['property_id']; ?>">
                                        <div class="property-name"><?php echo htmlspecialchars($property['owner_name']); ?>'s Property</div>
                                        <div class="property-details">
                                            <?php echo htmlspecialchars($property['property_number']); ?> • 
                                            <?php echo $property['number_of_rooms']; ?> rooms
                                        </div>
                                        <div class="property-details">
                                            <?php echo htmlspecialchars($property['structure']); ?> • 
                                            <?php echo htmlspecialchars($property['zone_name'] ?? 'No Zone'); ?>
                                        </div>
                                        <div class="property-status">
                                            <span class="badge <?php echo $property['property_use'] == 'Residential' ? 'badge-info' : 'badge-teal'; ?>">
                                                <?php echo $property['property_use']; ?>
                                            </span>
                                            <span class="badge <?php echo $property['payment_status'] == 'Up to Date' ? 'badge-success' : 'badge-danger'; ?>">
                                                <?php echo $property['payment_status']; ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Debug logging function
        function debugLog(message) {
            if (<?php echo isset($_GET['debug']) ? 'true' : 'false'; ?>) {
                console.log('Debug:', message);
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
        });

        // Sidebar toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const propertySidebar = document.getElementById('propertySidebar');
            const isMobile = window.innerWidth <= 768;
            
            if (isMobile) {
                sidebar.classList.toggle('show');
                sidebar.classList.toggle('hidden');
                if (propertySidebar) {
                    propertySidebar.classList.toggle('show');
                    propertySidebar.classList.toggle('hidden');
                }
            } else {
                sidebar.classList.toggle('hidden');
                if (propertySidebar) {
                    propertySidebar.classList.toggle('hidden');
                }
            }
            
            const isHidden = sidebar.classList.contains('hidden');
            localStorage.setItem('sidebarHidden', isHidden);
            debugLog('Sidebar toggled. Hidden: ' + isHidden);
        }

        // Restore sidebar state
        document.addEventListener('DOMContentLoaded', function() {
            const sidebarHidden = localStorage.getItem('sidebarHidden');
            const sidebar = document.getElementById('sidebar');
            const propertySidebar = document.getElementById('propertySidebar');
            const isMobile = window.innerWidth <= 768;
            
            if (isMobile) {
                sidebar.classList.add('hidden');
                sidebar.classList.remove('show');
                if (propertySidebar) {
                    propertySidebar.classList.add('hidden');
                    propertySidebar.classList.remove('show');
                }
            } else if (sidebarHidden === 'true') {
                sidebar.classList.add('hidden');
                if (propertySidebar) {
                    propertySidebar.classList.add('hidden');
                }
            }
            debugLog('Sidebar state restored. Hidden: ' + sidebarHidden);
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
            const propertySidebar = document.getElementById('propertySidebar');
            const toggleBtn = document.getElementById('toggleBtn');
            const isMobile = window.innerWidth <= 768;
            
            if (isMobile && !sidebar.contains(event.target) && !propertySidebar?.contains(event.target) && !toggleBtn.contains(event.target)) {
                sidebar.classList.remove('show');
                sidebar.classList.add('hidden');
                if (propertySidebar) {
                    propertySidebar.classList.remove('show');
                    propertySidebar.classList.add('hidden');
                }
                localStorage.setItem('sidebarHidden', true);
            }
        });

        // Map initialization and functions
        let map;
        let markers = [];
        let infoWindow;
        let currentMapType = 'roadmap';
        
        // Property data
        const properties = <?php echo json_encode($properties); ?>;
        debugLog('Loaded properties count: ' + properties.length);
        
        // Initialize map
        function initMap() {
            // Default center (Ghana)
            const defaultCenter = { lat: 5.6037, lng: -0.1870 };
            
            map = new google.maps.Map(document.getElementById('map'), {
                zoom: 10,
                center: defaultCenter,
                mapTypeId: currentMapType
            });
            
            infoWindow = new google.maps.InfoWindow();
            
            // Add markers for each property
            addMarkers();
            
            // Fit map to show all markers
            if (markers.length > 0) {
                const bounds = new google.maps.LatLngBounds();
                markers.forEach(marker => bounds.extend(marker.getPosition()));
                map.fitBounds(bounds);
                
                // Ensure minimum zoom level
                const listener = google.maps.event.addListener(map, "idle", function() {
                    if (map.getZoom() > 16) map.setZoom(16);
                    google.maps.event.removeListener(listener);
                });
            }
            debugLog('Map initialized with ' + markers.length + ' markers');
        }
        
        // Add markers to map
        function addMarkers() {
            properties.forEach((property, index) => {
                const position = {
                    lat: parseFloat(property.latitude),
                    lng: parseFloat(property.longitude)
                };
                
                // Validate coordinates
                if (isNaN(position.lat) || isNaN(position.lng)) {
                    debugLog('Invalid coordinates for property ' + property.property_id + ': ' + property.latitude + ', ' + property.longitude);
                    return;
                }
                
                // Determine marker color based on property use and payment status
                let markerColor = 'blue'; // Default for residential
                if (property.property_use === 'Commercial') {
                    markerColor = 'green';
                }
                if (property.payment_status === 'Defaulter') {
                    markerColor = 'red';
                }
                
                const marker = new google.maps.Marker({
                    position: position,
                    map: map,
                    title: `${property.owner_name}'s Property`,
                    icon: `https://maps.google.com/mapfiles/ms/icons/${markerColor}-dot.png`,
                    propertyId: property.property_id
                });
                
                // Create info window content
                const infoContent = `
                    <div style="padding: 10px; max-width: 250px;">
                        <h6 style="margin: 0 0 8px 0; color: #2d3748; font-weight: bold;">
                            ${property.owner_name}'s Property
                        </h6>
                        <p style="margin: 0 0 5px 0; font-size: 12px; color: #718096;">
                            <strong>Property:</strong> ${property.property_number}
                        </p>
                        <p style="margin: 0 0 5px 0; font-size: 12px; color: #718096;">
                            <strong>Structure:</strong> ${property.structure}
                        </p>
                        <p style="margin: 0 0 5px 0; font-size: 12px; color: #718096;">
                            <strong>Rooms:</strong> ${property.number_of_rooms}
                        </p>
                        <p style="margin: 0 0 8px 0; font-size: 12px; color: #718096;">
                            <strong>Zone:</strong> ${property.zone_name || 'Not assigned'}
                        </p>
                        <div style="display: flex; gap: 6px; margin-bottom: 8px;">
                            <span style="background: ${property.property_use === 'Residential' ? '#bee3f8' : '#b2f5ea'}; 
                                         color: ${property.property_use === 'Residential' ? '#2a4365' : '#234e52'}; 
                                         padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 600;">
                                ${property.property_use}
                            </span>
                            <span style="background: ${property.payment_status === 'Up to Date' ? '#c6f6d5' : '#fed7d7'}; 
                                         color: ${property.payment_status === 'Up to Date' ? '#22543d' : '#742a2a'}; 
                                         padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 600;">
                                ${property.payment_status}
                            </span>
                        </div>
                        <div style="text-align: center;">
                            <a href="../properties/view.php?id=${property.property_id}" 
                               style="background: #4299e1; color: white; padding: 6px 12px; 
                                      border-radius: 6px; text-decoration: none; font-size: 12px; font-weight: 600;">
                                View Details
                            </a>
                        </div>
                    </div>
                `;
                
                // Add click listener
                marker.addListener('click', function() {
                    infoWindow.setContent(infoContent);
                    infoWindow.open(map, marker);
                    
                    // Highlight property in list
                    highlightProperty(property.property_id);
                });
                
                markers.push(marker);
                debugLog('Added marker for property ' + property.property_id + ' at ' + position.lat + ', ' + position.lng);
            });
        }
        
        // Focus on specific property
        function focusOnProperty(propertyId) {
            const property = properties.find(p => p.property_id == propertyId);
            if (property) {
                const marker = markers.find(m => m.propertyId == propertyId);
                if (marker) {
                    map.setCenter(marker.getPosition());
                    map.setZoom(16);
                    google.maps.event.trigger(marker, 'click');
                    debugLog('Focused on property ' + propertyId);
                }
            }
            
            highlightProperty(propertyId);
        }
        
        // Highlight property in list
        function highlightProperty(propertyId) {
            // Remove previous highlights
            document.querySelectorAll('.property-item').forEach(item => {
                item.classList.remove('active');
            });
            
            // Add highlight to selected property
            const propertyItem = document.getElementById(`property-${propertyId}`);
            if (propertyItem) {
                propertyItem.classList.add('active');
                propertyItem.scrollIntoView({ behavior: 'smooth', block: 'center' });
                debugLog('Highlighted property ' + propertyId);
            }
        }
        
        // Toggle map type
        function toggleMapType() {
            currentMapType = currentMapType === 'roadmap' ? 'satellite' : 'roadmap';
            map.setMapTypeId(currentMapType);
            debugLog('Map type changed to: ' + currentMapType);
        }
        
        // Center map on all markers
        function centerMap() {
            if (markers.length > 0) {
                const bounds = new google.maps.LatLngBounds();
                markers.forEach(marker => bounds.extend(marker.getPosition()));
                map.fitBounds(bounds);
                
                const listener = google.maps.event.addListener(map, "idle", function() {
                    if (map.getZoom() > 16) map.setZoom(16);
                    google.maps.event.removeListener(listener);
                });
                debugLog('Map centered on all markers');
            }
        }
        
        // Initialize map when page loads
        window.onload = function() {
            <?php if (!empty($properties)): ?>
            try {
                initMap();
            } catch (error) {
                console.error('Map initialization error:', error);
                debugLog('Map initialization failed: ' + error.message);
            }
            <?php endif; ?>
        };
        
        // Handle responsive sidebar
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            const propertySidebar = document.getElementById('propertySidebar');
            if (window.innerWidth > 768) {
                sidebar.classList.remove('show');
                if (propertySidebar) {
                    propertySidebar.classList.remove('show');
                }
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.altKey) {
                switch(e.key) {
                    case '1':
                        e.preventDefault();
                        window.location.href = '../businesses/add.php';
                        break;
                    case '2':
                        e.preventDefault();
                        window.location.href = '../properties/add.php';
                        break;
                    case '3':
                        e.preventDefault();
                        window.location.href = '../payments/record.php';
                        break;
                    case '4':
                        e.preventDefault();
                        window.location.href = '../billing/generate.php';
                        break;
                    case '5':
                        e.preventDefault();
                        window.location.href = '../payments/search.php';
                        break;
                    case '6':
                        e.preventDefault();
                        window.location.href = 'businesses.php';
                        break;
                }
            }
        });

        // Error handling for map loading
        window.gm_authFailure = function() {
            console.error('Google Maps authentication failed');
            alert('Google Maps failed to load. Please check your API key.');
        };
    </script>
</body>
</html>