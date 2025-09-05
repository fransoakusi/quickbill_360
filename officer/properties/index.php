<?php
/**
 * Officer - Properties Index
 * List and manage properties with search and filter capabilities
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

$currentUser = getCurrentUser();
$userDisplayName = getUserDisplayName($currentUser);

// Get current directory and page for active link highlighting
$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pathParts = explode('/', trim($currentPath, '/'));
$currentDir = !empty($pathParts[0]) ? $pathParts[0] : '';
$currentPage = basename($_SERVER['PHP_SELF']);

// Initialize variables
$search = $_GET['search'] ?? '';
$zone_filter = $_GET['zone'] ?? '';
$structure_filter = $_GET['structure'] ?? '';
$property_use_filter = $_GET['property_use'] ?? '';
$ownership_filter = $_GET['ownership'] ?? '';
$payment_status_filter = $_GET['payment_status'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

try {
    $db = new Database();
    
    // Get filter options
    $zones = $db->fetchAll("SELECT zone_id, zone_name FROM zones ORDER BY zone_name");
    $structures = $db->fetchAll("SELECT DISTINCT structure FROM properties WHERE structure != '' ORDER BY structure");
    
    // Build query with filters
    $whereConditions = [];
    $params = [];
    
    if (!empty($search)) {
        $whereConditions[] = "(p.owner_name LIKE ? OR p.property_number LIKE ? OR p.telephone LIKE ?)";
        $searchTerm = '%' . $search . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    if (!empty($zone_filter)) {
        $whereConditions[] = "p.zone_id = ?";
        $params[] = $zone_filter;
    }
    
    if (!empty($structure_filter)) {
        $whereConditions[] = "p.structure = ?";
        $params[] = $structure_filter;
    }
    
    if (!empty($property_use_filter)) {
        $whereConditions[] = "p.property_use = ?";
        $params[] = $property_use_filter;
    }
    
    if (!empty($ownership_filter)) {
        $whereConditions[] = "p.ownership_type = ?";
        $params[] = $ownership_filter;
    }
    
    if (!empty($payment_status_filter)) {
        if ($payment_status_filter === 'Defaulter') {
            $whereConditions[] = "p.amount_payable > 0";
        } else {
            $whereConditions[] = "p.amount_payable <= 0";
        }
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    // Get total count
    $countSql = "SELECT COUNT(*) as total 
                 FROM properties p 
                 LEFT JOIN zones z ON p.zone_id = z.zone_id 
                 $whereClause";
    
    $totalResult = $db->fetchRow($countSql, $params);
    $totalRecords = $totalResult['total'];
    $totalPages = ceil($totalRecords / $limit);
    
    // Get properties with pagination
    $sql = "SELECT p.*, z.zone_name,
                   CASE WHEN p.amount_payable > 0 THEN 'Defaulter' ELSE 'Up to Date' END as payment_status,
                   u.first_name, u.last_name
            FROM properties p 
            LEFT JOIN zones z ON p.zone_id = z.zone_id 
            LEFT JOIN users u ON p.created_by = u.user_id
            $whereClause 
            ORDER BY p.created_at DESC 
            LIMIT $limit OFFSET $offset";
    
    $properties = $db->fetchAll($sql, $params);
    
    // Get statistics with proper NULL handling
    $statsResult = $db->fetchRow("
        SELECT 
            COUNT(*) as total_properties,
            COALESCE(SUM(CASE WHEN property_use = 'Residential' THEN 1 ELSE 0 END), 0) as residential_properties,
            COALESCE(SUM(CASE WHEN property_use = 'Commercial' THEN 1 ELSE 0 END), 0) as commercial_properties,
            COALESCE(SUM(CASE WHEN amount_payable > 0 THEN 1 ELSE 0 END), 0) as defaulters,
            COALESCE(SUM(amount_payable), 0) as total_outstanding,
            COALESCE(SUM(number_of_rooms), 0) as total_rooms
        FROM properties
    ");
    
    // Ensure all stats values are numeric (never null)
    $stats = [
        'total_properties' => (int)($statsResult['total_properties'] ?? 0),
        'residential_properties' => (int)($statsResult['residential_properties'] ?? 0),
        'commercial_properties' => (int)($statsResult['commercial_properties'] ?? 0),
        'defaulters' => (int)($statsResult['defaulters'] ?? 0),
        'total_outstanding' => (float)($statsResult['total_outstanding'] ?? 0),
        'total_rooms' => (int)($statsResult['total_rooms'] ?? 0)
    ];
    
} catch (Exception $e) {
    $properties = [];
    $totalRecords = 0;
    $totalPages = 0;
    $stats = [
        'total_properties' => 0,
        'residential_properties' => 0,
        'commercial_properties' => 0,
        'defaulters' => 0,
        'total_outstanding' => 0,
        'total_rooms' => 0
    ];
    setFlashMessage('error', 'Error loading properties: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Properties - <?php echo APP_NAME; ?></title>
    
    <!-- Multiple Icon Sources -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    
    <!-- Bootstrap for backup -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="../../assets/css/admin.css">
    
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
        
        /* Original Styles from Properties Index */
        .page-header {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .page-title {
            font-size: 28px;
            font-weight: bold;
            color: #2d3748;
            margin: 0;
        }
        
        .page-subtitle {
            color: #718096;
            font-size: 16px;
            margin-top: 5px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-card.primary {
            background: linear-gradient(135deg, #38a169 0%, #2f855a 100%);
            color: white;
        }
        
        .stat-card.info {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
        }
        
        .stat-card.secondary {
            background: linear-gradient(135deg, #9f7aea 0%, #805ad5 100%);
            color: white;
        }
        
        .stat-card.warning {
            background: linear-gradient(135deg, #ed8936 0%, #dd6b20 100%);
            color: white;
        }
        
        .stat-card.danger {
            background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%);
            color: white;
        }
        
        .stat-card.success {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
        }
        
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .stat-title {
            font-size: 14px;
            opacity: 0.9;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .stat-icon {
            font-size: 32px;
            opacity: 0.8;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: bold;
        }
        
        .filters-container {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .filter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }
        
        .form-group {
            margin-bottom: 0;
        }
        
        .form-label {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 8px;
            display: block;
        }
        
        .form-control {
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px 15px;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            border-color: #38a169;
            box-shadow: 0 0 0 3px rgba(56, 161, 105, 0.1);
            outline: none;
        }
        
        .btn-filter {
            background: linear-gradient(135deg, #38a169 0%, #2f855a 100%);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s;
            height: fit-content;
        }
        
        .btn-filter:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            color: white;
        }
        
        .btn-clear {
            background: #718096;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s;
            height: fit-content;
        }
        
        .btn-clear:hover {
            background: #4a5568;
            color: white;
        }
        
        .btn-add {
            background: linear-gradient(135deg, #38a169 0%, #2f855a 100%);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-add:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            color: white;
            text-decoration: none;
        }
        
        .table-container {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            overflow: hidden;
        }
        
        .table-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .table-title {
            font-size: 20px;
            font-weight: 600;
            color: #2d3748;
            margin: 0;
        }
        
        .table-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .table-responsive {
            border-radius: 10px;
            overflow: hidden;
        }
        
        .table {
            margin: 0;
        }
        
        .table th {
            background: #f7fafc;
            border: none;
            font-weight: 600;
            color: #2d3748;
            padding: 15px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .table td {
            padding: 15px;
            vertical-align: middle;
            border-bottom: 1px solid #f7fafc;
        }
        
        .table tbody tr:hover {
            background: #f7fafc;
        }
        
        .property-badge {
            padding: 4px 8px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .property-residential {
            background: #c6f6d5;
            color: #22543d;
        }
        
        .property-commercial {
            background: #bee3f8;
            color: #2c5282;
        }
        
        .ownership-badge {
            padding: 4px 8px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .ownership-self {
            background: #e6fffa;
            color: #234e52;
        }
        
        .ownership-family {
            background: #fef5e7;
            color: #744210;
        }
        
        .ownership-corporate {
            background: #ebf8ff;
            color: #2c5282;
        }
        
        .ownership-others {
            background: #f7fafc;
            color: #4a5568;
        }
        
        .payment-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .payment-uptodate {
            background: #c6f6d5;
            color: #22543d;
        }
        
        .payment-defaulter {
            background: #fed7d7;
            color: #742a2a;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .btn-action {
            width: 35px;
            height: 35px;
            border-radius: 8px;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
            font-size: 14px;
        }
        
        .btn-view {
            background: #38a169;
            color: white;
        }
        
        .btn-view:hover {
            background: #2f855a;
            color: white;
        }
        
        .btn-edit {
            background: #ed8936;
            color: white;
        }
        
        .btn-edit:hover {
            background: #dd6b20;
            color: white;
        }
        
        .pagination-container {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .pagination {
            margin: 0;
            justify-content: center;
        }
        
        .page-link {
            border: none;
            padding: 10px 15px;
            margin: 0 2px;
            border-radius: 8px;
            color: #38a169;
            font-weight: 600;
        }
        
        .page-link:hover {
            background: #f0fff4;
            color: #2f855a;
        }
        
        .page-item.active .page-link {
            background: #38a169;
            color: white;
        }
        
        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #718096;
        }
        
        .no-data i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .rooms-info {
            display: flex;
            align-items: center;
            gap: 5px;
            color: #4a5568;
            font-size: 12px;
        }
        
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
            }
            
            .page-header,
            .filters-container,
            .table-container,
            .pagination-container {
                margin: 0 -15px 30px -15px;
                border-radius: 0;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-row {
                grid-template-columns: 1fr;
            }
            
            .table-header {
                flex-direction: column;
                align-items: stretch;
            }
            
            .table-actions {
                justify-content: center;
            }
            
            .table-responsive {
                font-size: 14px;
            }
            
            .action-buttons {
                flex-direction: column;
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
                    <?php echo strtoupper(substr($currentUser['first_name'], 0, 1)); ?>
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
                            <?php echo strtoupper(substr($currentUser['first_name'], 0, 1)); ?>
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
                        <a href="../index.php" class="nav-link <?php echo ($currentPage === 'index.php' && $currentDir === '') ? 'active' : ''; ?>">
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
                        <a href="add.php" class="nav-link <?php echo ($currentDir === 'properties' && $currentPage === 'add.php') ? 'active' : ''; ?>">
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
                        <a href="index.php" class="nav-link <?php echo ($currentDir === 'properties' && $currentPage === 'index.php') ? 'active' : ''; ?>">
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
                        <a href="billing/print.php" class="nav-link <?php echo ($currentDir === 'bills' && $currentPage === 'print.php') ? 'active' : ''; ?>">
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
        <div class="main-content" id="mainContent">
            <!-- Page Header -->
            <div class="page-header">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                    <div>
                        <h1 class="page-title">
                            <i class="fas fa-home text-success"></i>
                            Properties Management
                        </h1>
                        <p class="page-subtitle">View and manage all registered properties</p>
                    </div>
                    <a href="add.php" class="btn-add">
                        <i class="fas fa-plus"></i>
                        Register New Property
                    </a>
                </div>
            </div>

            <!-- Flash Messages -->
            <?php if (getFlashMessages()): ?>
                <?php $flash = getFlashMessages(); ?>
                <div class="alert alert-<?php echo $flash['type']; ?> alert-dismissible fade show">
                    <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                    <?php echo htmlspecialchars($flash['message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="stat-header">
                        <div class="stat-title">Total Properties</div>
                        <div class="stat-icon">
                            <i class="fas fa-home"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_properties']); ?></div>
                </div>

                <div class="stat-card info">
                    <div class="stat-header">
                        <div class="stat-title">Residential</div>
                        <div class="stat-icon">
                            <i class="fas fa-house-user"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['residential_properties']); ?></div>
                </div>

                <div class="stat-card secondary">
                    <div class="stat-header">
                        <div class="stat-title">Commercial</div>
                        <div class="stat-icon">
                            <i class="fas fa-building"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['commercial_properties']); ?></div>
                </div>

                <div class="stat-card success">
                    <div class="stat-header">
                        <div class="stat-title">Total Rooms</div>
                        <div class="stat-icon">
                            <i class="fas fa-door-open"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_rooms']); ?></div>
                </div>

                <div class="stat-card warning">
                    <div class="stat-header">
                        <div class="stat-title">Defaulters</div>
                        <div class="stat-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['defaulters']); ?></div>
                </div>

                <div class="stat-card danger">
                    <div class="stat-header">
                        <div class="stat-title">Outstanding Amount</div>
                        <div class="stat-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo formatCurrency($stats['total_outstanding']); ?></div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-container">
                <h5 class="mb-3">
                    <i class="fas fa-filter text-success"></i>
                    Search & Filter
                </h5>
                
                <form method="GET" class="filter-form">
                    <div class="filter-row">
                        <div class="form-group">
                            <label class="form-label">Search</label>
                            <input type="text" 
                                   class="form-control" 
                                   name="search" 
                                   value="<?php echo htmlspecialchars($search); ?>" 
                                   placeholder="Search owner name, property number, phone...">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Zone</label>
                            <select class="form-control" name="zone">
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
                            <label class="form-label">Structure</label>
                            <select class="form-control" name="structure">
                                <option value="">All Structures</option>
                                <?php foreach ($structures as $structure): ?>
                                    <option value="<?php echo htmlspecialchars($structure['structure']); ?>" 
                                            <?php echo $structure_filter === $structure['structure'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($structure['structure']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Property Use</label>
                            <select class="form-control" name="property_use">
                                <option value="">All Uses</option>
                                <option value="Residential" <?php echo $property_use_filter === 'Residential' ? 'selected' : ''; ?>>Residential</option>
                                <option value="Commercial" <?php echo $property_use_filter === 'Commercial' ? 'selected' : ''; ?>>Commercial</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Ownership Type</label>
                            <select class="form-control" name="ownership">
                                <option value="">All Ownership</option>
                                <option value="Self" <?php echo $ownership_filter === 'Self' ? 'selected' : ''; ?>>Self</option>
                                <option value="Family" <?php echo $ownership_filter === 'Family' ? 'selected' : ''; ?>>Family</option>
                                <option value="Corporate" <?php echo $ownership_filter === 'Corporate' ? 'selected' : ''; ?>>Corporate</option>
                                <option value="Others" <?php echo $ownership_filter === 'Others' ? 'selected' : ''; ?>>Others</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Payment Status</label>
                            <select class="form-control" name="payment_status">
                                <option value="">All</option>
                                <option value="Up to Date" <?php echo $payment_status_filter === 'Up to Date' ? 'selected' : ''; ?>>Up to Date</option>
                                <option value="Defaulter" <?php echo $payment_status_filter === 'Defaulter' ? 'selected' : ''; ?>>Defaulter</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <button type="submit" class="btn-filter">
                                <i class="fas fa-search"></i>
                                Filter
                            </button>
                        </div>
                        
                        <div class="form-group">
                            <a href="index.php" class="btn-clear">
                                <i class="fas fa-times"></i>
                                Clear
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Properties Table -->
            <div class="table-container">
                <div class="table-header">
                    <h5 class="table-title">
                        Properties List 
                        <?php if ($totalRecords > 0): ?>
                            <span class="text-muted">
                                (<?php echo number_format($totalRecords); ?> 
                                <?php echo $totalRecords === 1 ? 'property' : 'properties'; ?>)
                            </span>
                        <?php endif; ?>
                    </h5>
                    <div class="table-actions">
                        <small class="text-muted">
                            Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $limit, $totalRecords); ?> 
                            of <?php echo number_format($totalRecords); ?>
                        </small>
                    </div>
                </div>

                <?php if (!empty($properties)): ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Property Number</th>
                                <th>Owner Details</th>
                                <th>Property Details</th>
                                <th>Zone</th>
                                <th>Rooms & Use</th>
                                <th>Amount Payable</th>
                                <th>Payment Status</th>
                                <th>Created By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($properties as $property): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($property['property_number']); ?></strong>
                                </td>
                                <td>
                                    <div>
                                        <strong><?php echo htmlspecialchars($property['owner_name']); ?></strong>
                                    </div>
                                    <?php if (!empty($property['telephone'])): ?>
                                        <small class="text-muted">
                                            <i class="fas fa-phone"></i> 
                                            <?php echo htmlspecialchars($property['telephone']); ?>
                                        </small>
                                    <?php endif; ?>
                                    <?php if (!empty($property['gender'])): ?>
                                        <br><small class="text-muted">
                                            Gender: <?php echo htmlspecialchars($property['gender']); ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div><?php echo htmlspecialchars($property['structure']); ?></div>
                                    <div class="mt-1">
                                        <span class="ownership-badge ownership-<?php echo strtolower($property['ownership_type']); ?>">
                                            <?php echo htmlspecialchars($property['ownership_type']); ?>
                                        </span>
                                    </div>
                                    <small class="text-muted">
                                        <?php echo htmlspecialchars($property['property_type']); ?>
                                    </small>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($property['zone_name'] ?? 'Not assigned'); ?>
                                </td>
                                <td>
                                    <div class="rooms-info">
                                        <i class="fas fa-door-open"></i>
                                        <strong><?php echo number_format($property['number_of_rooms']); ?></strong>
                                        <span>room<?php echo $property['number_of_rooms'] != 1 ? 's' : ''; ?></span>
                                    </div>
                                    <div class="mt-1">
                                        <span class="property-badge property-<?php echo strtolower($property['property_use']); ?>">
                                            <?php echo htmlspecialchars($property['property_use']); ?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <strong class="<?php echo $property['amount_payable'] > 0 ? 'text-danger' : 'text-success'; ?>">
                                        <?php echo formatCurrency($property['amount_payable']); ?>
                                    </strong>
                                </td>
                                <td>
                                    <span class="payment-badge payment-<?php echo strtolower(str_replace(' ', '', $property['payment_status'])); ?>">
                                        <?php echo htmlspecialchars($property['payment_status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <small class="text-muted">
                                        <?php echo htmlspecialchars(trim($property['first_name'] . ' ' . $property['last_name'])); ?>
                                    </small>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="view.php?id=<?php echo $property['property_id']; ?>" 
                                           class="btn-action btn-view" 
                                           title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="edit.php?id=<?php echo $property['property_id']; ?>" 
                                           class="btn-action btn-edit" 
                                           title="Edit Property">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-home"></i>
                    <h5>No properties found</h5>
                    <p>No properties match your current filter criteria.</p>
                    <a href="add.php" class="btn-add">
                        <i class="fas fa-plus"></i>
                        Register First Property
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination-container">
                <nav aria-label="Properties pagination">
                    <ul class="pagination">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                        
                        <?php
                        $start = max(1, $page - 2);
                        $end = min($totalPages, $page + 2);
                        
                        for ($i = $start; $i <= $end; $i++):
                        ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
                
                <div class="text-center mt-3">
                    <small class="text-muted">
                        Page <?php echo $page; ?> of <?php echo $totalPages; ?> 
                        (<?php echo number_format($totalRecords); ?> total properties)
                    </small>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
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

            // Animate stats cards with stagger effect
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
                card.classList.add('slide-in');
            });
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

        // Enhance table interactions
        document.addEventListener('DOMContentLoaded', function() {
            // Add hover effects to table rows
            const tableRows = document.querySelectorAll('.table tbody tr');
            tableRows.forEach(row => {
                row.addEventListener('mouseenter', function() {
                    this.style.transform = 'scale(1.01)';
                    this.style.transition = 'all 0.2s ease';
                });
                
                row.addEventListener('mouseleave', function() {
                    this.style.transform = 'scale(1)';
                });
            });

            // Add loading state to filter form
            const filterForm = document.querySelector('.filter-form');
            if (filterForm) {
                filterForm.addEventListener('submit', function() {
                    const submitBtn = this.querySelector('.btn-filter');
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Filtering...';
                    submitBtn.disabled = true;
                });
            }
        });

        // Quick search functionality
        let searchTimeout;
        const searchInput = document.querySelector('input[name="search"]');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    // Auto-submit form after 1 second of no typing
                    if (this.value.length >= 3 || this.value.length === 0) {
                        this.form.submit();
                    }
                }, 1000);
            });
        }
    </script>
</body>
</html>