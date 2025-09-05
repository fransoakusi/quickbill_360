<?php
/**
 * Data Collector - Businesses Management with Offline Support
 * businesses/index.php
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
    session_unset();
    session_destroy();
    setFlashMessage('error', 'Your session has expired. Please log in again.');
    header('Location: ../../index.php');
    exit();
}

$userDisplayName = getUserDisplayName($currentUser);

// Handle search and filtering
$search = $_GET['search'] ?? '';
$zone_filter = $_GET['zone'] ?? '';
$status_filter = $_GET['status'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Build query
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(business_name LIKE ? OR owner_name LIKE ? OR account_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($zone_filter)) {
    $where_conditions[] = "b.zone_id = ?";
    $params[] = $zone_filter;
}

if (!empty($status_filter)) {
    $where_conditions[] = "b.status = ?";
    $params[] = $status_filter;
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
}

// Initialize variables
$businesses = [];
$total_businesses = 0;
$total_pages = 0;
$zones = [];
$stats = ['total' => 0, 'active' => 0, 'defaulters' => 0, 'my_businesses' => 0];
$dataFromDatabase = false;

// Handle AJAX request for fresh data
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json');
    
    try {
        $db = new Database();
        
        // Get businesses with zone info
        $query = "
            SELECT 
                b.*,
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
            ORDER BY b.created_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $per_page;
        $params[] = $offset;
        
        $businesses = $db->fetchAll($query, $params);
        
        // Get total count for pagination
        $count_query = "
            SELECT COUNT(*) as total 
            FROM businesses b 
            LEFT JOIN zones z ON b.zone_id = z.zone_id
            LEFT JOIN sub_zones sz ON b.sub_zone_id = sz.sub_zone_id
            {$where_clause}
        ";
        
        $count_params = array_slice($params, 0, -2); // Remove LIMIT and OFFSET params
        $total_result = $db->fetchRow($count_query, $count_params);
        $total_businesses = $total_result['total'];
        $total_pages = ceil($total_businesses / $per_page);
        
        // Get zones for filter
        $zones = $db->fetchAll("SELECT zone_id, zone_name FROM zones ORDER BY zone_name");
        
        // Get summary stats
        $stats = $db->fetchRow("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN amount_payable > 0 THEN 1 ELSE 0 END) as defaulters,
                SUM(CASE WHEN created_by = ? THEN 1 ELSE 0 END) as my_businesses
            FROM businesses
        ", [$currentUser['user_id']]);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'businesses' => $businesses,
                'total_businesses' => $total_businesses,
                'total_pages' => $total_pages,
                'zones' => $zones,
                'stats' => $stats,
                'current_page' => $page,
                'filters' => [
                    'search' => $search,
                    'zone_filter' => $zone_filter,
                    'status_filter' => $status_filter
                ]
            ]
        ]);
        exit();
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
        exit();
    }
}

// Try to load data from database
try {
    $db = new Database();
    
    // Get businesses with zone info
    $query = "
        SELECT 
            b.*,
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
        ORDER BY b.created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $per_page;
    $params[] = $offset;
    
    $businesses = $db->fetchAll($query, $params);
    
    // Get total count for pagination
    $count_query = "
        SELECT COUNT(*) as total 
        FROM businesses b 
        LEFT JOIN zones z ON b.zone_id = z.zone_id
        LEFT JOIN sub_zones sz ON b.sub_zone_id = sz.sub_zone_id
        {$where_clause}
    ";
    
    $count_params = array_slice($params, 0, -2); // Remove LIMIT and OFFSET params
    $total_result = $db->fetchRow($count_query, $count_params);
    $total_businesses = $total_result['total'];
    $total_pages = ceil($total_businesses / $per_page);
    
    // Get zones for filter
    $zones = $db->fetchAll("SELECT zone_id, zone_name FROM zones ORDER BY zone_name");
    
    // Get summary stats
    $stats = $db->fetchRow("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN amount_payable > 0 THEN 1 ELSE 0 END) as defaulters,
            SUM(CASE WHEN created_by = ? THEN 1 ELSE 0 END) as my_businesses
        FROM businesses
    ", [$currentUser['user_id']]);
    
    $dataFromDatabase = true;
    
} catch (Exception $e) {
    // If database fails, we'll try to load from cache in JavaScript
    $businesses = [];
    $total_businesses = 0;
    $total_pages = 0;
    $zones = [];
    $stats = ['total' => 0, 'active' => 0, 'defaulters' => 0, 'my_businesses' => 0];
    $dataFromDatabase = false;
    error_log('Database error in businesses index: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Businesses - Data Collector - <?php echo APP_NAME; ?></title>
    
    <!-- Multiple Icon Sources -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    
    <!-- Bootstrap for backup -->
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
        .icon-edit::before { content: "✏️"; }
        .icon-eye::before { content: "👁️"; }
        .icon-wifi::before { content: "📶"; }
        .icon-wifi-slash::before { content: "📵"; }
        .icon-sync::before { content: "🔄"; }
        .icon-cloud::before { content: "☁️"; }
        .icon-exclamation-triangle::before { content: "⚠️"; }
        .icon-refresh::before { content: "🔃"; }
        .icon-search::before { content: "🔍"; }
        
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
        
        /* Network Status Indicator */
        .network-status {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .network-status.online {
            background: rgba(72, 187, 120, 0.2);
            color: #2f855a;
            border: 1px solid rgba(72, 187, 120, 0.3);
        }
        
        .network-status.offline {
            background: rgba(245, 101, 101, 0.2);
            color: #c53030;
            border: 1px solid rgba(245, 101, 101, 0.3);
        }
        
        .network-status.syncing {
            background: rgba(66, 153, 225, 0.2);
            color: #2b6cb0;
            border: 1px solid rgba(66, 153, 225, 0.3);
        }
        
        .network-icon {
            font-size: 14px;
        }
        
        .sync-spinner {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
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
        
        /* Main Content */
        .main-content {
            flex: 1;
            padding: 30px;
            background: #f8f9fa;
            transition: all 0.3s ease;
        }
        
        /* Offline Status Banner */
        .offline-banner {
            background: linear-gradient(135deg, #f56565 0%, #e53e3e 100%);
            color: white;
            padding: 15px 20px;
            margin-bottom: 20px;
            border-radius: 10px;
            display: none;
            align-items: center;
            gap: 15px;
        }
        
        .offline-banner.show {
            display: flex;
        }
        
        .offline-banner .icon {
            font-size: 20px;
        }
        
        .offline-info {
            flex: 1;
        }
        
        .offline-title {
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .offline-message {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .cache-info {
            background: rgba(255,255,255,0.2);
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .cache-info:hover {
            background: rgba(255,255,255,0.3);
        }
        
        /* Loading States */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.9);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 10000;
        }
        
        .loading-overlay.show {
            display: flex;
        }
        
        .loading-spinner {
            text-align: center;
            color: #38a169;
        }
        
        .loading-spinner i {
            font-size: 48px;
            margin-bottom: 15px;
            animation: spin 1s linear infinite;
        }
        
        .loading-text {
            font-size: 18px;
            font-weight: 600;
        }
        
        .loading-subtext {
            font-size: 14px;
            color: #718096;
            margin-top: 5px;
        }
        
        /* Page Header */
        .page-header {
            background: white;
            border-radius: 15px;
            padding: 25px;
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
        
        /* Stats Cards */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
            transition: all 0.3s;
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
        
        .stat-card.warning {
            background: linear-gradient(135deg, #ed8936 0%, #dd6b20 100%);
            color: white;
        }
        
        .stat-card.purple {
            background: linear-gradient(135deg, #9f7aea 0%, #805ad5 100%);
            color: white;
        }
        
        .stat-card.offline {
            background: linear-gradient(135deg, #718096 0%, #4a5568 100%);
            color: white;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 14px;
            opacity: 0.9;
        }
        
        /* Filters */
        .filters-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .filters-row {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr auto;
            gap: 15px;
            align-items: end;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-label {
            font-weight: 600;
            margin-bottom: 5px;
            color: #2d3748;
        }
        
        .form-control {
            padding: 10px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #38a169;
            box-shadow: 0 0 0 3px rgba(56, 161, 105, 0.1);
        }
        
        .btn {
            padding: 10px 20px;
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
            background: #38a169;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2f855a;
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: #718096;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #4a5568;
        }
        
        .btn-success {
            background: #48bb78;
            color: white;
        }
        
        .btn-success:hover {
            background: #38a169;
        }
        
        .btn-info {
            background: #4299e1;
            color: white;
        }
        
        .btn-info:hover {
            background: #3182ce;
        }
        
        /* Table */
        .table-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .table-header {
            background: #f7fafc;
            padding: 20px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .table-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            margin: 0;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }
        
        .table th,
        .table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .table th {
            background: #f7fafc;
            font-weight: 600;
            color: #2d3748;
        }
        
        .table tr:hover {
            background: #f7fafc;
        }
        
        .badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
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
        
        .badge-offline {
            background: #e2e8f0;
            color: #4a5568;
        }
        
        .actions {
            display: flex;
            gap: 8px;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        .btn-outline {
            background: transparent;
            border: 2px solid;
        }
        
        .btn-outline-primary {
            border-color: #38a169;
            color: #38a169;
        }
        
        .btn-outline-primary:hover {
            background: #38a169;
            color: white;
        }
        
        .btn-outline-info {
            border-color: #4299e1;
            color: #4299e1;
        }
        
        .btn-outline-info:hover {
            background: #4299e1;
            color: white;
        }
        
        .btn-outline-warning {
            border-color: #ed8936;
            color: #ed8936;
        }
        
        .btn-outline-warning:hover {
            background: #ed8936;
            color: white;
        }
        
        /* Pagination */
        .pagination-wrapper {
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .pagination-info {
            font-size: 14px;
            color: #718096;
        }
        
        .pagination {
            display: flex;
            gap: 5px;
        }
        
        .pagination a,
        .pagination span {
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            text-decoration: none;
            color: #2d3748;
            transition: all 0.3s;
        }
        
        .pagination a:hover {
            background: #f7fafc;
            border-color: #38a169;
        }
        
        .pagination .current {
            background: #38a169;
            color: white;
            border-color: #38a169;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #718096;
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
        
        /* Sync Status Messages */
        .sync-message {
            position: fixed;
            top: 100px;
            right: 20px;
            max-width: 350px;
            padding: 15px 20px;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            z-index: 10000;
            font-weight: 600;
            transform: translateX(400px);
            transition: transform 0.3s ease-out;
        }
        
        .sync-message.show {
            transform: translateX(0);
        }
        
        .sync-message.success {
            background: #48bb78;
            color: white;
        }
        
        .sync-message.error {
            background: #f56565;
            color: white;
        }
        
        .sync-message.info {
            background: #4299e1;
            color: white;
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
            
            .stats-row {
                grid-template-columns: 1fr;
            }
            
            .filters-row {
                grid-template-columns: 1fr;
            }
            
            .table-responsive {
                font-size: 14px;
            }
            
            .table th,
            .table td {
                padding: 10px;
            }
            
            .actions {
                flex-direction: column;
            }
            
            .container {
                flex-direction: column;
            }
            
            .network-status {
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
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner">
            <i class="fas fa-spinner"></i>
            <span class="icon-sync" style="display: none;"></span>
            <div class="loading-text">Loading Business Data</div>
            <div class="loading-subtext">Please wait...</div>
        </div>
    </div>

    <!-- Top Navigation -->
    <div class="top-nav">
        <div class="nav-left">
            <button class="toggle-btn" onclick="toggleSidebar()" id="toggleBtn">
                <i class="fas fa-bars"></i>
                <span class="icon-menu" style="display: none;"></span>
            </button>
            
            <a href="../index.php" class="brand">
                <i class="fas fa-clipboard-list"></i>
                <span class="icon-dashboard" style="display: none;"></span>
                Data Collector
            </a>
        </div>
        
        <div class="user-section">
            <!-- Network Status Indicator -->
            <div class="network-status" id="networkStatus">
                <div class="network-icon" id="networkIcon">
                    <i class="fas fa-wifi"></i>
                    <span class="icon-wifi" style="display: none;"></span>
                </div>
                <div class="network-text" id="networkText">Online</div>
            </div>
            
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
                            <span class="icon-user" style="display: none;"></span>
                            My Profile
                        </a>
                        <a href="#" class="dropdown-item" onclick="showCacheStatus()">
                            <i class="fas fa-sync-alt"></i>
                            <span class="icon-sync" style="display: none;"></span>
                            Cache Status
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
                
                <!-- Data Collection -->
                <div class="nav-section">
                    <div class="nav-title">Data Collection</div>
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
                            Business Locations
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../map/properties.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-map-marker-alt"></i>
                                <span class="icon-location" style="display: none;"></span>
                            </span>
                            Property Locations
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Offline Status Banner -->
            <div class="offline-banner" id="offlineBanner">
                <div class="icon">
                    <i class="fas fa-wifi-slash"></i>
                    <span class="icon-wifi-slash" style="display: none;"></span>
                </div>
                <div class="offline-info">
                    <div class="offline-title">Working Offline</div>
                    <div class="offline-message">Displaying cached business data. Some information may be outdated.</div>
                </div>
                <div class="cache-info" id="cacheInfo" onclick="showCacheStatus()">
                    <span id="cacheText">Cached Data</span>
                </div>
            </div>
            
            <!-- Page Header -->
            <div class="page-header fade-in" id="pageHeader" style="display: none;">
                <h1 class="page-title">Businesses Management</h1>
                <p class="page-subtitle">Register, view, and manage business data for the billing system</p>
            </div>

            <!-- Statistics -->
            <div class="stats-row fade-in" id="statsRow" style="display: none;">
                <!-- Stats will be populated by JavaScript -->
            </div>

            <!-- Filters -->
            <div class="filters-card fade-in" id="filtersCard" style="display: none;">
                <form method="GET" action="" id="filtersForm">
                    <div class="filters-row">
                        <div class="form-group">
                            <label class="form-label">Search</label>
                            <input type="text" name="search" class="form-control" id="searchInput"
                                   placeholder="Search by business name, owner, or account number..."
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Zone</label>
                            <select name="zone" class="form-control" id="zoneFilter">
                                <option value="">All Zones</option>
                                <!-- Options will be populated by JavaScript -->
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-control" id="statusFilter">
                                <option value="">All Status</option>
                                <option value="Active" <?php echo $status_filter == 'Active' ? 'selected' : ''; ?>>Active</option>
                                <option value="Inactive" <?php echo $status_filter == 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                <option value="Suspended" <?php echo $status_filter == 'Suspended' ? 'selected' : ''; ?>>Suspended</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i>
                                <span class="icon-search" style="display: none;"></span>
                                Search
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Actions -->
            <div style="margin-bottom: 20px; display: none;" class="fade-in" id="actionsRow">
                <a href="add.php" class="btn btn-success">
                    <i class="fas fa-plus"></i>
                    <span class="icon-plus" style="display: none;"></span>
                    Register New Business
                </a>
                <a href="../map/businesses.php" class="btn btn-primary">
                    <i class="fas fa-map"></i>
                    <span class="icon-map" style="display: none;"></span>
                    View on Map
                </a>
                <button onclick="refreshData()" class="btn btn-info" id="refreshBtn">
                    <i class="fas fa-sync-alt"></i>
                    <span class="icon-refresh" style="display: none;"></span>
                    Refresh Data
                </button>
            </div>

            <!-- Table -->
            <div class="table-card fade-in" id="tableCard" style="display: none;">
                <div class="table-header">
                    <h3 class="table-title">Business List</h3>
                </div>
                
                <div id="tableContent">
                    <!-- Content will be populated by JavaScript -->
                </div>
            </div>
        </div>
    </div>

    <script>
        // === ENHANCED OFFLINE BUSINESSES LIST SYSTEM ===
        
        // Global variables
        let isOnline = navigator.onLine;
        let currentData = null;
        let dataFromCache = false;
        let db;
        
        // IndexedDB setup for businesses list caching
        const dbName = 'BusinessesListDB';
        const dbVersion = 2;
        
        // Server-side data (if available)
        const serverData = <?php 
            if ($dataFromDatabase) {
                echo json_encode([
                    'businesses' => $businesses,
                    'total_businesses' => $total_businesses,
                    'total_pages' => $total_pages,
                    'zones' => $zones,
                    'stats' => $stats,
                    'current_page' => $page,
                    'filters' => [
                        'search' => $search,
                        'zone_filter' => $zone_filter,
                        'status_filter' => $status_filter
                    ]
                ]);
            } else {
                echo 'null';
            }
        ?>;
        
        // Debug server data
        console.log('Server data available:', !!serverData);
        console.log('Data from database:', <?php echo $dataFromDatabase ? 'true' : 'false'; ?>);
        console.log('Network status:', isOnline ? 'online' : 'offline');
        
        // Initialize IndexedDB
        function initDB() {
            return new Promise((resolve, reject) => {
                console.log('Initializing businesses list IndexedDB...');
                
                if (db) {
                    db.close();
                    db = null;
                }
                
                const request = indexedDB.open(dbName, dbVersion);
                
                request.onerror = (event) => {
                    console.error('IndexedDB error:', event.target.error);
                    reject(new Error('Failed to open IndexedDB: ' + event.target.error));
                };
                
                request.onsuccess = (event) => {
                    db = event.target.result;
                    console.log('Businesses list IndexedDB opened successfully');
                    
                    db.onerror = (event) => {
                        console.error('IndexedDB runtime error:', event.target.error);
                    };
                    
                    resolve(db);
                };
                
                request.onupgradeneeded = (event) => {
                    console.log('Upgrading businesses list IndexedDB schema...');
                    db = event.target.result;
                    
                    try {
                        // Delete existing stores
                        Array.from(db.objectStoreNames).forEach(storeName => {
                            db.deleteObjectStore(storeName);
                            console.log('Deleted old store:', storeName);
                        });
                        
                        // Create fresh object store
                        const store = db.createObjectStore('businessesList', { 
                            keyPath: 'cache_key'
                        });
                        
                        // Create indexes
                        store.createIndex('cached_at', 'cached_at', { unique: false });
                        store.createIndex('filters_hash', 'filters_hash', { unique: false });
                        
                        console.log('Businesses list IndexedDB schema created successfully');
                    } catch (error) {
                        console.error('Error creating IndexedDB schema:', error);
                        reject(error);
                    }
                };
            });
        }
        
        // Generate cache key from current filters and pagination
        function generateCacheKey() {
            const params = new URLSearchParams(window.location.search);
            const search = params.get('search') || '';
            const zone = params.get('zone') || '';
            const status = params.get('status') || '';
            const page = params.get('page') || '1';
            
            return `businesses_${search}_${zone}_${status}_${page}`;
        }
        
        // Cache businesses data
        async function cacheBusinessesData(businessesData) {
            try {
                if (!db) {
                    throw new Error('Database not initialized');
                }
                
                console.log('Caching businesses data:', businessesData);
                
                const cacheKey = generateCacheKey();
                const dataToCache = {
                    cache_key: cacheKey,
                    ...businessesData,
                    cached_at: new Date().toISOString(),
                    filters_hash: btoa(JSON.stringify({
                        search: businessesData.filters?.search || '',
                        zone_filter: businessesData.filters?.zone_filter || '',
                        status_filter: businessesData.filters?.status_filter || ''
                    })),
                    version: 2
                };
                
                return new Promise((resolve, reject) => {
                    const transaction = db.transaction(['businessesList'], 'readwrite');
                    const store = transaction.objectStore('businessesList');
                    
                    transaction.oncomplete = () => {
                        console.log('Businesses data cached successfully:', cacheKey);
                        resolve();
                    };
                    
                    transaction.onerror = (event) => {
                        console.error('Transaction failed:', event.target.error);
                        reject(new Error('Transaction failed: ' + event.target.error));
                    };
                    
                    const putRequest = store.put(dataToCache);
                    
                    putRequest.onerror = (event) => {
                        console.error('Failed to cache businesses data:', event.target.error);
                        reject(new Error('Failed to cache: ' + event.target.error));
                    };
                });
                
            } catch (error) {
                console.error('Error in cacheBusinessesData:', error);
                throw error;
            }
        }
        
        // Get cached businesses data
        async function getCachedBusinessesData() {
            try {
                if (!db) {
                    throw new Error('Database not initialized');
                }
                
                const cacheKey = generateCacheKey();
                
                return new Promise((resolve, reject) => {
                    const transaction = db.transaction(['businessesList'], 'readonly');
                    const store = transaction.objectStore('businessesList');
                    
                    const getRequest = store.get(cacheKey);
                    
                    getRequest.onsuccess = () => {
                        const data = getRequest.result;
                        console.log('Retrieved cached data for key:', cacheKey, data ? 'found' : 'not found');
                        resolve(data);
                    };
                    
                    getRequest.onerror = (event) => {
                        console.error('Failed to get cached businesses data:', event.target.error);
                        reject(new Error('Failed to get cached data: ' + event.target.error));
                    };
                });
                
            } catch (error) {
                console.error('Error in getCachedBusinessesData:', error);
                return null;
            }
        }
        
        // Format currency
        function formatCurrency(amount) {
            return '₵ ' + parseFloat(amount || 0).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }
        
        // Format date
        function formatDate(dateString) {
            if (!dateString) return 'Not available';
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
        }
        
        // Populate businesses data in UI
        function populateBusinessesData(data) {
            if (!data) {
                showError('No businesses data available');
                return;
            }
            
            currentData = data;
            
            // Populate statistics
            populateStats(data.stats || {});
            
            // Populate zones filter
            populateZonesFilter(data.zones || []);
            
            // Populate businesses table
            populateBusinessesTable(data.businesses || []);
            
            // Show all sections
            document.getElementById('pageHeader').style.display = 'block';
            document.getElementById('statsRow').style.display = 'grid';
            document.getElementById('filtersCard').style.display = 'block';
            document.getElementById('actionsRow').style.display = 'block';
            document.getElementById('tableCard').style.display = 'block';
            document.getElementById('loadingOverlay').classList.remove('show');
            
            // Update refresh button state
            const refreshBtn = document.getElementById('refreshBtn');
            if (refreshBtn) {
                refreshBtn.style.display = isOnline ? 'inline-flex' : 'none';
            }
        }
        
        // Populate statistics cards
        function populateStats(stats) {
            let cacheIndicator = '';
            if (dataFromCache) {
                cacheIndicator = `
                    <div class="stat-card offline">
                        <div class="stat-value">
                            <i class="fas fa-cloud-download-alt"></i>
                        </div>
                        <div class="stat-label">Cached Data</div>
                    </div>
                `;
            }
            
            document.getElementById('statsRow').innerHTML = `
                <div class="stat-card primary">
                    <div class="stat-value">${(stats.total || 0).toLocaleString()}</div>
                    <div class="stat-label">Total Businesses</div>
                </div>
                <div class="stat-card info">
                    <div class="stat-value">${(stats.active || 0).toLocaleString()}</div>
                    <div class="stat-label">Active Businesses</div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-value">${(stats.defaulters || 0).toLocaleString()}</div>
                    <div class="stat-label">Defaulters</div>
                </div>
                <div class="stat-card purple">
                    <div class="stat-value">${(stats.my_businesses || 0).toLocaleString()}</div>
                    <div class="stat-label">My Registrations</div>
                </div>
                ${cacheIndicator}
            `;
        }
        
        // Populate zones filter
        function populateZonesFilter(zones) {
            const zoneFilter = document.getElementById('zoneFilter');
            const currentValue = zoneFilter.value;
            
            let optionsHtml = '<option value="">All Zones</option>';
            zones.forEach(zone => {
                const selected = currentValue == zone.zone_id ? 'selected' : '';
                optionsHtml += `<option value="${zone.zone_id}" ${selected}>${zone.zone_name}</option>`;
            });
            
            zoneFilter.innerHTML = optionsHtml;
        }
        
        // Populate businesses table
        function populateBusinessesTable(businesses) {
            const tableContent = document.getElementById('tableContent');
            
            if (businesses.length === 0) {
                tableContent.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-building"></i>
                        <span class="icon-building" style="display: none;"></span>
                        <h3>No businesses found</h3>
                        <p>No businesses match your search criteria. Try adjusting your filters or register a new business.</p>
                        <a href="add.php" class="btn btn-success">
                            <i class="fas fa-plus"></i>
                            <span class="icon-plus" style="display: none;"></span>
                            Register First Business
                        </a>
                    </div>
                `;
                return;
            }
            
            let tableHtml = `
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Account Number</th>
                                <th>Business Name</th>
                                <th>Owner</th>
                                <th>Type</th>
                                <th>Zone</th>
                                <th>Status</th>
                                <th>Amount Payable</th>
                                <th>Payment Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            businesses.forEach(business => {
                const statusClass = business.status === 'Active' ? 'badge-success' : 'badge-warning';
                const paymentStatusClass = business.payment_status === 'Up to Date' ? 'badge-success' : 'badge-danger';
                
                tableHtml += `
                    <tr>
                        <td>
                            <strong>${business.account_number}</strong>
                        </td>
                        <td>
                            <div>
                                <strong>${business.business_name}</strong>
                                <br>
                                <small style="color: #718096;">
                                    ${business.category}
                                </small>
                            </div>
                        </td>
                        <td>
                            <div>
                                ${business.owner_name}
                                ${business.telephone ? `<br><small style="color: #718096;">${business.telephone}</small>` : ''}
                            </div>
                        </td>
                        <td>${business.business_type}</td>
                        <td>
                            <div>
                                ${business.zone_name || 'N/A'}
                                ${business.sub_zone_name ? `<br><small style="color: #718096;">${business.sub_zone_name}</small>` : ''}
                            </div>
                        </td>
                        <td>
                            <span class="badge ${statusClass}">
                                ${business.status}
                            </span>
                        </td>
                        <td>
                            <strong>${formatCurrency(business.amount_payable)}</strong>
                        </td>
                        <td>
                            <span class="badge ${paymentStatusClass}">
                                ${business.payment_status}
                            </span>
                        </td>
                        <td>
                            <div class="actions">
                                <a href="view.php?id=${business.business_id}" 
                                   class="btn btn-sm btn-outline-info">
                                    <i class="fas fa-eye"></i>
                                    <span class="icon-eye" style="display: none;"></span>
                                    View
                                </a>
                                <a href="edit.php?id=${business.business_id}" 
                                   class="btn btn-sm btn-outline-primary" ${!isOnline ? 'style="display: none;"' : ''}>
                                    <i class="fas fa-edit"></i>
                                    <span class="icon-edit" style="display: none;"></span>
                                    Edit
                                </a>
                            </div>
                        </td>
                    </tr>
                `;
            });
            
            tableHtml += `
                        </tbody>
                    </table>
                </div>
            `;
            
            // Add pagination if needed
            if (currentData.total_pages > 1) {
                tableHtml += buildPagination();
            }
            
            tableContent.innerHTML = tableHtml;
        }
        
        // Build pagination HTML
        function buildPagination() {
            const currentPage = currentData.current_page || 1;
            const totalPages = currentData.total_pages || 1;
            const totalBusinesses = currentData.total_businesses || 0;
            const perPage = 10;
            const offset = (currentPage - 1) * perPage;
            
            let paginationHtml = `
                <div class="pagination-wrapper">
                    <div class="pagination-info">
                        Showing ${offset + 1} to ${Math.min(offset + perPage, totalBusinesses)} 
                        of ${totalBusinesses} businesses
                    </div>
            `;
            
            if (totalPages > 1) {
                paginationHtml += '<div class="pagination">';
                
                // Previous button
                if (currentPage > 1) {
                    paginationHtml += `<a href="?page=${currentPage - 1}&${buildQueryString()}" ${!isOnline ? 'onclick="return false;" style="opacity: 0.5; cursor: not-allowed;"' : ''}>
                        <i class="fas fa-chevron-left"></i>
                    </a>`;
                }
                
                // Page numbers
                for (let i = Math.max(1, currentPage - 2); i <= Math.min(totalPages, currentPage + 2); i++) {
                    if (i === currentPage) {
                        paginationHtml += `<span class="current">${i}</span>`;
                    } else {
                        paginationHtml += `<a href="?page=${i}&${buildQueryString()}" ${!isOnline ? 'onclick="return false;" style="opacity: 0.5; cursor: not-allowed;"' : ''}>${i}</a>`;
                    }
                }
                
                // Next button
                if (currentPage < totalPages) {
                    paginationHtml += `<a href="?page=${currentPage + 1}&${buildQueryString()}" ${!isOnline ? 'onclick="return false;" style="opacity: 0.5; cursor: not-allowed;"' : ''}>
                        <i class="fas fa-chevron-right"></i>
                    </a>`;
                }
                
                paginationHtml += '</div>';
            }
            
            paginationHtml += '</div>';
            return paginationHtml;
        }
        
        // Build query string for pagination
        function buildQueryString() {
            const params = new URLSearchParams();
            const search = document.getElementById('searchInput')?.value || '';
            const zone = document.getElementById('zoneFilter')?.value || '';
            const status = document.getElementById('statusFilter')?.value || '';
            
            if (search) params.set('search', search);
            if (zone) params.set('zone', zone);
            if (status) params.set('status', status);
            
            return params.toString();
        }
        
        // Load businesses data (online or from cache)
        async function loadBusinessesData() {
            try {
                document.getElementById('loadingOverlay').classList.add('show');
                
                console.log('Loading businesses data...');
                console.log('Server data available:', !!serverData);
                console.log('Online status:', isOnline);
                
                // If we have server data and we're online, use it and cache it
                if (serverData && isOnline) {
                    console.log('Using server data:', serverData);
                    try {
                        await cacheBusinessesData(serverData);
                        console.log('Data cached successfully');
                    } catch (cacheError) {
                        console.warn('Failed to cache data:', cacheError);
                    }
                    dataFromCache = false;
                    populateBusinessesData(serverData);
                    return;
                }
                
                // If offline or no server data, try to load from cache
                console.log('Attempting to load from cache...');
                const cachedData = await getCachedBusinessesData();
                
                if (cachedData) {
                    console.log('Using cached data:', cachedData);
                    dataFromCache = true;
                    populateBusinessesData(cachedData);
                    return;
                }
                
                // No data available
                let errorMessage = 'No businesses data available';
                if (!isOnline) {
                    errorMessage += ' offline. Please connect to the internet to load business data.';
                } else {
                    errorMessage += '. Please try refreshing the page.';
                }
                
                throw new Error(errorMessage);
                
            } catch (error) {
                console.error('Error loading businesses data:', error);
                showError(error.message);
            }
        }
        
        // Show error message
        function showError(message) {
            document.getElementById('loadingOverlay').classList.remove('show');
            
            const errorHtml = `
                <div class="page-header fade-in" style="text-align: center; padding: 60px 30px;">
                    <div style="color: #e53e3e; margin-bottom: 20px;">
                        <i class="fas fa-exclamation-triangle" style="font-size: 48px; margin-bottom: 15px;"></i>
                        <h3>Unable to Load Business Data</h3>
                        <p style="color: #718096; margin-bottom: 30px;">${message}</p>
                        <div style="background: #f7fafc; padding: 15px; border-radius: 8px; margin: 20px 0; text-align: left;">
                            <strong>Debug Information:</strong><br>
                            • Network Status: ${isOnline ? 'Online' : 'Offline'}<br>
                            • Server Data Available: ${serverData ? 'Yes' : 'No'}<br>
                            • URL: ${window.location.href}
                        </div>
                    </div>
                    <div style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap;">
                        <button onclick="refreshData()" class="btn btn-primary" ${!isOnline ? 'disabled' : ''}>
                            <i class="fas fa-sync-alt"></i>
                            ${isOnline ? 'Try Again' : 'Offline - Cannot Retry'}
                        </button>
                        <button onclick="showCacheStatus()" class="btn btn-info">
                            <i class="fas fa-info-circle"></i>
                            Check Cache
                        </button>
                        <a href="add.php" class="btn btn-success" ${!isOnline ? 'style="opacity: 0.5; pointer-events: none;"' : ''}>
                            <i class="fas fa-plus"></i>
                            Register New Business
                        </a>
                    </div>
                </div>
            `;
            
            document.querySelector('.main-content').innerHTML = errorHtml;
        }
        
        // Network status detection and UI updates
        function updateNetworkStatus() {
            isOnline = navigator.onLine;
            const networkStatus = document.getElementById('networkStatus');
            const networkIcon = document.getElementById('networkIcon');
            const networkText = document.getElementById('networkText');
            const offlineBanner = document.getElementById('offlineBanner');
            
            if (isOnline) {
                networkStatus.className = 'network-status online';
                networkIcon.innerHTML = '<i class="fas fa-wifi"></i><span class="icon-wifi" style="display: none;"></span>';
                networkText.textContent = 'Online';
                offlineBanner.classList.remove('show');
                
                // Show refresh and edit buttons
                const refreshBtn = document.getElementById('refreshBtn');
                if (refreshBtn) refreshBtn.style.display = 'inline-flex';
                
                // Enable edit buttons in table
                document.querySelectorAll('.btn-outline-primary').forEach(btn => {
                    btn.style.display = 'inline-flex';
                });
                
                // Enable pagination links
                document.querySelectorAll('.pagination a').forEach(link => {
                    link.onclick = null;
                    link.style.opacity = '1';
                    link.style.cursor = 'pointer';
                });
                
                // Refresh data if we were using cached data
                if (dataFromCache && currentData) {
                    setTimeout(() => {
                        refreshData();
                    }, 1000);
                }
            } else {
                networkStatus.className = 'network-status offline';
                networkIcon.innerHTML = '<i class="fas fa-wifi-slash"></i><span class="icon-wifi-slash" style="display: none;"></span>';
                networkText.textContent = 'Offline';
                offlineBanner.classList.add('show');
                
                // Hide refresh button and edit buttons
                const refreshBtn = document.getElementById('refreshBtn');
                if (refreshBtn) refreshBtn.style.display = 'none';
                
                // Hide edit buttons in table
                document.querySelectorAll('.btn-outline-primary').forEach(btn => {
                    btn.style.display = 'none';
                });
                
                // Disable pagination links
                document.querySelectorAll('.pagination a').forEach(link => {
                    link.onclick = () => false;
                    link.style.opacity = '0.5';
                    link.style.cursor = 'not-allowed';
                });
                
                updateCacheInfo();
            }
        }
        
        // Update cache info
        function updateCacheInfo() {
            const cacheInfo = document.getElementById('cacheInfo');
            const cacheText = document.getElementById('cacheText');
            
            if (currentData && currentData.cached_at) {
                const cachedDate = new Date(currentData.cached_at);
                const now = new Date();
                const diffHours = Math.round((now - cachedDate) / (1000 * 60 * 60));
                
                if (diffHours < 1) {
                    cacheText.textContent = 'Recently Cached';
                } else if (diffHours < 24) {
                    cacheText.textContent = `Cached ${diffHours}h ago`;
                } else {
                    const diffDays = Math.round(diffHours / 24);
                    cacheText.textContent = `Cached ${diffDays}d ago`;
                }
            } else {
                cacheText.textContent = 'Cached Data';
            }
        }
        
        // Refresh businesses data
        async function refreshData() {
            if (!isOnline) {
                showSyncMessage('error', 'Cannot refresh data while offline');
                return;
            }
            
            try {
                updateSyncStatus('syncing', 'Refreshing data...');
                
                // Build current query string
                const params = new URLSearchParams(window.location.search);
                params.set('ajax', '1');
                
                const fetchUrl = 'index.php?' + params.toString();
                console.log('Fetching from URL:', fetchUrl);
                
                const response = await fetch(fetchUrl, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                
                console.log('Response status:', response.status);
                
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                
                const responseText = await response.text();
                console.log('Raw response length:', responseText.length);
                
                let freshData;
                try {
                    freshData = JSON.parse(responseText);
                    console.log('Parsed JSON data:', freshData);
                } catch (parseError) {
                    console.error('JSON parse error:', parseError);
                    throw new Error(`Invalid JSON response: ${parseError.message}`);
                }
                
                if (freshData.success && freshData.data) {
                    console.log('Attempting to cache fresh data...');
                    try {
                        await cacheBusinessesData(freshData.data);
                        console.log('Data cached successfully');
                    } catch (cacheError) {
                        console.error('Cache error:', cacheError);
                    }
                    
                    dataFromCache = false;
                    populateBusinessesData(freshData.data);
                    showSyncMessage('success', 'Business data refreshed successfully');
                } else {
                    throw new Error(freshData.message || 'Failed to fetch fresh data');
                }
                
            } catch (error) {
                console.error('Error refreshing data:', error);
                showSyncMessage('error', 'Failed to refresh data: ' + error.message);
            } finally {
                updateSyncStatus('online', 'Online');
            }
        }
        
        // Update sync status
        function updateSyncStatus(status, message) {
            const networkStatus = document.getElementById('networkStatus');
            const networkIcon = document.getElementById('networkIcon');
            const networkText = document.getElementById('networkText');
            
            switch (status) {
                case 'syncing':
                    networkStatus.className = 'network-status syncing';
                    networkIcon.innerHTML = '<i class="fas fa-sync-alt sync-spinner"></i><span class="icon-sync" style="display: none;"></span>';
                    networkText.textContent = 'Syncing...';
                    break;
                case 'online':
                    networkStatus.className = 'network-status online';
                    networkIcon.innerHTML = '<i class="fas fa-wifi"></i><span class="icon-wifi" style="display: none;"></span>';
                    networkText.textContent = 'Online';
                    break;
            }
        }
        
        // Show sync messages
        function showSyncMessage(type, message) {
            const messageDiv = document.createElement('div');
            messageDiv.className = `sync-message ${type}`;
            messageDiv.innerHTML = `
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                    <div>${message}</div>
                </div>
            `;
            
            document.body.appendChild(messageDiv);
            
            setTimeout(() => {
                messageDiv.classList.add('show');
            }, 100);
            
            setTimeout(() => {
                messageDiv.classList.remove('show');
                setTimeout(() => {
                    if (messageDiv.parentNode) {
                        messageDiv.parentNode.removeChild(messageDiv);
                    }
                }, 300);
            }, 5000);
        }
        
        // Show cache status
        async function showCacheStatus() {
            try {
                const cachedData = await getCachedBusinessesData();
                
                if (cachedData) {
                    const cachedTime = new Date(cachedData.cached_at).toLocaleString();
                    const businessCount = cachedData.businesses ? cachedData.businesses.length : 0;
                    const message = `Cache Status:\n\nBusinesses cached: ${businessCount}\nCached: ${cachedTime}\nNetwork: ${isOnline ? 'Online' : 'Offline'}`;
                    
                    alert(message);
                } else {
                    alert('Cache Status:\n\nNo cached data found for current filters.\nNetwork: ' + (isOnline ? 'Online' : 'Offline'));
                }
                
                if (isOnline && confirm('Would you like to refresh the data now?')) {
                    refreshData();
                }
            } catch (error) {
                alert('Error checking cache status: ' + error.message);
            }
        }
        
        // Handle form submissions for filtering (offline awareness)
        document.addEventListener('DOMContentLoaded', function() {
            const filtersForm = document.getElementById('filtersForm');
            if (filtersForm) {
                filtersForm.addEventListener('submit', function(e) {
                    if (!isOnline) {
                        e.preventDefault();
                        showSyncMessage('error', 'Cannot search while offline. Please connect to the internet.');
                        return false;
                    }
                });
            }
        });
        
        // Initialize system
        document.addEventListener('DOMContentLoaded', async function() {
            try {
                let retryCount = 0;
                const maxRetries = 3;
                
                while (retryCount < maxRetries) {
                    try {
                        await initDB();
                        console.log('IndexedDB initialized successfully');
                        break;
                    } catch (error) {
                        retryCount++;
                        console.warn(`IndexedDB init attempt ${retryCount} failed:`, error);
                        
                        if (retryCount >= maxRetries) {
                            console.error('Failed to initialize IndexedDB after', maxRetries, 'attempts');
                            showSyncMessage('error', 'Failed to initialize offline storage');
                            break;
                        }
                        
                        await new Promise(resolve => setTimeout(resolve, 1000 * retryCount));
                    }
                }
                
                updateNetworkStatus();
                await loadBusinessesData();
                
                window.addEventListener('online', updateNetworkStatus);
                window.addEventListener('offline', updateNetworkStatus);
                
                console.log('Businesses list system initialized successfully');
                
            } catch (error) {
                console.error('Failed to initialize businesses list system:', error);
                showError('Failed to initialize: ' + error.message);
            }
            
            // Font Awesome fallback check
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
        
        // === END ENHANCED OFFLINE BUSINESSES LIST SYSTEM ===
        
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

        // Add smooth hover effects
        document.addEventListener('DOMContentLoaded', function() {
            // Animate stat cards on hover
            document.addEventListener('mouseenter', function(e) {
                if (e.target.classList.contains('stat-card')) {
                    e.target.style.transform = 'translateY(-8px) scale(1.02)';
                }
            }, true);
            
            document.addEventListener('mouseleave', function(e) {
                if (e.target.classList.contains('stat-card')) {
                    e.target.style.transform = 'translateY(0) scale(1)';
                }
            }, true);
        });
    </script>
</body>
</html>