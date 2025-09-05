<?php
/**
 * Business Map View for QUICKBILL 305 with List/Map Toggle
 * Display all business locations on Google Maps
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

// Check if user has admin privileges
if (!isAdmin()) {
    setFlashMessage('error', 'Access denied. Admin privileges required.');
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

$currentUser = getCurrentUser();
$userDisplayName = getUserDisplayName($currentUser);

// Get filter parameters (preserve from URL for toggle functionality)
$selectedZone = $_GET['zone'] ?? '';
$selectedSubZone = $_GET['sub_zone'] ?? '';
$selectedStatus = $_GET['status'] ?? '';
$selectedBusinessType = $_GET['business_type'] ?? '';
$search = $_GET['search'] ?? '';
$typeFilter = $_GET['type'] ?? '';

try {
    $db = new Database();
    
    // Get all zones for filter
    $zones = $db->fetchAll("SELECT zone_id, zone_name FROM zones ORDER BY zone_name");
    
    // Get all sub-zones for filter
    $subZones = $db->fetchAll("
        SELECT sz.sub_zone_id, sz.sub_zone_name, sz.zone_id, z.zone_name 
        FROM sub_zones sz 
        LEFT JOIN zones z ON sz.zone_id = z.zone_id 
        ORDER BY z.zone_name, sz.sub_zone_name
    ");
    
    // Get all business types for filter
    $businessTypes = $db->fetchAll("
        SELECT DISTINCT business_type 
        FROM businesses 
        WHERE business_type IS NOT NULL AND business_type != '' 
        ORDER BY business_type
    ");
    
    // Build the query for businesses
    $whereConditions = [];
    $params = [];
    
    // Handle search parameter (from list view)
    if (!empty($search)) {
        $whereConditions[] = "(b.business_name LIKE ? OR b.owner_name LIKE ? OR b.account_number LIKE ? OR b.telephone LIKE ?)";
        $searchTerm = "%$search%";
        $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    }
    
    // Handle type filter (from list view)
    if (!empty($typeFilter)) {
        $whereConditions[] = "b.business_type = ?";
        $params[] = $typeFilter;
    }
    
    if (!empty($selectedZone)) {
        $whereConditions[] = "b.zone_id = ?";
        $params[] = $selectedZone;
    }
    
    if (!empty($selectedSubZone)) {
        $whereConditions[] = "b.sub_zone_id = ?";
        $params[] = $selectedSubZone;
    }
    
    if (!empty($selectedStatus)) {
        $whereConditions[] = "b.status = ?";
        $params[] = $selectedStatus;
    }
    
    if (!empty($selectedBusinessType)) {
        $whereConditions[] = "b.business_type = ?";
        $params[] = $selectedBusinessType;
    }
    
    // Only get businesses with valid coordinates
    $whereConditions[] = "b.latitude IS NOT NULL AND b.longitude IS NOT NULL";
    $whereConditions[] = "b.latitude != 0 AND b.longitude != 0";
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Get businesses with location data
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
            sz.sub_zone_name
        FROM businesses b
        LEFT JOIN zones z ON b.zone_id = z.zone_id
        LEFT JOIN sub_zones sz ON b.sub_zone_id = sz.sub_zone_id
        WHERE $whereClause
        ORDER BY b.business_name
    ", $params);
    
    // Get summary statistics
    $totalBusinesses = count($businesses);
    $totalPayable = array_sum(array_column($businesses, 'amount_payable'));
    $activeBusinesses = count(array_filter($businesses, function($b) { return $b['status'] === 'Active'; }));
    $defaulters = count(array_filter($businesses, function($b) { return $b['amount_payable'] > 0; }));
    
} catch (Exception $e) {
    $businesses = [];
    $zones = [];
    $subZones = [];
    $businessTypes = [];
    $totalBusinesses = 0;
    $totalPayable = 0;
    $activeBusinesses = 0;
    $defaulters = 0;
    error_log("Error fetching business map data: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Business Map - <?php echo APP_NAME; ?></title>
    
    <!-- Include the admin header styles and scripts -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
        .icon-list::before { content: "📋"; }
        .icon-map::before { content: "🗺️"; }
        .icon-menu::before { content: "☰"; }
        .icon-receipt::before { content: "🧾"; }

        /* Top Navigation (same as dashboard) */
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
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .brand:hover {
            color: white;
            text-decoration: none;
        }

        /* View Toggle */
        .view-toggle-container {
            background: rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 4px;
            display: flex;
            gap: 2px;
        }

        .view-toggle-btn {
            background: transparent;
            border: none;
            padding: 8px 14px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 6px;
            color: rgba(255,255,255,0.8);
            font-size: 14px;
        }

        .view-toggle-btn.active {
            background: rgba(255,255,255,0.2);
            color: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .view-toggle-btn:hover:not(.active) {
            background: rgba(255,255,255,0.15);
            color: white;
        }

        .user-section {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            padding: 8px 12px;
            border-radius: 10px;
            transition: all 0.3s;
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

        /* Layout */
        .container-fluid {
            margin-top: 80px;
            padding: 0;
            height: calc(100vh - 80px);
        }

        /* Map Container */
        .map-container {
            height: 100%;
            position: relative;
            display: flex;
        }

        /* Sidebar */
        .map-sidebar {
            width: 350px;
            background: white;
            border-right: 1px solid #e2e8f0;
            overflow-y: auto;
            transition: all 0.3s ease;
        }

        .map-sidebar.collapsed {
            width: 0;
            min-width: 0;
            overflow: hidden;
        }

        .sidebar-header {
            padding: 20px;
            background: #f7fafc;
            border-bottom: 1px solid #e2e8f0;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .sidebar-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Filters */
        .filters {
            padding: 20px;
            border-bottom: 1px solid #e2e8f0;
        }

        .filter-group {
            margin-bottom: 15px;
        }

        .filter-label {
            font-size: 12px;
            font-weight: 600;
            color: #4a5568;
            text-transform: uppercase;
            margin-bottom: 5px;
            display: block;
        }

        .filter-select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .filter-select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .filter-btn {
            width: 100%;
            padding: 10px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .filter-btn:hover {
            background: #5a67d8;
        }

        /* Statistics */
        .map-stats {
            padding: 20px;
            border-bottom: 1px solid #e2e8f0;
        }

        .stat-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .stat-item {
            background: #f7fafc;
            padding: 12px;
            border-radius: 8px;
            text-align: center;
        }

        .stat-value {
            font-size: 18px;
            font-weight: bold;
            color: #2d3748;
        }

        .stat-label {
            font-size: 11px;
            color: #718096;
            text-transform: uppercase;
            margin-top: 2px;
        }

        /* Business List */
        .business-list {
            flex: 1;
            overflow-y: auto;
        }

        .business-item {
            padding: 15px 20px;
            border-bottom: 1px solid #f7fafc;
            cursor: pointer;
            transition: all 0.3s;
        }

        .business-item:hover {
            background: #f7fafc;
        }

        .business-item.active {
            background: #edf2f7;
            border-left: 4px solid #667eea;
        }

        .business-name {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 4px;
        }

        .business-owner {
            font-size: 14px;
            color: #4a5568;
            margin-bottom: 4px;
        }

        .business-details {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 8px;
        }

        .business-type {
            font-size: 12px;
            background: #e2e8f0;
            color: #4a5568;
            padding: 2px 6px;
            border-radius: 10px;
        }

        .business-amount {
            font-weight: 600;
            color: #2d3748;
        }

        .business-amount.defaulter {
            color: #e53e3e;
        }

        .business-status {
            font-size: 12px;
            padding: 2px 6px;
            border-radius: 10px;
            font-weight: 600;
        }

        .business-status.active {
            background: #c6f6d5;
            color: #22543d;
        }

        .business-status.inactive {
            background: #fed7d7;
            color: #742a2a;
        }

        /* Map */
        .map-content {
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
            z-index: 10;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .map-control-btn {
            background: white;
            border: none;
            padding: 12px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            cursor: pointer;
            transition: all 0.3s;
        }

        .map-control-btn:hover {
            background: #f7fafc;
            transform: translateY(-2px);
        }

        .toggle-sidebar-btn {
            position: absolute;
            top: 20px;
            left: 20px;
            z-index: 10;
            background: white;
            border: none;
            padding: 12px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            cursor: pointer;
            transition: all 0.3s;
        }

        .toggle-sidebar-btn:hover {
            background: #f7fafc;
            transform: translateY(-2px);
        }

        /* Loading */
        .loading {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 20px rgba(0,0,0,0.1);
            text-align: center;
        }

        .loading-spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            margin: 0 auto 10px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* No data message */
        .no-data {
            text-align: center;
            padding: 40px;
            color: #718096;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .view-toggle-container {
                display: none; /* Hide on mobile for space */
            }

            .brand {
                font-size: 18px;
            }

            .map-sidebar {
                position: absolute;
                left: 0;
                top: 0;
                height: 100%;
                z-index: 15;
                transform: translateX(-100%);
                width: 300px;
            }

            .map-sidebar.show {
                transform: translateX(0);
            }

            .map-sidebar.collapsed {
                transform: translateX(-100%);
                width: 300px;
            }

            .toggle-sidebar-btn {
                display: block;
            }
        }

        /* Custom scrollbar */
        .map-sidebar::-webkit-scrollbar {
            width: 6px;
        }

        .map-sidebar::-webkit-scrollbar-track {
            background: #f1f1f1;
        }

        .map-sidebar::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 3px;
        }

        .map-sidebar::-webkit-scrollbar-thumb:hover {
            background: #a1a1a1;
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <div class="top-nav">
        <div class="nav-left">
            <a href="../index.php" class="brand">
                <i class="fas fa-building"></i>
                <span>Business Management</span>
            </a>

            <!-- View Toggle Buttons -->
            <div class="view-toggle-container">
                <button class="view-toggle-btn" onclick="switchToListView()">
                    <i class="fas fa-list"></i>
                    <span class="icon-list" style="display: none;"></span>
                    List View
                </button>
                <button class="view-toggle-btn active" onclick="switchToMapView()">
                    <i class="fas fa-map"></i>
                    <span class="icon-map" style="display: none;"></span>
                    Map View
                </button>
            </div>
        </div>

        <div class="user-section">
            <div class="user-profile">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($currentUser['first_name'], 0, 1)); ?>
                </div>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($userDisplayName); ?></div>
                    <div class="user-role"><?php echo htmlspecialchars(getCurrentUserRole()); ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="container-fluid">
        <div class="map-container">
            <!-- Toggle Sidebar Button (Mobile) -->
            <button class="toggle-sidebar-btn" onclick="toggleSidebar()" style="display: none;">
                <i class="fas fa-bars"></i>
                <span class="icon-menu" style="display: none;"></span>
            </button>

            <!-- Sidebar -->
            <div class="map-sidebar" id="mapSidebar">
                <!-- Header -->
                <div class="sidebar-header">
                    <div class="sidebar-title">
                        <i class="fas fa-building"></i>
                        Business Locations
                    </div>
                    
                    <!-- Filters -->
                    <form method="GET" action="" id="filterForm">
                        <!-- Preserve search and type filters from list view -->
                        <?php if (!empty($search)): ?>
                            <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                        <?php endif; ?>
                        <?php if (!empty($typeFilter)): ?>
                            <input type="hidden" name="type" value="<?php echo htmlspecialchars($typeFilter); ?>">
                        <?php endif; ?>

                        <div class="filter-group">
                            <label class="filter-label">Zone</label>
                            <select name="zone" class="filter-select" id="zoneSelect">
                                <option value="">All Zones</option>
                                <?php foreach ($zones as $zone): ?>
                                    <option value="<?php echo $zone['zone_id']; ?>" 
                                            <?php echo $selectedZone == $zone['zone_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($zone['zone_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label class="filter-label">Sub-Zone</label>
                            <select name="sub_zone" class="filter-select" id="subZoneSelect">
                                <option value="">All Sub-Zones</option>
                                <?php foreach ($subZones as $subZone): ?>
                                    <option value="<?php echo $subZone['sub_zone_id']; ?>" 
                                            data-zone="<?php echo $subZone['zone_id']; ?>"
                                            <?php echo $selectedSubZone == $subZone['sub_zone_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($subZone['sub_zone_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label class="filter-label">Business Type</label>
                            <select name="business_type" class="filter-select">
                                <option value="">All Types</option>
                                <?php foreach ($businessTypes as $type): ?>
                                    <option value="<?php echo htmlspecialchars($type['business_type']); ?>" 
                                            <?php echo $selectedBusinessType == $type['business_type'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($type['business_type']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label class="filter-label">Status</label>
                            <select name="status" class="filter-select">
                                <option value="">All Status</option>
                                <option value="Active" <?php echo $selectedStatus == 'Active' ? 'selected' : ''; ?>>Active</option>
                                <option value="Inactive" <?php echo $selectedStatus == 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                <option value="Suspended" <?php echo $selectedStatus == 'Suspended' ? 'selected' : ''; ?>>Suspended</option>
                            </select>
                        </div>

                        <button type="submit" class="filter-btn">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                    </form>
                </div>

                <!-- Statistics -->
                <div class="map-stats">
                    <div class="stat-grid">
                        <div class="stat-item">
                            <div class="stat-value"><?php echo number_format($totalBusinesses); ?></div>
                            <div class="stat-label">Total</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?php echo number_format($activeBusinesses); ?></div>
                            <div class="stat-label">Active</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?php echo number_format($defaulters); ?></div>
                            <div class="stat-label">Defaulters</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value">₵<?php echo number_format($totalPayable, 2); ?></div>
                            <div class="stat-label">Payable</div>
                        </div>
                    </div>
                </div>

                <!-- Business List -->
                <div class="business-list">
                    <?php if (empty($businesses)): ?>
                        <div class="no-data">
                            <i class="fas fa-map-marker-alt" style="font-size: 48px; color: #e2e8f0; margin-bottom: 20px;"></i>
                            <p>No businesses found with the selected filters</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($businesses as $business): ?>
                            <div class="business-item" 
                                 data-lat="<?php echo $business['latitude']; ?>" 
                                 data-lng="<?php echo $business['longitude']; ?>"
                                 data-id="<?php echo $business['business_id']; ?>"
                                 onclick="selectBusiness(this)">
                                
                                <div class="business-name">
                                    <?php echo htmlspecialchars($business['business_name']); ?>
                                </div>
                                
                                <div class="business-owner">
                                    <?php echo htmlspecialchars($business['owner_name']); ?>
                                </div>
                                
                                <div class="business-details">
                                    <div>
                                        <span class="business-type">
                                            <?php echo htmlspecialchars($business['business_type']); ?>
                                        </span>
                                        <span class="business-status <?php echo strtolower($business['status']); ?>">
                                            <?php echo $business['status']; ?>
                                        </span>
                                    </div>
                                    <div class="business-amount <?php echo $business['amount_payable'] > 0 ? 'defaulter' : ''; ?>">
                                        ₵<?php echo number_format($business['amount_payable'], 2); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Map -->
            <div class="map-content">
                <!-- Map Controls -->
                <div class="map-controls">
                    <button class="map-control-btn" onclick="toggleSidebar()" title="Toggle Sidebar">
                        <i class="fas fa-list"></i>
                    </button>
                    <button class="map-control-btn" onclick="centerMap()" title="Center Map">
                        <i class="fas fa-crosshairs"></i>
                    </button>
                    <button class="map-control-btn" onclick="showAllBusinesses()" title="Show All">
                        <i class="fas fa-expand"></i>
                    </button>
                </div>

                <!-- Loading Indicator -->
                <div id="mapLoading" class="loading">
                    <div class="loading-spinner"></div>
                    <div>Loading map...</div>
                </div>

                <!-- Map Container -->
                <div id="map"></div>
            </div>
        </div>
    </div>

    <!-- Google Maps JavaScript API -->
    <script>
        let map;
        let markers = [];
        let infoWindow;
        let businessData = <?php echo json_encode($businesses); ?>;
        
        // View toggle functions
        function switchToListView() {
            // Preserve current filters when switching to list view
            const urlParams = new URLSearchParams(window.location.search);
            let listUrl = 'index.php';
            
            // Add current filters to list URL
            if (urlParams.toString()) {
                listUrl += '?' + urlParams.toString();
            }
            
            window.location.href = listUrl;
        }

        function switchToMapView() {
            // Already on map view, just update button states
            updateToggleButtons('map');
        }

        function updateToggleButtons(activeView) {
            const buttons = document.querySelectorAll('.view-toggle-btn');
            
            buttons.forEach(btn => {
                btn.classList.remove('active');
            });

            if (activeView === 'map') {
                const mapBtn = buttons[1]; // Second button is map view
                mapBtn.classList.add('active');
            }
        }

        // Initialize view toggle on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateToggleButtons('map'); // Currently on map view

            // Check if Font Awesome loaded, if not show emoji icons
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
        
        // Initialize the map
        function initMap() {
            const mapLoading = document.getElementById('mapLoading');
            
            try {
                // Default center (Ghana)
                const defaultCenter = { lat: 5.6037, lng: -0.1870 };
                
                // Initialize map
                map = new google.maps.Map(document.getElementById('map'), {
                    zoom: 10,
                    center: defaultCenter,
                    mapTypeControl: true,
                    streetViewControl: true,
                    fullscreenControl: true,
                    zoomControl: true
                });
                
                // Initialize info window
                infoWindow = new google.maps.InfoWindow();
                
                // Add markers for all businesses
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
                } else {
                    // Center on Ghana if no businesses
                    map.setCenter(defaultCenter);
                    map.setZoom(7);
                }
                
                // Hide loading indicator
                mapLoading.style.display = 'none';
                
            } catch (error) {
                console.error('Error initializing map:', error);
                mapLoading.innerHTML = '<div style="color: #e53e3e;">Error loading map. Please refresh the page.</div>';
            }
        }
        
        // Add markers for businesses
        function addMarkers() {
            // Clear existing markers
            markers.forEach(marker => marker.setMap(null));
            markers = [];
            
            businessData.forEach(business => {
                if (business.latitude && business.longitude) {
                    const position = {
                        lat: parseFloat(business.latitude),
                        lng: parseFloat(business.longitude)
                    };
                    
                    // Choose marker color based on status and payment
                    let markerColor = '#48bb78'; // Green for active
                    if (business.status !== 'Active') {
                        markerColor = '#718096'; // Gray for inactive
                    } else if (parseFloat(business.amount_payable) > 0) {
                        markerColor = '#e53e3e'; // Red for defaulters
                    }
                    
                    const marker = new google.maps.Marker({
                        position: position,
                        map: map,
                        title: business.business_name,
                        icon: {
                            path: google.maps.SymbolPath.CIRCLE,
                            scale: 8,
                            fillColor: markerColor,
                            fillOpacity: 0.8,
                            strokeColor: '#ffffff',
                            strokeWeight: 2
                        }
                    });
                    
                    // Create info window content
                    const infoContent = `
                        <div style="max-width: 300px;">
                            <h6 style="margin-bottom: 10px; color: #2d3748; font-weight: 600;">
                                ${business.business_name}
                            </h6>
                            <div style="margin-bottom: 8px;">
                                <strong>Owner:</strong> ${business.owner_name}
                            </div>
                            <div style="margin-bottom: 8px;">
                                <strong>Account:</strong> ${business.account_number}
                            </div>
                            <div style="margin-bottom: 8px;">
                                <strong>Type:</strong> ${business.business_type} (${business.category})
                            </div>
                            <div style="margin-bottom: 8px;">
                                <strong>Location:</strong> ${business.exact_location || 'Not specified'}
                            </div>
                            <div style="margin-bottom: 8px;">
                                <strong>Zone:</strong> ${business.zone_name || 'Not assigned'}
                            </div>
                            <div style="margin-bottom: 8px;">
                                <strong>Sub-Zone:</strong> ${business.sub_zone_name || 'Not assigned'}
                            </div>
                            <div style="margin-bottom: 10px;">
                                <strong>Amount Payable:</strong> 
                                <span style="color: ${parseFloat(business.amount_payable) > 0 ? '#e53e3e' : '#48bb78'}; font-weight: 600;">
                                    ₵${parseFloat(business.amount_payable).toLocaleString()}
                                </span>
                            </div>
                            <div style="margin-bottom: 10px;">
                                <strong>Status:</strong>
                                <span style="background: ${business.status === 'Active' ? '#c6f6d5' : '#fed7d7'}; 
                                             color: ${business.status === 'Active' ? '#22543d' : '#742a2a'};
                                             padding: 2px 6px; border-radius: 10px; font-size: 12px;">
                                    ${business.status}
                                </span>
                            </div>
                            <div style="text-align: center; margin-top: 15px;">
                                <a href="view.php?id=${business.business_id}" 
                                   style="background: #667eea; color: white; padding: 8px 16px; 
                                          text-decoration: none; border-radius: 6px; font-size: 14px;">
                                    View Details
                                </a>
                            </div>
                        </div>
                    `;
                    
                    // Add click listener to marker
                    marker.addListener('click', () => {
                        infoWindow.setContent(infoContent);
                        infoWindow.open(map, marker);
                        
                        // Highlight corresponding business in sidebar
                        highlightBusinessInSidebar(business.business_id);
                    });
                    
                    markers.push(marker);
                }
            });
        }
        
        // Select business from sidebar
        function selectBusiness(element) {
            // Remove active class from all items
            document.querySelectorAll('.business-item').forEach(item => {
                item.classList.remove('active');
            });
            
            // Add active class to selected item
            element.classList.add('active');
            
            // Get coordinates
            const lat = parseFloat(element.dataset.lat);
            const lng = parseFloat(element.dataset.lng);
            const businessId = element.dataset.id;
            
            // Center map on selected business
            map.setCenter({ lat, lng });
            map.setZoom(16);
            
            // Find and click the corresponding marker
            const business = businessData.find(b => b.business_id == businessId);
            if (business) {
                const marker = markers.find(m => {
                    const pos = m.getPosition();
                    return Math.abs(pos.lat() - lat) < 0.0001 && Math.abs(pos.lng() - lng) < 0.0001;
                });
                
                if (marker) {
                    google.maps.event.trigger(marker, 'click');
                }
            }
        }
        
        // Highlight business in sidebar
        function highlightBusinessInSidebar(businessId) {
            document.querySelectorAll('.business-item').forEach(item => {
                item.classList.remove('active');
                if (item.dataset.id == businessId) {
                    item.classList.add('active');
                    // Scroll into view
                    item.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            });
        }
        
        // Map control functions
        function centerMap() {
            if (markers.length > 0) {
                const bounds = new google.maps.LatLngBounds();
                markers.forEach(marker => bounds.extend(marker.getPosition()));
                map.fitBounds(bounds);
            }
        }
        
        function showAllBusinesses() {
            centerMap();
        }
        
        // Toggle sidebar
        function toggleSidebar() {
            const sidebar = document.getElementById('mapSidebar');
            sidebar.classList.toggle('collapsed');
            
            // On mobile, use show class instead
            if (window.innerWidth <= 768) {
                sidebar.classList.toggle('show');
            }
            
            // Trigger map resize
            setTimeout(() => {
                google.maps.event.trigger(map, 'resize');
            }, 300);
        }
        
        // Handle zone/sub-zone filtering
        document.getElementById('zoneSelect').addEventListener('change', function() {
            const selectedZone = this.value;
            const subZoneSelect = document.getElementById('subZoneSelect');
            const options = subZoneSelect.querySelectorAll('option[data-zone]');
            
            // Show/hide sub-zone options based on selected zone
            options.forEach(option => {
                if (!selectedZone || option.dataset.zone === selectedZone) {
                    option.style.display = 'block';
                } else {
                    option.style.display = 'none';
                }
            });
            
            // Reset sub-zone selection if it doesn't match current zone
            if (selectedZone && subZoneSelect.value) {
                const selectedOption = subZoneSelect.querySelector(`option[value="${subZoneSelect.value}"]`);
                if (selectedOption && selectedOption.dataset.zone !== selectedZone) {
                    subZoneSelect.value = '';
                }
            }
        });
        
        // Auto-submit form on filter change
        document.querySelectorAll('.filter-select').forEach(select => {
            select.addEventListener('change', function() {
                // Auto-submit after a short delay to allow for multiple quick changes
                clearTimeout(window.filterTimeout);
                window.filterTimeout = setTimeout(() => {
                    document.getElementById('filterForm').submit();
                }, 500);
            });
        });
        
        // Handle responsive behavior
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                const sidebar = document.getElementById('mapSidebar');
                sidebar.classList.remove('show');
            }
        });
        
        // Error handling for Google Maps
        window.gm_authFailure = function() {
            document.getElementById('mapLoading').innerHTML = 
                '<div style="color: #e53e3e;">Google Maps authentication failed. Please check API key.</div>';
        };
    </script>
    
    <!-- Google Maps API -->
    <script async defer src="https://maps.googleapis.com/maps/api/js?key=AIzaSyDg1CWNtJ8BHeclYP7VfltZZLIcY3TVHaI&callback=initMap&libraries=geometry"></script>
</body>
</html>