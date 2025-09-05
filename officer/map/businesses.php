<?php
/**
 * Data Collector - Business Locations Map
 * map/businesses.php - FIXED VERSION
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
if (!isLoggedIn() || !isset($_SESSION['user_id'])) {
    error_log("Session user_id not set in businesses.php");
    setFlashMessage('error', 'Please log in to continue.');
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

// Check if user is officer or admin
$currentUser = getCurrentUser();
if (empty($currentUser) || !isset($currentUser['user_id'])) { // FIXED: Changed from 'id' to 'user_id'
    error_log("getCurrentUser() failed or returned no user_id: " . json_encode($currentUser));
    setFlashMessage('error', 'User data not found. Please log in again.');
    header('Location: ../../auth/login.php');
    exit();
}
if (!isOfficer() && !isAdmin()) {
    setFlashMessage('error', 'Access denied. Officer privileges required.');
    header('Location: ../../auth/login.php');
    exit();
}

$userDisplayName = getUserDisplayName($currentUser);
$errors = [];
$success = false;

// Handle filtering
$zone_filter = $_GET['zone'] ?? '';
$business_type_filter = $_GET['business_type'] ?? '';
$status_filter = $_GET['status'] ?? '';
$my_businesses_only = isset($_GET['my_businesses']) ? 1 : 0;

try {
    $db = new Database();
    
    // Build query conditions
    $where_conditions = ["b.latitude IS NOT NULL", "b.longitude IS NOT NULL", "b.latitude != 0", "b.longitude != 0"];
    $params = [];
    
    if (!empty($zone_filter)) {
        $where_conditions[] = "b.zone_id = ?";
        $params[] = $zone_filter;
    }
    
    if (!empty($business_type_filter)) {
        $where_conditions[] = "b.business_type = ?";
        $params[] = $business_type_filter;
    }
    
    if (!empty($status_filter)) {
        $where_conditions[] = "b.status = ?";
        $params[] = $status_filter;
    }
    
    if ($my_businesses_only && isset($currentUser['user_id'])) { // FIXED: Changed from 'id' to 'user_id'
        $where_conditions[] = "b.created_by = ?";
        $params[] = $currentUser['user_id']; // FIXED: Changed from 'id' to 'user_id'
    }
    
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
    
    // Get businesses with coordinates
    $businesses = $db->fetchAll("
        SELECT 
            b.business_id,
            b.account_number,
            b.business_name,
            b.owner_name,
            b.business_type,
            b.category,
            b.telephone,
            b.exact_location,
            b.latitude,
            b.longitude,
            b.amount_payable,
            b.status,
            z.zone_name,
            sz.sub_zone_name,
            CASE 
                WHEN b.amount_payable > 0 THEN 'Defaulter' 
                ELSE 'Up to Date' 
            END as payment_status
        FROM businesses b
        LEFT JOIN zones z ON b.zone_id = z.zone_id
        LEFT JOIN sub_zones sz ON b.sub_zone_id = sz.sub_zone_id
        {$where_clause}
        ORDER BY b.business_name
    ", $params);
    
    // Get filter options
    $zones = $db->fetchAll("SELECT zone_id, zone_name FROM zones ORDER BY zone_name");
    $business_types = $db->fetchAll("SELECT DISTINCT business_type FROM businesses ORDER BY business_type");
    
    // Get summary stats
    $stats_params = isset($currentUser['user_id']) ? [$currentUser['user_id']] : [0]; // FIXED: Changed from 'id' to 'user_id'
    $stats = $db->fetchRow("
        SELECT 
            COUNT(*) as total_with_coordinates,
            SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active_count,
            SUM(CASE WHEN amount_payable > 0 THEN 1 ELSE 0 END) as defaulters_count,
            SUM(CASE WHEN created_by = ? THEN 1 ELSE 0 END) as my_count
        FROM businesses 
        WHERE latitude IS NOT NULL AND longitude IS NOT NULL AND latitude != 0 AND longitude != 0
    ", $stats_params);
    
} catch (Exception $e) {
    $businesses = [];
    $zones = [];
    $business_types = [];
    $stats = ['total_with_coordinates' => 0, 'active_count' => 0, 'defaulters_count' => 0, 'my_count' => 0];
    error_log("Error in businesses.php: " . $e->getMessage());
}

// Get current directory and page for active link highlighting
$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pathParts = explode('/', trim($currentPath, '/'));
$currentDir = !empty($pathParts[1]) ? $pathParts[1] : '';
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Business Locations Map - <?php echo APP_NAME; ?></title>
    
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
        
        /* Business List */
        .business-list {
            flex: 1;
            overflow-y: auto;
            background: white;
        }
        
        .business-item {
            padding: 15px 20px;
            border-bottom: 1px solid #e2e8f0;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .business-item:hover {
            background: #f7fafc;
        }
        
        .business-item.active {
            background: #e6f0fa;
            border-left: 4px solid #4299e1;
        }
        
        .business-name {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 4px;
        }
        
        .business-details {
            font-size: 12px;
            color: #718096;
            margin-bottom: 6px;
        }
        
        .business-status {
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
        
        .legend-marker.active {
            background: #4299e1;
        }
        
        .legend-marker.inactive {
            background: #ed8936;
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
        Businesses Count: <?php echo count($businesses); ?><br>
        My Businesses Count: <?php echo $stats['my_count'] ?? 0; ?>
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
                        <a href="../businesses/add.php" class="nav-link <?php echo ($currentDir === 'businesses' && $currentPage === 'add.php') ? 'active' : ''; ?>">
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
                        <a href="../businesses/index.php" class="nav-link <?php echo ($currentDir === 'businesses' && $currentPage === 'index.php') ? 'active' : ''; ?>">
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

                  
                </div>
                
                <!-- Maps & Locations -->
                <div class="nav-section">
                    <div class="nav-title">Maps & Locations</div>
                    <div class="nav-item">
                        <a href="businesses.php" class="nav-link active">
                            <span class="nav-icon">
                                <i class="fas fa-map-marked-alt"></i>
                                <span class="icon-map" style="display: none;"></span>
                            </span>
                            Business Map
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="properties.php" class="nav-link <?php echo ($currentDir === 'map' && $currentPage === 'properties.php') ? 'active' : ''; ?>">
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
            <?php if (empty($businesses)): ?>
                <div class="empty-state">
                    <i class="fas fa-map"></i>
                    <span class="icon-map" style="display: none;"></span>
                    <h3>No Business Locations</h3>
                    <p>No businesses with GPS coordinates found. Register businesses with location data to see them on the map.</p>
                    <a href="../businesses/add.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i>
                        <span class="icon-plus" style="display: none;"></span>
                        Register Business
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
                        <div class="legend-marker active"></div>
                        <div class="legend-text">Active Business</div>
                    </div>
                    <div class="legend-item">
                        <div class="legend-marker inactive"></div>
                        <div class="legend-text">Inactive Business</div>
                    </div>
                    <div class="legend-item">
                        <div class="legend-marker defaulter"></div>
                        <div class="legend-text">Has Outstanding Bill</div>
                    </div>
                </div>
                
                <!-- Business List -->
                <div class="sidebar show d-none d-md-block" id="businessSidebar">
                    <div class="sidebar-content">
                        <!-- Statistics -->
                        <div class="stats-section">
                            <div class="stats-grid">
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo number_format($stats['total_with_coordinates']); ?></div>
                                    <div class="stat-label">Total</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo number_format($stats['active_count']); ?></div>
                                    <div class="stat-label">Active</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo number_format($stats['defaulters_count']); ?></div>
                                    <div class="stat-label">Defaulters</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo number_format($stats['my_count']); ?></div>
                                    <div class="stat-label">My Businesses</div>
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
                                    <label class="form-label">Business Type</label>
                                    <select name="business_type" class="form-control">
                                        <option value="">All Types</option>
                                        <?php foreach ($business_types as $type): ?>
                                            <option value="<?php echo htmlspecialchars($type['business_type']); ?>" 
                                                    <?php echo $business_type_filter == $type['business_type'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($type['business_type']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-control">
                                        <option value="">All Status</option>
                                        <option value="Active" <?php echo $status_filter == 'Active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="Inactive" <?php echo $status_filter == 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                        <option value="Suspended" <?php echo $status_filter == 'Suspended' ? 'selected' : ''; ?>>Suspended</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <div class="checkbox-item">
                                        <input type="checkbox" name="my_businesses" id="myBusinesses" 
                                               <?php echo $my_businesses_only ? 'checked' : ''; ?>>
                                        <label for="myBusinesses" class="form-label" style="margin: 0;">Show only my registrations</label>
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
                        
                        <!-- Business List -->
                        <div class="business-list" id="businessList">
                            <?php if (empty($businesses)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <span class="icon-map-marker-alt" style="display: none;"></span>
                                    <h4>No Businesses Found</h4>
                                    <p>No businesses with GPS coordinates match your filters.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($businesses as $business): ?>
                                    <div class="business-item" onclick="focusOnBusiness(<?php echo $business['business_id']; ?>)" 
                                         id="business-<?php echo $business['business_id']; ?>">
                                        <div class="business-name"><?php echo htmlspecialchars($business['business_name']); ?></div>
                                        <div class="business-details">
                                            <?php echo htmlspecialchars($business['owner_name']); ?> • 
                                            <?php echo htmlspecialchars($business['account_number']); ?>
                                        </div>
                                        <div class="business-details">
                                            <?php echo htmlspecialchars($business['business_type']); ?> • 
                                            <?php echo htmlspecialchars($business['zone_name'] ?? 'No Zone'); ?>
                                        </div>
                                        <div class="business-status">
                                            <span class="badge <?php echo $business['status'] == 'Active' ? 'badge-success' : 'badge-warning'; ?>">
                                                <?php echo $business['status']; ?>
                                            </span>
                                            <span class="badge <?php echo $business['payment_status'] == 'Up to Date' ? 'badge-success' : 'badge-danger'; ?>">
                                                <?php echo $business['payment_status']; ?>
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
            const businessSidebar = document.getElementById('businessSidebar');
            const isMobile = window.innerWidth <= 768;
            
            if (isMobile) {
                sidebar.classList.toggle('show');
                sidebar.classList.toggle('hidden');
                if (businessSidebar) {
                    businessSidebar.classList.toggle('show');
                    businessSidebar.classList.toggle('hidden');
                }
            } else {
                sidebar.classList.toggle('hidden');
                if (businessSidebar) {
                    businessSidebar.classList.toggle('hidden');
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
            const businessSidebar = document.getElementById('businessSidebar');
            const isMobile = window.innerWidth <= 768;
            
            if (isMobile) {
                sidebar.classList.add('hidden');
                sidebar.classList.remove('show');
                if (businessSidebar) {
                    businessSidebar.classList.add('hidden');
                    businessSidebar.classList.remove('show');
                }
            } else if (sidebarHidden === 'true') {
                sidebar.classList.add('hidden');
                if (businessSidebar) {
                    businessSidebar.classList.add('hidden');
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
            const businessSidebar = document.getElementById('businessSidebar');
            const toggleBtn = document.getElementById('toggleBtn');
            const isMobile = window.innerWidth <= 768;
            
            if (isMobile && !sidebar.contains(event.target) && !businessSidebar?.contains(event.target) && !toggleBtn.contains(event.target)) {
                sidebar.classList.remove('show');
                sidebar.classList.add('hidden');
                if (businessSidebar) {
                    businessSidebar.classList.remove('show');
                    businessSidebar.classList.add('hidden');
                }
                localStorage.setItem('sidebarHidden', true);
            }
        });

        // Map initialization and functions
        let map;
        let markers = [];
        let infoWindow;
        let currentMapType = 'roadmap';
        
        // Business data
        const businesses = <?php echo json_encode($businesses); ?>;
        debugLog('Loaded businesses count: ' + businesses.length);
        
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
            
            // Add markers for each business
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
            businesses.forEach((business, index) => {
                const position = {
                    lat: parseFloat(business.latitude),
                    lng: parseFloat(business.longitude)
                };
                
                // Validate coordinates
                if (isNaN(position.lat) || isNaN(position.lng)) {
                    debugLog('Invalid coordinates for business ' + business.business_id + ': ' + business.latitude + ', ' + business.longitude);
                    return;
                }
                
                // Determine marker color based on status and payment
                let markerColor = 'red'; // Default for defaulters
                if (business.payment_status === 'Up to Date' && business.status === 'Active') {
                    markerColor = 'blue';
                } else if (business.status !== 'Active') {
                    markerColor = 'orange';
                }
                
                const marker = new google.maps.Marker({
                    position: position,
                    map: map,
                    title: business.business_name,
                    icon: `https://maps.google.com/mapfiles/ms/icons/${markerColor}-dot.png`,
                    businessId: business.business_id
                });
                
                // Create info window content
                const infoContent = `
                    <div style="padding: 10px; max-width: 250px;">
                        <h6 style="margin: 0 0 8px 0; color: #2d3748; font-weight: bold;">
                            ${business.business_name}
                        </h6>
                        <p style="margin: 0 0 5px 0; font-size: 12px; color: #718096;">
                            <strong>Owner:</strong> ${business.owner_name}
                        </p>
                        <p style="margin: 0 0 5px 0; font-size: 12px; color: #718096;">
                            <strong>Account:</strong> ${business.account_number}
                        </p>
                        <p style="margin: 0 0 5px 0; font-size: 12px; color: #718096;">
                            <strong>Type:</strong> ${business.business_type} - ${business.category}
                        </p>
                        <p style="margin: 0 0 8px 0; font-size: 12px; color: #718096;">
                            <strong>Zone:</strong> ${business.zone_name || 'Not assigned'}
                        </p>
                        <div style="display: flex; gap: 6px; margin-bottom: 8px;">
                            <span style="background: ${business.status === 'Active' ? '#c6f6d5' : '#faf0e6'}; 
                                         color: ${business.status === 'Active' ? '#22543d' : '#c05621'}; 
                                         padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 600;">
                                ${business.status}
                            </span>
                            <span style="background: ${business.payment_status === 'Up to Date' ? '#c6f6d5' : '#fed7d7'}; 
                                         color: ${business.payment_status === 'Up to Date' ? '#22543d' : '#742a2a'}; 
                                         padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 600;">
                                ${business.payment_status}
                            </span>
                        </div>
                        <div style="text-align: center;">
                            <a href="../businesses/view.php?id=${business.business_id}" 
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
                    
                    // Highlight business in list
                    highlightBusiness(business.business_id);
                });
                
                markers.push(marker);
                debugLog('Added marker for business ' + business.business_id + ' at ' + position.lat + ', ' + position.lng);
            });
        }
        
        // Focus on specific business
        function focusOnBusiness(businessId) {
            const business = businesses.find(b => b.business_id == businessId);
            if (business) {
                const marker = markers.find(m => m.businessId == businessId);
                if (marker) {
                    map.setCenter(marker.getPosition());
                    map.setZoom(16);
                    google.maps.event.trigger(marker, 'click');
                    debugLog('Focused on business ' + businessId);
                }
            }
            
            highlightBusiness(businessId);
        }
        
        // Highlight business in list
        function highlightBusiness(businessId) {
            // Remove previous highlights
            document.querySelectorAll('.business-item').forEach(item => {
                item.classList.remove('active');
            });
            
            // Add highlight to selected business
            const businessItem = document.getElementById(`business-${businessId}`);
            if (businessItem) {
                businessItem.classList.add('active');
                businessItem.scrollIntoView({ behavior: 'smooth', block: 'center' });
                debugLog('Highlighted business ' + businessId);
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
            <?php if (!empty($businesses)): ?>
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
            const businessSidebar = document.getElementById('businessSidebar');
            if (window.innerWidth > 768) {
                sidebar.classList.remove('show');
                if (businessSidebar) {
                    businessSidebar.classList.remove('show');
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