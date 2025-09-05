<?php
/**
 * Zone Management - View Zone Details
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
    setFlashMessage('error', 'Access denied. You do not have permission to view zones.');
    header('Location: index.php');
    exit();
}

$pageTitle = 'Zone Details';
$currentUser = getCurrentUser();
$userDisplayName = getUserDisplayName($currentUser);

// Get zone ID from URL
$zoneId = intval($_GET['id'] ?? 0);

if (!$zoneId) {
    setFlashMessage('error', 'Invalid zone ID.');
    header('Location: index.php');
    exit();
}

try {
    $db = new Database();
    
    // Get zone details with creator information
    $zoneQuery = "SELECT z.*, 
                         u.first_name, u.last_name, u.username
                  FROM zones z 
                  LEFT JOIN users u ON z.created_by = u.user_id
                  WHERE z.zone_id = ?";
    
    $zone = $db->fetchRow($zoneQuery, [$zoneId]);
    
    if (!$zone) {
        setFlashMessage('error', 'Zone not found.');
        header('Location: index.php');
        exit();
    }
    
    // Get sub-zones for this zone
    $subZonesQuery = "SELECT sz.*, 
                             u.first_name as creator_first_name, 
                             u.last_name as creator_last_name,
                             COUNT(CASE WHEN b.sub_zone_id IS NOT NULL THEN 1 END) as business_count,
                             COUNT(CASE WHEN p.zone_id IS NOT NULL THEN 1 END) as property_count
                      FROM sub_zones sz 
                      LEFT JOIN users u ON sz.created_by = u.user_id
                      LEFT JOIN businesses b ON sz.sub_zone_id = b.sub_zone_id
                      LEFT JOIN properties p ON sz.sub_zone_id = p.zone_id
                      WHERE sz.zone_id = ? 
                      GROUP BY sz.sub_zone_id
                      ORDER BY sz.created_at DESC";
    $subZones = $db->fetchAll($subZonesQuery, [$zoneId]);
    
    // Get businesses in this zone
    $businessesQuery = "SELECT b.business_id, b.business_name, b.owner_name, b.telephone, 
                               b.amount_payable, b.status, b.created_at,
                               sz.sub_zone_name
                        FROM businesses b 
                        LEFT JOIN sub_zones sz ON b.sub_zone_id = sz.sub_zone_id
                        WHERE b.zone_id = ? 
                        ORDER BY b.created_at DESC 
                        LIMIT 10";
    $recentBusinesses = $db->fetchAll($businessesQuery, [$zoneId]);
    
    // Get properties in this zone
    $propertiesQuery = "SELECT p.property_id, p.property_number, p.owner_name, p.telephone,
                               p.amount_payable, p.structure, p.property_use, p.created_at
                        FROM properties p 
                        WHERE p.zone_id = ? 
                        ORDER BY p.created_at DESC 
                        LIMIT 10";
    $recentProperties = $db->fetchAll($propertiesQuery, [$zoneId]);
    
    // Get zone statistics
    $statsQuery = "SELECT 
                       (SELECT COUNT(*) FROM sub_zones WHERE zone_id = ?) as total_sub_zones,
                       (SELECT COUNT(*) FROM businesses WHERE zone_id = ?) as total_businesses,
                       (SELECT COUNT(*) FROM properties WHERE zone_id = ?) as total_properties,
                       (SELECT COALESCE(SUM(amount_payable), 0) FROM businesses WHERE zone_id = ?) as total_business_payable,
                       (SELECT COALESCE(SUM(amount_payable), 0) FROM properties WHERE zone_id = ?) as total_property_payable,
                       (SELECT COUNT(*) FROM businesses WHERE zone_id = ? AND status = 'Active') as active_businesses,
                       (SELECT COUNT(*) FROM businesses WHERE zone_id = ? AND amount_payable > 0) as business_defaulters,
                       (SELECT COUNT(*) FROM properties WHERE zone_id = ? AND amount_payable > 0) as property_defaulters";
    
    $statsParams = array_fill(0, 8, $zoneId);
    $stats = $db->fetchRow($statsQuery, $statsParams);
    
} catch (Exception $e) {
    writeLog("Zone view error: " . $e->getMessage(), 'ERROR');
    setFlashMessage('error', 'An error occurred while loading zone details.');
    header('Location: index.php');
    exit();
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
        .icon-edit::before { content: "✏️"; }
        .icon-plus::before { content: "➕"; }
        .icon-layer::before { content: "🏷️"; }
        .icon-trash::before { content: "🗑️"; }
        .icon-eye::before { content: "👁️"; }
        .icon-location::before { content: "📍"; }
        .icon-info::before { content: "ℹ️"; }
        
        /* Top Navigation - Same as previous pages */
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
        
        /* User Dropdown - Same as previous pages */
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
        
        /* Sidebar - Same as previous pages */
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
            align-items: flex-start;
            margin-bottom: 20px;
        }
        
        .zone-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .zone-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 32px;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }
        
        .zone-details h1 {
            font-size: 28px;
            font-weight: bold;
            color: #2d3748;
            margin: 0 0 8px 0;
        }
        
        .zone-details .zone-code {
            font-size: 16px;
            color: #667eea;
            font-weight: 600;
            margin-bottom: 8px;
            font-family: monospace;
            background: #f1f5f9;
            padding: 4px 8px;
            border-radius: 6px;
            display: inline-block;
        }
        
        .zone-details .zone-description {
            color: #64748b;
            margin-bottom: 10px;
            line-height: 1.5;
        }
        
        .zone-meta {
            display: flex;
            gap: 20px;
            margin-top: 10px;
        }
        
        .meta-item {
            font-size: 14px;
            color: #64748b;
        }
        
        .meta-item strong {
            color: #2d3748;
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
        
        .btn-warning {
            background: linear-gradient(135deg, #ed8936 0%, #dd6b20 100%);
            color: white;
        }
        
        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(237, 137, 54, 0.3);
            color: white;
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%);
            color: white;
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(229, 62, 62, 0.3);
            color: white;
        }
        
        .btn-info {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
        }
        
        .btn-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(66, 153, 225, 0.3);
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
        
        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        /* Statistics Cards */
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
            text-align: center;
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
        
        .stat-card.danger {
            background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%);
            color: white;
        }
        
        .stat-icon {
            font-size: 32px;
            margin-bottom: 15px;
            opacity: 0.8;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 8px;
        }
        
        .stat-label {
            font-size: 14px;
            opacity: 0.9;
            font-weight: 500;
        }
        
        /* Content Cards */
        .content-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .card-title {
            font-size: 20px;
            font-weight: 600;
            color: #2d3748;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .card-icon {
            width: 35px;
            height: 35px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
        }
        
        /* Details List */
        .details-list {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .detail-label {
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .detail-value {
            font-size: 14px;
            font-weight: 500;
            color: #2d3748;
        }
        
        .detail-value.amount {
            font-size: 18px;
            font-weight: bold;
        }
        
        /* Tables */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        
        .data-table th {
            background: #f8fafc;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            color: #2d3748;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
        }
        
        .data-table td {
            padding: 15px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            font-size: 14px;
        }
        
        .data-table tr:hover {
            background: #f8fafc;
        }
        
        .table-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }
        
        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }
        
        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .badge-info {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .sub-zone-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px 20px;
            background: #f8fafc;
            border-radius: 10px;
            margin-bottom: 15px;
            border-left: 4px solid #667eea;
        }
        
        .sub-zone-info h6 {
            margin: 0 0 5px 0;
            font-weight: 600;
            color: #2d3748;
        }
        
        .sub-zone-info p {
            margin: 0;
            color: #64748b;
            font-size: 14px;
        }
        
        .sub-zone-code {
            padding: 4px 8px;
            background: #667eea;
            color: white;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            font-family: monospace;
        }
        
        .sub-zone-stats {
            display: flex;
            gap: 15px;
        }
        
        .sub-zone-stat {
            text-align: center;
        }
        
        .sub-zone-stat .number {
            font-weight: bold;
            color: #2d3748;
        }
        
        .sub-zone-stat .label {
            font-size: 12px;
            color: #64748b;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #64748b;
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        .empty-state h3 {
            margin-bottom: 10px;
            color: #2d3748;
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
        
        .alert.error {
            background: #fee2e2;
            border-color: #fecaca;
            color: #991b1b;
        }
        
        /* Responsive Design */
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
            
            .content-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .header-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 20px;
            }
            
            .zone-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .zone-meta {
                flex-direction: column;
                gap: 10px;
            }
            
            .action-buttons {
                width: 100%;
                justify-content: stretch;
            }
            
            .action-buttons .btn {
                flex: 1;
            }
            
            .details-list {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .sub-zone-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .sub-zone-stats {
                align-self: stretch;
                justify-content: space-around;
            }
        }
        
        /* Animations */
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .content-card {
            animation: fadeIn 0.6s ease forwards;
        }
        
        .content-card:nth-child(2) {
            animation-delay: 0.1s;
        }
        
        .content-card:nth-child(3) {
            animation-delay: 0.2s;
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
                <button style="
                    background: rgba(255,255,255,0.2);
                    border: none;
                    color: white;
                    font-size: 18px;
                    padding: 10px;
                    border-radius: 50%;
                    cursor: pointer;
                    transition: all 0.3s;
                " onmouseover="this.style.background='rgba(255,255,255,0.3)'" 
                   onmouseout="this.style.background='rgba(255,255,255,0.2)'"
                   onclick="showComingSoon('Notifications')">
                    <i class="fas fa-bell"></i>
                    <span class="icon-bell" style="display: none;"></span>
                </button>
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
                        <a href="#" class="dropdown-item" onclick="showComingSoon('User Profile')">
                            <i class="fas fa-user"></i>
                            <span class="icon-user" style="display: none;"></span>
                            My Profile
                        </a>
                        <a href="#" class="dropdown-item" onclick="showComingSoon('Account Settings')">
                            <i class="fas fa-cog"></i>
                            <span class="icon-cog" style="display: none;"></span>
                            Account Settings
                        </a>
                        <a href="#" class="dropdown-item" onclick="showComingSoon('Activity Log')">
                            <i class="fas fa-history"></i>
                            <span class="icon-chart" style="display: none;"></span>
                            Activity Log
                        </a>
                        <a href="#" class="dropdown-item" onclick="showComingSoon('Help & Support')">
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
                        <a href="#" class="nav-link" onclick="showComingSoon('Properties')">
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
                        <a href="#" class="nav-link" onclick="showComingSoon('Billing')">
                            <span class="nav-icon">
                                <i class="fas fa-file-invoice"></i>
                                <span class="icon-invoice" style="display: none;"></span>
                            </span>
                            Billing
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="#" class="nav-link" onclick="showComingSoon('Payments')">
                            <span class="nav-icon">
                                <i class="fas fa-credit-card"></i>
                                <span class="icon-credit" style="display: none;"></span>
                            </span>
                            Payments
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="#" class="nav-link" onclick="showComingSoon('Fee Structure')">
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
                        <a href="#" class="nav-link" onclick="showComingSoon('Reports')">
                            <span class="nav-icon">
                                <i class="fas fa-chart-bar"></i>
                                <span class="icon-chart" style="display: none;"></span>
                            </span>
                            Reports
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="#" class="nav-link" onclick="showComingSoon('Notifications')">
                            <span class="nav-icon">
                                <i class="fas fa-bell"></i>
                                <span class="icon-bell" style="display: none;"></span>
                            </span>
                            Notifications
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="#" class="nav-link" onclick="showComingSoon('Settings')">
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
                    <span class="breadcrumb-current"><?php echo htmlspecialchars($zone['zone_name']); ?></span>
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

            <!-- Zone Header -->
            <div class="zone-header">
                <div class="header-content">
                    <div class="zone-info">
                        <div class="zone-avatar">
                            <i class="fas fa-map-marker-alt"></i>
                            <span class="icon-location" style="display: none;"></span>
                        </div>
                        <div class="zone-details">
                            <h1><?php echo htmlspecialchars($zone['zone_name']); ?></h1>
                            <?php if ($zone['zone_code']): ?>
                                <div class="zone-code"><?php echo htmlspecialchars($zone['zone_code']); ?></div>
                            <?php endif; ?>
                            <?php if ($zone['description']): ?>
                                <div class="zone-description"><?php echo htmlspecialchars($zone['description']); ?></div>
                            <?php endif; ?>
                            <div class="zone-meta">
                                <div class="meta-item">
                                    <strong>Created:</strong> <?php echo formatDate($zone['created_at']); ?>
                                </div>
                                <div class="meta-item">
                                    <strong>Created by:</strong> <?php echo htmlspecialchars(($zone['first_name'] ?? '') . ' ' . ($zone['last_name'] ?? '')); ?>
                                </div>
                                <div class="meta-item">
                                    <strong>Zone ID:</strong> #<?php echo $zone['zone_id']; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="action-buttons">
                        <a href="edit.php?id=<?php echo $zone['zone_id']; ?>" class="btn btn-primary">
                            <i class="fas fa-edit"></i>
                            <span class="icon-edit" style="display: none;"></span>
                            Edit Zone
                        </a>
                        
                        <a href="sub_zones.php?zone_id=<?php echo $zone['zone_id']; ?>" class="btn btn-success">
                            <i class="fas fa-layer-group"></i>
                            <span class="icon-layer" style="display: none;"></span>
                            Manage Sub-Zones
                        </a>
                        
                        <button class="btn btn-info" onclick="addSubZone(<?php echo $zone['zone_id']; ?>)">
                            <i class="fas fa-plus"></i>
                            <span class="icon-plus" style="display: none;"></span>
                            Add Sub-Zone
                        </button>
                        
                        <button class="btn btn-danger" onclick="deleteZone(<?php echo $zone['zone_id']; ?>, '<?php echo htmlspecialchars($zone['zone_name']); ?>')">
                            <i class="fas fa-trash"></i>
                            <span class="icon-trash" style="display: none;"></span>
                            Delete Zone
                        </button>
                        
                        <a href="index.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i>
                            Back to Zones
                        </a>
                    </div>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="stat-icon">
                        <i class="fas fa-layer-group"></i>
                        <span class="icon-layer" style="display: none;"></span>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_sub_zones']); ?></div>
                    <div class="stat-label">Sub-Zones</div>
                </div>

                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-building"></i>
                        <span class="icon-building" style="display: none;"></span>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_businesses']); ?></div>
                    <div class="stat-label">Businesses</div>
                </div>

                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-home"></i>
                        <span class="icon-home" style="display: none;"></span>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_properties']); ?></div>
                    <div class="stat-label">Properties</div>
                </div>

                <div class="stat-card info">
                    <div class="stat-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-value">₵ <?php echo number_format($stats['total_business_payable'] + $stats['total_property_payable'], 2); ?></div>
                    <div class="stat-label">Total Payable</div>
                </div>

                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['business_defaulters'] + $stats['property_defaulters']); ?></div>
                    <div class="stat-label">Defaulters</div>
                </div>
            </div>

            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Left Column - Sub-Zones and Business/Property Lists -->
                <div>
                    <!-- Sub-Zones -->
                    <div class="content-card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <div class="card-icon">
                                    <i class="fas fa-layer-group"></i>
                                    <span class="icon-layer" style="display: none;"></span>
                                </div>
                                Sub-Zones (<?php echo count($subZones); ?>)
                            </h3>
                            <a href="sub_zones.php?zone_id=<?php echo $zone['zone_id']; ?>" class="btn btn-primary">
                                <i class="fas fa-plus"></i>
                                Add Sub-Zone
                            </a>
                        </div>
                        
                        <?php if (empty($subZones)): ?>
                            <div class="empty-state">
                                <i class="fas fa-layer-group"></i>
                                <h3>No Sub-Zones Yet</h3>
                                <p>This zone doesn't have any sub-zones. Create sub-zones to organize businesses and properties more precisely.</p>
                                <button class="btn btn-primary" onclick="addSubZone(<?php echo $zone['zone_id']; ?>)" style="margin-top: 15px;">
                                    <i class="fas fa-plus"></i> Add First Sub-Zone
                                </button>
                            </div>
                        <?php else: ?>
                            <?php foreach ($subZones as $subZone): ?>
                                <div class="sub-zone-item">
                                    <div class="sub-zone-info">
                                        <h6><?php echo htmlspecialchars($subZone['sub_zone_name']); ?></h6>
                                        <p><?php echo htmlspecialchars($subZone['description'] ?? 'No description'); ?></p>
                                        <?php if ($subZone['sub_zone_code']): ?>
                                            <span class="sub-zone-code"><?php echo htmlspecialchars($subZone['sub_zone_code']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="sub-zone-stats">
                                        <div class="sub-zone-stat">
                                            <div class="number"><?php echo number_format($subZone['business_count']); ?></div>
                                            <div class="label">Businesses</div>
                                        </div>
                                        <div class="sub-zone-stat">
                                            <div class="number"><?php echo number_format($subZone['property_count']); ?></div>
                                            <div class="label">Properties</div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Recent Businesses -->
                    <div class="content-card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <div class="card-icon">
                                    <i class="fas fa-building"></i>
                                    <span class="icon-building" style="display: none;"></span>
                                </div>
                                Recent Businesses
                            </h3>
                            <a href="../businesses/index.php?zone_id=<?php echo $zone['zone_id']; ?>" class="btn btn-secondary">
                                View All
                            </a>
                        </div>
                        
                        <?php if (empty($recentBusinesses)): ?>
                            <div class="empty-state">
                                <i class="fas fa-building"></i>
                                <h3>No Businesses</h3>
                                <p>No businesses are registered in this zone yet.</p>
                            </div>
                        <?php else: ?>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Business Name</th>
                                        <th>Owner</th>
                                        <th>Sub-Zone</th>
                                        <th>Amount Payable</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentBusinesses as $business): ?>
                                        <tr>
                                            <td>
                                                <a href="../businesses/view.php?id=<?php echo $business['business_id']; ?>" 
                                                   style="color: #667eea; text-decoration: none; font-weight: 600;">
                                                    <?php echo htmlspecialchars($business['business_name']); ?>
                                                </a>
                                            </td>
                                            <td><?php echo htmlspecialchars($business['owner_name']); ?></td>
                                            <td><?php echo htmlspecialchars($business['sub_zone_name'] ?? 'Not assigned'); ?></td>
                                            <td>₵ <?php echo number_format($business['amount_payable'], 2); ?></td>
                                            <td>
                                                <span class="table-badge <?php 
                                                    echo $business['status'] === 'Active' ? 'badge-success' : 
                                                        ($business['status'] === 'Inactive' ? 'badge-warning' : 'badge-danger'); 
                                                ?>">
                                                    <?php echo htmlspecialchars($business['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            
                            <?php if (count($recentBusinesses) >= 10): ?>
                                <div style="text-align: center; margin-top: 15px;">
                                    <a href="../businesses/index.php?zone_id=<?php echo $zone['zone_id']; ?>" class="btn btn-secondary">
                                        View All Businesses in Zone
                                    </a>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right Column - Zone Summary & Properties -->
                <div>
                    <!-- Zone Summary -->
                    <div class="content-card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <div class="card-icon">
                                    <i class="fas fa-info-circle"></i>
                                    <span class="icon-info" style="display: none;"></span>
                                </div>
                                Zone Summary
                            </h3>
                        </div>
                        
                        <div class="details-list" style="grid-template-columns: 1fr;">
                            <div class="detail-item">
                                <div class="detail-label">Total Sub-Zones</div>
                                <div class="detail-value"><?php echo number_format($stats['total_sub_zones']); ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Total Businesses</div>
                                <div class="detail-value"><?php echo number_format($stats['total_businesses']); ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Active Businesses</div>
                                <div class="detail-value"><?php echo number_format($stats['active_businesses']); ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Total Properties</div>
                                <div class="detail-value"><?php echo number_format($stats['total_properties']); ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Business Revenue</div>
                                <div class="detail-value amount">₵ <?php echo number_format($stats['total_business_payable'], 2); ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Property Revenue</div>
                                <div class="detail-value amount">₵ <?php echo number_format($stats['total_property_payable'], 2); ?></div>
                            </div>
                            
                            <div style="border-top: 2px solid #e2e8f0; margin: 15px 0; padding-top: 15px;">
                                <div class="detail-item">
                                    <div class="detail-label">Total Revenue</div>
                                    <div class="detail-value amount" style="font-size: 20px; color: #667eea;">
                                        ₵ <?php echo number_format($stats['total_business_payable'] + $stats['total_property_payable'], 2); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Properties -->
                    <div class="content-card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <div class="card-icon">
                                    <i class="fas fa-home"></i>
                                    <span class="icon-home" style="display: none;"></span>
                                </div>
                                Recent Properties
                            </h3>
                        </div>
                        
                        <?php if (empty($recentProperties)): ?>
                            <div class="empty-state">
                                <i class="fas fa-home"></i>
                                <h3>No Properties</h3>
                                <p>No properties are registered in this zone yet.</p>
                            </div>
                        <?php else: ?>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Property #</th>
                                        <th>Owner</th>
                                        <th>Structure</th>
                                        <th>Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentProperties as $property): ?>
                                        <tr>
                                            <td>
                                                <a href="../properties/view.php?id=<?php echo $property['property_id']; ?>" 
                                                   style="color: #667eea; text-decoration: none; font-weight: 600;">
                                                    <?php echo htmlspecialchars($property['property_number']); ?>
                                                </a>
                                            </td>
                                            <td><?php echo htmlspecialchars($property['owner_name']); ?></td>
                                            <td><?php echo htmlspecialchars($property['structure']); ?></td>
                                            <td>₵ <?php echo number_format($property['amount_payable'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            
                            <?php if (count($recentProperties) >= 10): ?>
                                <div style="text-align: center; margin-top: 15px;">
                                    <button class="btn btn-secondary" onclick="showComingSoon('Property Management')">
                                        View All Properties in Zone
                                    </button>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
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

        // Coming soon modal
        function showComingSoon(feature) {
            const backdrop = document.createElement('div');
            backdrop.style.cssText = `
                position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(0,0,0,0.6); backdrop-filter: blur(8px); z-index: 10000;
                display: flex; align-items: center; justify-content: center;
                animation: fadeIn 0.3s ease; cursor: pointer;
            `;
            
            const modal = document.createElement('div');
            modal.style.cssText = `
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white; padding: 50px 40px; border-radius: 25px; text-align: center;
                box-shadow: 0 25px 80px rgba(0,0,0,0.4); max-width: 450px; width: 90%;
                animation: modalSlideIn 0.4s ease; cursor: default; position: relative; overflow: hidden;
            `;
            
            modal.innerHTML = `
                <div style="position: absolute; top: -50%; right: -50%; width: 200%; height: 200%;
                    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
                    animation: rotate 20s linear infinite; pointer-events: none;"></div>
                
                <div style="position: relative; z-index: 2;">
                    <div style="width: 80px; height: 80px; background: rgba(255,255,255,0.2);
                        border-radius: 50%; display: flex; align-items: center; justify-content: center;
                        margin: 0 auto 25px; animation: bounce 2s ease-in-out infinite;">
                        <i class="fas fa-rocket" style="font-size: 2.5rem; color: white;"></i>
                        <span style="font-size: 2.5rem; display: none;">🚀</span>
                    </div>
                    
                    <h3 style="margin: 0 0 15px 0; font-weight: 700; font-size: 1.8rem;
                        text-shadow: 0 2px 4px rgba(0,0,0,0.3);">${feature}</h3>
                    
                    <p style="margin: 0 0 30px 0; opacity: 0.9; font-size: 1.1rem; line-height: 1.6;">
                        This amazing feature is coming soon! 🎉<br>We're working hard to bring you the best experience.</p>
                    
                    <button onclick="closeModal()" style="background: rgba(255,255,255,0.2);
                        border: 2px solid rgba(255,255,255,0.3); color: white; padding: 12px 30px;
                        border-radius: 25px; cursor: pointer; font-weight: 600; font-size: 1rem;
                        transition: all 0.3s ease; backdrop-filter: blur(10px);">
                        Awesome! Let's Go 🚀
                    </button>
                    
                    <div style="margin-top: 20px; font-size: 0.9rem; opacity: 0.7;">
                        Click anywhere outside to close</div>
                </div>
            `;
            
            backdrop.appendChild(modal);
            document.body.appendChild(backdrop);
            
            if (!document.getElementById('modalAnimations')) {
                const style = document.createElement('style');
                style.id = 'modalAnimations';
                style.textContent = `
                    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
                    @keyframes modalSlideIn { from { transform: translateY(-30px) scale(0.9); opacity: 0; } 
                        to { transform: translateY(0) scale(1); opacity: 1; } }
                    @keyframes bounce { 0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
                        40% { transform: translateY(-10px); } 60% { transform: translateY(-5px); } }
                    @keyframes rotate { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
                    @keyframes fadeOut { from { opacity: 1; } to { opacity: 0; } }
                    @keyframes modalSlideOut { from { transform: translateY(0) scale(1); opacity: 1; }
                        to { transform: translateY(-30px) scale(0.9); opacity: 0; } }
                `;
                document.head.appendChild(style);
            }
            
            window.closeModal = function() {
                backdrop.style.animation = 'fadeOut 0.3s ease forwards';
                modal.style.animation = 'modalSlideOut 0.3s ease forwards';
                setTimeout(() => backdrop.remove(), 300);
            };
            
            backdrop.addEventListener('click', function(e) {
                if (e.target === backdrop) closeModal();
            });
        }

        // Zone action functions
        function addSubZone(zoneId) {
            window.location.href = 'add_sub_zone.php?zone_id=' + zoneId;
        }

        function deleteZone(zoneId, zoneName) {
            if (confirm('Are you sure you want to delete zone "' + zoneName + '"?\n\nThis action cannot be undone and will also delete all sub-zones, and unassign all businesses and properties from this zone.')) {
                window.location.href = 'delete.php?id=' + zoneId;
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