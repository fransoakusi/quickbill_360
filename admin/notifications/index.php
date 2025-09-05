<?php
/**
 * Admin Notifications Management - Index
 * QUICKBILL 305 - Following proper admin structure
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

$currentUser = getCurrentUser();
$userDisplayName = getUserDisplayName($currentUser);

// Initialize database
$db = new Database();

// Handle actions
$action = $_GET['action'] ?? '';
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($action) {
        case 'mark_read':
            $notificationId = (int)($_POST['notification_id'] ?? 0);
            if ($notificationId > 0) {
                try {
                    $db->execute("UPDATE notifications SET status = 'Read' WHERE notification_id = ?", [$notificationId]);
                    $message = 'Notification marked as read.';
                    $messageType = 'success';
                } catch (Exception $e) {
                    $message = 'Error updating notification: ' . $e->getMessage();
                    $messageType = 'error';
                }
            }
            break;
    }
}

// Get notifications with pagination
$page = (int)($_GET['page'] ?? 1);
$limit = 15;
$offset = ($page - 1) * $limit;

$filters = [
    'status' => $_GET['status'] ?? '',
    'type' => $_GET['type'] ?? '',
    'search' => $_GET['search'] ?? ''
];

// Build query
$whereConditions = [];
$params = [];

if (!empty($filters['status'])) {
    $whereConditions[] = "n.status = ?";
    $params[] = $filters['status'];
}

if (!empty($filters['type'])) {
    $whereConditions[] = "n.notification_type = ?";
    $params[] = $filters['type'];
}

if (!empty($filters['search'])) {
    $whereConditions[] = "(n.subject LIKE ? OR n.message LIKE ?)";
    $params[] = '%' . $filters['search'] . '%';
    $params[] = '%' . $filters['search'] . '%';
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Get notifications
$query = "
    SELECT n.*, 
           u.first_name as sender_first_name, 
           u.last_name as sender_last_name,
           CASE 
               WHEN n.recipient_type = 'Business' THEN b.business_name
               WHEN n.recipient_type = 'Property' THEN p.owner_name
               WHEN n.recipient_type = 'User' THEN CONCAT(ur.first_name, ' ', ur.last_name)
               ELSE 'Unknown'
           END as recipient_name
    FROM notifications n
    LEFT JOIN users u ON n.sent_by = u.user_id
    LEFT JOIN businesses b ON n.recipient_type = 'Business' AND n.recipient_id = b.business_id
    LEFT JOIN properties p ON n.recipient_type = 'Property' AND n.recipient_id = p.property_id
    LEFT JOIN users ur ON n.recipient_type = 'User' AND n.recipient_id = ur.user_id
    $whereClause
    ORDER BY n.created_at DESC
    LIMIT $limit OFFSET $offset
";

try {
    $notifications = $db->fetchAll($query, $params);
} catch (Exception $e) {
    $notifications = [];
}

// Get total count for pagination
try {
    $countQuery = "SELECT COUNT(*) as total FROM notifications $whereClause";
    $totalResult = $db->fetchRow($countQuery, $params);
    $totalNotifications = $totalResult['total'] ?? 0;
} catch (Exception $e) {
    $totalNotifications = 0;
}

$totalPages = ceil($totalNotifications / $limit);

// Get statistics
$stats = [
    'total' => 0,
    'pending' => 0,
    'sent' => 0,
    'failed' => 0
];

try {
    $stats['total'] = $db->fetchRow("SELECT COUNT(*) as count FROM notifications")['count'] ?? 0;
    $stats['pending'] = $db->fetchRow("SELECT COUNT(*) as count FROM notifications WHERE status = 'Pending'")['count'] ?? 0;
    $stats['sent'] = $db->fetchRow("SELECT COUNT(*) as count FROM notifications WHERE status = 'Sent'")['count'] ?? 0;
    $stats['failed'] = $db->fetchRow("SELECT COUNT(*) as count FROM notifications WHERE status = 'Failed'")['count'] ?? 0;
} catch (Exception $e) {
    // Keep default values
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - <?php echo APP_NAME; ?></title>
    
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
        .icon-bell::before { content: "🔔"; }
        .icon-sms::before { content: "💬"; }
        .icon-users::before { content: "👥"; }
        .icon-file::before { content: "📄"; }
        .icon-plus::before { content: "➕"; }
        .icon-eye::before { content: "👁️"; }
        .icon-check::before { content: "✅"; }
        .icon-search::before { content: "🔍"; }
        .icon-clock::before { content: "⏰"; }
        .icon-warning::before { content: "⚠️"; }
        .icon-history::before { content: "📜"; }
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
        .icon-plus::before { content: "➕"; }
        .icon-server::before { content: "🖥️"; }
        .icon-database::before { content: "💾"; }
        .icon-shield::before { content: "🛡️"; }
        .icon-user-plus::before { content: "👤➕"; }
        .icon-history::before { content: "📜"; }
        .icon-question::before { content: "❓"; }
        
        /* Same styling as admin dashboard */
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
            margin-bottom: 20px;
        }
        
        .nav-title {
            color: #a0aec0;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            padding: 10px 20px;
            margin-bottom: 8px;
            letter-spacing: 1.2px;
            border-left: 3px solid transparent;
        }
        
        .nav-item {
            margin-bottom: 2px;
        }
        
        .nav-link {
            color: #e2e8f0;
            text-decoration: none;
            padding: 10px 20px;
            display: block;
            transition: all 0.3s;
            border-left: 3px solid transparent;
            font-size: 14px;
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
        
        .nav-subsection {
            padding-left: 20px;
        }
        
        .nav-sublink {
            color: #cbd5e1;
            text-decoration: none;
            padding: 8px 20px 8px 30px;
            display: block;
            font-size: 13px;
            transition: all 0.3s;
            border-left: 3px solid transparent;
        }
        
        .nav-sublink:hover {
            background: rgba(255,255,255,0.08);
            color: white;
            border-left-color: #7f9cf5;
        }
        
        .nav-sublink.active {
            background: rgba(102, 126, 234, 0.2);
            color: white;
            border-left-color: #7f9cf5;
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            padding: 30px;
            background: #f8f9fa;
            transition: all 0.3s ease;
        }
        
        /* Stats Grid */
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
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 14px;
            opacity: 0.9;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        /* Cards */
        .card {
            background: white;
            border-radius: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
            border: none;
        }
        
        .card-header {
            padding: 20px 25px;
            background: #f7fafc;
            border-bottom: 1px solid #e2e8f0;
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
        
        /* Filters */
        .filter-section {
            background: white;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        /* Action Buttons */
        .action-btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            text-align: center;
            text-decoration: none;
            display: inline-block;
            margin-right: 10px;
            margin-bottom: 10px;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            color: white;
            text-decoration: none;
        }
        
        .action-btn.success { background: #48bb78; }
        .action-btn.info { background: #4299e1; }
        .action-btn.warning { background: #ed8936; }
        .action-btn.purple { background: #9f7aea; }
        .action-btn.small { padding: 8px 12px; font-size: 12px; }
        
        /* Notification items */
        .notification-item {
            border-left: 4px solid #e2e8f0;
            padding: 15px;
            margin-bottom: 10px;
            background: white;
            border-radius: 0 10px 10px 0;
            transition: all 0.3s;
        }
        
        .notification-item:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .notification-item.pending {
            border-left-color: #ed8936;
        }
        
        .notification-item.sent {
            border-left-color: #48bb78;
        }
        
        .notification-item.failed {
            border-left-color: #e53e3e;
        }
        
        .notification-item.read {
            border-left-color: #4299e1;
        }
        
        .notification-meta {
            font-size: 12px;
            color: #718096;
            margin-top: 5px;
        }
        
        .badge {
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge.success { background: #48bb78; color: white; }
        .badge.warning { background: #ed8936; color: white; }
        .badge.danger { background: #e53e3e; color: white; }
        .badge.info { background: #4299e1; color: white; }
        .badge.primary { background: #667eea; color: white; }
        .badge.secondary { background: #718096; color: white; }
        
        /* Form styles */
        .form-control, .form-select {
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            padding: 10px 15px;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        /* Alert styles */
        .alert {
            border-radius: 10px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 20px;
        }
        
        .alert.alert-success {
            background: #f0fff4;
            color: #276749;
            border-left: 4px solid #48bb78;
        }
        
        .alert.alert-danger {
            background: #fed7d7;
            color: #c53030;
            border-left: 4px solid #e53e3e;
        }
        
        .alert.alert-warning {
            background: #fef5e7;
            color: #dd6b20;
            border-left: 4px solid #ed8936;
        }
        
        .alert.alert-info {
            background: #ebf8ff;
            color: #3182ce;
            border-left: 4px solid #4299e1;
        }
        
        /* Pagination */
        .pagination {
            justify-content: center;
            margin-top: 20px;
        }
        
        .page-link {
            color: #667eea;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            margin: 0 2px;
            padding: 8px 12px;
        }
        
        .page-link:hover {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        
        .page-item.active .page-link {
            background: #667eea;
            border-color: #667eea;
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
            
            .container {
                flex-direction: column;
            }
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
            <div class="user-profile">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($currentUser['first_name'] ?? '', 0, 1)); ?>
                </div>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($userDisplayName ?? ''); ?></div>
                    <div class="user-role"><?php echo htmlspecialchars(getCurrentUserRole() ?? ''); ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-content">
                <!-- Overview -->
                <div class="nav-section">
                    <div class="nav-title">Overview</div>
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
                        <a href="../fee_structure/business_fees.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-tags"></i>
                                <span class="icon-tags" style="display: none;"></span>
                            </span>
                            Fee Structure
                        </a>
                    </div>
                </div>
                
                <!-- Notifications -->
                <div class="nav-section">
                    <div class="nav-title">Notifications</div>
                    <div class="nav-item">
                        <a href="index.php" class="nav-link active">
                            <span class="nav-icon">
                                <i class="fas fa-bell"></i>
                                <span class="icon-bell" style="display: none;"></span>
                            </span>
                            Notification Center
                        </a>
                    </div>
                    <div class="nav-subsection">
                        <div class="nav-item">
                            <a href="send_sms.php" class="nav-sublink">
                                <span class="nav-icon">
                                    <i class="fas fa-sms"></i>
                                    <span class="icon-sms" style="display: none;"></span>
                                </span>
                                Send SMS
                            </a>
                        </div>
                        <div class="nav-item">
                            <a href="bulk_notify.php" class="nav-sublink">
                                <span class="nav-icon">
                                    <i class="fas fa-bullhorn"></i>
                                    <span class="icon-bullhorn" style="display: none;"></span>
                                </span>
                                Bulk Notifications
                            </a>
                        </div>
                        <div class="nav-item">
                            <a href="templates.php" class="nav-sublink">
                                <span class="nav-icon">
                                    <i class="fas fa-file-alt"></i>
                                    <span class="icon-file" style="display: none;"></span>
                                </span>
                                Templates
                            </a>
                        </div>
                        <div class="nav-item">
                            <a href="history.php" class="nav-sublink">
                                <span class="nav-icon">
                                    <i class="fas fa-history"></i>
                                    <span class="icon-history" style="display: none;"></span>
                                </span>
                                History
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- System -->
                <div class="nav-section">
                    <div class="nav-title">System</div>
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
            <div style="margin-bottom: 30px;">
                <h1 style="font-size: 28px; font-weight: bold; color: #2d3748; margin-bottom: 8px;">
                    🔔 Notifications Management
                </h1>
                <p style="color: #718096; font-size: 16px;">
                    Manage and send notifications to users, businesses, and properties.
                </p>
            </div>

            <!-- Flash Messages -->
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $messageType === 'error' ? 'danger' : $messageType; ?>">
                    <?php echo htmlspecialchars($message ?? ''); ?>
                </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="stat-icon">
                        <i class="fas fa-bell"></i>
                        <span class="icon-bell" style="display: none;"></span>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total'] ?? 0); ?></div>
                    <div class="stat-label">Total Notifications</div>
                </div>

                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                        <span class="icon-clock" style="display: none;"></span>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['pending'] ?? 0); ?></div>
                    <div class="stat-label">Pending</div>
                </div>

                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                        <span class="icon-check" style="display: none;"></span>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['sent'] ?? 0); ?></div>
                    <div class="stat-label">Sent Successfully</div>
                </div>

                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span class="icon-warning" style="display: none;"></span>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['failed'] ?? 0); ?></div>
                    <div class="stat-label">Failed</div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">⚡ Quick Actions</h5>
                </div>
                <div class="card-body">
                    <a href="send_sms.php" class="action-btn primary">
                        <i class="fas fa-sms"></i>
                        <span class="icon-sms" style="display: none;"></span>
                        Send SMS
                    </a>
                    <a href="bulk_notify.php" class="action-btn success">
                        <i class="fas fa-users"></i>
                        <span class="icon-users" style="display: none;"></span>
                        Bulk Notify
                    </a>
                    <a href="templates.php" class="action-btn info">
                        <i class="fas fa-file-alt"></i>
                        <span class="icon-file" style="display: none;"></span>
                        Templates
                    </a>
                    <a href="history.php" class="action-btn purple">
                        <i class="fas fa-history"></i>
                        <span class="icon-history" style="display: none;"></span>
                        View History
                    </a>
                </div>
            </div>

            <!-- Filters -->
            <div class="filter-section">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="Pending" <?php echo $filters['status'] === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="Sent" <?php echo $filters['status'] === 'Sent' ? 'selected' : ''; ?>>Sent</option>
                            <option value="Failed" <?php echo $filters['status'] === 'Failed' ? 'selected' : ''; ?>>Failed</option>
                            <option value="Read" <?php echo $filters['status'] === 'Read' ? 'selected' : ''; ?>>Read</option>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Type</label>
                        <select name="type" class="form-select">
                            <option value="">All Types</option>
                            <option value="SMS" <?php echo $filters['type'] === 'SMS' ? 'selected' : ''; ?>>SMS</option>
                            <option value="System" <?php echo $filters['type'] === 'System' ? 'selected' : ''; ?>>System</option>
                            <option value="Email" <?php echo $filters['type'] === 'Email' ? 'selected' : ''; ?>>Email</option>
                        </select>
                    </div>
                    
                    <div class="col-md-4">
                        <label class="form-label">Search</label>
                        <input type="text" name="search" class="form-control" placeholder="Search notifications..." 
                               value="<?php echo htmlspecialchars($filters['search'] ?? ''); ?>">
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label"> </label>
                        <button type="submit" class="form-control action-btn info">
                            <i class="fas fa-search"></i>
                            <span class="icon-search" style="display: none;"></span>
                            Filter
                        </button>
                    </div>
                </form>
            </div>

            <!-- Notifications List -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">📋 Recent Notifications</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($notifications)): ?>
                        <div style="text-align: center; padding: 50px; color: #718096;">
                            <i class="fas fa-bell-slash" style="font-size: 48px; margin-bottom: 20px;"></i>
                            <h5>No notifications found</h5>
                            <p>Start by sending your first notification to users, businesses, or properties.</p>
                            <a href="send_sms.php" class="action-btn primary">
                                <i class="fas fa-plus"></i>
                                <span class="icon-plus" style="display: none;"></span>
                                Send Notification
                            </a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($notifications as $notification): ?>
                            <div class="notification-item <?php echo strtolower($notification['status'] ?? ''); ?>">
                                <div style="display: flex; justify-content: space-between; align-items: start;">
                                    <div style="flex: 1;">
                                        <h6 style="margin: 0 0 5px 0; font-weight: 600;">
                                            <?php echo htmlspecialchars($notification['subject'] ?? 'No Subject'); ?>
                                        </h6>
                                        <p style="margin: 0 0 10px 0; color: #4a5568;">
                                            <?php echo htmlspecialchars(substr($notification['message'] ?? '', 0, 100)); ?>
                                            <?php if (strlen($notification['message'] ?? '') > 100): ?>...<?php endif; ?>
                                        </p>
                                        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                            <span class="badge <?php echo ($notification['notification_type'] ?? '') === 'SMS' ? 'primary' : 
                                                (($notification['notification_type'] ?? '') === 'Email' ? 'info' : 'secondary'); ?>">
                                                <?php echo htmlspecialchars($notification['notification_type'] ?? ''); ?>
                                            </span>
                                            <span class="badge 
                                                <?php 
                                                switch($notification['status'] ?? '') {
                                                    case 'Pending': echo 'warning'; break;
                                                    case 'Sent': echo 'success'; break;
                                                    case 'Failed': echo 'danger'; break;
                                                    case 'Read': echo 'info'; break;
                                                    default: echo 'secondary';
                                                }
                                                ?>">
                                                <?php echo htmlspecialchars($notification['status'] ?? ''); ?>
                                            </span>
                                            <span style="font-size: 12px; color: #718096;">
                                                To: <?php echo htmlspecialchars($notification['recipient_name'] ?? 'Unknown'); ?>
                                            </span>
                                        </div>
                                        <div class="notification-meta">
                                            Created: <?php echo date('M j, Y g:i A', strtotime($notification['created_at'] ?? 'now')); ?>
                                            <?php if (!empty($notification['sent_at'])): ?>
                                                | Sent: <?php echo date('M j, Y g:i A', strtotime($notification['sent_at'])); ?>
                                            <?php endif; ?>
                                            <?php if (!empty($notification['sender_first_name'])): ?>
                                                | By: <?php echo htmlspecialchars(($notification['sender_first_name'] ?? '') . ' ' . ($notification['sender_last_name'] ?? '')); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div style="margin-left: 15px;">
                                        <button onclick="viewNotification(<?php echo (int)($notification['notification_id'] ?? 0); ?>)" 
                                                class="action-btn small info">
                                            <i class="fas fa-eye"></i>
                                            <span class="icon-eye" style="display: none;"></span>
                                            View
                                        </button>
                                        <?php if (($notification['status'] ?? '') !== 'Read'): ?>
                                            <form method="POST" action="?action=mark_read" style="display: inline; margin-left: 5px;">
                                                <input type="hidden" name="notification_id" value="<?php echo (int)($notification['notification_id'] ?? 0); ?>">
                                                <button type="submit" class="action-btn small success">
                                                    <i class="fas fa-check"></i>
                                                    <span class="icon-check" style="display: none;"></span>
                                                    Mark Read
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                            <nav>
                                <ul class="pagination">
                                    <?php if ($page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?page=<?php echo $page - 1; ?>&<?php echo http_build_query($filters); ?>">Previous</a>
                                        </li>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $i; ?>&<?php echo http_build_query($filters); ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <?php if ($page < $totalPages): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?page=<?php echo $page + 1; ?>&<?php echo http_build_query($filters); ?>">Next</a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </nav>
                        <?php endif; ?>
                        
                        <!-- View All Link -->
                        <div style="text-align: center; margin-top: 20px;">
                            <a href="history.php" class="action-btn info">
                                View All Notifications
                                <i class="fas fa-arrow-right" style="margin-left: 8px;"></i>
                            </a>
                        </div>
                    <?php endif; ?>
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

        // View notification function
        function viewNotification(notificationId) {
            alert('View notification #' + notificationId + ' - Feature will open detailed view');
            // This would open a modal or navigate to a detailed view
        }

        // Mobile sidebar toggle
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth <= 768) {
                sidebar.classList.remove('hidden');
            }
        });
    </script>
</body>
</html>