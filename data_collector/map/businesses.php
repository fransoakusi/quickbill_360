<?php
/**
 * Data Collector - Business Locations Map
 * map/businesses.php
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

// Get the user ID safely - handle both possible key names
$userId = $currentUser['user_id'] ?? $currentUser['id'] ?? null;

if (!$userId) {
    setFlashMessage('error', 'User session invalid.');
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
    
    if ($my_businesses_only) {
        $where_conditions[] = "b.created_by = ?";
        $params[] = $userId; // FIXED: Using $userId instead of $currentUser['user_id']
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
    
    // Get summary stats - Fixed with COALESCE to handle null values
    $stats = $db->fetchRow("
        SELECT 
            COALESCE(COUNT(*), 0) as total_with_coordinates,
            COALESCE(SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END), 0) as active_count,
            COALESCE(SUM(CASE WHEN amount_payable > 0 THEN 1 ELSE 0 END), 0) as defaulters_count,
            COALESCE(SUM(CASE WHEN created_by = ? THEN 1 ELSE 0 END), 0) as my_count
        FROM businesses 
        WHERE latitude IS NOT NULL AND longitude IS NOT NULL AND latitude != 0 AND longitude != 0
    ", [$userId]); // FIXED: Using $userId instead of $currentUser['user_id']
    
    // Additional safety check - ensure all stats values are integers
    $stats = [
        'total_with_coordinates' => (int)($stats['total_with_coordinates'] ?? 0),
        'active_count' => (int)($stats['active_count'] ?? 0),
        'defaulters_count' => (int)($stats['defaulters_count'] ?? 0),
        'my_count' => (int)($stats['my_count'] ?? 0)
    ];
    
} catch (Exception $e) {
    $businesses = [];
    $zones = [];
    $business_types = [];
    // Ensure all stats values are integers, not null
    $stats = [
        'total_with_coordinates' => 0, 
        'active_count' => 0, 
        'defaulters_count' => 0, 
        'my_count' => 0
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Business Locations Map - Data Collector - <?php echo APP_NAME; ?></title>
    
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
        [class*="icon-"] {
            display: none; /* Hidden by default */
        }
        
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
        .icon-filter::before { content: "🔍"; }
        .icon-times::before { content: "❌"; }
        .icon-layer-group::before { content: "🗺️"; }
        .icon-crosshairs::before { content: "🎯"; }
        .icon-map-marker-alt::before { content: "📍"; }
        .icon-list::before { content: "📋"; }
        
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
            border-color: #38a169;
            box-shadow: 0 0 0 3px rgba(56, 161, 105, 0.1);
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
            background: #38a169;
            color: white;
            width: 100%;
        }
        
        .btn-primary:hover {
            background: #2f855a;
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
            background: #e6fffa;
            border-left: 4px solid #38a169;
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
            background: #38a169;
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
        
        /* Mobile Overlay */
        .mobile-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 998;
        }
        
        .mobile-overlay.show {
            display: block;
        }
        
        /* Business Sidebar for Mobile */
        .business-sidebar-mobile {
            display: none;
            position: fixed;
            top: 80px;
            right: 0;
            width: 300px;
            height: calc(100vh - 80px);
            background: white;
            z-index: 999;
            transform: translateX(100%);
            transition: transform 0.3s ease;
            overflow-y: auto;
            box-shadow: -2px 0 10px rgba(0,0,0,0.1);
        }
        
        .business-sidebar-mobile.show {
            transform: translateX(0);
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
                flex-direction: column;
                gap: 5px;
            }
            
            .map-control {
                font-size: 14px;
                padding: 12px;
                min-width: 44px;
                min-height: 44px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .map-legend {
                bottom: 10px;
                left: 10px;
                right: 10px;
                font-size: 12px;
            }
            
            .legend-item {
                margin-bottom: 4px;
            }
            
            .legend-text {
                font-size: 11px;
            }
            
            .business-sidebar-mobile {
                display: block;
            }
            
            /* Hide desktop business sidebar on mobile */
            #businessSidebar {
                display: none;
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
    </style>
</head>
<body>
    <!-- Mobile Overlay -->
    <div class="mobile-overlay" id="mobileOverlay" onclick="closeAllSidebars()"></div>

    <!-- Top Navigation -->
    <div class="top-nav">
        <div class="nav-left">
            <button class="toggle-btn" onclick="toggleMainSidebar()" id="toggleBtn">
                <i class="fas fa-bars"></i>
                <span class="icon-menu"></span>
            </button>
            
            <a href="../index.php" class="brand">
                <i class="fas fa-clipboard-list"></i>
                <span class="icon-dashboard"></span>
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
                            <span class="icon-user"></span>
                            My Profile
                        </a>
                        <a href="#" class="dropdown-item" onclick="alert('Help documentation coming soon!')">
                            <i class="fas fa-question-circle"></i>
                            <span class="icon-question"></span>
                            Help & Support
                        </a>
                        <div style="height: 1px; background: #e2e8f0; margin: 10px 0;"></div>
                        <a href="../../auth/logout.php" class="dropdown-item logout">
                            <i class="fas fa-sign-out-alt"></i>
                            <span class="icon-logout"></span>
                            Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- Main Navigation Sidebar -->
        <div class="sidebar hidden" id="sidebar">
            <div class="sidebar-content">
                <!-- Dashboard -->
                <div class="nav-section">
                    <div class="nav-item">
                        <a href="../index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-tachometer-alt"></i>
                                <span class="icon-dashboard"></span>
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
                                <span class="icon-building"></span>
                            </span>
                            Businesses
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../properties/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-home"></i>
                                <span class="icon-home"></span>
                            </span>
                            Properties
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
                                <span class="icon-map"></span>
                            </span>
                            Business Locations
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="properties.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-map-marker-alt"></i>
                                <span class="icon-location"></span>
                            </span>
                            Property Locations
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
                    <span class="icon-map"></span>
                    <h3>No Business Locations</h3>
                    <p>No businesses with GPS coordinates found. Register businesses with location data to see them on the map.</p>
                    <a href="../businesses/add.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i>
                        <span class="icon-plus"></span>
                        Register Business
                    </a>
                </div>
            <?php else: ?>
                <div id="map"></div>
                
                <!-- Map Controls -->
                <div class="map-controls">
                    <button class="map-control" onclick="toggleMapType()" title="Toggle Map Type">
                        <i class="fas fa-layer-group"></i>
                        <span class="icon-layer-group"></span>
                    </button>
                    <button class="map-control" onclick="centerMap()" title="Center Map">
                        <i class="fas fa-crosshairs"></i>
                        <span class="icon-crosshairs"></span>
                    </button>
                    <button class="map-control d-md-none" onclick="toggleBusinessSidebar()" title="Businesses & Filters">
                        <i class="fas fa-list"></i>
                        <span class="icon-list"></span>
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
                
                <!-- Desktop Business Sidebar -->
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
                                        <span class="icon-filter"></span>
                                        Apply Filters
                                    </button>
                                </div>
                                
                                <div class="form-group">
                                    <a href="?" class="btn btn-secondary">
                                        <i class="fas fa-times"></i>
                                        <span class="icon-times"></span>
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
                                    <span class="icon-map-marker-alt"></span>
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
                
                <!-- Mobile Business Sidebar -->
                <div class="business-sidebar-mobile" id="businessSidebarMobile">
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
                                        <input type="checkbox" name="my_businesses" id="myBusinessesMobile" 
                                               <?php echo $my_businesses_only ? 'checked' : ''; ?>>
                                        <label for="myBusinessesMobile" class="form-label" style="margin: 0;">Show only my registrations</label>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-filter"></i>
                                        <span class="icon-filter"></span>
                                        Apply Filters
                                    </button>
                                </div>
                                
                                <div class="form-group">
                                    <a href="?" class="btn btn-secondary">
                                        <i class="fas fa-times"></i>
                                        <span class="icon-times"></span>
                                        Clear Filters
                                    </a>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Business List -->
                        <div class="business-list">
                            <?php if (empty($businesses)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <span class="icon-map-marker-alt"></span>
                                    <h4>No Businesses Found</h4>
                                    <p>No businesses with GPS coordinates match your filters.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($businesses as $business): ?>
                                    <div class="business-item" onclick="focusOnBusiness(<?php echo $business['business_id']; ?>); closeAllSidebars();" 
                                         id="business-mobile-<?php echo $business['business_id']; ?>">
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
        // Check if Font Awesome loaded, if not show emoji icons
        document.addEventListener('DOMContentLoaded', function() {
            // First, hide all emoji icons by default
            document.querySelectorAll('[class*="icon-"]').forEach(function(emoji) {
                emoji.style.display = 'none';
            });
            
            // Then check if Font Awesome loaded
            setTimeout(function() {
                let fontAwesomeLoaded = false;
                
                // Check if Font Awesome CSS is loaded
                const stylesheets = document.styleSheets;
                for (let i = 0; i < stylesheets.length; i++) {
                    try {
                        const href = stylesheets[i].href;
                        if (href && (href.includes('font-awesome') || href.includes('fontawesome'))) {
                            fontAwesomeLoaded = true;
                            break;
                        }
                    } catch (e) {
                        // Cross-origin stylesheet, skip
                    }
                }
                
                // Also check if icons render properly
                const testIcon = document.querySelector('.fas.fa-bars');
                if (testIcon) {
                    const iconStyle = getComputedStyle(testIcon, ':before');
                    if (iconStyle.content === 'none' || iconStyle.content === '""') {
                        fontAwesomeLoaded = false;
                    }
                }
                
                if (!fontAwesomeLoaded) {
                    // Hide Font Awesome icons and show emoji fallbacks
                    document.querySelectorAll('.fas, .far').forEach(function(icon) {
                        icon.style.display = 'none';
                    });
                    document.querySelectorAll('[class*="icon-"]').forEach(function(emoji) {
                        emoji.style.display = 'inline';
                    });
                }
            }, 200);
        });

        // Simple toggle functions
        function toggleMainSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('mobileOverlay');
            const businessSidebar = document.getElementById('businessSidebarMobile');
            
            // Close business sidebar if open
            if (businessSidebar && businessSidebar.classList.contains('show')) {
                businessSidebar.classList.remove('show');
            }
            
            // Toggle main sidebar
            sidebar.classList.toggle('show');
            sidebar.classList.toggle('hidden');
            
            // Show/hide overlay
            if (sidebar.classList.contains('show')) {
                overlay.classList.add('show');
            } else {
                overlay.classList.remove('show');
            }
        }

        function toggleBusinessSidebar() {
            const businessSidebar = document.getElementById('businessSidebarMobile');
            const overlay = document.getElementById('mobileOverlay');
            const mainSidebar = document.getElementById('sidebar');
            
            // Close main sidebar if open
            if (mainSidebar && mainSidebar.classList.contains('show')) {
                mainSidebar.classList.remove('show');
                mainSidebar.classList.add('hidden');
            }
            
            // Toggle business sidebar
            businessSidebar.classList.toggle('show');
            
            // Show/hide overlay
            if (businessSidebar.classList.contains('show')) {
                overlay.classList.add('show');
            } else {
                overlay.classList.remove('show');
            }
        }

        function closeAllSidebars() {
            const sidebar = document.getElementById('sidebar');
            const businessSidebar = document.getElementById('businessSidebarMobile');
            const overlay = document.getElementById('mobileOverlay');
            
            if (sidebar) {
                sidebar.classList.remove('show');
                sidebar.classList.add('hidden');
            }
            
            if (businessSidebar) {
                businessSidebar.classList.remove('show');
            }
            
            overlay.classList.remove('show');
        }

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

        // Map initialization and functions
        let map;
        let markers = [];
        let infoWindow;
        let currentMapType = 'roadmap';
        
        // Business data
        const businesses = <?php echo json_encode($businesses); ?>;
        
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
        }
        
        // Add markers to map
        function addMarkers() {
            businesses.forEach(business => {
                const position = {
                    lat: parseFloat(business.latitude),
                    lng: parseFloat(business.longitude)
                };
                
                // Determine marker color based on status and payment
                let markerColor = 'red'; // Default for defaulters
                if (business.payment_status === 'Up to Date' && business.status === 'Active') {
                    markerColor = 'green';
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
                               style="background: #38a169; color: white; padding: 6px 12px; 
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
            
            // Add highlight to selected business (both desktop and mobile)
            const desktopItem = document.getElementById(`business-${businessId}`);
            const mobileItem = document.getElementById(`business-mobile-${businessId}`);
            
            if (desktopItem) {
                desktopItem.classList.add('active');
                desktopItem.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            
            if (mobileItem) {
                mobileItem.classList.add('active');
            }
        }
        
        // Toggle map type
        function toggleMapType() {
            currentMapType = currentMapType === 'roadmap' ? 'satellite' : 'roadmap';
            map.setMapTypeId(currentMapType);
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
            }
        }
        
        // Initialize map when page loads
        window.onload = function() {
            <?php if (!empty($businesses)): ?>
            initMap();
            <?php endif; ?>
        };
        
        // Handle responsive behavior
        window.addEventListener('resize', function() {
            closeAllSidebars();
        });
    </script>
</body>
</html>