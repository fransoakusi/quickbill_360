<?php
/**
 * Officer Dashboard for QUICKBILL 305
 * Comprehensive access for field officers
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

// Check if user is officer or admin
$currentUser = getCurrentUser();
if (!isOfficer() && !isAdmin()) {
    setFlashMessage('error', 'Access denied. Officer privileges required.');
    header('Location: ../auth/login.php');
    exit();
}

$userDisplayName = getUserDisplayName($currentUser);

// Get current directory and page for active link highlighting
$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pathParts = explode('/', trim($currentPath, '/'));
$currentDir = !empty($pathParts[0]) ? $pathParts[0] : '';
$currentPage = basename($_SERVER['PHP_SELF']);

// Get statistics for officer dashboard
try {
    $db = new Database();
    $totalBusinesses = 0;
    $totalProperties = 0;
    $myBusinesses = 0;
    $myProperties = 0;
    $todayPayments = 0;
    $todayAmount = 0;
    $myTodayPayments = 0;
    $myTodayAmount = 0;
    $pendingBills = 0;
    $totalBills = 0;
    
    // Today's date
    $today = date('Y-m-d');
    
    // Total businesses and properties
    try {
        $result = $db->fetchRow("SELECT COUNT(*) as count FROM businesses WHERE status = 'Active'");
        $totalBusinesses = $result['count'] ?? 0;
    } catch (Exception $e) {}
    
    try {
        $result = $db->fetchRow("SELECT COUNT(*) as count FROM properties");
        $totalProperties = $result['count'] ?? 0;
    } catch (Exception $e) {}
    
    // My registered businesses and properties
    if (!empty($currentUser['user_id'])) {
        try {
            $result = $db->fetchRow("SELECT COUNT(*) as count FROM businesses WHERE created_by = ? AND status = 'Active'", [$currentUser['user_id']]);
            $myBusinesses = $result['count'] ?? 0;
        } catch (Exception $e) {}
        
        try {
            $result = $db->fetchRow("SELECT COUNT(*) as count FROM properties WHERE created_by = ?", [$currentUser['user_id']]);
            $myProperties = $result['count'] ?? 0;
        } catch (Exception $e) {}
        
        // My today's payments
        try {
            $result = $db->fetchRow("SELECT COUNT(*) as count, COALESCE(SUM(amount_paid), 0) as total FROM payments WHERE DATE(payment_date) = ? AND payment_status = 'Successful' AND processed_by = ?", [$today, $currentUser['user_id']]);
            $myTodayPayments = $result['count'] ?? 0;
            $myTodayAmount = $result['total'] ?? 0;
        } catch (Exception $e) {}
    }
    
    // Today's total payments
    try {
        $result = $db->fetchRow("SELECT COUNT(*) as count, COALESCE(SUM(amount_paid), 0) as total FROM payments WHERE DATE(payment_date) = ? AND payment_status = 'Successful'", [$today]);
        $todayPayments = $result['count'] ?? 0;
        $todayAmount = $result['total'] ?? 0;
    } catch (Exception $e) {}
    
    // Bills statistics
    try {
        $result = $db->fetchRow("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending FROM bills");
        $totalBills = $result['total'] ?? 0;
        $pendingBills = $result['pending'] ?? 0;
    } catch (Exception $e) {}
    
} catch (Exception $e) {
    // Set defaults if database connection fails
    $totalBusinesses = 0;
    $totalProperties = 0;
    $myBusinesses = 0;
    $myProperties = 0;
    $todayPayments = 0;
    $todayAmount = 0;
    $myTodayPayments = 0;
    $myTodayAmount = 0;
    $pendingBills = 0;
    $totalBills = 0;
}

// Calculate my contribution percentage
$myContribution = $todayPayments > 0 ? round(($myTodayPayments / $todayPayments) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Officer Dashboard - <?php echo APP_NAME; ?></title>
    
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
        
        .stat-card.primary {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
        }
        
        .stat-card.success {
            background: linear-gradient(135deg, #38a169 0%, #2f855a 100%);
            color: white;
        }
        
        .stat-card.info {
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
            background: #4299e1;
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
            position: relative;
            overflow: hidden;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            color: white;
            text-decoration: none;
        }
        
        .action-btn.success { background: #48bb78; }
        .action-btn.warning { background: #ed8936; }
        .action-btn.danger { background: #e53e3e; }
        .action-btn.purple { background: #9f7aea; }
        
        .action-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.6s;
        }
        
        .action-btn:hover::before {
            left: 100%;
        }
        
        /* Alert */
        .alert {
            background: #e6fffa;
            border: 1px solid #4fd1c7;
            border-radius: 10px;
            padding: 20px;
            color: #234e52;
        }
        
        .alert h4 {
            margin-bottom: 10px;
            font-weight: 600;
        }
        
        /* Quick Stats */
        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .quick-stat {
            background: #f7fafc;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            border-left: 4px solid #e2e8f0;
        }
        
        .quick-stat.businesses { border-left-color: #38a169; }
        .quick-stat.properties { border-left-color: #4299e1; }
        .quick-stat.payments { border-left-color: #ed8936; }
        .quick-stat.bills { border-left-color: #9f7aea; }
        
        .quick-stat-icon {
            font-size: 32px;
            margin-bottom: 10px;
            color: #718096;
        }
        
        .quick-stat.businesses .quick-stat-icon { color: #38a169; }
        .quick-stat.properties .quick-stat-icon { color: #4299e1; }
        .quick-stat.payments .quick-stat-icon { color: #ed8936; }
        .quick-stat.bills .quick-stat-icon { color: #9f7aea; }
        
        .quick-stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 5px;
        }
        
        .quick-stat-label {
            color: #718096;
            font-size: 14px;
            font-weight: 600;
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
            
            .actions-grid {
                grid-template-columns: 1fr;
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
            
            <a href="index.php" class="brand">
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
                
                <!-- Registration -->
                <div class="nav-section">
                    <div class="nav-title">Registration</div>
                    <div class="nav-item">
                        <a href="businesses/add.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-plus-circle"></i>
                                <span class="icon-plus" style="display: none;"></span>
                            </span>
                            Register Business
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="properties/add.php" class="nav-link">
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
                        <a href="businesses/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-building"></i>
                                <span class="icon-building" style="display: none;"></span>
                            </span>
                            Businesses
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="properties/index.php" class="nav-link">
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
                        <a href="payments/record.php" class="nav-link <?php echo ($currentDir === 'payments' && $currentPage === 'record.php') ? 'active' : ''; ?>">
                            <span class="nav-icon">
                                <i class="fas fa-cash-register"></i>
                                <span class="icon-money" style="display: none;"></span>
                            </span>
                            Record Payment
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="payments/search.php" class="nav-link <?php echo ($currentDir === 'payments' && $currentPage === 'search.php') ? 'active' : ''; ?>">
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
                        <a href="map/businesses.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-map-marked-alt"></i>
                                <span class="icon-map" style="display: none;"></span>
                            </span>
                            Business Map
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="map/properties.php" class="nav-link">
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
            <!-- Welcome Section -->
            <div class="welcome-card fade-in">
                <h1 class="welcome-title">Welcome, <?php echo htmlspecialchars($userDisplayName); ?>! ⚡</h1>
                <p class="welcome-subtitle">Your comprehensive portal for managing businesses, properties, payments, and bills in <?php echo APP_NAME; ?>.</p>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid fade-in">
                <div class="stat-card primary">
                    <div class="stat-header">
                        <div class="stat-title">Today's Revenue</div>
                        <div class="stat-icon">
                            <i class="fas fa-money-bill-wave"></i>
                            <span class="icon-money" style="display: none;"></span>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo formatCurrency($todayAmount); ?></div>
                    <div class="stat-subtitle"><?php echo number_format($todayPayments); ?> payments processed</div>
                </div>

                <div class="stat-card success">
                    <div class="stat-header">
                        <div class="stat-title">My Contribution</div>
                        <div class="stat-icon">
                            <i class="fas fa-user-check"></i>
                            <span class="icon-user" style="display: none;"></span>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo formatCurrency($myTodayAmount); ?></div>
                    <div class="stat-subtitle"><?php echo number_format($myTodayPayments); ?> payments (<?php echo $myContribution; ?>%)</div>
                </div>

                <div class="stat-card info">
                    <div class="stat-header">
                        <div class="stat-title">My Registrations</div>
                        <div class="stat-icon">
                            <i class="fas fa-plus-circle"></i>
                            <span class="icon-plus" style="display: none;"></span>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($myBusinesses + $myProperties); ?></div>
                    <div class="stat-subtitle"><?php echo number_format($myBusinesses); ?> businesses, <?php echo number_format($myProperties); ?> properties</div>
                </div>

                <div class="stat-card warning">
                    <div class="stat-header">
                        <div class="stat-title">Pending Bills</div>
                        <div class="stat-icon">
                            <i class="fas fa-file-invoice"></i>
                            <span class="icon-receipt" style="display: none;"></span>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($pendingBills); ?></div>
                    <div class="stat-subtitle">of <?php echo number_format($totalBills); ?> total bills</div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="card fade-in">
                <div class="card-header">
                    <h5 class="card-title">⚡ Quick Actions</h5>
                </div>
                <div class="card-body">
                    <div class="actions-grid">
                        <a href="businesses/add.php" class="action-btn success pulse">
                            <i class="fas fa-plus-circle"></i>
                            <span class="icon-plus" style="display: none;"></span>
                            Register Business
                        </a>
                        <a href="properties/add.php" class="action-btn">
                            <i class="fas fa-plus-circle"></i>
                            <span class="icon-plus" style="display: none;"></span>
                            Register Property
                        </a>
                        <a href="payments/record.php" class="action-btn danger">
                            <i class="fas fa-cash-register"></i>
                            <span class="icon-money" style="display: none;"></span>
                            Record Payment
                        </a>
                      
                        <a href="payments/search.php" class="action-btn purple">
                            <i class="fas fa-search"></i>
                            <span class="icon-search" style="display: none;"></span>
                            Search Accounts
                        </a>
                        <a href="map/businesses.php" class="action-btn">
                            <i class="fas fa-map-marked-alt"></i>
                            <span class="icon-map" style="display: none;"></span>
                            View Map
                        </a>
                    </div>
                </div>
            </div>

            <!-- Quick Stats Overview -->
            <div class="card fade-in">
                <div class="card-header">
                    <h5 class="card-title">📊 System Overview</h5>
                </div>
                <div class="card-body">
                    <div class="quick-stats">
                        <div class="quick-stat businesses">
                            <div class="quick-stat-icon">
                                <i class="fas fa-building"></i>
                                <span class="icon-building" style="display: none;"></span>
                            </div>
                            <div class="quick-stat-value"><?php echo number_format($totalBusinesses); ?></div>
                            <div class="quick-stat-label">Total Businesses</div>
                        </div>
                        
                        <div class="quick-stat properties">
                            <div class="quick-stat-icon">
                                <i class="fas fa-home"></i>
                                <span class="icon-home" style="display: none;"></span>
                            </div>
                            <div class="quick-stat-value"><?php echo number_format($totalProperties); ?></div>
                            <div class="quick-stat-label">Total Properties</div>
                        </div>
                        
                        <div class="quick-stat payments">
                            <div class="quick-stat-icon">
                                <i class="fas fa-credit-card"></i>
                                <span class="icon-money" style="display: none;"></span>
                            </div>
                            <div class="quick-stat-value"><?php echo number_format($todayPayments); ?></div>
                            <div class="quick-stat-label">Today's Payments</div>
                        </div>
                        
                        <div class="quick-stat bills">
                            <div class="quick-stat-icon">
                                <i class="fas fa-file-invoice-dollar"></i>
                                <span class="icon-receipt" style="display: none;"></span>
                            </div>
                            <div class="quick-stat-value"><?php echo number_format($totalBills); ?></div>
                            <div class="quick-stat-label">Total Bills</div>
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

        // Add smooth hover effects to stat cards
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

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Alt + 1-6 for quick navigation
            if (e.altKey) {
                switch(e.key) {
                    case '1':
                        e.preventDefault();
                        window.location.href = 'businesses/add.php';
                        break;
                    case '2':
                        e.preventDefault();
                        window.location.href = 'properties/add.php';
                        break;
                    case '3':
                        e.preventDefault();
                        window.location.href = 'payments/record.php';
                        break;
                    case '4':
                        e.preventDefault();
                        window.location.href = 'billing/generate.php';
                        break;
                    case '5':
                        e.preventDefault();
                        window.location.href = 'payments/search.php';
                        break;
                    case '6':
                        e.preventDefault();
                        window.location.href = 'map/businesses.php';
                        break;
                }
            }
        });

        // Welcome message animation
        setTimeout(() => {
            const welcomeCard = document.querySelector('.welcome-card');
            if (welcomeCard) {
                welcomeCard.style.background = 'linear-gradient(135deg, #4299e1, #ffffff)';
                welcomeCard.style.color = 'white';
                setTimeout(() => {
                    welcomeCard.style.background = 'white';
                    welcomeCard.style.color = '#2d3748';
                }, 1000);
            }
        }, 2000);
    </script>
    
</body>
</html>