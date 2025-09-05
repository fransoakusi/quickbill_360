<?php
/**
 * Audit Report for QUICKBILL 305
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

// Check if user is admin
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

// Get filter parameters
$selectedUser = isset($_GET['user']) ? intval($_GET['user']) : 0;
$selectedAction = isset($_GET['action']) ? $_GET['action'] : '';
$selectedDate = isset($_GET['date']) ? $_GET['date'] : '';
$selectedEndDate = isset($_GET['end_date']) ? $_GET['end_date'] : '';

// Set default date range (last 30 days)
if (empty($selectedDate)) {
    $selectedDate = date('Y-m-d', strtotime('-30 days'));
}
if (empty($selectedEndDate)) {
    $selectedEndDate = date('Y-m-d');
}

// Export handling
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    // Export to Excel functionality would go here
    setFlashMessage('info', 'Excel export functionality will be implemented soon.');
    header('Location: audit_report.php');
    exit();
}

// Get audit data
try {
    $db = new Database();
    
    // Build WHERE clause based on filters
    $whereConditions = ["DATE(al.created_at) BETWEEN ? AND ?"];
    $params = [$selectedDate, $selectedEndDate];
    
    if ($selectedUser > 0) {
        $whereConditions[] = "al.user_id = ?";
        $params[] = $selectedUser;
    }
    
    if (!empty($selectedAction)) {
        $whereConditions[] = "al.action LIKE ?";
        $params[] = "%{$selectedAction}%";
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Get audit logs with user information
    $auditQuery = "
        SELECT 
            al.log_id,
            al.user_id,
            al.action,
            al.table_name,
            al.record_id,
            al.old_values,
            al.new_values,
            al.ip_address,
            al.user_agent,
            al.created_at,
            CONCAT(u.first_name, ' ', u.last_name) as user_name,
            ur.role_name
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.user_id
        LEFT JOIN user_roles ur ON u.role_id = ur.role_id
        WHERE $whereClause
        ORDER BY al.created_at DESC
        LIMIT 1000
    ";
    
    $auditLogs = $db->fetchAll($auditQuery, $params);
    
    // Get audit statistics
    $statsQuery = "
        SELECT 
            COUNT(*) as total_actions,
            COUNT(DISTINCT al.user_id) as unique_users,
            COUNT(DISTINCT DATE(al.created_at)) as active_days,
            COUNT(DISTINCT al.ip_address) as unique_ips
        FROM audit_logs al
        WHERE $whereClause
    ";
    
    $auditStats = $db->fetchRow($statsQuery, $params);
    
    // Get actions by type
    $actionTypesQuery = "
        SELECT 
            al.action,
            COUNT(*) as action_count,
            COUNT(DISTINCT al.user_id) as users_performed
        FROM audit_logs al
        WHERE $whereClause
        GROUP BY al.action
        ORDER BY action_count DESC
        LIMIT 20
    ";
    
    $actionTypes = $db->fetchAll($actionTypesQuery, $params);
    
    // Get user activity breakdown
    $userActivityQuery = "
        SELECT 
            CONCAT(u.first_name, ' ', u.last_name) as user_name,
            ur.role_name,
            COUNT(*) as total_actions,
            COUNT(DISTINCT DATE(al.created_at)) as active_days,
            MAX(al.created_at) as last_activity
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.user_id
        LEFT JOIN user_roles ur ON u.role_id = ur.role_id
        WHERE $whereClause
        GROUP BY al.user_id, u.first_name, u.last_name, ur.role_name
        ORDER BY total_actions DESC
        LIMIT 20
    ";
    
    $userActivity = $db->fetchAll($userActivityQuery, $params);
    
    // Get daily activity
    $dailyActivityQuery = "
        SELECT 
            DATE(al.created_at) as activity_date,
            COUNT(*) as daily_actions,
            COUNT(DISTINCT al.user_id) as daily_users
        FROM audit_logs al
        WHERE $whereClause
        GROUP BY DATE(al.created_at)
        ORDER BY DATE(al.created_at) DESC
        LIMIT 30
    ";
    
    $dailyActivity = $db->fetchAll($dailyActivityQuery, $params);
    
    // Get security events (failed logins, suspicious activities)
    $securityEventsQuery = "
        SELECT 
            al.log_id,
            al.action,
            al.ip_address,
            al.user_agent,
            al.created_at,
            CONCAT(u.first_name, ' ', u.last_name) as user_name
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.user_id
        WHERE $whereClause
        AND (
            al.action LIKE '%FAILED%' OR 
            al.action LIKE '%BLOCKED%' OR 
            al.action LIKE '%SUSPICIOUS%' OR
            al.action LIKE '%PASSWORD_CHANGED%' OR
            al.action LIKE '%HARD_DELETE%'
        )
        ORDER BY al.created_at DESC
        LIMIT 50
    ";
    
    $securityEvents = $db->fetchAll($securityEventsQuery, $params);
    
    // Get table modifications
    $tableModsQuery = "
        SELECT 
            al.table_name,
            COUNT(*) as modification_count,
            COUNT(DISTINCT al.user_id) as users_involved,
            MAX(al.created_at) as last_modified
        FROM audit_logs al
        WHERE $whereClause
        AND al.table_name IS NOT NULL
        AND al.table_name != ''
        GROUP BY al.table_name
        ORDER BY modification_count DESC
    ";
    
    $tableMods = $db->fetchAll($tableModsQuery, $params);
    
    // Get available users and actions for filters
    $usersQuery = "
        SELECT DISTINCT u.user_id, u.first_name, u.last_name, ur.role_name
        FROM users u
        INNER JOIN user_roles ur ON u.role_id = ur.role_id
        INNER JOIN audit_logs al ON u.user_id = al.user_id
        ORDER BY u.first_name, u.last_name
    ";
    $availableUsers = $db->fetchAll($usersQuery);
    
    $actionsQuery = "
        SELECT DISTINCT action 
        FROM audit_logs 
        WHERE action IS NOT NULL 
        ORDER BY action
    ";
    $availableActions = $db->fetchAll($actionsQuery);
    
} catch (Exception $e) {
    $auditLogs = [];
    $auditStats = ['total_actions' => 0, 'unique_users' => 0, 'active_days' => 0, 'unique_ips' => 0];
    $actionTypes = [];
    $userActivity = [];
    $dailyActivity = [];
    $securityEvents = [];
    $tableMods = [];
    $availableUsers = [];
    $availableActions = [];
}

// Prepare chart data
$dailyLabels = array_reverse(array_column($dailyActivity, 'activity_date'));
$dailyData = array_reverse(array_column($dailyActivity, 'daily_actions'));

// Helper function to format action names
function formatActionName($action) {
    return ucwords(str_replace('_', ' ', strtolower($action)));
}

// Helper function to get action icon
function getActionIcon($action) {
    $action = strtolower($action);
    if (strpos($action, 'login') !== false) return '🔐';
    if (strpos($action, 'logout') !== false) return '🚪';
    if (strpos($action, 'create') !== false) return '➕';
    if (strpos($action, 'update') !== false) return '✏️';
    if (strpos($action, 'delete') !== false) return '🗑️';
    if (strpos($action, 'payment') !== false) return '💳';
    if (strpos($action, 'bill') !== false) return '📄';
    if (strpos($action, 'backup') !== false) return '💾';
    if (strpos($action, 'password') !== false) return '🔑';
    return '📝';
}

// Helper function to get risk level
function getRiskLevel($action) {
    $action = strtolower($action);
    if (strpos($action, 'delete') !== false || 
        strpos($action, 'hard_delete') !== false ||
        strpos($action, 'backup') !== false) return 'high';
    if (strpos($action, 'password') !== false || 
        strpos($action, 'create') !== false ||
        strpos($action, 'update') !== false) return 'medium';
    return 'low';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Report - <?php echo APP_NAME; ?></title>

    <!-- Local Chart.js -->
    <script src="../../assets/js/chart.min.js"></script>
    
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

        /* Page Header */
        .page-header {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-title {
            font-size: 28px;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 5px;
        }

        .page-subtitle {
            color: #718096;
            font-size: 16px;
        }

        .page-actions {
            display: flex;
            gap: 10px;
        }

        /* Filters */
        .filters-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
            margin-bottom: 5px;
            font-size: 14px;
        }

        .form-control {
            padding: 10px 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .form-control:focus {
            border-color: #667eea;
            outline: none;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 18px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-3px);
        }

        .stat-card.primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .stat-card.success {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
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

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .stat-title {
            font-size: 12px;
            opacity: 0.9;
            font-weight: 600;
            text-transform: uppercase;
        }

        .stat-icon {
            font-size: 24px;
            opacity: 0.8;
        }

        .stat-value {
            font-size: 24px;
            font-weight: bold;
        }

        .stat-subtitle {
            font-size: 11px;
            opacity: 0.8;
            margin-top: 4px;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .card-header {
            padding: 20px 25px;
            background: #f7fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            margin: 0;
        }

        .card-body {
            padding: 25px;
        }

        /* Charts */
        .chart-container {
            position: relative;
            height: 350px;
            width: 100%;
        }

        /* Tables */
        .table-responsive {
            overflow-x: auto;
            border-radius: 10px;
            max-height: 500px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        .table th {
            background: #f7fafc;
            color: #2d3748;
            font-weight: 600;
            padding: 15px 12px;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
            font-size: 14px;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
            color: #4a5568;
        }

        .table tbody tr:hover {
            background: #f7fafc;
        }

        /* Audit Log Specific Styles */
        .action-icon {
            font-size: 16px;
            margin-right: 8px;
        }

        .risk-indicator {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .risk-low {
            background: #c6f6d5;
            color: #22543d;
        }

        .risk-medium {
            background: #fbd38d;
            color: #744210;
        }

        .risk-high {
            background: #fed7d7;
            color: #c53030;
        }

        .ip-address {
            font-family: monospace;
            background: #f7fafc;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 12px;
        }

        .user-agent {
            font-size: 11px;
            color: #718096;
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .json-data {
            font-family: monospace;
            font-size: 11px;
            background: #f7fafc;
            padding: 5px;
            border-radius: 4px;
            max-width: 250px;
            max-height: 60px;
            overflow: auto;
            white-space: pre-wrap;
        }

        .badge {
            padding: 4px 8px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .badge-primary {
            background: #d6e9fd;
            color: #2a4365;
        }

        .badge-success {
            background: #c6f6d5;
            color: #22543d;
        }

        .badge-info {
            background: #bee3f8;
            color: #2a4365;
        }

        .badge-warning {
            background: #fbd38d;
            color: #744210;
        }

        .badge-danger {
            background: #fed7d7;
            color: #c53030;
        }

        /* Search Box */
        .search-box {
            position: relative;
            margin-bottom: 20px;
        }

        .search-input {
            width: 100%;
            padding: 12px 15px 12px 45px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .search-input:focus {
            border-color: #667eea;
            outline: none;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #718096;
        }

        /* Buttons */
        .btn {
            padding: 10px 18px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
            color: white;
        }

        .btn-outline {
            background: transparent;
            color: #667eea;
            border: 2px solid #667eea;
        }

        .btn-outline:hover {
            background: #667eea;
            color: white;
            transform: translateY(-2px);
        }

        .btn-success {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(72, 187, 120, 0.4);
            color: white;
        }

        .btn-secondary {
            background: linear-gradient(135deg, #718096 0%, #4a5568 100%);
            color: white;
        }

        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(113, 128, 150, 0.4);
            color: white;
        }

        /* Print Header */
        .print-header {
            display: none;
            text-align: center;
            margin-bottom: 30px;
        }

        .print-date-range {
            display: none;
            margin-bottom: 20px;
            padding: 15px;
            background: #f7fafc;
            border-radius: 8px;
            text-align: center;
        }

        /* Print Styles */
        @media print {
            body {
                background: white;
                font-size: 11pt;
                line-height: 1.4;
            }

            /* Hide ALL screen elements */
            .top-nav,
            .sidebar,
            .page-actions,
            .filters-card,
            .search-box,
            .btn,
            button,
            .chart-container,
            .dropdown,
            .user-dropdown,
            .no-print,
            .stats-grid,
            .security-events,
            .page-header {
                display: none !important;
            }

            /* Hide all cards EXCEPT the detailed audit logs */
            .card:not(.audit-summary) {
                display: none !important;
            }

            /* Show print elements */
            .print-header,
            .print-date-range {
                display: block !important;
            }

            /* Adjust layout for print */
            .container {
                margin-top: 0;
                display: block;
            }

            .main-content {
                padding: 0;
                background: white;
            }

            /* Only show audit logs card */
            .card.audit-summary {
                border: 1px solid #e2e8f0;
                box-shadow: none;
                margin-bottom: 15px;
                page-break-inside: avoid;
            }

            .card.audit-summary .card-header {
                background: #f7fafc;
                border-bottom: 1px solid #e2e8f0;
                padding: 12px 15px;
            }

            .card.audit-summary .card-body {
                padding: 15px;
            }

            .card.audit-summary .card-title {
                font-size: 14pt;
                font-weight: bold;
            }

            /* Table styles for print */
            .table-responsive {
                overflow: visible;
                max-height: none;
            }

            .table {
                border-collapse: collapse;
                width: 100%;
                font-size: 9pt;
            }

            .table th {
                background: #f7fafc !important;
                border: 1px solid #e2e8f0;
                padding: 8px 6px;
                font-weight: bold;
                position: static;
            }

            .table td {
                border: 1px solid #e2e8f0;
                padding: 6px;
                font-size: 9pt;
                word-wrap: break-word;
            }

            .table tbody tr:hover {
                background: transparent;
            }

            /* Specific adjustments for audit table */
            .table td:nth-child(7) { /* Details column */
                max-width: 150px;
                font-size: 8pt;
            }

            .json-data {
                font-size: 8pt;
                max-height: 40px;
                max-width: 120px;
                background: #f9f9f9;
                border: 1px solid #e2e8f0;
            }

            .user-agent {
                max-width: 100px;
                font-size: 8pt;
            }

            .ip-address {
                background: #f9f9f9;
                border: 1px solid #e2e8f0;
                font-size: 8pt;
            }

            /* Risk indicators */
            .risk-indicator {
                border: 1px solid #ccc;
                background: white;
                color: black;
                font-size: 8pt;
                padding: 2px 4px;
            }

            .risk-low { border-color: #22543d; }
            .risk-medium { border-color: #744210; }
            .risk-high { border-color: #c53030; }

            /* Page breaks */
            .page-break {
                page-break-before: always;
            }

            .page-break-inside-avoid {
                page-break-inside: avoid;
            }

            /* Hide expanded details in print */
            details[open] summary ~ * {
                display: none;
            }

            details summary {
                display: list-item;
            }

            details summary::after {
                content: " (Details hidden in print)";
                font-style: italic;
                color: #666;
            }

            /* Print-specific grid layouts - HIDDEN */
            .print-grid-2 {
                display: none !important;
            }

            /* Hide chart fallback data */
            .print-chart-data {
                display: none !important;
            }

            /* Ensure audit logs section stays together and is the only visible content */
            .audit-summary {
                page-break-inside: avoid;
                display: block !important;
            }

            /* Footer for each page */
            @page {
                margin: 1in;
                @bottom-right {
                    content: "Page " counter(page) " of " counter(pages);
                    font-size: 9pt;
                }
                @bottom-left {
                    content: "Generated: <?php echo date('M j, Y g:i A'); ?>";
                    font-size: 9pt;
                }
            }
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

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .filters-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Print Header (only visible when printing) -->
    <div class="print-header">
        <h1><?php echo APP_NAME; ?> - Detailed Audit Logs</h1>
        <p>System activity audit trail</p>
        <p>Generated on: <?php echo date('F j, Y \a\t g:i A'); ?></p>
    </div>

    <!-- Print Date Range (only visible when printing) -->
    <div class="print-date-range">
        <strong>Report Period:</strong> 
        <?php echo date('F j, Y', strtotime($selectedDate)); ?> to <?php echo date('F j, Y', strtotime($selectedEndDate)); ?>
        <?php if ($selectedUser > 0): ?>
            <?php
            $selectedUserName = '';
            foreach ($availableUsers as $user) {
                if ($user['user_id'] == $selectedUser) {
                    $selectedUserName = $user['first_name'] . ' ' . $user['last_name'];
                    break;
                }
            }
            ?>
            | <strong>Filtered by User:</strong> <?php echo htmlspecialchars($selectedUserName); ?>
        <?php endif; ?>
        <?php if (!empty($selectedAction)): ?>
            | <strong>Filtered by Action:</strong> <?php echo formatActionName($selectedAction); ?>
        <?php endif; ?>
    </div>

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
                    display: inline-block;
                " onmouseover="this.style.background='rgba(255,255,255,0.3)'" 
                   onmouseout="this.style.background='rgba(255,255,255,0.2)'">
                    <i class="fas fa-bell"></i>
                    <span class="icon-bell" style="display: none;"></span>
                </a>
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
                        <a href="../users/view.php?id=<?php echo $currentUser['user_id']; ?>" class="dropdown-item">
                            <i class="fas fa-user"></i>
                            My Profile
                        </a>
                        <a href="../settings/index.php" class="dropdown-item">
                            <i class="fas fa-cog"></i>
                            Account Settings
                        </a>
                        <a href="../../auth/logout.php" class="dropdown-item logout">
                            <i class="fas fa-sign-out-alt"></i>
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
                        <a href="index.php" class="nav-link active">
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
        <div class="main-content" id="mainContent">
            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1 class="page-title">🛡️ Audit Report</h1>
                    <p class="page-subtitle">System activity logs and security audit trail</p>
                </div>
                <div class="page-actions">
                    <a href="index.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i>
                        Back to Reports
                    </a>
                    <button onclick="window.print()" class="btn btn-secondary">
                        <i class="fas fa-print"></i>
                        Print Audit Logs
                    </button>
                    <a href="?export=excel&<?php echo http_build_query($_GET); ?>" class="btn btn-success">
                        <i class="fas fa-file-excel"></i>
                        Export Excel
                    </a>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-card">
                <form method="GET" action="">
                    <div class="filters-grid">
                        <div class="form-group">
                            <label class="form-label">Start Date</label>
                            <input type="date" name="date" class="form-control" value="<?php echo $selectedDate; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">End Date</label>
                            <input type="date" name="end_date" class="form-control" value="<?php echo $selectedEndDate; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">User</label>
                            <select name="user" class="form-control">
                                <option value="0">All Users</option>
                                <?php foreach ($availableUsers as $user): ?>
                                    <option value="<?php echo $user['user_id']; ?>" 
                                        <?php echo $selectedUser == $user['user_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                        (<?php echo htmlspecialchars($user['role_name']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Action</label>
                            <select name="action" class="form-control">
                                <option value="">All Actions</option>
                                <?php foreach ($availableActions as $action): ?>
                                    <option value="<?php echo htmlspecialchars($action['action']); ?>" 
                                        <?php echo $selectedAction == $action['action'] ? 'selected' : ''; ?>>
                                        <?php echo formatActionName($action['action']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i>
                                Apply Filters
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="stat-header">
                        <div class="stat-title">Total Actions</div>
                        <div class="stat-icon">
                            <i class="fas fa-list"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($auditStats['total_actions']); ?></div>
                    <div class="stat-subtitle">Recorded activities</div>
                </div>

                <div class="stat-card success">
                    <div class="stat-header">
                        <div class="stat-title">Active Users</div>
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                            <span class="icon-users" style="display: none;"></span>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($auditStats['unique_users']); ?></div>
                    <div class="stat-subtitle">Unique users</div>
                </div>

                <div class="stat-card info">
                    <div class="stat-header">
                        <div class="stat-title">Active Days</div>
                        <div class="stat-icon">
                            <i class="fas fa-calendar"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($auditStats['active_days']); ?></div>
                    <div class="stat-subtitle">Days with activity</div>
                </div>

                <div class="stat-card warning">
                    <div class="stat-header">
                        <div class="stat-title">Security Events</div>
                        <div class="stat-icon">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format(count($securityEvents)); ?></div>
                    <div class="stat-subtitle">Security-related actions</div>
                </div>
            </div>

            <!-- Charts and Summary Tables -->
            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 30px;" class="print-grid-2">
                <!-- Daily Activity Chart -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">📈 Daily Activity</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="dailyActivityChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Top Actions -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">🔥 Top Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive" style="max-height: 350px;">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Action</th>
                                        <th>Count</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($actionTypes)): ?>
                                        <tr>
                                            <td colspan="2" style="text-align: center; color: #718096;">No data available</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($actionTypes as $action): ?>
                                            <tr>
                                                <td>
                                                    <span class="action-icon"><?php echo getActionIcon($action['action']); ?></span>
                                                    <?php echo formatActionName($action['action']); ?>
                                                </td>
                                                <td>
                                                    <span class="badge badge-primary">
                                                        <?php echo number_format($action['action_count']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- User Activity and Table Modifications -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px;" class="print-grid-2">
                <!-- User Activity -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">👥 User Activity</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Actions</th>
                                        <th>Last Activity</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($userActivity)): ?>
                                        <tr>
                                            <td colspan="3" style="text-align: center; color: #718096;">No data available</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($userActivity as $user): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($user['user_name'] ?? 'Unknown'); ?></strong><br>
                                                    <small style="color: #718096;"><?php echo htmlspecialchars($user['role_name'] ?? 'Unknown Role'); ?></small>
                                                </td>
                                                <td><?php echo number_format($user['total_actions']); ?></td>
                                                <td><?php echo $user['last_activity'] ? date('M j, g:i A', strtotime($user['last_activity'])) : 'N/A'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Table Modifications -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">🗄️ Table Modifications</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Table</th>
                                        <th>Changes</th>
                                        <th>Last Modified</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($tableMods)): ?>
                                        <tr>
                                            <td colspan="3" style="text-align: center; color: #718096;">No data available</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($tableMods as $table): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($table['table_name']); ?></td>
                                                <td><?php echo number_format($table['modification_count']); ?></td>
                                                <td><?php echo date('M j, g:i A', strtotime($table['last_modified'])); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Security Events -->
            <?php if (!empty($securityEvents)): ?>
            <div class="card security-events" style="margin-bottom: 30px;">
                <div class="card-header">
                    <h5 class="card-title">⚠️ Security Events</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Action</th>
                                    <th>User</th>
                                    <th>IP Address</th>
                                    <th>Date/Time</th>
                                    <th>Risk</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($securityEvents as $event): ?>
                                    <tr>
                                        <td>
                                            <span class="action-icon"><?php echo getActionIcon($event['action']); ?></span>
                                            <?php echo formatActionName($event['action']); ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($event['user_name'] ?? 'System'); ?></td>
                                        <td><span class="ip-address"><?php echo htmlspecialchars($event['ip_address']); ?></span></td>
                                        <td><?php echo date('M j, Y g:i A', strtotime($event['created_at'])); ?></td>
                                        <td>
                                            <?php 
                                            $riskLevel = getRiskLevel($event['action']);
                                            ?>
                                            <span class="risk-indicator risk-<?php echo $riskLevel; ?>">
                                                <?php echo ucfirst($riskLevel); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Detailed Audit Logs -->
            <div class="card audit-summary page-break-inside-avoid">
                <div class="card-header">
                    <h5 class="card-title">📋 Detailed Audit Logs (Last 1000 entries)</h5>
                </div>
                <div class="card-body">
                    <!-- Search Box -->
                    <div class="search-box">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" id="searchInput" class="search-input" 
                            placeholder="Search by action, user, or IP address...">
                    </div>

                    <div class="table-responsive">
                        <table class="table" id="auditTable">
                            <thead>
                                <tr>
                                    <th>Date/Time</th>
                                    <th>User</th>
                                    <th>Action</th>
                                    <th>Table</th>
                                    <th>IP Address</th>
                                    <th>Risk</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($auditLogs)): ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center; color: #718096; padding: 40px;">
                                            No audit logs found for the selected filters
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($auditLogs as $log): ?>
                                        <tr>
                                            <td><?php echo date('M j, g:i A', strtotime($log['created_at'])); ?></td>
                                            <td>
                                                <?php echo htmlspecialchars($log['user_name'] ?? 'System'); ?>
                                                <?php if ($log['role_name']): ?>
                                                    <br><small style="color: #718096;"><?php echo htmlspecialchars($log['role_name']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="action-icon"><?php echo getActionIcon($log['action']); ?></span>
                                                <?php echo formatActionName($log['action']); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($log['table_name'] ?? 'N/A'); ?></td>
                                            <td><span class="ip-address"><?php echo htmlspecialchars($log['ip_address']); ?></span></td>
                                            <td>
                                                <?php 
                                                $riskLevel = getRiskLevel($log['action']);
                                                ?>
                                                <span class="risk-indicator risk-<?php echo $riskLevel; ?>">
                                                    <?php echo ucfirst($riskLevel); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($log['new_values']): ?>
                                                    <details>
                                                        <summary style="cursor: pointer; color: #667eea;">View Changes</summary>
                                                        <div class="json-data"><?php echo htmlspecialchars($log['new_values']); ?></div>
                                                    </details>
                                                <?php else: ?>
                                                    <span style="color: #718096;">No details</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Chart data
        const dailyLabels = <?php echo json_encode($dailyLabels); ?>;
        const dailyData = <?php echo json_encode($dailyData); ?>;

        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Initializing audit report...');
            initializeCharts();
            initializeSearch();
            setupPrintOptimizations();
        });

        function initializeCharts() {
            if (typeof Chart === 'undefined') {
                console.log('Chart.js not available from local file');
                showChartFallback();
                return;
            }

            if (dailyLabels.length === 0) {
                console.log('No chart data available');
                showChartFallback();
                return;
            }

            try {
                // Daily Activity Chart
                const ctx = document.getElementById('dailyActivityChart');
                if (ctx) {
                    new Chart(ctx.getContext('2d'), {
                        type: 'line',
                        data: {
                            labels: dailyLabels,
                            datasets: [{
                                label: 'Daily Actions',
                                data: dailyData,
                                borderColor: '#667eea',
                                backgroundColor: 'rgba(102, 126, 234, 0.1)',
                                fill: true,
                                tension: 0.4,
                                borderWidth: 3,
                                pointBackgroundColor: '#667eea',
                                pointBorderColor: '#fff',
                                pointBorderWidth: 2,
                                pointRadius: 4
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    grid: {
                                        color: '#e2e8f0'
                                    },
                                    title: {
                                        display: true,
                                        text: 'Number of Actions'
                                    }
                                },
                                x: {
                                    grid: {
                                        display: false
                                    },
                                    title: {
                                        display: true,
                                        text: 'Date'
                                    }
                                }
                            },
                            plugins: {
                                legend: {
                                    display: false
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            return 'Actions: ' + context.parsed.y;
                                        }
                                    }
                                }
                            }
                        }
                    });
                }

                console.log('Chart created successfully');
            } catch (error) {
                console.error('Error creating chart:', error);
                showChartFallback();
            }
        }

        function showChartFallback() {
            const chartContainer = document.querySelector('.chart-container');
            if (chartContainer) {
                chartContainer.innerHTML = '<div style="height: 350px; display: flex; align-items: center; justify-content: center; color: #718096; font-style: italic;">📊 Chart will appear here when Chart.js is available and data exists</div>';
            }
        }

        function initializeSearch() {
            const searchInput = document.getElementById('searchInput');
            const table = document.getElementById('auditTable');
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));

            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    if (text.includes(searchTerm)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            });
        }

        function setupPrintOptimizations() {
            // Optimize for printing audit logs only
            window.addEventListener('beforeprint', function() {
                // Close all details elements for cleaner print
                const details = document.querySelectorAll('details[open]');
                details.forEach(detail => {
                    detail.removeAttribute('open');
                });
            });
        }

        // Sidebar toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('hidden');
            
            const isHidden = sidebar.classList.contains('hidden');
            localStorage.setItem('sidebarHidden', isHidden);
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

        // Restore sidebar state
        document.addEventListener('DOMContentLoaded', function() {
            const sidebarHidden = localStorage.getItem('sidebarHidden');
            if (sidebarHidden === 'true') {
                document.getElementById('sidebar').classList.add('hidden');
            }

            // Check if Font Awesome loaded
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
    </script>
</body>
</html>