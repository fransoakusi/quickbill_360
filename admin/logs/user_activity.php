<?php
/**
 * Admin User Activity Logs Page
 * Track and monitor user activities and sessions
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
require_once '../../includes/restriction_warning.php';

// Check if user is logged in and is admin
if (!isLoggedIn()) {
    header('Location: ../../auth/login.php');
    exit();
}

if (!isAdmin()) {
    setFlashMessage('error', 'Access denied. Admin privileges required.');
    header('Location: ../../auth/login.php');
    exit();
}

$currentUser = getCurrentUser();
$db = new Database();

// Get parameters
$user_id = intval($_GET['user_id'] ?? 0);
$activity_type = sanitizeInput($_GET['activity_type'] ?? '');
$date_from = sanitizeInput($_GET['date_from'] ?? '');
$date_to = sanitizeInput($_GET['date_to'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$limit = intval($_GET['limit'] ?? 50);
$offset = ($page - 1) * $limit;

// Build WHERE clause for activities
$whereConditions = [];
$params = [];

if ($user_id > 0) {
    $whereConditions[] = "al.user_id = ?";
    $params[] = $user_id;
}

if ($activity_type) {
    $activityFilter = match($activity_type) {
        'login' => ["al.action LIKE ?", "%LOGIN%"],
        'logout' => ["al.action LIKE ?", "%LOGOUT%"],
        'create' => ["al.action LIKE ?", "%CREATE%"],
        'update' => ["al.action LIKE ?", "%UPDATE%"],
        'delete' => ["al.action LIKE ?", "%DELETE%"],
        'payment' => ["al.action LIKE ?", "%PAYMENT%"],
        'billing' => ["al.action LIKE ?", "%BILL%"],
        default => null
    };
    
    if ($activityFilter) {
        $whereConditions[] = $activityFilter[0];
        $params[] = $activityFilter[1];
    }
}

if ($date_from) {
    $whereConditions[] = "DATE(al.created_at) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $whereConditions[] = "DATE(al.created_at) <= ?";
    $params[] = $date_to;
}

$whereClause = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Get user activities
$activitiesQuery = "
    SELECT 
        al.*,
        u.username,
        u.first_name,
        u.last_name,
        u.email,
        ur.role_name
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.user_id
    LEFT JOIN user_roles ur ON u.role_id = ur.role_id
    {$whereClause}
    ORDER BY al.created_at DESC
    LIMIT {$limit} OFFSET {$offset}
";

$activities = $db->fetchAll($activitiesQuery, $params);

// Get total count
$countQuery = "SELECT COUNT(*) as total FROM audit_logs al LEFT JOIN users u ON al.user_id = u.user_id {$whereClause}";
$totalResult = $db->fetchRow($countQuery, $params);
$totalRecords = $totalResult['total'] ?? 0;
$totalPages = ceil($totalRecords / $limit);

// Get all users for filter dropdown
$users = $db->fetchAll("
    SELECT u.user_id, u.username, u.first_name, u.last_name, ur.role_name 
    FROM users u 
    LEFT JOIN user_roles ur ON u.role_id = ur.role_id 
    WHERE u.is_active = 1 
    ORDER BY u.first_name, u.last_name
");

// Get user activity statistics
$statsQuery = "
    SELECT 
        COUNT(*) as total_activities,
        COUNT(DISTINCT al.user_id) as active_users,
        SUM(CASE WHEN al.action LIKE '%LOGIN%' THEN 1 ELSE 0 END) as login_count,
        SUM(CASE WHEN al.action LIKE '%CREATE%' THEN 1 ELSE 0 END) as create_count,
        SUM(CASE WHEN al.action LIKE '%UPDATE%' THEN 1 ELSE 0 END) as update_count,
        SUM(CASE WHEN al.action LIKE '%DELETE%' THEN 1 ELSE 0 END) as delete_count,
        SUM(CASE WHEN al.action LIKE '%PAYMENT%' THEN 1 ELSE 0 END) as payment_count
    FROM audit_logs al
    WHERE DATE(al.created_at) = CURDATE()
";

$todayStats = $db->fetchRow($statsQuery);

// Get most active users today
$mostActiveQuery = "
    SELECT 
        u.username,
        u.first_name,
        u.last_name,
        ur.role_name,
        COUNT(*) as activity_count,
        MAX(al.created_at) as last_activity
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.user_id
    LEFT JOIN user_roles ur ON u.role_id = ur.role_id
    WHERE DATE(al.created_at) = CURDATE()
    AND u.user_id IS NOT NULL
    GROUP BY al.user_id
    ORDER BY activity_count DESC
    LIMIT 5
";

$mostActiveUsers = $db->fetchAll($mostActiveQuery);

// Get recent login attempts (including failed ones)
$recentLoginsQuery = "
    SELECT 
        al.*,
        u.username,
        u.first_name,
        u.last_name
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.user_id
    WHERE al.action LIKE '%LOGIN%' OR al.action LIKE '%LOGOUT%'
    ORDER BY al.created_at DESC
    LIMIT 10
";

$recentLogins = $db->fetchAll($recentLoginsQuery);

// Export functionality
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="user_activity_' . date('Y-m-d_H-i-s') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // CSV headers
    fputcsv($output, [
        'Activity ID', 'User', 'Role', 'Action', 'Table', 'Record ID', 
        'IP Address', 'User Agent', 'Timestamp', 'Changes'
    ]);
    
    // Export all matching records
    $exportQuery = str_replace("LIMIT {$limit} OFFSET {$offset}", "", $activitiesQuery);
    $exportActivities = $db->fetchAll($exportQuery, $params);
    
    foreach ($exportActivities as $activity) {
        $changes = '';
        if ($activity['old_values'] || $activity['new_values']) {
            $changes = 'Old: ' . ($activity['old_values'] ?: 'N/A') . ' | New: ' . ($activity['new_values'] ?: 'N/A');
        }
        
        fputcsv($output, [
            $activity['log_id'],
            ($activity['username'] ?? 'System') . ' (' . trim($activity['first_name'] . ' ' . $activity['last_name']) . ')',
            $activity['role_name'] ?? 'N/A',
            $activity['action'],
            $activity['table_name'] ?? 'N/A',
            $activity['record_id'] ?? 'N/A',
            $activity['ip_address'],
            $activity['user_agent'],
            $activity['created_at'],
            $changes
        ]);
    }
    
    fclose($output);
    exit();
}

function getActivityIcon($action) {
    $action = strtoupper($action);
    
    if (strpos($action, 'LOGIN') !== false) return '🔐';
    if (strpos($action, 'LOGOUT') !== false) return '🚪';
    if (strpos($action, 'CREATE') !== false) return '➕';
    if (strpos($action, 'UPDATE') !== false) return '✏️';
    if (strpos($action, 'DELETE') !== false) return '🗑️';
    if (strpos($action, 'PAYMENT') !== false) return '💰';
    if (strpos($action, 'BILL') !== false) return '📄';
    if (strpos($action, 'BACKUP') !== false) return '💾';
    if (strpos($action, 'IMPORT') !== false) return '📥';
    if (strpos($action, 'EXPORT') !== false) return '📤';
    
    return '📝';
}

function getActivityBadgeClass($action) {
    $action = strtoupper($action);
    
    if (strpos($action, 'LOGIN') !== false) return 'success';
    if (strpos($action, 'LOGOUT') !== false) return 'secondary';
    if (strpos($action, 'CREATE') !== false) return 'primary';
    if (strpos($action, 'UPDATE') !== false) return 'warning';
    if (strpos($action, 'DELETE') !== false) return 'danger';
    if (strpos($action, 'PAYMENT') !== false) return 'success';
    if (strpos($action, 'BILL') !== false) return 'info';
    if (strpos($action, 'BLOCKED') !== false) return 'danger';
    
    return 'secondary';
}

// Note: timeAgo() function is now used from functions.php - no need to redeclare it here

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Activity Logs - <?php echo APP_NAME; ?></title>
    
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
        }

        /* Header */
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .header h1 {
            font-size: 28px;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .header p {
            opacity: 0.9;
            font-size: 16px;
        }

        .back-link {
            color: rgba(255,255,255,0.9);
            text-decoration: none;
            font-size: 14px;
            margin-bottom: 10px;
            display: inline-block;
        }

        .back-link:hover {
            color: white;
        }

        /* Container */
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px;
        }

        /* Dashboard Stats */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }

        .stats-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
        }

        .stat-icon {
            font-size: 32px;
            margin-bottom: 10px;
        }

        .stat-value {
            font-size: 28px;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 14px;
            color: #718096;
            font-weight: 500;
        }

        /* Quick Info Cards */
        .quick-info {
            display: grid;
            gap: 20px;
        }

        .info-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .info-card h3 {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .user-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .user-item:last-child {
            border-bottom: none;
        }

        .user-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .user-name {
            font-weight: 600;
            color: #2d3748;
            font-size: 14px;
        }

        .user-role {
            font-size: 12px;
            color: #718096;
        }

        .activity-count {
            background: #667eea;
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .login-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .login-item:last-child {
            border-bottom: none;
        }

        .login-action {
            font-size: 12px;
            font-weight: 600;
        }

        .login-time {
            font-size: 11px;
            color: #718096;
        }

        /* Filters */
        .filters-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .filters-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-label {
            font-size: 14px;
            font-weight: 600;
            color: #4a5568;
            margin-bottom: 5px;
        }

        .form-control {
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 14px;
            transition: all 0.3s;
            background: white;
        }

        .form-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: #667eea;
            color: white;
        }

        .btn-primary:hover {
            background: #5a67d8;
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: #e2e8f0;
            color: #4a5568;
        }

        .btn-secondary:hover {
            background: #cbd5e0;
            color: #2d3748;
        }

        .btn-success {
            background: #48bb78;
            color: white;
        }

        .btn-success:hover {
            background: #38a169;
        }

        /* Activity Table */
        .activity-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .activity-header {
            background: #f7fafc;
            padding: 20px 25px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .activity-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
        }

        .activity-list {
            max-height: 600px;
            overflow-y: auto;
        }

        .activity-item {
            padding: 20px 25px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: flex-start;
            gap: 15px;
            transition: background 0.2s;
        }

        .activity-item:hover {
            background: #f8f9fa;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            font-size: 20px;
            width: 40px;
            height: 40px;
            background: #f7fafc;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .activity-content {
            flex: 1;
        }

        .activity-main {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
        }

        .activity-user {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 4px;
        }

        .activity-action {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 4px;
        }

        .activity-time {
            font-size: 12px;
            color: #718096;
        }

        .activity-details {
            font-size: 13px;
            color: #4a5568;
            margin-top: 8px;
            padding: 8px 12px;
            background: #f8f9fa;
            border-radius: 6px;
            border-left: 3px solid #e2e8f0;
        }

        .activity-ip {
            font-family: monospace;
            font-size: 11px;
            background: #e6f3ff;
            color: #0366d6;
            padding: 2px 6px;
            border-radius: 4px;
            margin-top: 4px;
            display: inline-block;
        }

        /* Badges */
        .badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }

        .badge-primary { background: #e6f3ff; color: #0066cc; }
        .badge-success { background: #e6f7f1; color: #0f5132; }
        .badge-warning { background: #fff3cd; color: #664d03; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-secondary { background: #e9ecef; color: #495057; }
        .badge-info { background: #d1ecf1; color: #055160; }

        /* Pagination */
        .pagination-container {
            padding: 20px 25px;
            background: #f7fafc;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .pagination-info {
            font-size: 14px;
            color: #718096;
        }

        .pagination {
            display: flex;
            gap: 5px;
            align-items: center;
        }

        .pagination a,
        .pagination span {
            padding: 8px 12px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
            transition: all 0.3s;
        }

        .pagination a {
            background: white;
            color: #4a5568;
            border: 1px solid #e2e8f0;
        }

        .pagination a:hover {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }

        .pagination .current {
            background: #667eea;
            color: white;
            border: 1px solid #667eea;
        }

        /* No data */
        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #718096;
        }

        .no-data-icon {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }

            .dashboard-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .stats-section {
                grid-template-columns: repeat(2, 1fr);
            }

            .filters-grid {
                grid-template-columns: 1fr;
            }

            .activity-header {
                flex-direction: column;
                align-items: stretch;
            }

            .activity-item {
                flex-direction: column;
                gap: 10px;
            }

            .activity-main {
                flex-direction: column;
                gap: 8px;
            }

            .filter-actions {
                justify-content: center;
            }
        }

        /* Icons */
        .icon-activity::before { content: "👤"; }
        .icon-filter::before { content: "🔍"; }
        .icon-export::before { content: "📥"; }
        .icon-refresh::before { content: "🔄"; }
        .icon-users::before { content: "👥"; }
        .icon-login::before { content: "🔐"; }
        .icon-stats::before { content: "📊"; }
    </style>
</head>
<body>
    <div class="header">
        <a href="../index.php" class="back-link">← Back to Dashboard</a>
        <h1><span class="icon-activity"></span> User Activity Logs</h1>
        <p>Monitor user activities, sessions, and system interactions</p>
    </div>

    <div class="container">
        <!-- Dashboard Overview -->
        <div class="dashboard-grid">
            <div class="stats-section">
                <div class="stat-card">
                    <div class="stat-icon">📊</div>
                    <div class="stat-value"><?php echo number_format($todayStats['total_activities'] ?? 0); ?></div>
                    <div class="stat-label">Activities Today</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">👥</div>
                    <div class="stat-value"><?php echo number_format($todayStats['active_users'] ?? 0); ?></div>
                    <div class="stat-label">Active Users Today</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">🔐</div>
                    <div class="stat-value"><?php echo number_format($todayStats['login_count'] ?? 0); ?></div>
                    <div class="stat-label">Login Attempts</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">💰</div>
                    <div class="stat-value"><?php echo number_format($todayStats['payment_count'] ?? 0); ?></div>
                    <div class="stat-label">Payment Activities</div>
                </div>
            </div>

            <div class="quick-info">
                <!-- Most Active Users -->
                <div class="info-card">
                    <h3>
                        <span class="icon-users"></span>
                        Most Active Users Today
                    </h3>
                    <?php if (empty($mostActiveUsers)): ?>
                        <p style="color: #718096; font-style: italic; text-align: center; padding: 20px 0;">
                            No user activity today
                        </p>
                    <?php else: ?>
                        <?php foreach ($mostActiveUsers as $user): ?>
                            <div class="user-item">
                                <div class="user-info">
                                    <div class="user-name">
                                        <?php echo htmlspecialchars($user['username']); ?>
                                    </div>
                                    <div class="user-role">
                                        <?php echo htmlspecialchars(trim($user['first_name'] . ' ' . $user['last_name'])); ?>
                                        <?php echo $user['role_name'] ? ' (' . htmlspecialchars($user['role_name']) . ')' : ''; ?>
                                    </div>
                                </div>
                                <div class="activity-count">
                                    <?php echo $user['activity_count']; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Recent Logins -->
                <div class="info-card">
                    <h3>
                        <span class="icon-login"></span>
                        Recent Login Activity
                    </h3>
                    <?php if (empty($recentLogins)): ?>
                        <p style="color: #718096; font-style: italic; text-align: center; padding: 20px 0;">
                            No login activity
                        </p>
                    <?php else: ?>
                        <?php foreach ($recentLogins as $login): ?>
                            <div class="login-item">
                                <div>
                                    <div class="login-action">
                                        <span class="badge badge-<?php echo getActivityBadgeClass($login['action']); ?>">
                                            <?php echo htmlspecialchars($login['action']); ?>
                                        </span>
                                    </div>
                                    <div style="font-size: 12px; color: #2d3748; margin-top: 2px;">
                                        <?php echo htmlspecialchars($login['username'] ?? 'System'); ?>
                                    </div>
                                </div>
                                <div class="login-time">
                                    <?php echo timeAgo($login['created_at']); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-card">
            <h3 class="filters-title">
                <span class="icon-filter"></span>
                Activity Filters
            </h3>
            
            <form method="GET" action="">
                <div class="filters-grid">
                    <div class="form-group">
                        <label class="form-label">Select User</label>
                        <select name="user_id" class="form-control">
                            <option value="">All Users</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['user_id']; ?>" 
                                        <?php echo $user_id == $user['user_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name'] . ' (' . $user['username'] . ')'); ?>
                                    <?php echo $user['role_name'] ? ' - ' . htmlspecialchars($user['role_name']) : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Activity Type</label>
                        <select name="activity_type" class="form-control">
                            <option value="">All Activities</option>
                            <option value="login" <?php echo $activity_type === 'login' ? 'selected' : ''; ?>>Login/Sessions</option>
                            <option value="logout" <?php echo $activity_type === 'logout' ? 'selected' : ''; ?>>Logout</option>
                            <option value="create" <?php echo $activity_type === 'create' ? 'selected' : ''; ?>>Create/Add</option>
                            <option value="update" <?php echo $activity_type === 'update' ? 'selected' : ''; ?>>Update/Edit</option>
                            <option value="delete" <?php echo $activity_type === 'delete' ? 'selected' : ''; ?>>Delete</option>
                            <option value="payment" <?php echo $activity_type === 'payment' ? 'selected' : ''; ?>>Payments</option>
                            <option value="billing" <?php echo $activity_type === 'billing' ? 'selected' : ''; ?>>Billing</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Date From</label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Date To</label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <span class="icon-filter"></span>
                        Apply Filters
                    </button>
                    <a href="user_activity.php" class="btn btn-secondary">
                        <span class="icon-refresh"></span>
                        Clear All
                    </a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="btn btn-success">
                        <span class="icon-export"></span>
                        Export CSV
                    </a>
                </div>
            </form>
        </div>

        <!-- Activity Log -->
        <div class="activity-card">
            <div class="activity-header">
                <h3 class="activity-title">User Activities</h3>
                <div class="filter-actions">
                    <select onchange="changeLimit(this.value)" class="form-control" style="width: auto;">
                        <option value="25" <?php echo $limit == 25 ? 'selected' : ''; ?>>25 per page</option>
                        <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50 per page</option>
                        <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100 per page</option>
                    </select>
                </div>
            </div>

            <?php if (empty($activities)): ?>
                <div class="no-data">
                    <div class="no-data-icon">👤</div>
                    <h3>No User Activities Found</h3>
                    <p>No user activities match your current filters.</p>
                </div>
            <?php else: ?>
                <div class="activity-list">
                    <?php foreach ($activities as $activity): ?>
                        <div class="activity-item">
                            <div class="activity-icon">
                                <?php echo getActivityIcon($activity['action']); ?>
                            </div>
                            
                            <div class="activity-content">
                                <div class="activity-main">
                                    <div>
                                        <div class="activity-user">
                                            <?php echo htmlspecialchars($activity['username'] ?? 'System'); ?>
                                            <?php if ($activity['first_name'] || $activity['last_name']): ?>
                                                - <?php echo htmlspecialchars(trim($activity['first_name'] . ' ' . $activity['last_name'])); ?>
                                            <?php endif; ?>
                                            <?php if ($activity['role_name']): ?>
                                                <span style="font-size: 12px; color: #718096; font-weight: normal;">
                                                    (<?php echo htmlspecialchars($activity['role_name']); ?>)
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="activity-action">
                                            <span class="badge badge-<?php echo getActivityBadgeClass($activity['action']); ?>">
                                                <?php echo htmlspecialchars($activity['action']); ?>
                                            </span>
                                            <?php if ($activity['table_name']): ?>
                                                <span style="font-size: 12px; color: #718096;">
                                                    on <strong><?php echo htmlspecialchars($activity['table_name']); ?></strong>
                                                    <?php if ($activity['record_id']): ?>
                                                        (ID: <?php echo $activity['record_id']; ?>)
                                                    <?php endif; ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="activity-time">
                                        <?php echo timeAgo($activity['created_at']); ?>
                                    </div>
                                </div>

                                <?php if ($activity['ip_address']): ?>
                                    <div class="activity-ip">
                                        📍 <?php echo htmlspecialchars($activity['ip_address']); ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($activity['old_values'] || $activity['new_values']): ?>
                                    <div class="activity-details">
                                        <strong>Changes Made:</strong><br>
                                        <?php if ($activity['old_values']): ?>
                                            <strong style="color: #e53e3e;">Before:</strong> 
                                            <?php echo htmlspecialchars(substr($activity['old_values'], 0, 200)); ?>
                                            <?php echo strlen($activity['old_values']) > 200 ? '...' : ''; ?><br>
                                        <?php endif; ?>
                                        <?php if ($activity['new_values']): ?>
                                            <strong style="color: #38a169;">After:</strong> 
                                            <?php echo htmlspecialchars(substr($activity['new_values'], 0, 200)); ?>
                                            <?php echo strlen($activity['new_values']) > 200 ? '...' : ''; ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination -->
                <div class="pagination-container">
                    <div class="pagination-info">
                        Showing <?php echo number_format(($page - 1) * $limit + 1); ?> to <?php echo number_format(min($page * $limit, $totalRecords)); ?> of <?php echo number_format($totalRecords); ?> activities
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">First</a>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">Previous</a>
                            <?php endif; ?>

                            <?php
                            $start = max(1, $page - 2);
                            $end = min($totalPages, $page + 2);
                            
                            for ($i = $start; $i <= $end; $i++):
                            ?>
                                <?php if ($i == $page): ?>
                                    <span class="current"><?php echo $i; ?></span>
                                <?php else: ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">Next</a>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $totalPages])); ?>">Last</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function changeLimit(limit) {
            const url = new URL(window.location);
            url.searchParams.set('limit', limit);
            url.searchParams.set('page', '1');
            window.location.href = url.toString();
        }

        // Auto-refresh functionality
        let autoRefreshInterval;
        function startAutoRefresh() {
            autoRefreshInterval = setInterval(() => {
                // Only refresh if we're on the first page and no specific filters
                const url = new URL(window.location);
                if (!url.searchParams.get('page') || url.searchParams.get('page') === '1') {
                    window.location.reload();
                }
            }, 60000); // Refresh every minute
        }

        // Start auto-refresh for recent activity
        document.addEventListener('DOMContentLoaded', function() {
            // Only auto-refresh if viewing recent activities (no date filters)
            const urlParams = new URLSearchParams(window.location.search);
            if (!urlParams.get('date_from') && !urlParams.get('date_to')) {
                startAutoRefresh();
            }

            // Add click handlers for activity items
            const activityItems = document.querySelectorAll('.activity-item');
            activityItems.forEach(item => {
                item.addEventListener('click', function() {
                    // Could implement detailed view modal
                    this.style.backgroundColor = '#f0f4f8';
                    setTimeout(() => {
                        this.style.backgroundColor = '';
                    }, 1000);
                });
            });
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                window.location.reload();
            }
            if (e.ctrlKey && e.key === 'e') {
                e.preventDefault();
                const exportBtn = document.querySelector('a[href*="export=csv"]');
                if (exportBtn) exportBtn.click();
            }
        });
    </script>
</body>
</html>