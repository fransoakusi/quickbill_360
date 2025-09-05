<?php
/**
 * Reports Dashboard for QUICKBILL 305
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

// Get report statistics
try {
    $db = new Database();
    
    // Revenue statistics
    $totalRevenue = 0;
    $monthlyRevenue = 0;
    $pendingPayments = 0;
    $totalTransactions = 0;
    $totalBusinesses = 0;
    $totalProperties = 0;
    
    // Get total revenue for current year
    $revenueResult = $db->fetchRow("
        SELECT SUM(amount_paid) as total_revenue 
        FROM payments 
        WHERE payment_status = 'Successful' 
        AND YEAR(payment_date) = YEAR(CURDATE())
    ");
    $totalRevenue = $revenueResult['total_revenue'] ?? 0;
    
    // Get monthly revenue
    $monthlyResult = $db->fetchRow("
        SELECT SUM(amount_paid) as monthly_revenue 
        FROM payments 
        WHERE payment_status = 'Successful' 
        AND YEAR(payment_date) = YEAR(CURDATE())
        AND MONTH(payment_date) = MONTH(CURDATE())
    ");
    $monthlyRevenue = $monthlyResult['monthly_revenue'] ?? 0;
    
    // Get pending payments
    $pendingResult = $db->fetchRow("
        SELECT SUM(amount_payable) as pending_payments 
        FROM (
            SELECT amount_payable FROM businesses WHERE amount_payable > 0
            UNION ALL
            SELECT amount_payable FROM properties WHERE amount_payable > 0
        ) as pending
    ");
    $pendingPayments = $pendingResult['pending_payments'] ?? 0;
    
    // Get total transactions
    $transactionsResult = $db->fetchRow("
        SELECT COUNT(*) as total_transactions 
        FROM payments 
        WHERE payment_status = 'Successful'
        AND YEAR(payment_date) = YEAR(CURDATE())
    ");
    $totalTransactions = $transactionsResult['total_transactions'] ?? 0;
    
    // Get total businesses
    $businessResult = $db->fetchRow("SELECT COUNT(*) as total_businesses FROM businesses");
    $totalBusinesses = $businessResult['total_businesses'] ?? 0;
    
    // Get total properties
    $propertyResult = $db->fetchRow("SELECT COUNT(*) as total_properties FROM properties");
    $totalProperties = $propertyResult['total_properties'] ?? 0;
    
} catch (Exception $e) {
    $totalRevenue = 0;
    $monthlyRevenue = 0;
    $pendingPayments = 0;
    $totalTransactions = 0;
    $totalBusinesses = 0;
    $totalProperties = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports Dashboard - <?php echo APP_NAME; ?></title>

    <!-- Chart.js from CDNJS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    
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
        .icon-plus::before { content: "➕"; }
        .icon-server::before { content: "🖥️"; }
        .icon-database::before { content: "💾"; }
        .icon-shield::before { content: "🛡️"; }
        .icon-user-plus::before { content: "👤➕"; }
        .icon-history::before { content: "📜"; }
        .icon-question::before { content: "❓"; }
        .icon-clock::before { content: "⏰"; }
        .icon-percentage::before { content: "📊"; }

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

        /* Welcome Section */
        .welcome-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .welcome-title {
            font-size: 28px;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 8px;
        }

        .welcome-subtitle {
            color: #718096;
            font-size: 16px;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
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

        .stat-card.secondary {
            background: linear-gradient(135deg, #9f7aea 0%, #805ad5 100%);
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

        /* Report Cards Grid */
        .reports-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .report-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .report-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
            border-color: #667eea;
        }

        .report-header {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }

        .report-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            margin-right: 15px;
        }

        .report-icon.revenue {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .report-icon.defaulters {
            background: linear-gradient(135deg, #f56565 0%, #e53e3e 100%);
        }

        .report-icon.collection {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
        }

        .report-icon.zone {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
        }

        .report-icon.audit {
            background: linear-gradient(135deg, #ed8936 0%, #dd6b20 100%);
        }

        .report-icon.status {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }

        .report-icon.data {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
        }

        .report-info h3 {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 5px;
        }

        .report-info p {
            color: #718096;
            font-size: 14px;
            margin: 0;
        }

        .report-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

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

            .reports-grid {
                grid-template-columns: 1fr;
            }

            .container {
                flex-direction: column;
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
                        <a href="../logs/user_activity.php" class="dropdown-item">
                            <i class="fas fa-history"></i>
                            Activity Log
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
            <!-- Welcome Section -->
            <div class="welcome-card">
                <h1 class="welcome-title">Reports Dashboard</h1>
                <p class="welcome-subtitle">Generate comprehensive reports and analytics for your billing system.</p>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="stat-header">
                        <div class="stat-title">Total Revenue (<?php echo date('Y'); ?>)</div>
                        <div class="stat-icon">
                            <i class="fas fa-money-bill-wave"></i>
                            <span class="icon-money" style="display: none;"></span>
                        </div>
                    </div>
                    <div class="stat-value">₵ <?php echo number_format($totalRevenue, 2); ?></div>
                    <div class="stat-subtitle">This Month: ₵ <?php echo number_format($monthlyRevenue, 2); ?></div>
                </div>

                <div class="stat-card success">
                    <div class="stat-header">
                        <div class="stat-title">Total Transactions</div>
                        <div class="stat-icon">
                            <i class="fas fa-receipt"></i>
                            <span class="icon-receipt" style="display: none;"></span>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($totalTransactions); ?></div>
                    <div class="stat-subtitle"><?php echo date('Y'); ?> transactions</div>
                </div>

                <div class="stat-card warning">
                    <div class="stat-header">
                        <div class="stat-title">Pending Payments</div>
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                            <span class="icon-clock" style="display: none;"></span>
                        </div>
                    </div>
                    <div class="stat-value">₵ <?php echo number_format($pendingPayments, 2); ?></div>
                    <div class="stat-subtitle">Outstanding amounts</div>
                </div>

                <div class="stat-card info">
                    <div class="stat-header">
                        <div class="stat-title">Collection Rate</div>
                        <div class="stat-icon">
                            <i class="fas fa-percentage"></i>
                            <span class="icon-percentage" style="display: none;"></span>
                        </div>
                    </div>
                    <div class="stat-value">
                        <?php 
                        $collectionRate = ($totalRevenue + $pendingPayments) > 0 ? 
                            round(($totalRevenue / ($totalRevenue + $pendingPayments)) * 100, 1) : 0;
                        echo $collectionRate;
                        ?>%
                    </div>
                    <div class="stat-subtitle">Collection efficiency</div>
                </div>

                <div class="stat-card secondary">
                    <div class="stat-header">
                        <div class="stat-title">Total Records</div>
                        <div class="stat-icon">
                            <i class="fas fa-database"></i>
                            <span class="icon-database" style="display: none;"></span>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($totalBusinesses + $totalProperties); ?></div>
                    <div class="stat-subtitle"><?php echo number_format($totalBusinesses); ?> businesses • <?php echo number_format($totalProperties); ?> properties</div>
                </div>
            </div>

            <!-- Available Reports -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">Available Reports</h5>
                </div>
                <div class="card-body">
                    <div class="reports-grid">
                        <!-- Revenue Report -->
                        <div class="report-card">
                            <div class="report-header">
                                <div class="report-icon revenue">
                                    <i class="fas fa-chart-line"></i>
                                </div>
                                <div class="report-info">
                                    <h3>Revenue Report</h3>
                                    <p>Comprehensive revenue analysis with trends and comparisons</p>
                                </div>
                            </div>
                            <div class="report-actions">
                                <a href="revenue_report.php" class="btn btn-primary">
                                    <i class="fas fa-eye"></i>
                                    View Report
                                </a>
                                <a href="revenue_report.php?export=pdf" class="btn btn-outline">
                                    <i class="fas fa-download"></i>
                                    Export PDF
                                </a>
                            </div>
                        </div>

                        <!-- Defaulters Report -->
                        <div class="report-card">
                            <div class="report-header">
                                <div class="report-icon defaulters">
                                    <i class="fas fa-exclamation-triangle"></i>
                                </div>
                                <div class="report-info">
                                    <h3>Defaulters Report</h3>
                                    <p>List of businesses and properties with outstanding payments</p>
                                </div>
                            </div>
                            <div class="report-actions">
                                <a href="defaulters_report.php" class="btn btn-primary">
                                    <i class="fas fa-eye"></i>
                                    View Report
                                </a>
                                <a href="defaulters_report.php?export=excel" class="btn btn-outline">
                                    <i class="fas fa-file-excel"></i>
                                    Export Excel
                                </a>
                            </div>
                        </div>

                        <!-- Bills by Status Report -->
                        <div class="report-card">
                            <div class="report-header">
                                <div class="report-icon status">
                                    <i class="fas fa-chart-pie"></i>
                                </div>
                                <div class="report-info">
                                    <h3>Bills by Status Report</h3>
                                    <p>Enhanced bill status analysis with serving information</p>
                                </div>
                            </div>
                            <div class="report-actions">
                                <a href="status_report.php" class="btn btn-primary">
                                    <i class="fas fa-eye"></i>
                                    View Report
                                </a>
                                <a href="status_report.php?export=excel" class="btn btn-outline">
                                    <i class="fas fa-file-excel"></i>
                                    Export Excel
                                </a>
                            </div>
                        </div>

                        <!-- Data Management Report -->
                        <div class="report-card">
                            <div class="report-header">
                                <div class="report-icon data">
                                    <i class="fas fa-database"></i>
                                </div>
                                <div class="report-info">
                                    <h3>Data Management Report</h3>
                                    <p>Track data entry timestamps, updates, and user activity</p>
                                </div>
                            </div>
                            <div class="report-actions">
                                <a href="data_management_report.php" class="btn btn-primary">
                                    <i class="fas fa-eye"></i>
                                    View Report
                                </a>
                                <a href="data_management_report.php?export=excel" class="btn btn-outline">
                                    <i class="fas fa-file-excel"></i>
                                    Export Excel
                                </a>
                            </div>
                        </div>

                        <!-- Collection Report -->
                        <div class="report-card">
                            <div class="report-header">
                                <div class="report-icon collection">
                                    <i class="fas fa-coins"></i>
                                </div>
                                <div class="report-info">
                                    <h3>Collection Report</h3>
                                    <p>Payment collection performance and analytics</p>
                                </div>
                            </div>
                            <div class="report-actions">
                                <a href="collection_report.php" class="btn btn-primary">
                                    <i class="fas fa-eye"></i>
                                    View Report
                                </a>
                                <a href="collection_report.php?export=pdf" class="btn btn-outline">
                                    <i class="fas fa-download"></i>
                                    Export PDF
                                </a>
                            </div>
                        </div>

                        <!-- Zone Performance Report -->
                        <div class="report-card">
                            <div class="report-header">
                                <div class="report-icon zone">
                                    <i class="fas fa-map"></i>
                                </div>
                                <div class="report-info">
                                    <h3>Zone Performance</h3>
                                    <p>Performance analysis by geographical zones</p>
                                </div>
                            </div>
                            <div class="report-actions">
                                <a href="zone_performance.php" class="btn btn-primary">
                                    <i class="fas fa-eye"></i>
                                    View Report
                                </a>
                                <a href="zone_performance.php?export=pdf" class="btn btn-outline">
                                    <i class="fas fa-download"></i>
                                    Export PDF
                                </a>
                            </div>
                        </div>

                        <!-- Audit Report -->
                        <div class="report-card">
                            <div class="report-header">
                                <div class="report-icon audit">
                                    <i class="fas fa-shield-alt"></i>
                                </div>
                                <div class="report-info">
                                    <h3>Audit Report</h3>
                                    <p>System activity logs and audit trail</p>
                                </div>
                            </div>
                            <div class="report-actions">
                                <a href="audit_report.php" class="btn btn-primary">
                                    <i class="fas fa-eye"></i>
                                    View Report
                                </a>
                                <a href="audit_report.php?export=excel" class="btn btn-outline">
                                    <i class="fas fa-file-excel"></i>
                                    Export Excel
                                </a>
                            </div>
                        </div>

                        <!-- Custom Report Builder -->
                        <div class="report-card">
                            <div class="report-header">
                                <div class="report-icon" style="background: linear-gradient(135deg, #9f7aea 0%, #805ad5 100%);">
                                    <i class="fas fa-tools"></i>
                                </div>
                                <div class="report-info">
                                    <h3>Custom Report Builder</h3>
                                    <p>Build custom reports with specific filters and criteria</p>
                                </div>
                            </div>
                            <div class="report-actions">
                                <a href="#" class="btn btn-primary" onclick="alert('Custom report builder coming soon!')">
                                    <i class="fas fa-plus"></i>
                                    Build Report
                                </a>
                            </div>
                        </div>
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

        // Add hover effects to report cards
        document.addEventListener('DOMContentLoaded', function() {
            const reportCards = document.querySelectorAll('.report-card');
            reportCards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-8px) scale(1.02)';
                });
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                });
            });
        });
    </script>
</body>
</html>