<?php
/**
 * Data Collector - Property Locations Map
 * map/properties.php
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

// Get the user ID safely - handle both possible key names
$userId = $currentUser['user_id'] ?? $currentUser['id'] ?? null;

if (!$userId) {
    setFlashMessage('error', 'User session invalid.');
    header('Location: ../../auth/login.php');
    exit();
}

$userDisplayName = getUserDisplayName($currentUser);

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
        $params[] = $userId; // FIXED: Using $userId instead of $currentUser['id']
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
    ", [$userId]); // FIXED: Using $userId instead of $currentUser['id']
    
} catch (Exception $e) {
    $properties = [];
    $zones = [];
    $structures = [];
    $stats = ['total_with_coordinates' => 0, 'residential_count' => 0, 'commercial_count' => 0, 'defaulters_count' => 0, 'my_count' => 0];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Property Locations Map - Data Collector - <?php echo APP_NAME; ?></title>
    
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
            background: #e6fffa;
            border-left: 4px solid #38a169;
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
        
        /* Property Sidebar for Mobile */
        .property-sidebar-mobile {
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
        
        .property-sidebar-mobile.show {
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
            
            .property-sidebar-mobile {
                display: block;
            }
            
            /* Hide desktop property sidebar on mobile */
            #propertySidebar {
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
                        <a href="businesses.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-map-marked-alt"></i>
                                <span class="icon-map"></span>
                            </span>
                            Business Locations
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="properties.php" class="nav-link active">
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
            <?php if (empty($properties)): ?>
                <div class="empty-state">
                    <i class="fas fa-map"></i>
                    <span class="icon-map"></span>
                    <h3>No Property Locations</h3>
                    <p>No properties with GPS coordinates found. Register properties with location data to see them on the map.</p>
                    <a href="../properties/add.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i>
                        <span class="icon-plus"></span>
                        Register Property
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
                    <button class="map-control d-md-none" onclick="togglePropertySidebar()" title="Properties & Filters">
                        <i class="fas fa-list"></i>
                        <span class="icon-list"></span>
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
                
                <!-- Desktop Property Sidebar -->
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
                        
                        <!-- Property List -->
                        <div class="property-list" id="propertyList">
                            <?php if (empty($properties)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <span class="icon-map-marker-alt"></span>
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
                
                <!-- Mobile Property Sidebar -->
                <div class="property-sidebar-mobile" id="propertySidebarMobile">
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
                                        <input type="checkbox" name="my_properties" id="myPropertiesMobile" 
                                               <?php echo $my_properties_only ? 'checked' : ''; ?>>
                                        <label for="myPropertiesMobile" class="form-label" style="margin: 0;">Show only my registrations</label>
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
                        
                        <!-- Property List -->
                        <div class="property-list">
                            <?php if (empty($properties)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <span class="icon-map-marker-alt"></span>
                                    <h4>No Properties Found</h4>
                                    <p>No properties with GPS coordinates match your filters.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($properties as $property): ?>
                                    <div class="property-item" onclick="focusOnProperty(<?php echo $property['property_id']; ?>); closeAllSidebars();" 
                                         id="property-mobile-<?php echo $property['property_id']; ?>">
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
            const propertySidebar = document.getElementById('propertySidebarMobile');
            
            // Close property sidebar if open
            if (propertySidebar && propertySidebar.classList.contains('show')) {
                propertySidebar.classList.remove('show');
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

        function togglePropertySidebar() {
            const propertySidebar = document.getElementById('propertySidebarMobile');
            const overlay = document.getElementById('mobileOverlay');
            const mainSidebar = document.getElementById('sidebar');
            
            // Close main sidebar if open
            if (mainSidebar && mainSidebar.classList.contains('show')) {
                mainSidebar.classList.remove('show');
                mainSidebar.classList.add('hidden');
            }
            
            // Toggle property sidebar
            propertySidebar.classList.toggle('show');
            
            // Show/hide overlay
            if (propertySidebar.classList.contains('show')) {
                overlay.classList.add('show');
            } else {
                overlay.classList.remove('show');
            }
        }

        function closeAllSidebars() {
            const sidebar = document.getElementById('sidebar');
            const propertySidebar = document.getElementById('propertySidebarMobile');
            const overlay = document.getElementById('mobileOverlay');
            
            if (sidebar) {
                sidebar.classList.remove('show');
                sidebar.classList.add('hidden');
            }
            
            if (propertySidebar) {
                propertySidebar.classList.remove('show');
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
        
        // Property data
        const properties = <?php echo json_encode($properties); ?>;
        
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
        }
        
        // Add markers to map
        function addMarkers() {
            properties.forEach(property => {
                const position = {
                    lat: parseFloat(property.latitude),
                    lng: parseFloat(property.longitude)
                };
                
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
                    
                    // Highlight property in list
                    highlightProperty(property.property_id);
                });
                
                markers.push(marker);
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
            
            // Add highlight to selected property (both desktop and mobile)
            const desktopItem = document.getElementById(`property-${propertyId}`);
            const mobileItem = document.getElementById(`property-mobile-${propertyId}`);
            
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
            <?php if (!empty($properties)): ?>
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