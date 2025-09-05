<?php
/**
 * Properties Management - List All Properties with Map View Integration
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
if (!hasPermission('properties.view')) {
    setFlashMessage('error', 'Access denied. You do not have permission to view properties.');
    header('Location: ../index.php');
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

$pageTitle = 'Properties Management';
$currentUser = getCurrentUser();
$userDisplayName = getUserDisplayName($currentUser);

// Handle search and filtering
$search = sanitizeInput($_GET['search'] ?? '');
$structureFilter = sanitizeInput($_GET['structure'] ?? '');
$useFilter = sanitizeInput($_GET['use'] ?? '');
$zoneFilter = sanitizeInput($_GET['zone'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

try {
    $db = new Database();
    
    // Build query conditions
    $conditions = [];
    $params = [];
    
    if ($search) {
        $conditions[] = "(p.owner_name LIKE ? OR p.property_number LIKE ? OR p.telephone LIKE ? OR p.location LIKE ?)";
        $searchTerm = "%$search%";
        $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    }
    
    if ($structureFilter) {
        $conditions[] = "p.structure = ?";
        $params[] = $structureFilter;
    }
    
    if ($useFilter) {
        $conditions[] = "p.property_use = ?";
        $params[] = $useFilter;
    }
    
    if ($zoneFilter) {
        $conditions[] = "p.zone_id = ?";
        $params[] = $zoneFilter;
    }
    
    $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
    
    // Get total count for pagination
    $countQuery = "SELECT COUNT(*) as total FROM properties p 
                   LEFT JOIN zones z ON p.zone_id = z.zone_id 
                   $whereClause";
    $totalResult = $db->fetchRow($countQuery, $params);
    $totalProperties = $totalResult['total'] ?? 0;
    $totalPages = ceil($totalProperties / $perPage);
    
    // Get properties with pagination
    $query = "SELECT p.*, z.zone_name, u.first_name, u.last_name
              FROM properties p 
              LEFT JOIN zones z ON p.zone_id = z.zone_id 
              LEFT JOIN users u ON p.created_by = u.user_id
              $whereClause 
              ORDER BY p.created_at DESC 
              LIMIT $perPage OFFSET $offset";
    
    $properties = $db->fetchAll($query, $params);
    
    // Get filter options
    $structures = $db->fetchAll("SELECT DISTINCT structure FROM properties ORDER BY structure");
    $propertyUses = $db->fetchAll("SELECT DISTINCT property_use FROM properties ORDER BY property_use");
    $zones = $db->fetchAll("SELECT * FROM zones ORDER BY zone_name");
    
    // Get statistics
    $stats = [
        'total' => $db->fetchRow("SELECT COUNT(*) as count FROM properties")['count'] ?? 0,
        'residential' => $db->fetchRow("SELECT COUNT(*) as count FROM properties WHERE property_use = 'Residential'")['count'] ?? 0,
        'commercial' => $db->fetchRow("SELECT COUNT(*) as count FROM properties WHERE property_use = 'Commercial'")['count'] ?? 0,
        'defaulters' => $db->fetchRow("SELECT COUNT(*) as count FROM properties WHERE amount_payable > 0")['count'] ?? 0,
        'revenue' => $db->fetchRow("SELECT SUM(amount_payable) as total FROM properties WHERE amount_payable > 0")['total'] ?? 0
    ];
    
} catch (Exception $e) {
    writeLog("Properties list error: " . $e->getMessage(), 'ERROR');
    setFlashMessage('error', 'An error occurred while loading properties.');
    $properties = [];
    $totalProperties = 0;
    $totalPages = 0;
    $stats = ['total' => 0, 'residential' => 0, 'commercial' => 0, 'defaulters' => 0, 'revenue' => 0];
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
        .icon-user::before { content: "👤"; }
        .icon-plus::before { content: "➕"; }
        .icon-search::before { content: "🔍"; }
        .icon-edit::before { content: "✏️"; }
        .icon-trash::before { content: "🗑️"; }
        .icon-eye::before { content: "👁️"; }
        .icon-money::before { content: "💰"; }
        .icon-location::before { content: "📍"; }
        .icon-list::before { content: "📋"; }
        
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
        
        .user-profile:hover .user-avatar {
            transform: scale(1.05);
            border-color: rgba(255,255,255,0.4);
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
        
        .user-profile.active .dropdown-arrow {
            transform: rotate(180deg);
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
        
        /* Page Header */
        .page-header {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .page-title {
            font-size: 28px;
            font-weight: bold;
            color: #2d3748;
            margin: 0;
        }
        
        .add-property-btn {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }
        
        .add-property-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(72, 187, 120, 0.3);
            color: white;
        }
        
        /* View Toggle */
        .view-toggle-container {
            background: #f1f5f9;
            border-radius: 12px;
            padding: 4px;
            display: flex;
            gap: 2px;
        }
        
        .view-toggle-btn {
            background: transparent;
            border: none;
            padding: 10px 16px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            color: #64748b;
        }
        
        .view-toggle-btn.active {
            background: white;
            color: #48bb78;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .view-toggle-btn:hover:not(.active) {
            background: rgba(72, 187, 120, 0.1);
            color: #48bb78;
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
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
        }
        
        .stat-card.success {
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
        
        .stat-card.secondary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
            grid-template-columns: 2fr 1fr 1fr 1fr auto;
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
            border-color: #48bb78;
            box-shadow: 0 0 0 3px rgba(72, 187, 120, 0.1);
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
        }
        
        .btn-primary {
            background: #48bb78;
            color: white;
        }
        
        .btn-primary:hover {
            background: #38a169;
            transform: translateY(-1px);
        }
        
        .btn-secondary {
            background: #64748b;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #475569;
        }
        
        /* Properties Table */
        .properties-table-card {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .table-header {
            background: #f8fafc;
            padding: 20px 25px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .table-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            margin: 0;
        }
        
        .properties-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .properties-table th {
            background: #f8fafc;
            padding: 15px 20px;
            text-align: left;
            font-weight: 600;
            color: #2d3748;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
        }
        
        .properties-table td {
            padding: 20px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }
        
        .properties-table tr:hover {
            background: #f8fafc;
        }
        
        .property-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .property-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 18px;
        }
        
        .property-details h6 {
            margin: 0 0 5px 0;
            font-weight: 600;
            color: #2d3748;
        }
        
        .property-details p {
            margin: 0;
            color: #64748b;
            font-size: 14px;
        }
        
        .structure-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: #e2e8f0;
            color: #2d3748;
        }
        
        .use-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .use-residential {
            background: #d1fae5;
            color: #065f46;
        }
        
        .use-commercial {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .amount-display {
            font-weight: 600;
            font-size: 16px;
        }
        
        .amount-positive {
            color: #dc2626;
        }
        
        .amount-zero {
            color: #059669;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
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
        
        .action-btn.map {
            background: #d1fae5;
            color: #059669;
        }
        
        .action-btn.map:hover {
            background: #059669;
            color: white;
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
            margin-left: auto;
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
            background: #48bb78;
            color: white;
        }
        
        .pagination .current {
            background: #48bb78;
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
        }
        
        /* Map Modal */
        .map-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(8px);
            z-index: 10000;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
        }
        
        .map-content {
            background: white;
            padding: 30px;
            border-radius: 15px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 25px 80px rgba(0,0,0,0.4);
            text-align: center;
            animation: modalSlideIn 0.4s ease;
        }
        
        .location-info {
            background: #f8fafc;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
        }
        
        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .map-btn {
            background: #48bb78;
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }
        
        .map-btn:hover {
            background: #38a169;
            color: white;
            transform: translateY(-1px);
        }
        
        .close-btn {
            background: #64748b;
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }
        
        .close-btn:hover {
            background: #475569;
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
            
            .header-content > div:last-child {
                width: 100%;
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 10px;
            }
            
            .view-toggle-container {
                order: 1;
            }
            
            .add-property-btn {
                order: 2;
            }
            
            .stats-row {
                grid-template-columns: 1fr;
            }
            
            .properties-table {
                font-size: 14px;
            }
            
            .properties-table th,
            .properties-table td {
                padding: 10px;
            }
            
            .action-buttons {
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
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes modalSlideIn {
            from { transform: translateY(-30px) scale(0.9); opacity: 0; }
            to { transform: translateY(0) scale(1); opacity: 1; }
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
                    text-decoration: none;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
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
                        <a href="../users/view.php?id=<?php echo getCurrentUserId(); ?>" class="dropdown-item">
                            <i class="fas fa-user"></i>
                            <span class="icon-user" style="display: none;"></span>
                            My Profile
                        </a>
                        <a href="../settings/index.php" class="dropdown-item">
                            <i class="fas fa-cog"></i>
                            <span class="icon-cog" style="display: none;"></span>
                            Account Settings
                        </a>
                        <a href="../logs/user_activity.php" class="dropdown-item">
                            <i class="fas fa-history"></i>
                            <span class="icon-chart" style="display: none;"></span>
                            Activity Log
                        </a>
                        <a href="../docs/user_manual.md" class="dropdown-item">
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
                        <a href="index.php" class="nav-link active">
                            <span class="nav-icon">
                                <i class="fas fa-home"></i>
                                <span class="icon-home" style="display: none;"></span>
                            </span>
                            Properties
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../zones/index.php" class="nav-link">
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
                        <a href="../fee_structure/index.php" class="nav-link">
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
                    <span class="breadcrumb-current">Properties Management</span>
                </div>
            </div>

            <!-- Flash Messages -->
            <?php 
            $flashMessages = getFlashMessages();
            if (!empty($flashMessages)): 
            ?>
                <?php foreach ($flashMessages as $message): ?>
                    <div class="alert alert-<?php echo $message['type']; ?>">
                        <?php echo htmlspecialchars($message['message']); ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Page Header with View Toggle -->
            <div class="page-header">
                <div class="header-content">
                    <div>
                        <h1 class="page-title">
                            <i class="fas fa-home"></i>
                            Properties Management
                        </h1>
                        <p style="color: #64748b; margin: 5px 0 0 0;">Manage registered properties, billing, and compliance</p>
                    </div>
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <!-- View Toggle Buttons -->
                        <div class="view-toggle-container">
                            <button class="view-toggle-btn active" onclick="switchToListView()">
                                <i class="fas fa-list"></i>
                                <span class="icon-list" style="display: none;"></span>
                                List View
                            </button>
                            <button class="view-toggle-btn" onclick="switchToMapView()">
                                <i class="fas fa-map"></i>
                                <span class="icon-map" style="display: none;"></span>
                                Map View
                            </button>
                        </div>
                        
                        <!-- Add Property Button -->
                        <a href="add.php" class="add-property-btn">
                            <i class="fas fa-plus"></i>
                            Register New Property
                        </a>
                    </div>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-row">
                <div class="stat-card primary">
                    <div class="stat-header">
                        <div class="stat-title">Total Properties</div>
                        <div class="stat-icon">
                            <i class="fas fa-home"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
                </div>

                <div class="stat-card success">
                    <div class="stat-header">
                        <div class="stat-title">Residential</div>
                        <div class="stat-icon">
                            <i class="fas fa-house-user"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['residential']); ?></div>
                </div>

                <div class="stat-card info">
                    <div class="stat-header">
                        <div class="stat-title">Commercial</div>
                        <div class="stat-icon">
                            <i class="fas fa-building"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['commercial']); ?></div>
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

                <div class="stat-card secondary">
                    <div class="stat-header">
                        <div class="stat-title">Total Revenue</div>
                        <div class="stat-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                    </div>
                    <div class="stat-value">₵ <?php echo number_format($stats['revenue'], 2); ?></div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-card">
                <form method="GET" action="">
                    <div class="filters-row">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-search"></i> Search Properties
                            </label>
                            <input type="text" name="search" class="form-control" 
                                   placeholder="Search by owner, property number, location..." 
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Structure</label>
                            <select name="structure" class="form-control">
                                <option value="">All Structures</option>
                                <?php foreach ($structures as $structure): ?>
                                    <option value="<?php echo htmlspecialchars($structure['structure']); ?>" 
                                            <?php echo $structureFilter === $structure['structure'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($structure['structure']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Property Use</label>
                            <select name="use" class="form-control">
                                <option value="">All Uses</option>
                                <?php foreach ($propertyUses as $use): ?>
                                    <option value="<?php echo htmlspecialchars($use['property_use']); ?>" 
                                            <?php echo $useFilter === $use['property_use'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($use['property_use']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Zone</label>
                            <select name="zone" class="form-control">
                                <option value="">All Zones</option>
                                <?php foreach ($zones as $zone): ?>
                                    <option value="<?php echo $zone['zone_id']; ?>" 
                                            <?php echo $zoneFilter == $zone['zone_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($zone['zone_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div style="display: flex; gap: 10px;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Filter
                            </button>
                            <a href="index.php" class="btn btn-secondary">
                                <i class="fas fa-undo"></i> Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Properties Table -->
            <div class="properties-table-card">
                <div class="table-header">
                    <h3 class="table-title">
                        All Properties (<?php echo number_format($totalProperties); ?> total)
                    </h3>
                </div>

                <?php if (empty($properties)): ?>
                    <div class="empty-state">
                        <i class="fas fa-home"></i>
                        <h3>No Properties Found</h3>
                        <p>No properties match your current search criteria.</p>
                        <a href="add.php" class="btn btn-primary" style="margin-top: 15px;">
                            <i class="fas fa-plus"></i> Register First Property
                        </a>
                    </div>
                <?php else: ?>
                    <table class="properties-table">
                        <thead>
                            <tr>
                                <th>Property</th>
                                <th>Structure & Use</th>
                                <th>Owner</th>
                                <th>Rooms</th>
                                <th>Zone</th>
                                <th>Amount Due</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($properties as $property): ?>
                                <tr>
                                    <td>
                                        <div class="property-info">
                                            <div class="property-avatar">
                                                🏠
                                            </div>
                                            <div class="property-details">
                                                <h6><?php echo htmlspecialchars($property['property_number']); ?></h6>
                                                <p><?php echo htmlspecialchars($property['location'] ?: 'No location'); ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="structure-badge">
                                            <?php echo htmlspecialchars($property['structure']); ?>
                                        </span>
                                        <br>
                                        <span class="use-badge use-<?php echo strtolower($property['property_use']); ?>">
                                            <?php echo htmlspecialchars($property['property_use']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($property['owner_name']); ?></strong>
                                            <br><small style="color: #64748b;"><?php echo htmlspecialchars($property['telephone'] ?? 'No phone'); ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <span style="font-weight: 600; font-size: 16px; color: #2d3748;">
                                            <?php echo $property['number_of_rooms']; ?>
                                        </span>
                                        <br><small style="color: #64748b;">rooms</small>
                                    </td>
                                    <td>
                                        <span style="color: #64748b; font-size: 14px;">
                                            <?php echo htmlspecialchars($property['zone_name'] ?? 'No zone'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="amount-display <?php echo $property['amount_payable'] > 0 ? 'amount-positive' : 'amount-zero'; ?>">
                                            ₵ <?php echo number_format($property['amount_payable'], 2); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="action-btn view" onclick="viewProperty(<?php echo $property['property_id']; ?>)" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="action-btn edit" onclick="editProperty(<?php echo $property['property_id']; ?>)" title="Edit Property">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php if ($property['latitude'] && $property['longitude']): ?>
                                                <button class="action-btn map" onclick="showOnMap(<?php echo $property['latitude']; ?>, <?php echo $property['longitude']; ?>, '<?php echo htmlspecialchars($property['property_number']); ?>')" title="View on Map">
                                                    <i class="fas fa-map-marker-alt"></i>
                                                </button>
                                            <?php endif; ?>
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
                                Showing <?php echo (($page - 1) * $perPage) + 1; ?> to <?php echo min($page * $perPage, $totalProperties); ?> of <?php echo number_format($totalProperties); ?> properties
                            </div>
                            <div class="pagination">
                                <?php if ($page > 1): ?>
                                    <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&structure=<?php echo urlencode($structureFilter); ?>&use=<?php echo urlencode($useFilter); ?>&zone=<?php echo urlencode($zoneFilter); ?>">
                                        <i class="fas fa-chevron-left"></i> Previous
                                    </a>
                                <?php endif; ?>

                                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                    <?php if ($i == $page): ?>
                                        <span class="current"><?php echo $i; ?></span>
                                    <?php else: ?>
                                        <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&structure=<?php echo urlencode($structureFilter); ?>&use=<?php echo urlencode($useFilter); ?>&zone=<?php echo urlencode($zoneFilter); ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    <?php endif; ?>
                                <?php endfor; ?>

                                <?php if ($page < $totalPages): ?>
                                    <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&structure=<?php echo urlencode($structureFilter); ?>&use=<?php echo urlencode($useFilter); ?>&zone=<?php echo urlencode($zoneFilter); ?>">
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

        // Property actions
        function viewProperty(propertyId) {
            window.location.href = `view.php?id=${propertyId}`;
        }

        function editProperty(propertyId) {
            window.location.href = `edit.php?id=${propertyId}`;
        }

        function showOnMap(lat, lng, name) {
            const mapModal = document.createElement('div');
            mapModal.className = 'map-modal';
            
            const mapContent = document.createElement('div');
            mapContent.className = 'map-content';
            
            mapContent.innerHTML = `
                <h3 style="margin: 0 0 20px 0; color: #2d3748; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-map-marker-alt" style="color: #48bb78;"></i>
                    Property Location
                </h3>
                <div class="location-info">
                    <h4 style="margin: 0 0 15px 0; color: #48bb78; font-size: 18px;">${name}</h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; text-align: left;">
                        <div>
                            <strong style="color: #2d3748;">Latitude:</strong>
                            <div style="color: #64748b; font-family: monospace; background: #f1f5f9; padding: 5px 8px; border-radius: 4px; margin-top: 3px;">${lat}</div>
                        </div>
                        <div>
                            <strong style="color: #2d3748;">Longitude:</strong>
                            <div style="color: #64748b; font-family: monospace; background: #f1f5f9; padding: 5px 8px; border-radius: 4px; margin-top: 3px;">${lng}</div>
                        </div>
                    </div>
                </div>
                <div class="modal-actions">
                    <a href="https://www.google.com/maps?q=${lat},${lng}" target="_blank" class="map-btn">
                        <i class="fas fa-external-link-alt"></i> Open in Google Maps
                    </a>
                    <a href="https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}" target="_blank" class="map-btn">
                        <i class="fas fa-route"></i> Get Directions
                    </a>
                    <button onclick="this.closest('.map-modal').remove()" class="close-btn">
                        <i class="fas fa-times"></i> Close
                    </button>
                </div>
            `;
            
            mapModal.appendChild(mapContent);
            document.body.appendChild(mapModal);
            
            mapModal.addEventListener('click', function(e) {
                if (e.target === mapModal) {
                    mapModal.remove();
                }
            });
        }

        // View toggle functions
        function switchToListView() {
            // Already on list view, just update button states
            updateToggleButtons('list');
        }

        function switchToMapView() {
            // Preserve current filters when switching to map view
            const urlParams = new URLSearchParams(window.location.search);
            let mapUrl = 'map.php';
            
            // Add current filters to map URL
            if (urlParams.toString()) {
                mapUrl += '?' + urlParams.toString();
            }
            
            window.location.href = mapUrl;
        }

        function updateToggleButtons(activeView) {
            const buttons = document.querySelectorAll('.view-toggle-btn');
            
            buttons.forEach(btn => {
                btn.classList.remove('active');
                btn.style.background = 'transparent';
                btn.style.color = '#64748b';
                btn.style.boxShadow = 'none';
            });

            if (activeView === 'list') {
                const listBtn = buttons[0]; // First button is list view
                listBtn.classList.add('active');
                listBtn.style.background = 'white';
                listBtn.style.color = '#48bb78';
                listBtn.style.boxShadow = '0 2px 4px rgba(0,0,0,0.1)';
            } else if (activeView === 'map') {
                const mapBtn = buttons[1]; // Second button is map view
                mapBtn.classList.add('active');
                mapBtn.style.background = 'white';
                mapBtn.style.color = '#48bb78';
                mapBtn.style.boxShadow = '0 2px 4px rgba(0,0,0,0.1)';
            }
        }

        // Initialize view toggle on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateToggleButtons('list'); // Currently on list view
        });

        // Auto-submit form on filter change
        document.querySelectorAll('select[name="structure"], select[name="use"], select[name="zone"]').forEach(function(select) {
            select.addEventListener('change', function() {
                this.form.submit();
            });
        });

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