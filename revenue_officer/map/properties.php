<?php
/**
 * Property Map Page for QUICKBILL 305
 * Revenue Officer interface for viewing property locations
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

// Check if user is revenue officer or admin
$currentUser = getCurrentUser();
if (!isRevenueOfficer() && !isAdmin()) {
    setFlashMessage('error', 'Access denied. Revenue Officer privileges required.');
    header('Location: ../../auth/login.php');
    exit();
}

// Check session expiration
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > 1800)) {
    // Session expired (30 minutes)
    session_unset();
    session_destroy();
    setFlashMessage('error', 'Your session has expired. Please log in again.');
    header('Location: ../../index.php');
    exit();
}

$userDisplayName = getUserDisplayName($currentUser);

// Get filter parameters
$zoneFilter = isset($_GET['zone']) ? intval($_GET['zone']) : 0;
$structureFilter = isset($_GET['structure']) ? sanitizeInput($_GET['structure']) : 'all';
$useFilter = isset($_GET['use']) ? sanitizeInput($_GET['use']) : 'all';
$paymentStatusFilter = isset($_GET['payment_status']) ? sanitizeInput($_GET['payment_status']) : 'all';

// Database connection
try {
    $db = new Database();
} catch (Exception $e) {
    $error = 'Database connection failed. Please try again.';
}

// Get zones for filter dropdown
$zones = [];
try {
    $zones = $db->fetchAll("SELECT zone_id, zone_name FROM zones ORDER BY zone_name");
    if ($zones === false) {
        $zones = [];
    }
} catch (Exception $e) {
    $zones = [];
}

// Get unique structures for filter
$structures = [];
try {
    $structures = $db->fetchAll("SELECT DISTINCT structure FROM properties WHERE structure IS NOT NULL ORDER BY structure");
    if ($structures === false) {
        $structures = [];
    }
} catch (Exception $e) {
    $structures = [];
}

// Build properties query with filters
$propertyQuery = "
    SELECT p.*, z.zone_name,
           CASE WHEN p.amount_payable > 0 THEN 'Outstanding' ELSE 'Paid' END as payment_status
    FROM properties p
    LEFT JOIN zones z ON p.zone_id = z.zone_id
    WHERE p.latitude IS NOT NULL AND p.longitude IS NOT NULL
";

$queryParams = [];

// Add zone filter
if ($zoneFilter > 0) {
    $propertyQuery .= " AND p.zone_id = ?";
    $queryParams[] = $zoneFilter;
}

// Add structure filter
if ($structureFilter !== 'all') {
    $propertyQuery .= " AND p.structure = ?";
    $queryParams[] = $structureFilter;
}

// Add property use filter
if ($useFilter !== 'all') {
    $propertyQuery .= " AND p.property_use = ?";
    $queryParams[] = $useFilter;
}

// Add payment status filter
if ($paymentStatusFilter !== 'all') {
    if ($paymentStatusFilter === 'outstanding') {
        $propertyQuery .= " AND p.amount_payable > 0";
    } else {
        $propertyQuery .= " AND p.amount_payable <= 0";
    }
}

$propertyQuery .= " ORDER BY p.owner_name";

// Get properties
$properties = [];
try {
    $properties = $db->fetchAll($propertyQuery, $queryParams);
    if ($properties === false) {
        $properties = [];
    }
} catch (Exception $e) {
    $properties = [];
}

// Get summary statistics
$totalProperties = count($properties);
$outstandingCount = 0;
$paidCount = 0;
$totalOutstanding = 0;
$residentialCount = 0;
$commercialCount = 0;

foreach ($properties as $property) {
    if ($property['amount_payable'] > 0) {
        $outstandingCount++;
        $totalOutstanding += $property['amount_payable'];
    } else {
        $paidCount++;
    }
    
    if ($property['property_use'] === 'Residential') {
        $residentialCount++;
    } else {
        $commercialCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Property Map - <?php echo APP_NAME; ?></title>
    
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
            color: #2d3748;
            overflow: hidden;
        }
        
        /* Custom Icons (fallback if Font Awesome fails) */
        [class*="icon-"] {
            display: none; /* Hidden by default */
        }
        
        .icon-map::before { content: "🗺️"; }
        .icon-home::before { content: "🏠"; }
        .icon-filter::before { content: "🔧"; }
        .icon-location::before { content: "📍"; }
        .icon-phone::before { content: "📞"; }
        .icon-money::before { content: "💰"; }
        .icon-back::before { content: "←"; }
        .icon-search::before { content: "🔍"; }
        .icon-layers::before { content: "📚"; }
        .icon-navigation::before { content: "🧭"; }
        .icon-residential::before { content: "🏘️"; }
        .icon-commercial::before { content: "🏬"; }
        .icon-menu::before { content: "☰"; }
        .icon-list::before { content: "📋"; }
        .icon-crosshairs::before { content: "🎯"; }
        .icon-layer-group::before { content: "🗺️"; }
        
        /* Header */
        .header {
            background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%);
            color: white;
            padding: 15px 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: relative;
            z-index: 1000;
        }
        
        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .header-title {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .header-title h1 {
            font-size: 24px;
            font-weight: 700;
        }
        
        .header-icon {
            font-size: 28px;
            opacity: 0.9;
        }
        
        .header-stats {
            display: flex;
            gap: 20px;
            font-size: 14px;
        }
        
        .stat-item {
            background: rgba(255,255,255,0.2);
            padding: 8px 12px;
            border-radius: 8px;
            text-align: center;
            min-width: 80px;
        }
        
        .stat-value {
            font-weight: 700;
            font-size: 16px;
        }
        
        .stat-label {
            opacity: 0.9;
            font-size: 12px;
        }
        
        .back-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .back-btn:hover {
            background: rgba(255,255,255,0.3);
            color: white;
            text-decoration: none;
        }
        
        /* Main Layout */
        .main-layout {
            display: flex;
            height: calc(100vh - 70px);
        }
        
        /* Sidebar */
        .sidebar {
            width: 380px;
            background: white;
            border-right: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid #e2e8f0;
            background: #f7fafc;
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
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        .filter-group.full-width {
            grid-column: 1 / -1;
        }
        
        .filter-label {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 5px;
            font-size: 14px;
        }
        
        .filter-select {
            padding: 8px 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 12px center;
            background-repeat: no-repeat;
            background-size: 16px;
            padding-right: 40px;
        }
        
        .filter-select:focus {
            outline: none;
            border-color: #e53e3e;
        }
        
        /* Property List */
        .property-list {
            flex: 1;
            overflow-y: auto;
            padding: 0;
        }
        
        .property-item {
            padding: 15px 20px;
            border-bottom: 1px solid #e2e8f0;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
        }
        
        .property-item:hover {
            background: #f7fafc;
        }
        
        .property-item.active {
            background: #e53e3e;
            color: white;
        }
        
        .property-item.active .property-amount {
            color: #fed7d7;
        }
        
        .property-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 8px;
        }
        
        .property-owner {
            font-weight: 600;
            font-size: 15px;
            margin-bottom: 3px;
        }
        
        .property-number {
            font-size: 13px;
            opacity: 0.8;
            font-family: monospace;
        }
        
        .property-amount {
            font-weight: 700;
            color: #e53e3e;
            font-size: 14px;
        }
        
        .property-amount.paid {
            color: #38a169;
        }
        
        .property-details {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            font-size: 12px;
            opacity: 0.8;
            margin-bottom: 8px;
        }
        
        .property-detail {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .property-type-badges {
            display: flex;
            gap: 5px;
        }
        
        .type-badge {
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-residential {
            background: #bee3f8;
            color: #2c5282;
        }
        
        .badge-commercial {
            background: #fbd38d;
            color: #c05621;
        }
        
        .badge-modern {
            background: #e9d8fd;
            color: #553c9a;
        }
        
        .badge-traditional {
            background: #c6f6d5;
            color: #276749;
        }
        
        .status-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 2px solid white;
        }
        
        .status-outstanding {
            background: #e53e3e;
        }
        
        .status-paid {
            background: #38a169;
        }
        
        /* Map Container */
        .map-container {
            flex: 1;
            position: relative;
            background: #f0f0f0;
        }
        
        #map {
            width: 100%;
            height: 100%;
        }
        
        /* Map Loading */
        .map-loading {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            color: #718096;
            z-index: 100;
        }
        
        .loading-spinner {
            font-size: 48px;
            margin-bottom: 15px;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        /* Map Controls */
        .map-controls {
            position: absolute;
            top: 20px;
            right: 20px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            z-index: 500;
        }
        
        .map-control-btn {
            background: white;
            border: none;
            padding: 12px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            cursor: pointer;
            transition: all 0.3s;
            font-size: 16px;
            color: #2d3748;
        }
        
        .map-control-btn:hover {
            background: #f7fafc;
            transform: translateY(-2px);
        }
        
        .map-control-btn.active {
            background: #e53e3e;
            color: white;
        }
        
        /* Info Window Styles */
        .info-window {
            max-width: 320px;
            padding: 0;
        }
        
        .info-header {
            background: #4299e1;
            color: white;
            padding: 15px;
            margin: -10px -15px 15px -15px;
        }
        
        .info-title {
            font-weight: 600;
            font-size: 16px;
            margin-bottom: 5px;
        }
        
        .info-subtitle {
            opacity: 0.9;
            font-size: 14px;
        }
        
        .info-body {
            padding: 0 15px 10px;
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .info-label {
            font-weight: 600;
            color: #718096;
        }
        
        .info-value {
            color: #2d3748;
        }
        
        .info-amount {
            font-weight: 700;
            color: #e53e3e;
            font-size: 16px;
        }
        
        .info-amount.paid {
            color: #38a169;
        }
        
        .info-badges {
            display: flex;
            gap: 5px;
            margin-bottom: 10px;
        }
        
        .info-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        
        .info-btn {
            flex: 1;
            padding: 8px 12px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            text-align: center;
            display: inline-block;
        }
        
        .info-btn.primary {
            background: #e53e3e;
            color: white;
        }
        
        .info-btn.primary:hover {
            background: #c53030;
            color: white;
            text-decoration: none;
        }
        
        .info-btn.secondary {
            background: #e2e8f0;
            color: #2d3748;
        }
        
        .info-btn.secondary:hover {
            background: #cbd5e0;
            color: #2d3748;
            text-decoration: none;
        }
        
        /* Legend */
        .map-legend {
            position: absolute;
            bottom: 20px;
            left: 20px;
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            z-index: 500;
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
            margin-bottom: 5px;
            font-size: 14px;
        }
        
        .legend-marker {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 2px solid white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        
        .legend-marker.outstanding {
            background: #e53e3e;
        }
        
        .legend-marker.paid {
            background: #38a169;
        }
        
        .legend-marker.residential {
            background: #4299e1;
        }
        
        .legend-marker.commercial {
            background: #ed8936;
        }
        
        /* No Results */
        .no-results {
            padding: 40px 20px;
            text-align: center;
            color: #718096;
        }
        
        .no-results-icon {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
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
            top: 70px;
            right: 0;
            width: 350px;
            height: calc(100vh - 70px);
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
                display: none;
            }
            
            .map-container {
                width: 100%;
            }
            
            .header-stats {
                display: none;
            }
            
            .map-controls {
                top: 10px;
                right: 10px;
                flex-direction: column;
                gap: 5px;
            }
            
            .map-control-btn {
                font-size: 14px;
                padding: 10px;
                min-width: 44px;
                min-height: 44px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .map-legend {
                bottom: 10px;
                left: 10px;
                font-size: 12px;
            }
            
            .property-sidebar-mobile {
                display: block;
            }
            
            .filters {
                grid-template-columns: 1fr;
                gap: 10px;
            }
        }
        
        /* Hide certain elements on different screen sizes */
        .d-md-none {
            display: none;
        }
        
        @media (max-width: 768px) {
            .d-md-none {
                display: flex;
            }
        }
    </style>
</head>
<body>
    <!-- Mobile Overlay -->
    <div class="mobile-overlay" id="mobileOverlay" onclick="closeAllSidebars()"></div>

    <!-- Header -->
    <div class="header">
        <div class="header-content">
            <div class="header-title">
                <div class="header-icon">
                    <i class="fas fa-home"></i>
                    <span class="icon-home"></span>
                </div>
                <h1>Property Locations Map</h1>
            </div>
            
            <div class="header-stats">
                <div class="stat-item">
                    <div class="stat-value"><?php echo number_format($totalProperties); ?></div>
                    <div class="stat-label">Total Properties</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo number_format($residentialCount); ?></div>
                    <div class="stat-label">Residential</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo number_format($commercialCount); ?></div>
                    <div class="stat-label">Commercial</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo number_format($outstandingCount); ?></div>
                    <div class="stat-label">Outstanding</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo formatCurrency($totalOutstanding); ?></div>
                    <div class="stat-label">Total Due</div>
                </div>
            </div>
            
            <a href="../index.php" class="back-btn">
                <i class="fas fa-arrow-left"></i>
                <span class="icon-back"></span>
                Dashboard
            </a>
        </div>
    </div>

    <!-- Main Layout -->
    <div class="main-layout">
        <!-- Desktop Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3 class="sidebar-title">
                    <i class="fas fa-filter"></i>
                    <span class="icon-filter"></span>
                    Filters & Property List
                </h3>
                
                <form method="GET" class="filters">
                    <div class="filter-group">
                        <label class="filter-label">Zone</label>
                        <select name="zone" class="filter-select" onchange="this.form.submit()">
                            <option value="0">All Zones</option>
                            <?php foreach ($zones as $zone): ?>
                                <option value="<?php echo $zone['zone_id']; ?>" 
                                        <?php echo $zoneFilter == $zone['zone_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($zone['zone_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Structure</label>
                        <select name="structure" class="filter-select" onchange="this.form.submit()">
                            <option value="all" <?php echo $structureFilter === 'all' ? 'selected' : ''; ?>>All Structures</option>
                            <?php foreach ($structures as $structure): ?>
                                <option value="<?php echo htmlspecialchars($structure['structure']); ?>" 
                                        <?php echo $structureFilter === $structure['structure'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($structure['structure']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Property Use</label>
                        <select name="use" class="filter-select" onchange="this.form.submit()">
                            <option value="all" <?php echo $useFilter === 'all' ? 'selected' : ''; ?>>All Uses</option>
                            <option value="Residential" <?php echo $useFilter === 'Residential' ? 'selected' : ''; ?>>Residential</option>
                            <option value="Commercial" <?php echo $useFilter === 'Commercial' ? 'selected' : ''; ?>>Commercial</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Payment Status</label>
                        <select name="payment_status" class="filter-select" onchange="this.form.submit()">
                            <option value="all" <?php echo $paymentStatusFilter === 'all' ? 'selected' : ''; ?>>All Payments</option>
                            <option value="outstanding" <?php echo $paymentStatusFilter === 'outstanding' ? 'selected' : ''; ?>>Outstanding</option>
                            <option value="paid" <?php echo $paymentStatusFilter === 'paid' ? 'selected' : ''; ?>>Paid Up</option>
                        </select>
                    </div>
                </form>
            </div>
            
            <div class="property-list">
                <?php if (!empty($properties)): ?>
                    <?php foreach ($properties as $property): ?>
                        <div class="property-item" 
                             onclick="selectProperty(<?php echo $property['property_id']; ?>, <?php echo $property['latitude']; ?>, <?php echo $property['longitude']; ?>)"
                             data-id="<?php echo $property['property_id']; ?>">
                            <div class="status-badge <?php echo $property['amount_payable'] > 0 ? 'status-outstanding' : 'status-paid'; ?>"></div>
                            
                            <div class="property-header">
                                <div>
                                    <div class="property-owner"><?php echo htmlspecialchars($property['owner_name']); ?></div>
                                    <div class="property-number"><?php echo htmlspecialchars($property['property_number']); ?></div>
                                </div>
                                <div class="property-amount <?php echo $property['amount_payable'] <= 0 ? 'paid' : ''; ?>">
                                    <?php echo $property['amount_payable'] > 0 ? formatCurrency($property['amount_payable']) : 'Paid'; ?>
                                </div>
                            </div>
                            
                            <div class="property-details">
                                <div class="property-detail">
                                    <i class="fas fa-phone" style="font-size: 10px;"></i>
                                    <span class="icon-phone"></span>
                                    <?php echo htmlspecialchars($property['telephone'] ?: 'N/A'); ?>
                                </div>
                                <div class="property-detail">
                                    <i class="fas fa-map-marker-alt" style="font-size: 10px;"></i>
                                    <span class="icon-location"></span>
                                    <?php echo htmlspecialchars($property['zone_name'] ?: 'Unknown'); ?>
                                </div>
                                <div class="property-detail">
                                    <i class="fas fa-door-open" style="font-size: 10px;"></i>
                                    <?php echo $property['number_of_rooms']; ?> rooms
                                </div>
                            </div>
                            
                            <div class="property-type-badges">
                                <span class="type-badge badge-<?php echo strtolower($property['property_use']); ?>">
                                    <?php echo $property['property_use']; ?>
                                </span>
                                <span class="type-badge badge-<?php echo strtolower($property['property_type']); ?>">
                                    <?php echo $property['property_type']; ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="no-results">
                        <div class="no-results-icon">
                            <i class="fas fa-search"></i>
                            <span class="icon-search"></span>
                        </div>
                        <h4>No properties found</h4>
                        <p>No properties match your current filter criteria.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Mobile Property Sidebar -->
        <div class="property-sidebar-mobile" id="propertySidebarMobile">
            <div class="sidebar-header">
                <h3 class="sidebar-title">
                    <i class="fas fa-filter"></i>
                    <span class="icon-filter"></span>
                    Filters & Property List
                </h3>
                
                <form method="GET" class="filters">
                    <div class="filter-group">
                        <label class="filter-label">Zone</label>
                        <select name="zone" class="filter-select" onchange="this.form.submit()">
                            <option value="0">All Zones</option>
                            <?php foreach ($zones as $zone): ?>
                                <option value="<?php echo $zone['zone_id']; ?>" 
                                        <?php echo $zoneFilter == $zone['zone_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($zone['zone_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Structure</label>
                        <select name="structure" class="filter-select" onchange="this.form.submit()">
                            <option value="all" <?php echo $structureFilter === 'all' ? 'selected' : ''; ?>>All Structures</option>
                            <?php foreach ($structures as $structure): ?>
                                <option value="<?php echo htmlspecialchars($structure['structure']); ?>" 
                                        <?php echo $structureFilter === $structure['structure'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($structure['structure']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Property Use</label>
                        <select name="use" class="filter-select" onchange="this.form.submit()">
                            <option value="all" <?php echo $useFilter === 'all' ? 'selected' : ''; ?>>All Uses</option>
                            <option value="Residential" <?php echo $useFilter === 'Residential' ? 'selected' : ''; ?>>Residential</option>
                            <option value="Commercial" <?php echo $useFilter === 'Commercial' ? 'selected' : ''; ?>>Commercial</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Payment Status</label>
                        <select name="payment_status" class="filter-select" onchange="this.form.submit()">
                            <option value="all" <?php echo $paymentStatusFilter === 'all' ? 'selected' : ''; ?>>All Payments</option>
                            <option value="outstanding" <?php echo $paymentStatusFilter === 'outstanding' ? 'selected' : ''; ?>>Outstanding</option>
                            <option value="paid" <?php echo $paymentStatusFilter === 'paid' ? 'selected' : ''; ?>>Paid Up</option>
                        </select>
                    </div>
                </form>
            </div>
            
            <div class="property-list">
                <?php if (!empty($properties)): ?>
                    <?php foreach ($properties as $property): ?>
                        <div class="property-item" 
                             onclick="selectProperty(<?php echo $property['property_id']; ?>, <?php echo $property['latitude']; ?>, <?php echo $property['longitude']; ?>); closeAllSidebars();"
                             data-id="mobile-<?php echo $property['property_id']; ?>">
                            <div class="status-badge <?php echo $property['amount_payable'] > 0 ? 'status-outstanding' : 'status-paid'; ?>"></div>
                            
                            <div class="property-header">
                                <div>
                                    <div class="property-owner"><?php echo htmlspecialchars($property['owner_name']); ?></div>
                                    <div class="property-number"><?php echo htmlspecialchars($property['property_number']); ?></div>
                                </div>
                                <div class="property-amount <?php echo $property['amount_payable'] <= 0 ? 'paid' : ''; ?>">
                                    <?php echo $property['amount_payable'] > 0 ? formatCurrency($property['amount_payable']) : 'Paid'; ?>
                                </div>
                            </div>
                            
                            <div class="property-details">
                                <div class="property-detail">
                                    <i class="fas fa-phone" style="font-size: 10px;"></i>
                                    <span class="icon-phone"></span>
                                    <?php echo htmlspecialchars($property['telephone'] ?: 'N/A'); ?>
                                </div>
                                <div class="property-detail">
                                    <i class="fas fa-map-marker-alt" style="font-size: 10px;"></i>
                                    <span class="icon-location"></span>
                                    <?php echo htmlspecialchars($property['zone_name'] ?: 'Unknown'); ?>
                                </div>
                                <div class="property-detail">
                                    <i class="fas fa-door-open" style="font-size: 10px;"></i>
                                    <?php echo $property['number_of_rooms']; ?> rooms
                                </div>
                            </div>
                            
                            <div class="property-type-badges">
                                <span class="type-badge badge-<?php echo strtolower($property['property_use']); ?>">
                                    <?php echo $property['property_use']; ?>
                                </span>
                                <span class="type-badge badge-<?php echo strtolower($property['property_type']); ?>">
                                    <?php echo $property['property_type']; ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="no-results">
                        <div class="no-results-icon">
                            <i class="fas fa-search"></i>
                            <span class="icon-search"></span>
                        </div>
                        <h4>No properties found</h4>
                        <p>No properties match your current filter criteria.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Map Container -->
        <div class="map-container">
            <div class="map-loading" id="mapLoading">
                <div class="loading-spinner">
                    <i class="fas fa-spinner"></i>
                    <span class="icon-loading">⏳</span>
                </div>
                <div>Loading map...</div>
            </div>
            
            <div id="map"></div>
            
            <!-- Map Controls -->
            <div class="map-controls">
                <button class="map-control-btn" onclick="centerMap()" title="Center Map">
                    <i class="fas fa-crosshairs"></i>
                    <span class="icon-navigation"></span>
                </button>
                <button class="map-control-btn" onclick="toggleMapType()" title="Toggle Map Type">
                    <i class="fas fa-layer-group"></i>
                    <span class="icon-layer-group"></span>
                </button>
                <button class="map-control-btn" onclick="toggleTraffic()" title="Toggle Traffic" id="trafficBtn">
                    <i class="fas fa-road"></i>
                    <span class="icon-map"></span>
                </button>
                <button class="map-control-btn d-md-none" onclick="togglePropertySidebar()" title="Properties & Filters">
                    <i class="fas fa-list"></i>
                    <span class="icon-list"></span>
                </button>
                <button class="map-control-btn" onclick="toggleHeatmap()" title="Toggle Density" id="heatmapBtn">
                    <i class="fas fa-fire"></i>
                    <span class="icon-chart">🔥</span>
                </button>
            </div>
            
            <!-- Map Legend -->
            <div class="map-legend">
                <div class="legend-title">Legend</div>
                <div class="legend-item">
                    <div class="legend-marker outstanding"></div>
                    <span>Outstanding Payment</span>
                </div>
                <div class="legend-item">
                    <div class="legend-marker paid"></div>
                    <span>Paid Up</span>
                </div>
                <div class="legend-item">
                    <div class="legend-marker residential"></div>
                    <span>Residential</span>
                </div>
                <div class="legend-item">
                    <div class="legend-marker commercial"></div>
                    <span>Commercial</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Google Maps API -->
    <script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyDg1CWNtJ8BHeclYP7VfltZZLIcY3TVHaI&callback=initMap&libraries=visualization" async defer></script>
    
    <script>
        let map;
        let markers = [];
        let heatmap;
        let infoWindow;
        let selectedPropertyId = null;
        let mapCenter = { lat: 5.593020, lng: -0.077100 }; // Default to Ghana coordinates
        let currentMapType = 'roadmap';
        
        // Property data from PHP
        const properties = <?php echo json_encode($properties); ?>;
        
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
                const testIcon = document.querySelector('.fas.fa-home');
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
        function togglePropertySidebar() {
            const propertySidebar = document.getElementById('propertySidebarMobile');
            const overlay = document.getElementById('mobileOverlay');
            
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
            const propertySidebar = document.getElementById('propertySidebarMobile');
            const overlay = document.getElementById('mobileOverlay');
            
            if (propertySidebar) {
                propertySidebar.classList.remove('show');
            }
            
            overlay.classList.remove('show');
        }

        // Initialize Google Map
        function initMap() {
            // Hide loading spinner
            document.getElementById('mapLoading').style.display = 'none';
            
            // Calculate map center from properties
            if (properties.length > 0) {
                let totalLat = 0;
                let totalLng = 0;
                let validCoords = 0;
                
                properties.forEach(property => {
                    if (property.latitude && property.longitude) {
                        totalLat += parseFloat(property.latitude);
                        totalLng += parseFloat(property.longitude);
                        validCoords++;
                    }
                });
                
                if (validCoords > 0) {
                    mapCenter.lat = totalLat / validCoords;
                    mapCenter.lng = totalLng / validCoords;
                }
            }
            
            // Initialize map
            map = new google.maps.Map(document.getElementById('map'), {
                zoom: 13,
                center: mapCenter,
                mapTypeId: currentMapType,
                styles: [
                    {
                        featureType: 'poi',
                        elementType: 'labels',
                        stylers: [{ visibility: 'off' }]
                    }
                ]
            });
            
            // Initialize info window
            infoWindow = new google.maps.InfoWindow();
            
            // Add markers for properties
            addPropertyMarkers();
            
            // Initialize heatmap
            initHeatmap();
        }
        
        // Add property markers to map
        function addPropertyMarkers() {
            properties.forEach(property => {
                if (property.latitude && property.longitude) {
                    // Determine marker color based on property use and payment status
                    let fillColor;
                    if (property.amount_payable > 0) {
                        fillColor = '#e53e3e'; // Outstanding - red
                    } else {
                        fillColor = '#38a169'; // Paid - green
                    }
                    
                    // Different shapes for residential vs commercial
                    const markerSymbol = property.property_use === 'Residential' ? 
                        google.maps.SymbolPath.CIRCLE : 
                        google.maps.SymbolPath.FORWARD_CLOSED_ARROW;
                    
                    const marker = new google.maps.Marker({
                        position: {
                            lat: parseFloat(property.latitude),
                            lng: parseFloat(property.longitude)
                        },
                        map: map,
                        title: property.owner_name,
                        icon: {
                            path: markerSymbol,
                            scale: property.property_use === 'Residential' ? 8 : 10,
                            fillColor: fillColor,
                            fillOpacity: 0.8,
                            strokeColor: '#ffffff',
                            strokeWeight: 2,
                            rotation: property.property_use === 'Commercial' ? 45 : 0
                        },
                        animation: google.maps.Animation.DROP
                    });
                    
                    // Create info window content
                    const infoContent = createInfoWindowContent(property);
                    
                    // Add click listener
                    marker.addListener('click', () => {
                        infoWindow.setContent(infoContent);
                        infoWindow.open(map, marker);
                        highlightPropertyInList(property.property_id);
                    });
                    
                    markers.push({
                        marker: marker,
                        property: property
                    });
                }
            });
        }
        
        // Initialize heatmap
        function initHeatmap() {
            const heatmapData = properties
                .filter(property => property.latitude && property.longitude && property.amount_payable > 0)
                .map(property => {
                    return {
                        location: new google.maps.LatLng(parseFloat(property.latitude), parseFloat(property.longitude)),
                        weight: parseFloat(property.amount_payable)
                    };
                });
            
            heatmap = new google.maps.visualization.HeatmapLayer({
                data: heatmapData,
                map: null, // Initially hidden
                radius: 50,
                opacity: 0.8,
                gradient: [
                    'rgba(0, 255, 255, 0)',
                    'rgba(0, 255, 255, 1)',
                    'rgba(0, 191, 255, 1)',
                    'rgba(0, 127, 255, 1)',
                    'rgba(0, 63, 255, 1)',
                    'rgba(0, 0, 255, 1)',
                    'rgba(0, 0, 223, 1)',
                    'rgba(0, 0, 191, 1)',
                    'rgba(0, 0, 159, 1)',
                    'rgba(0, 0, 127, 1)',
                    'rgba(63, 0, 91, 1)',
                    'rgba(127, 0, 63, 1)',
                    'rgba(191, 0, 31, 1)',
                    'rgba(255, 0, 0, 1)'
                ]
            });
        }
        
        // Create info window content
        function createInfoWindowContent(property) {
            const amountClass = property.amount_payable > 0 ? '' : 'paid';
            const amountText = property.amount_payable > 0 ? 
                `GH₵ ${parseFloat(property.amount_payable).toFixed(2)}` : 'Paid Up';
            
            return `
                <div class="info-window">
                    <div class="info-header">
                        <div class="info-title">${property.owner_name}</div>
                        <div class="info-subtitle">${property.property_number}</div>
                    </div>
                    <div class="info-body">
                        <div class="info-badges">
                            <span class="type-badge badge-${property.property_use.toLowerCase()}">${property.property_use}</span>
                            <span class="type-badge badge-${property.property_type.toLowerCase()}">${property.property_type}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Structure:</span>
                            <span class="info-value">${property.structure}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Rooms:</span>
                            <span class="info-value">${property.number_of_rooms}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Ownership:</span>
                            <span class="info-value">${property.ownership_type}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Phone:</span>
                            <span class="info-value">${property.telephone || 'N/A'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Zone:</span>
                            <span class="info-value">${property.zone_name || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Amount Due:</span>
                            <span class="info-amount ${amountClass}">${amountText}</span>
                        </div>
                        <div class="info-actions">
                            ${property.amount_payable > 0 ? 
                                `<a href="../payments/record.php?account=property:${property.property_id}" class="info-btn primary">Record Payment</a>` : 
                                ''
                            }
                            <a href="../payments/search.php?search=${property.property_number}" class="info-btn secondary">View Details</a>
                        </div>
                    </div>
                </div>
            `;
        }
        
        // Select property from sidebar
        function selectProperty(id, lat, lng) {
            // Remove previous selection
            document.querySelectorAll('.property-item').forEach(item => {
                item.classList.remove('active');
            });
            
            // Highlight selected property
            const selectedItem = document.querySelector(`[data-id="${id}"]`);
            const selectedMobileItem = document.querySelector(`[data-id="mobile-${id}"]`);
            
            if (selectedItem) {
                selectedItem.classList.add('active');
            }
            if (selectedMobileItem) {
                selectedMobileItem.classList.add('active');
            }
            
            // Center map on property
            const position = { lat: parseFloat(lat), lng: parseFloat(lng) };
            map.setCenter(position);
            map.setZoom(16);
            
            // Find and trigger marker click
            const markerData = markers.find(m => m.property.property_id == id);
            if (markerData) {
                google.maps.event.trigger(markerData.marker, 'click');
            }
            
            selectedPropertyId = id;
        }
        
        // Highlight property in list
        function highlightPropertyInList(id) {
            document.querySelectorAll('.property-item').forEach(item => {
                item.classList.remove('active');
            });
            
            const item = document.querySelector(`[data-id="${id}"]`);
            const mobileItem = document.querySelector(`[data-id="mobile-${id}"]`);
            
            if (item) {
                item.classList.add('active');
                item.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            
            if (mobileItem) {
                mobileItem.classList.add('active');
            }
            
            selectedPropertyId = id;
        }
        
        // Map control functions
        function centerMap() {
            map.setCenter(mapCenter);
            map.setZoom(13);
        }
        
        // Toggle map type
        function toggleMapType() {
            currentMapType = currentMapType === 'roadmap' ? 'satellite' : 'roadmap';
            map.setMapTypeId(currentMapType);
        }
        
        function toggleTraffic() {
            const btn = document.getElementById('trafficBtn');
            const trafficLayer = new google.maps.TrafficLayer();
            
            if (btn.classList.contains('active')) {
                trafficLayer.setMap(null);
                btn.classList.remove('active');
            } else {
                trafficLayer.setMap(map);
                btn.classList.add('active');
            }
        }
        
        function toggleHeatmap() {
            const btn = document.getElementById('heatmapBtn');
            
            if (btn.classList.contains('active')) {
                heatmap.setMap(null);
                btn.classList.remove('active');
            } else {
                heatmap.setMap(map);
                btn.classList.add('active');
            }
        }
        
        // Handle map load error
        window.gm_authFailure = function() {
            document.getElementById('mapLoading').innerHTML = `
                <div style="color: #e53e3e;">
                    <i class="fas fa-exclamation-triangle" style="font-size: 48px; margin-bottom: 15px;"></i>
                    <div>Map failed to load. Please check your internet connection.</div>
                </div>
            `;
        };
        
        // Handle responsive behavior
        window.addEventListener('resize', function() {
            closeAllSidebars();
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // M to toggle sidebar on mobile
            if (e.key === 'm' || e.key === 'M') {
                if (window.innerWidth <= 768) {
                    togglePropertySidebar();
                }
            }
            
            // H to toggle heatmap
            if (e.key === 'h' || e.key === 'H') {
                toggleHeatmap();
            }
            
            // Arrow keys to navigate properties
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                const items = document.querySelectorAll('.property-item');
                let currentIndex = -1;
                
                items.forEach((item, index) => {
                    if (item.classList.contains('active')) {
                        currentIndex = index;
                    }
                });
                
                if (e.key === 'ArrowDown' && currentIndex < items.length - 1) {
                    currentIndex++;
                } else if (e.key === 'ArrowUp' && currentIndex > 0) {
                    currentIndex--;
                }
                
                if (currentIndex >= 0 && items[currentIndex]) {
                    items[currentIndex].click();
                }
            }
        });
    </script>
</body>
</html>