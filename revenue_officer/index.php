<?php
/**
 * Revenue Officer Dashboard for QUICKBILL 305
 * Focused on payment collection activities
 */

// Define application constant
define('QUICKBILL_305', true);

// Include configuration files
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';


// Start session
session_start();

// Include auth and security
require_once '../includes/auth.php';
require_once '../includes/security.php';

// Initialize auth and security
initAuth();
initSecurity();

require_once '../includes/restriction_warning.php';
// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit();
}

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit();
}

// Check if user is revenue officer or admin
$currentUser = getCurrentUser();
if (!isRevenueOfficer() && !isAdmin()) {
    setFlashMessage('error', 'Access denied. Revenue Officer privileges required.');
    header('Location: ../auth/login.php');
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

// Get statistics for revenue officer
try {
    $db = new Database();
    $totalPaymentsToday = 0;
    $totalAmountToday = 0;
    $totalBusinesses = 0;
    $totalProperties = 0;
    $pendingBills = 0;
    $paidBills = 0;
    $myPaymentsToday = 0;
    $myAmountToday = 0;
    
    // Today's date
    $today = date('Y-m-d');
    
    // Total payments today
    try {
        $result = $db->fetchRow("SELECT COUNT(*) as count, COALESCE(SUM(amount_paid), 0) as total FROM payments WHERE DATE(payment_date) = ? AND payment_status = 'Successful'", [$today]);
        $totalPaymentsToday = $result['count'] ?? 0;
        $totalAmountToday = $result['total'] ?? 0;
    } catch (Exception $e) {}
    
    // Total businesses and properties
    try {
        $result = $db->fetchRow("SELECT COUNT(*) as count FROM businesses WHERE status = 'Active'");
        $totalBusinesses = $result['count'] ?? 0;
    } catch (Exception $e) {}
    
    try {
        $result = $db->fetchRow("SELECT COUNT(*) as count FROM properties");
        $totalProperties = $result['count'] ?? 0;
    } catch (Exception $e) {}
    
    // Bills status
    try {
        $result = $db->fetchRow("SELECT COUNT(*) as count FROM bills WHERE status = 'Pending'");
        $pendingBills = $result['count'] ?? 0;
    } catch (Exception $e) {}
    
    try {
        $result = $db->fetchRow("SELECT COUNT(*) as count FROM bills WHERE status = 'Paid'");
        $paidBills = $result['count'] ?? 0;
    } catch (Exception $e) {}
    
    // My payments today (if processed_by is tracked)
    if (!empty($currentUser['user_id'])) {
        try {
            $result = $db->fetchRow("SELECT COUNT(*) as count, COALESCE(SUM(amount_paid), 0) as total FROM payments WHERE DATE(payment_date) = ? AND payment_status = 'Successful' AND processed_by = ?", [$today, $currentUser['user_id']]);
            $myPaymentsToday = $result['count'] ?? 0;
            $myAmountToday = $result['total'] ?? 0;
        } catch (Exception $e) {}
    }
    
} catch (Exception $e) {
    // Set defaults if database connection fails
    $totalPaymentsToday = 0;
    $totalAmountToday = 0;
    $totalBusinesses = 0;
    $totalProperties = 0;
    $pendingBills = 0;
    $paidBills = 0;
    $myPaymentsToday = 0;
    $myAmountToday = 0;
}

// Note: formatCurrency() function is defined in includes/functions.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revenue Officer Dashboard - <?php echo APP_NAME; ?></title>
    
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
        .icon-dashboard::before { content: "💰"; }
        .icon-money::before { content: "💵"; }
        .icon-search::before { content: "🔍"; }
        .icon-map::before { content: "🗺️"; }
        .icon-menu::before { content: "☰"; }
        .icon-logout::before { content: "🚪"; }
        .icon-plus::before { content: "➕"; }
        .icon-calendar::before { content: "📅"; }
        .icon-chart::before { content: "📊"; }
        .icon-building::before { content: "🏢"; }
        .icon-home::before { content: "🏠"; }
        .icon-receipt::before { content: "🧾"; }
        .icon-user::before { content: "👤"; }
        .icon-location::before { content: "📍"; }
        .icon-bill::before { content: "📄"; }
        .icon-check::before { content: "✅"; }
        .icon-pending::before { content: "⏳"; }
        .icon-question::before { content: "❓"; }
        
        /* Top Navigation */
        .top-nav {
            background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%);
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
            background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%);
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
            color: #e53e3e;
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
            border-left-color: #e53e3e;
        }
        
        .nav-link.active {
            background: rgba(229, 62, 62, 0.3);
            color: white;
            border-left-color: #e53e3e;
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
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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
        
        .stat-card.danger {
            background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%);
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
        
        .stat-card.purple {
            background: linear-gradient(135deg, #9f7aea 0%, #805ad5 100%);
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
        
        .stat-subtitle {
            font-size: 14px;
            opacity: 0.8;
            margin-top: 5px;
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
        
        /* Action Buttons */
        .actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .action-btn {
            background: #e53e3e;
            color: white;
            border: none;
            padding: 15px 20px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            text-align: center;
            text-decoration: none;
            display: inline-block;
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
        
        /* Alert */
        .alert {
            background: #fef5e7;
            border: 1px solid #f6ad55;
            border-radius: 10px;
            padding: 20px;
            color: #c05621;
        }
        
        .alert h4 {
            margin-bottom: 10px;
            font-weight: 600;
        }
        
        /* Performance Summary */
        .performance-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        
        .performance-item {
            text-align: center;
            padding: 20px;
            background: #f7fafc;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
        }
        
        .performance-icon {
            font-size: 40px;
            margin-bottom: 15px;
            color: #e53e3e;
        }
        
        .performance-value {
            font-size: 24px;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 5px;
        }
        
        .performance-label {
            color: #718096;
            font-size: 14px;
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
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .container {
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
            
            <a href="index.php" class="brand">
                <i class="fas fa-cash-register"></i>
                <span class="icon-money" style="display: none;"></span>
                Revenue Officer
            </a>
        </div>
        
        <div class="user-section">
            <div class="user-profile" onclick="toggleUserDropdown()" id="userProfile">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($currentUser['first_name'], 0, 1)); ?>
                </div>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($userDisplayName); ?></div>
                    <div class="user-role">Revenue Officer</div>
                </div>
                <i class="fas fa-chevron-down dropdown-arrow"></i>
                
                <!-- User Dropdown -->
                <div class="user-dropdown" id="userDropdown">
                    <div class="dropdown-header">
                        <div class="dropdown-avatar">
                            <?php echo strtoupper(substr($currentUser['first_name'], 0, 1)); ?>
                        </div>
                        <div class="dropdown-name"><?php echo htmlspecialchars($userDisplayName); ?></div>
                        <div class="dropdown-role">Revenue Officer</div>
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
                        <a href="../auth/logout.php" class="dropdown-item logout">
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
                        <a href="index.php" class="nav-link active">
                            <span class="nav-icon">
                                <i class="fas fa-tachometer-alt"></i>
                                <span class="icon-dashboard" style="display: none;"></span>
                            </span>
                            Dashboard
                        </a>
                    </div>
                </div>
                
                <!-- Payment Management -->
                <div class="nav-section">
                    <div class="nav-title">Payment Management</div>
                    <div class="nav-item">
                        <a href="payments/record.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-cash-register"></i>
                                <span class="icon-money" style="display: none;"></span>
                            </span>
                            Record Payment
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="payments/search.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-search"></i>
                                <span class="icon-search" style="display: none;"></span>
                            </span>
                            Search Accounts
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="payments/daily_summary.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-chart-line"></i>
                                <span class="icon-chart" style="display: none;"></span>
                            </span>
                            Daily Summary
                        </a>
                    </div>
                </div>
                
                <!-- Maps & Locations -->
                <div class="nav-section">
                    <div class="nav-title">Maps & Locations</div>
                    <div class="nav-item">
                        <a href="map/businesses.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-building"></i>
                                <span class="icon-building" style="display: none;"></span>
                            </span>
                            Business Map
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="map/properties.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-home"></i>
                                <span class="icon-home" style="display: none;"></span>
                            </span>
                            Property Map
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <!-- Welcome Section -->
            <div class="welcome-card fade-in">
                <h1 class="welcome-title">Welcome, <?php echo htmlspecialchars($userDisplayName); ?>! 💰</h1>
                <p class="welcome-subtitle">Ready to collect payments and manage revenue for <?php echo APP_NAME; ?>.</p>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid fade-in">
                <div class="stat-card danger">
                    <div class="stat-header">
                        <div class="stat-title">Today's Collections</div>
                        <div class="stat-icon">
                            <i class="fas fa-cash-register"></i>
                            <span class="icon-money" style="display: none;"></span>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo formatCurrency($totalAmountToday); ?></div>
                    <div class="stat-subtitle"><?php echo number_format($totalPaymentsToday); ?> payments processed</div>
                </div>

                <div class="stat-card success">
                    <div class="stat-header">
                        <div class="stat-title">My Collections</div>
                        <div class="stat-icon">
                            <i class="fas fa-user-check"></i>
                            <span class="icon-user" style="display: none;"></span>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo formatCurrency($myAmountToday); ?></div>
                    <div class="stat-subtitle"><?php echo number_format($myPaymentsToday); ?> payments by me</div>
                </div>

                <div class="stat-card info">
                    <div class="stat-header">
                        <div class="stat-title">Total Accounts</div>
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                            <span class="icon-users" style="display: none;"></span>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($totalBusinesses + $totalProperties); ?></div>
                    <div class="stat-subtitle"><?php echo number_format($totalBusinesses); ?> businesses, <?php echo number_format($totalProperties); ?> properties</div>
                </div>

                <div class="stat-card warning">
                    <div class="stat-header">
                        <div class="stat-title">Bill Status</div>
                        <div class="stat-icon">
                            <i class="fas fa-file-invoice"></i>
                            <span class="icon-bill" style="display: none;"></span>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($pendingBills); ?></div>
                    <div class="stat-subtitle"><?php echo number_format($paidBills); ?> paid, <?php echo number_format($pendingBills); ?> pending</div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="card fade-in">
                <div class="card-header">
                    <h5 class="card-title">⚡ Quick Actions</h5>
                </div>
                <div class="card-body">
                    <div class="actions-grid">
                        <a href="payments/record.php" class="action-btn danger pulse">
                            <i class="fas fa-cash-register"></i>
                            <span class="icon-money" style="display: none;"></span>
                            Record Payment
                        </a>
                        <a href="payments/search.php" class="action-btn info">
                            <i class="fas fa-search"></i>
                            <span class="icon-search" style="display: none;"></span>
                            Search Account
                        </a>
                        <a href="payments/daily_summary.php" class="action-btn success">
                            <i class="fas fa-chart-line"></i>
                            <span class="icon-chart" style="display: none;"></span>
                            Daily Summary
                        </a>
                        <a href="map/businesses.php" class="action-btn warning">
                            <i class="fas fa-building"></i>
                            <span class="icon-building" style="display: none;"></span>
                            Business Map
                        </a>
                        <a href="map/properties.php" class="action-btn purple">
                            <i class="fas fa-home"></i>
                            <span class="icon-home" style="display: none;"></span>
                            Property Map
                        </a>
                        <a href="#" class="action-btn" onclick="alert('Receipt printing feature coming soon!')">
                            <i class="fas fa-receipt"></i>
                            <span class="icon-receipt" style="display: none;"></span>
                            Print Receipt
                        </a>
                    </div>
                </div>
            </div>

            <!-- Today's Performance -->
            <div class="card fade-in">
                <div class="card-header">
                    <h5 class="card-title">📊 Today's Performance - <?php echo date('F j, Y'); ?></h5>
                </div>
                <div class="card-body">
                    <div class="performance-grid">
                        <div class="performance-item">
                            <div class="performance-icon">
                                <i class="fas fa-money-bill-wave"></i>
                                <span class="icon-money" style="display: none;"></span>
                            </div>
                            <div class="performance-value"><?php echo formatCurrency($totalAmountToday); ?></div>
                            <div class="performance-label">Total Revenue</div>
                        </div>
                        <div class="performance-item">
                            <div class="performance-icon">
                                <i class="fas fa-calculator"></i>
                                <span class="icon-chart" style="display: none;"></span>
                            </div>
                            <div class="performance-value"><?php echo number_format($totalPaymentsToday); ?></div>
                            <div class="performance-label">Total Payments</div>
                        </div>
                        <div class="performance-item">
                            <div class="performance-icon">
                                <i class="fas fa-user-tie"></i>
                                <span class="icon-user" style="display: none;"></span>
                            </div>
                            <div class="performance-value"><?php echo formatCurrency($myAmountToday); ?></div>
                            <div class="performance-label">My Collection</div>
                        </div>
                        <div class="performance-item">
                            <div class="performance-icon">
                                <i class="fas fa-percentage"></i>
                                <span class="icon-chart" style="display: none;"></span>
                            </div>
                            <div class="performance-value">
                                <?php 
                                    $percentage = $totalPaymentsToday > 0 ? round(($myPaymentsToday / $totalPaymentsToday) * 100, 1) : 0;
                                    echo $percentage . '%';
                                ?>
                            </div>
                            <div class="performance-label">My Contribution</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Collection Targets -->
            <div class="card fade-in">
                <div class="card-header">
                    <h5 class="card-title">🎯 Collection Targets & Tips</h5>
                </div>
                <div class="card-body">
                    <div class="stats-grid">
                        <div style="text-align: center; padding: 20px;">
                            <div style="font-size: 48px; margin-bottom: 15px; color: #e53e3e;">
                                <i class="fas fa-bullseye"></i>
                                <span class="icon-chart" style="display: none;"></span>
                            </div>
                            <h4 style="color: #2d3748; margin-bottom: 10px;">Daily Target</h4>
                            <div style="font-size: 24px; font-weight: bold; color: #e53e3e;">
                                GH₵ 5,000
                            </div>
                            <div style="margin-top: 10px; color: #718096;">
                                <?php 
                                    $targetProgress = ($totalAmountToday / 5000) * 100;
                                    echo round($targetProgress, 1) . '% achieved';
                                ?>
                            </div>
                        </div>
                        <div style="text-align: center; padding: 20px;">
                            <div style="font-size: 48px; margin-bottom: 15px; color: #38a169;">
                                <i class="fas fa-trophy"></i>
                                <span class="icon-check" style="display: none;"></span>
                            </div>
                            <h4 style="color: #2d3748; margin-bottom: 10px;">Best Practices</h4>
                            <div style="color: #718096; text-align: left;">
                                • Verify account details<br>
                                • Print receipts<br>
                                • Update payment status<br>
                                • Record mobile money refs
                            </div>
                        </div>
                        <div style="text-align: center; padding: 20px;">
                            <div style="font-size: 48px; margin-bottom: 15px; color: #4299e1;">
                                <i class="fas fa-clock"></i>
                                <span class="icon-pending" style="display: none;"></span>
                            </div>
                            <h4 style="color: #2d3748; margin-bottom: 10px;">Working Hours</h4>
                            <div style="color: #718096;">
                                <strong>8:00 AM - 5:00 PM</strong><br>
                                Current time: <?php echo date('g:i A'); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Instructions -->
            <div class="alert fade-in">
                <h4>💡 Revenue Officer Instructions</h4>
                <p>As a Revenue Officer, your primary focus is on collecting payments and maintaining accurate records. Here's what you can do:</p>
                <p><strong>💰 Payments:</strong> 
                    <a href="payments/record.php" style="color: #c05621; font-weight: bold;">Record new payments</a>, 
                    <a href="payments/search.php" style="color: #c05621; font-weight: bold;">search for account bills</a>, and
                    <a href="payments/daily_summary.php" style="color: #c05621; font-weight: bold;">view daily collection summary</a>.
                </p>
                <p><strong>🗺️ Maps:</strong> 
                    <a href="map/businesses.php" style="color: #c05621; font-weight: bold;">View business locations</a> and
                    <a href="map/properties.php" style="color: #c05621; font-weight: bold;">property locations</a> to help with field collection.
                </p>
                <p><strong>📊 Reporting:</strong> Your collection activities are automatically tracked and included in daily, weekly, and monthly revenue reports.</p>
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
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-8px) scale(1.02)';
                });
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                });
            });
        });

        // Auto-refresh statistics every 5 minutes
        setInterval(function() {
            location.reload();
        }, 300000); // 5 minutes

        // Show current time
        function updateTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', {
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            });
            console.log('Current time:', timeString);
        }

        // Update time every minute
        updateTime();
        setInterval(updateTime, 60000);
    </script>
</body>
</html>