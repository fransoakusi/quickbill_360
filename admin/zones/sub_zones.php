<?php
/**
 * Zone Management - Sub-Zone Management
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
if (!hasPermission('zones.view')) {
    setFlashMessage('error', 'Access denied. You do not have permission to manage sub-zones.');
    header('Location: index.php');
    exit();
}

// Generate CSRF token
$csrf_token = generateCsrfToken();

$pageTitle = 'Sub-Zone Management';
$currentUser = getCurrentUser();
$userDisplayName = getUserDisplayName($currentUser);

// Get zone ID from URL
$zoneId = intval($_GET['zone_id'] ?? 0);

if (!$zoneId) {
    setFlashMessage('error', 'Invalid zone ID.');
    header('Location: index.php');
    exit();
}

// Handle search and filtering
$search = sanitizeInput($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

try {
    $db = new Database();
    
    // Get zone information
    $zone = $db->fetchRow("SELECT * FROM zones WHERE zone_id = ?", [$zoneId]);
    
    if (!$zone) {
        setFlashMessage('error', 'Zone not found.');
        header('Location: index.php');
        exit();
    }
    
    // Build query conditions for sub-zones
    $conditions = ["sz.zone_id = ?"];
    $params = [$zoneId];
    
    if ($search) {
        $conditions[] = "(sz.sub_zone_name LIKE ? OR sz.sub_zone_code LIKE ? OR sz.description LIKE ?)";
        $searchTerm = "%$search%";
        $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
    }
    
    $whereClause = 'WHERE ' . implode(' AND ', $conditions);
    
    // Get total count for pagination
    $countQuery = "SELECT COUNT(*) as total FROM sub_zones sz $whereClause";
    $totalResult = $db->fetchRow($countQuery, $params);
    $totalSubZones = $totalResult['total'] ?? 0;
    $totalPages = ceil($totalSubZones / $perPage);
    
    // Get sub-zones with pagination and counts
    $query = "SELECT sz.*, 
                     u.first_name, u.last_name,
                     COUNT(CASE WHEN b.sub_zone_id IS NOT NULL THEN 1 END) as business_count,
                     COUNT(CASE WHEN p.zone_id IS NOT NULL THEN 1 END) as property_count,
                     COALESCE(SUM(CASE WHEN b.sub_zone_id IS NOT NULL THEN b.amount_payable ELSE 0 END), 0) as business_revenue,
                     COALESCE(SUM(CASE WHEN p.zone_id IS NOT NULL THEN p.amount_payable ELSE 0 END), 0) as property_revenue
              FROM sub_zones sz 
              LEFT JOIN users u ON sz.created_by = u.user_id
              LEFT JOIN businesses b ON sz.sub_zone_id = b.sub_zone_id
              LEFT JOIN properties p ON sz.sub_zone_id = p.zone_id
              $whereClause 
              GROUP BY sz.sub_zone_id
              ORDER BY sz.created_at DESC 
              LIMIT $perPage OFFSET $offset";
    
    $subZones = $db->fetchAll($query, $params);
    
    // Get zone statistics
    $zoneStats = [
        'total_sub_zones' => $db->fetchRow("SELECT COUNT(*) as count FROM sub_zones WHERE zone_id = ?", [$zoneId])['count'] ?? 0,
        'total_businesses' => $db->fetchRow("SELECT COUNT(*) as count FROM businesses WHERE zone_id = ?", [$zoneId])['count'] ?? 0,
        'total_properties' => $db->fetchRow("SELECT COUNT(*) as count FROM properties WHERE zone_id = ?", [$zoneId])['count'] ?? 0,
        'businesses_with_subzone' => $db->fetchRow("SELECT COUNT(*) as count FROM businesses WHERE zone_id = ? AND sub_zone_id IS NOT NULL", [$zoneId])['count'] ?? 0,
    ];
    
} catch (Exception $e) {
    writeLog("Sub-zones management error: " . $e->getMessage(), 'ERROR');
    setFlashMessage('error', 'An error occurred while loading sub-zones.');
    $subZones = [];
    $totalSubZones = 0;
    $totalPages = 0;
    $zoneStats = ['total_sub_zones' => 0, 'total_businesses' => 0, 'total_properties' => 0, 'businesses_with_subzone' => 0];
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
        .icon-plus::before { content: "➕"; }
        .icon-search::before { content: "🔍"; }
        .icon-edit::before { content: "✏️"; }
        .icon-trash::before { content: "🗑️"; }
        .icon-eye::before { content: "👁️"; }
        .icon-layer::before { content: "🏷️"; }
        .icon-user::before { content: "👤"; }
        .icon-location::before { content: "📍"; }
        .icon-money::before { content: "💰"; }
        
        /* Top Navigation */
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
        
        /* Zone Header */
        .zone-header {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
        }
        
        .zone-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .zone-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .zone-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 24px;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }
        
        .zone-details h1 {
            font-size: 24px;
            font-weight: bold;
            color: #2d3748;
            margin: 0 0 5px 0;
        }
        
        .zone-details .zone-code {
            font-size: 14px;
            color: #667eea;
            font-weight: 600;
            margin-bottom: 5px;
            font-family: monospace;
            background: #f1f5f9;
            padding: 4px 8px;
            border-radius: 6px;
            display: inline-block;
        }
        
        .zone-description {
            color: #64748b;
            font-size: 14px;
        }
        
        .action-buttons {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
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
        
        .btn-success {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(72, 187, 120, 0.3);
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
        
        /* Stats Cards */
        .stats-row {
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .stat-card.success {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
        }
        
        .stat-card.warning {
            background: linear-gradient(135deg, #ed8936 0%, #dd6b20 100%);
            color: white;
        }
        
        .stat-card.info {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
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
            font-size: 24px;
            opacity: 0.8;
        }
        
        .stat-value {
            font-size: 28px;
            font-weight: bold;
        }
        
        /* Filters */
        .filters-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .filters-row {
            display: grid;
            grid-template-columns: 3fr auto;
            gap: 15px;
            align-items: end;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-label {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .form-control {
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        /* Sub-Zones Table */
        .subzones-table-card {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .table-header {
            background: #f8fafc;
            padding: 20px 25px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .table-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            margin: 0;
        }
        
        .subzones-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .subzones-table th {
            background: #f8fafc;
            padding: 15px 20px;
            text-align: left;
            font-weight: 600;
            color: #2d3748;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
        }
        
        .subzones-table td {
            padding: 20px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }
        
        .subzones-table tr:hover {
            background: #f8fafc;
        }
        
        .subzone-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
        }
        
        .subzone-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .subzone-details h6 {
            margin: 0 0 5px 0;
            font-weight: 600;
            color: #2d3748;
        }
        
        .subzone-details p {
            margin: 0;
            color: #64748b;
            font-size: 14px;
        }
        
        .subzone-code {
            padding: 4px 8px;
            background: #f1f5f9;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            color: #475569;
            font-family: monospace;
        }
        
        .count-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            min-width: 24px;
            text-align: center;
        }
        
        .count-businesses {
            background: #dcfce7;
            color: #166534;
        }
        
        .count-properties {
            background: #fef3c7;
            color: #d97706;
        }
        
        .revenue-amount {
            font-weight: 600;
            color: #2d3748;
        }
        
        .action-btn {
            width: 35px;
            height: 35px;
            border: none;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 14px;
            margin-right: 5px;
        }
        
        .action-btn.view {
            background: #dbeafe;
            color: #1d4ed8;
        }
        
        .action-btn.view:hover {
            background: #1d4ed8;
            color: white;
        }
        
        .action-btn.edit {
            background: #fef3c7;
            color: #d97706;
        }
        
        .action-btn.edit:hover {
            background: #d97706;
            color: white;
        }
        
        .action-btn.delete {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .action-btn.delete:hover {
            background: #dc2626;
            color: white;
        }
        
        .action-buttons-table {
            display: flex;
            gap: 5px;
        }
        
        /* Pagination */
        .pagination-container {
            padding: 25px;
            background: white;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .pagination-info {
            color: #64748b;
            font-size: 14px;
        }
        
        .pagination {
            display: flex;
            gap: 5px;
        }
        
        .pagination a, .pagination span {
            padding: 8px 12px;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .pagination a {
            color: #64748b;
            background: #f1f5f9;
        }
        
        .pagination a:hover {
            background: #667eea;
            color: white;
        }
        
        .pagination .current {
            background: #667eea;
            color: white;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #64748b;
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        /* Alert */
        .alert {
            background: #d1fae5;
            border: 1px solid #9ae6b4;
            border-radius: 10px;
            padding: 15px;
            color: #065f46;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert.error {
            background: #fee2e2;
            border-color: #fecaca;
            color: #991b1b;
        }
        
        /* Responsive */
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
            
            .filters-row {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .header-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .zone-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .stats-row {
                grid-template-columns: 1fr;
            }
            
            .subzones-table {
                font-size: 14px;
            }
            
            .subzones-table th,
            .subzones-table td {
                padding: 10px;
            }
            
            .action-buttons-table {
                flex-direction: column;
                gap: 5px;
            }
        }
        
        /* Animations */
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
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
                    display: inline-block;
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
                        <a href="../profile/index.php" class="dropdown-item">
                            <i class="fas fa-user"></i>
                            <span class="icon-user" style="display: none;"></span>
                            My Profile
                        </a>
                        <a href="../settings/account.php" class="dropdown-item">
                            <i class="fas fa-cog"></i>
                            <span class="icon-cog" style="display: none;"></span>
                            Account Settings
                        </a>
                        <a href="../activity/index.php" class="dropdown-item">
                            <i class="fas fa-history"></i>
                            <span class="icon-chart" style="display: none;"></span>
                            Activity Log
                        </a>
                        <a href="../support/index.php" class="dropdown-item">
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
                    <div class="nav-item">
                        <a href="index.php" class="nav-link active">
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
                        <a href="../fees/index.php" class="nav-link">
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
                    <a href="index.php">Zone Management</a>
                    <span>/</span>
                    <a href="view.php?id=<?php echo $zoneId; ?>"><?php echo htmlspecialchars($zone['zone_name']); ?></a>
                    <span>/</span>
                    <span class="breadcrumb-current">Sub-Zones</span>
                </div>
            </div>

            <!-- Flash Messages -->
            <?php 
            $flashMessages = getFlashMessages();
            if (!empty($flashMessages)): 
            ?>
                <?php foreach ($flashMessages as $message): ?>
                    <div class="alert alert-<?php echo $message['type']; ?>">
                        <i class="fas fa-<?php echo $message['type'] === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                        <?php echo htmlspecialchars($message['message']); ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Zone Header -->
            <div class="zone-header">
                <div class="header-content">
                    <div class="zone-info">
                        <div class="zone-avatar">
                            <i class="fas fa-layer-group"></i>
                            <span class="icon-layer" style="display: none;"></span>
                        </div>
                        <div class="zone-details">
                            <h1>Sub-Zones: <?php echo htmlspecialchars($zone['zone_name']); ?></h1>
                            <?php if ($zone['zone_code']): ?>
                                <div class="zone-code"><?php echo htmlspecialchars($zone['zone_code']); ?></div>
                            <?php endif; ?>
                            <?php if ($zone['description']): ?>
                                <div class="zone-description"><?php echo htmlspecialchars($zone['description']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="action-buttons">
                        <a href="add_sub_zone.php?zone_id=<?php echo $zoneId; ?>" class="btn btn-success">
                            <i class="fas fa-plus"></i>
                            <span class="icon-plus" style="display: none;"></span>
                            Add Sub-Zone
                        </a>
                        
                        <a href="view.php?id=<?php echo $zoneId; ?>" class="btn btn-primary">
                            <i class="fas fa-eye"></i>
                            <span class="icon-eye" style="display: none;"></span>
                            Zone Overview
                        </a>
                        
                        <a href="index.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i>
                            Back to Zones
                        </a>
                    </div>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-row">
                <div class="stat-card primary">
                    <div class="stat-header">
                        <div class="stat-title">Sub-Zones</div>
                        <div class="stat-icon">
                            <i class="fas fa-layer-group"></i>
                            <span class="icon-layer" style="display: none;"></span>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($zoneStats['total_sub_zones']); ?></div>
                </div>

                <div class="stat-card success">
                    <div class="stat-header">
                        <div class="stat-title">Total Businesses</div>
                        <div class="stat-icon">
                            <i class="fas fa-building"></i>
                            <span class="icon-building" style="display: none;"></span>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($zoneStats['total_businesses']); ?></div>
                </div>

                <div class="stat-card warning">
                    <div class="stat-header">
                        <div class="stat-title">With Sub-Zone</div>
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($zoneStats['businesses_with_subzone']); ?></div>
                </div>

                <div class="stat Dichiarazione di conformità stat-card info">
                    <div class="stat-header">
                        <div class="stat-title">Properties</div>
                        <div class="stat-icon">
                            <i class="fas fa-home"></i>
                            <span class="icon-home" style="display: none;"></span>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($zoneStats['total_properties']); ?></div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-card">
                <form method="GET" action="">
                    <input type="hidden" name="zone_id" value="<?php echo $zoneId; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <div class="filters-row">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-search"></i>
                                <span class="icon-search" style="display: none;"></span>
                                Search Sub-Zones
                            </label>
                            <input type="text" name="search" class="form-control" 
                                   placeholder="Search by sub-zone name, code, or description..." 
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        
                        <div style="display: flex; gap: 10px;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i>
                                <span class="icon-search" style="display: none;"></span>
                                Search
                            </button>
                            <a href="sub_zones.php?zone_id=<?php echo $zoneId; ?>" class="btn btn-secondary">
                                <i class="fas fa-undo"></i>
                                Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Sub-Zones Table -->
            <div class="subzones-table-card">
                <div class="table-header">
                    <h3 class="table-title">
                        Sub-Zones (<?php echo number_format($totalSubZones); ?> total)
                    </h3>
                    <a href="add_sub_zone.php?zone_id=<?php echo $zoneId; ?>" class="btn btn-success">
                        <i class="fas fa-plus"></i>
                        <span class="icon-plus" style="display: none;"></span>
                        Add Sub-Zone
                    </a>
                </div>

                <?php if (empty($subZones)): ?>
                    <div class="empty-state">
                        <i class="fas fa-layer-group"></i>
                        <span class="icon-layer" style="display: none;"></span>
                        <h3>No Sub-Zones Found</h3>
                        <p>No sub-zones match your current search criteria or this zone doesn't have any sub-zones yet.</p>
                        <a href="add_sub_zone.php?zone_id=<?php echo $zoneId; ?>" class="btn btn-success" style="margin-top: 15px;">
                            <i class="fas fa-plus"></i>
                            <span class="icon-plus" style="display: none;"></span>
                            Add First Sub-Zone
                        </a>
                    </div>
                <?php else: ?>
                    <table class="subzones-table">
                        <thead>
                            <tr>
                                <th>Sub-Zone Details</th>
                                <th>Code</th>
                                <th>Businesses</th>
                                <th>Properties</th>
                                <th>Revenue</th>
                                <th>Created By</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subZones as $subZone): ?>
                                <tr>
                                    <td>
                                        <div class="subzone-info">
                                            <div class="subzone-icon">
                                                <i class="fas fa-layer-group"></i>
                                                <span class="icon-layer" style="display: none;"></span>
                                            </div>
                                            <div class="subzone-details">
                                                <h6><?php echo htmlspecialchars($subZone['sub_zone_name']); ?></h6>
                                                <p><?php echo htmlspecialchars($subZone['description'] ?? 'No description'); ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($subZone['sub_zone_code']): ?>
                                            <span class="subzone-code">
                                                <?php echo htmlspecialchars($subZone['sub_zone_code']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: #94a3b8; font-style: italic;">No code</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="count-badge count-businesses">
                                            <?php echo number_format($subZone['business_count']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="count-badge count-properties">
                                            <?php echo number_format($subZone['property_count']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="revenue-amount">
                                            ₵ <?php echo number_format($subZone['business_revenue'] + $subZone['property_revenue'], 2); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span style="color: #64748b; font-size: 14px;">
                                            <?php echo htmlspecialchars(($subZone['first_name'] ?? '') . ' ' . ($subZone['last_name'] ?? '')); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span style="color: #64748b; font-size: 14px;">
                                            <?php echo formatDate($subZone['created_at']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons-table">
                                            <a href="sub_zone_view.php?id=<?php echo $subZone['sub_zone_id']; ?>" class="action-btn view" title="View Details">
                                                <i class="fas fa-eye"></i>
                                                <span class="icon-eye" style="display: none;"></span>
                                            </a>
                                            <a href="sub_zone_edit.php?id=<?php echo $subZone['sub_zone_id']; ?>" class="action-btn edit" title="Edit Sub-Zone">
                                                <i class="fas fa-edit"></i>
                                                <span class="icon-edit" style="display: none;"></span>
                                            </a>
                                            <button class="action-btn delete" onclick="deleteSubZone(<?php echo $subZone['sub_zone_id']; ?>, '<?php echo htmlspecialchars($subZone['sub_zone_name']); ?>')" title="Delete Sub-Zone">
                                                <i class="fas fa-trash"></i>
                                                <span class="icon-trash" style="display: none;"></span>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="pagination-container">
                            <div class="pagination-info">
                                Showing <?php echo (($page - 1) * $perPage) + 1; ?> to <?php echo min($page * $perPage, $totalSubZones); ?> of <?php echo number_format($totalSubZones); ?> sub-zones
                            </div>
                            <div class="pagination">
                                <?php if ($page > 1): ?>
                                    <a href="?zone_id=<?php echo $zoneId; ?>&page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>">
                                        <i class="fas fa-chevron-left"></i> Previous
                                    </a>
                                <?php endif; ?>

                                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                    <?php if ($i == $page): ?>
                                        <span class="current"><?php echo $i; ?></span>
                                    <?php else: ?>
                                        <a href="?zone_id=<?php echo $zoneId; ?>&page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    <?php endif; ?>
                                <?php endfor; ?>

                                <?php if ($page < $totalPages): ?>
                                    <a href="?zone_id=<?php echo $zoneId; ?>&page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>">
                                        Next <i class="fas fa-chevron-right"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

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
        });

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

        // Sub-zone actions
        function addSubZone(zoneId) {
            window.location.href = 'sub_zones_add.php?zone_id=' + zoneId;
        }

        function viewSubZone(subZoneId) {
            window.location.href = 'sub_zone_view.php?id=' + subZoneId;
        }

        function editSubZone(subZoneId) {
            window.location.href = 'sub_zone_edit.php?id=' + subZoneId;
        }

        function deleteSubZone(subZoneId, subZoneName) {
            if (confirm('Are you sure you want to delete sub-zone "' + subZoneName + '"?\n\nThis action cannot be undone and will unassign all businesses and properties from this sub-zone.')) {
                window.location.href = 'delete_sub_zone.php?id=' + subZoneId;
            }
        }

        // Mobile responsiveness
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth <= 768) {
                sidebar.classList.remove('hidden');
            }
        });
    </script>
</body>
</html>