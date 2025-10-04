<?php
/**
 * Collection Report for QUICKBILL 305
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
$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$selectedMonth = isset($_GET['month']) ? intval($_GET['month']) : 0; // 0 = all months
$selectedUser = isset($_GET['user']) ? intval($_GET['user']) : 0; // 0 = all users

// Export handling
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    // Export to PDF functionality would go here
    setFlashMessage('info', 'PDF export functionality will be implemented soon.');
    header('Location: collection_report.php');
    exit();
}

// Get collection data
try {
    $db = new Database();
    
    // Build WHERE clause based on filters
    $whereConditions = ["p.payment_status = 'Successful'"];
    $params = [];
    
    if ($selectedYear > 0) {
        $whereConditions[] = "YEAR(p.payment_date) = ?";
        $params[] = $selectedYear;
    }
    
    if ($selectedMonth > 0) {
        $whereConditions[] = "MONTH(p.payment_date) = ?";
        $params[] = $selectedMonth;
    }
    
    if ($selectedUser > 0) {
        $whereConditions[] = "p.processed_by = ?";
        $params[] = $selectedUser;
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Get overall collection statistics
    $collectionQuery = "
        SELECT 
            COUNT(*) as total_collections,
            SUM(p.amount_paid) as total_collected,
            AVG(p.amount_paid) as avg_collection,
            MIN(p.amount_paid) as min_collection,
            MAX(p.amount_paid) as max_collection
        FROM payments p 
        WHERE $whereClause
    ";
    
    $collectionData = $db->fetchRow($collectionQuery, $params);
    
    // Get daily collections for the selected period
    $dailyQuery = "
        SELECT 
            DATE(p.payment_date) as collection_date,
            COUNT(*) as transaction_count,
            SUM(p.amount_paid) as daily_total
        FROM payments p 
        WHERE $whereClause
        GROUP BY DATE(p.payment_date)
        ORDER BY DATE(p.payment_date) DESC
        LIMIT 30
    ";
    
    $dailyCollections = $db->fetchAll($dailyQuery, $params);
    
    // Get collections by payment method
    $methodQuery = "
        SELECT 
            p.payment_method,
            COUNT(*) as transaction_count,
            SUM(p.amount_paid) as total_amount,
            AVG(p.amount_paid) as avg_amount
        FROM payments p 
        WHERE $whereClause
        GROUP BY p.payment_method
        ORDER BY total_amount DESC
    ";
    
    $collectionsByMethod = $db->fetchAll($methodQuery, $params);
    
    // Get collections by user (revenue officers)
    $userQuery = "
        SELECT 
            u.first_name,
            u.last_name,
            ur.role_name,
            COUNT(p.payment_id) as transaction_count,
            SUM(p.amount_paid) as total_collected,
            AVG(p.amount_paid) as avg_collection
        FROM payments p
        INNER JOIN users u ON p.processed_by = u.user_id
        INNER JOIN user_roles ur ON u.role_id = ur.role_id
        WHERE $whereClause
        GROUP BY u.user_id, u.first_name, u.last_name, ur.role_name
        ORDER BY total_collected DESC
    ";
    
    $collectionsByUser = $db->fetchAll($userQuery, $params);
    
    // Get collections by bill type
    $billTypeQuery = "
        SELECT 
            b.bill_type,
            COUNT(p.payment_id) as transaction_count,
            SUM(p.amount_paid) as total_amount,
            AVG(p.amount_paid) as avg_amount
        FROM payments p
        INNER JOIN bills b ON p.bill_id = b.bill_id
        WHERE $whereClause
        GROUP BY b.bill_type
        ORDER BY total_amount DESC
    ";
    
    $collectionsByBillType = $db->fetchAll($billTypeQuery, $params);
    
    // Get hourly collection patterns
    $hourlyQuery = "
        SELECT 
            HOUR(p.payment_date) as hour_of_day,
            COUNT(*) as transaction_count,
            SUM(p.amount_paid) as total_amount
        FROM payments p 
        WHERE $whereClause
        GROUP BY HOUR(p.payment_date)
        ORDER BY HOUR(p.payment_date)
    ";
    
    $hourlyCollections = $db->fetchAll($hourlyQuery, $params);
    
    // Get recent collections
    $recentQuery = "
        SELECT 
            p.payment_reference,
            p.amount_paid,
            p.payment_method,
            p.payment_date,
            b.bill_number,
            b.bill_type,
            CASE 
                WHEN b.bill_type = 'Business' THEN bs.business_name
                WHEN b.bill_type = 'Property' THEN pr.owner_name
            END as payer_name,
            CONCAT(u.first_name, ' ', u.last_name) as collected_by
        FROM payments p
        INNER JOIN bills b ON p.bill_id = b.bill_id
        LEFT JOIN businesses bs ON (b.bill_type = 'Business' AND b.reference_id = bs.business_id)
        LEFT JOIN properties pr ON (b.bill_type = 'Property' AND b.reference_id = pr.property_id)
        LEFT JOIN users u ON p.processed_by = u.user_id
        WHERE $whereClause
        ORDER BY p.payment_date DESC
        LIMIT 20
    ";
    
    $recentCollections = $db->fetchAll($recentQuery, $params);
    
    // Get collection targets (if any)
    $targetQuery = "
        SELECT 
            setting_value as monthly_target
        FROM system_settings 
        WHERE setting_key = 'monthly_collection_target'
    ";
    $targetResult = $db->fetchRow($targetQuery);
    $monthlyTarget = $targetResult ? floatval($targetResult['monthly_target']) : 0;
    
    // Calculate current month performance if viewing current year
    $currentMonthPerformance = 0;
    if ($selectedYear == date('Y')) {
        $currentMonthQuery = "
            SELECT SUM(amount_paid) as current_month_total
            FROM payments 
            WHERE payment_status = 'Successful' 
            AND YEAR(payment_date) = YEAR(CURDATE())
            AND MONTH(payment_date) = MONTH(CURDATE())
        ";
        $currentMonthResult = $db->fetchRow($currentMonthQuery);
        $currentMonthPerformance = $currentMonthResult['current_month_total'] ?? 0;
    }
    
    // Get available years and users for filters
    $yearsQuery = "
        SELECT DISTINCT YEAR(payment_date) as year 
        FROM payments 
        WHERE payment_status = 'Successful'
        ORDER BY year DESC
    ";
    $availableYears = $db->fetchAll($yearsQuery);
    
    $usersQuery = "
        SELECT DISTINCT u.user_id, u.first_name, u.last_name, ur.role_name
        FROM users u
        INNER JOIN user_roles ur ON u.role_id = ur.role_id
        INNER JOIN payments p ON u.user_id = p.processed_by
        WHERE p.payment_status = 'Successful'
        ORDER BY u.first_name, u.last_name
    ";
    $availableUsers = $db->fetchAll($usersQuery);
    
} catch (Exception $e) {
    $collectionData = ['total_collections' => 0, 'total_collected' => 0, 'avg_collection' => 0, 'min_collection' => 0, 'max_collection' => 0];
    $dailyCollections = [];
    $collectionsByMethod = [];
    $collectionsByUser = [];
    $collectionsByBillType = [];
    $hourlyCollections = [];
    $recentCollections = [];
    $monthlyTarget = 0;
    $currentMonthPerformance = 0;
    $availableYears = [];
    $availableUsers = [];
}

// Prepare chart data
$dailyLabels = array_reverse(array_column($dailyCollections, 'collection_date'));
$dailyData = array_reverse(array_column($dailyCollections, 'daily_total'));

// Prepare hourly data (24 hours)
$hourlyLabels = [];
$hourlyData = [];
for ($i = 0; $i < 24; $i++) {
    $hourlyLabels[] = sprintf('%02d:00', $i);
    $hourlyData[$i] = 0;
}

foreach ($hourlyCollections as $hour) {
    $hourlyData[$hour['hour_of_day']] = floatval($hour['total_amount']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Collection Report - <?php echo APP_NAME; ?></title>

    <!-- Local Chart.js -->
    <script src="../../assets/js/chart.min.js"></script>
    
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

        /* Emoji Icons */
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
        .icon-calculator::before { content: "🧮"; }
        .icon-arrow-down::before { content: "⬇️"; }
        .icon-arrow-up::before { content: "⬆️"; }
        .icon-target::before { content: "🎯"; }
        .icon-filter::before { content: "🔍"; }
        .icon-download::before { content: "📥"; }
        .icon-back::before { content: "⬅️"; }
        .icon-user::before { content: "👤"; }
        .icon-chevron-down::before { content: "⌄"; }

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
            display: flex;
            align-items: center;
            gap: 10px;
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

        .dropdown-item .icon-user,
        .dropdown-item .icon-cog,
        .dropdown-item .icon-logout {
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
            display: flex;
            align-items: center;
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

        /* Target Progress */
        .target-progress {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .progress-bar {
            background: #e2e8f0;
            border-radius: 10px;
            height: 20px;
            overflow: hidden;
            position: relative;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            transition: width 0.3s ease;
            border-radius: 10px;
        }

        .progress-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-weight: 600;
            font-size: 12px;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.5);
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
        .charts-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }

        .chart-container {
            position: relative;
            height: 350px;
            width: 100%;
        }

        /* Tables */
        .table-responsive {
            overflow-x: auto;
            border-radius: 10px;
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

        .badge {
            padding: 4px 8px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
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

        .badge-primary {
            background: #d6e9fd;
            color: #2a4365;
        }

        /* Performance indicators */
        .performance-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 8px;
        }

        .performance-high {
            background: #48bb78;
        }

        .performance-medium {
            background: #ed8936;
        }

        .performance-low {
            background: #f56565;
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

            .charts-grid {
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
    <!-- Top Navigation -->
    <div class="top-nav">
        <div class="nav-left">
            <button class="toggle-btn" onclick="toggleSidebar()" id="toggleBtn">
                <span class="icon-menu"></span>
            </button>

            <a href="../index.php" class="brand">
                <span class="icon-receipt"></span>
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
                    <span class="icon-bell"></span>
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
                <span class="icon-chevron-down dropdown-arrow"></span>

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
                            <span class="icon-user"></span>
                            My Profile
                        </a>
                        <a href="../settings/index.php" class="dropdown-item">
                            <span class="icon-cog"></span>
                            Account Settings
                        </a>
                        <a href="../../auth/logout.php" class="dropdown-item logout">
                            <span class="icon-logout"></span>
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
                            <span class="nav-icon icon-dashboard"></span>
                            Dashboard
                        </a>
                    </div>
                </div>

                <!-- Core Management -->
                <div class="nav-section">
                    <div class="nav-title">Core Management</div>
                    <div class="nav-item">
                        <a href="../users/index.php" class="nav-link">
                            <span class="nav-icon icon-users"></span>
                            Users
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../businesses/index.php" class="nav-link">
                            <span class="nav-icon icon-building"></span>
                            Businesses
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../properties/index.php" class="nav-link">
                            <span class="nav-icon icon-home"></span>
                            Properties
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../zones/index.php" class="nav-link">
                            <span class="nav-icon icon-map"></span>
                            Zones & Areas
                        </a>
                    </div>
                </div>

                <!-- Billing & Payments -->
                <div class="nav-section">
                    <div class="nav-title">Billing & Payments</div>
                    <div class="nav-item">
                        <a href="../billing/index.php" class="nav-link">
                            <span class="nav-icon icon-invoice"></span>
                            Billing
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../payments/index.php" class="nav-link">
                            <span class="nav-icon icon-credit"></span>
                            Payments
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../fee_structure/index.php" class="nav-link">
                            <span class="nav-icon icon-tags"></span>
                            Fee Structure
                        </a>
                    </div>
                </div>

                <!-- Reports & System -->
                <div class="nav-section">
                    <div class="nav-title">Reports & System</div>
                    <div class="nav-item">
                        <a href="index.php" class="nav-link active">
                            <span class="nav-icon icon-chart"></span>
                            Reports
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../notifications/index.php" class="nav-link">
                            <span class="nav-icon icon-bell"></span>
                            Notifications
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../settings/index.php" class="nav-link">
                            <span class="nav-icon icon-cog"></span>
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
                    <h1 class="page-title">💰 Collection Report</h1>
                    <p class="page-subtitle">Payment collection performance and analytics</p>
                </div>
                <div class="page-actions">
                    <a href="index.php" class="btn btn-outline">
                        <span class="icon-back"></span>
                        Back to Reports
                    </a>
                    <a href="?export=pdf&<?php echo http_build_query($_GET); ?>" class="btn btn-success">
                        <span class="icon-download"></span>
                        Export PDF
                    </a>
                </div>
            </div>

            <!-- Monthly Target Progress -->
            <?php if ($monthlyTarget > 0 && $selectedYear == date('Y')): ?>
            <div class="target-progress">
                <div class="progress-header">
                    <h6 style="margin: 0; color: #2d3748; display: flex; align-items: center; gap: 8px;">
                        <span class="icon-target"></span>
                        Monthly Collection Target Progress
                    </h6>
                    <span style="color: #718096; font-size: 14px;">
                        ₵ <?php echo number_format($currentMonthPerformance, 2); ?> / 
                        ₵ <?php echo number_format($monthlyTarget, 2); ?>
                    </span>
                </div>
                <div class="progress-bar">
                    <?php 
                    $progressPercentage = min(100, ($currentMonthPerformance / $monthlyTarget) * 100);
                    ?>
                    <div class="progress-fill" style="width: <?php echo $progressPercentage; ?>%"></div>
                    <div class="progress-text"><?php echo round($progressPercentage, 1); ?>%</div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Filters -->
            <div class="filters-card">
                <form method="GET" action="">
                    <div class="filters-grid">
                        <div class="form-group">
                            <label class="form-label">Year</label>
                            <select name="year" class="form-control">
                                <option value="">All Years</option>
                                <?php foreach ($availableYears as $year): ?>
                                    <option value="<?php echo $year['year']; ?>" 
                                        <?php echo $selectedYear == $year['year'] ? 'selected' : ''; ?>>
                                        <?php echo $year['year']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Month</label>
                            <select name="month" class="form-control">
                                <option value="0">All Months</option>
                                <?php for ($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?php echo $i; ?>" 
                                        <?php echo $selectedMonth == $i ? 'selected' : ''; ?>>
                                        <?php echo date('F', mktime(0, 0, 0, $i, 1)); ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Collected By</label>
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
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-primary">
                                <span class="icon-filter"></span>
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
                        <div class="stat-title">Total Collected</div>
                        <div class="stat-icon">
                            <span class="icon-money"></span>
                        </div>
                    </div>
                    <div class="stat-value">₵ <?php echo number_format($collectionData['total_collected'] ?? 0, 2); ?></div>
                    <div class="stat-subtitle"><?php echo number_format($collectionData['total_collections'] ?? 0); ?> transactions</div>
                </div>

                <div class="stat-card success">
                    <div class="stat-header">
                        <div class="stat-title">Average Collection</div>
                        <div class="stat-icon">
                            <span class="icon-calculator"></span>
                        </div>
                    </div>
                    <div class="stat-value">₵ <?php echo number_format($collectionData['avg_collection'] ?? 0, 2); ?></div>
                    <div class="stat-subtitle">Per transaction</div>
                </div>

                <div class="stat-card info">
                    <div class="stat-header">
                        <div class="stat-title">Minimum Collection</div>
                        <div class="stat-icon">
                            <span class="icon-arrow-down"></span>
                        </div>
                    </div>
                    <div class="stat-value">₵ <?php echo number_format($collectionData['min_collection'] ?? 0, 2); ?></div>
                    <div class="stat-subtitle">Lowest payment</div>
                </div>

                <div class="stat-card warning">
                    <div class="stat-header">
                        <div class="stat-title">Maximum Collection</div>
                        <div class="stat-icon">
                            <span class="icon-arrow-up"></span>
                        </div>
                    </div>
                    <div class="stat-value">₵ <?php echo number_format($collectionData['max_collection'] ?? 0, 2); ?></div>
                    <div class="stat-subtitle">Highest payment</div>
                </div>
            </div>

            <!-- Charts -->
            <div class="charts-grid">
                <!-- Daily Collections Chart -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">📈 Daily Collections (Last 30 Days)</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="dailyCollectionsChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Payment Methods Chart -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">💳 Collections by Payment Method</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="paymentMethodChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Collection Performance Tables -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px;">
                <!-- Collections by User -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">👥 Collections by User</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Transactions</th>
                                        <th>Total Collected</th>
                                        <th>Average</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($collectionsByUser)): ?>
                                        <tr>
                                            <td colspan="4" style="text-align: center; color: #718096;">No data available</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($collectionsByUser as $user): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></strong><br>
                                                    <small style="color: #718096;"><?php echo htmlspecialchars($user['role_name']); ?></small>
                                                </td>
                                                <td><?php echo number_format($user['transaction_count']); ?></td>
                                                <td>₵ <?php echo number_format($user['total_collected'], 2); ?></td>
                                                <td>₵ <?php echo number_format($user['avg_collection'], 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Collections by Bill Type -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">🏢 Collections by Bill Type</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Bill Type</th>
                                        <th>Transactions</th>
                                        <th>Total Amount</th>
                                        <th>Average</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($collectionsByBillType)): ?>
                                        <tr>
                                            <td colspan="4" style="text-align: center; color: #718096;">No data available</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($collectionsByBillType as $billType): ?>
                                            <tr>
                                                <td>
                                                    <span class="badge badge-<?php echo $billType['bill_type'] == 'Business' ? 'info' : 'success'; ?>">
                                                        <?php echo htmlspecialchars($billType['bill_type']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo number_format($billType['transaction_count']); ?></td>
                                                <td>₵ <?php echo number_format($billType['total_amount'], 2); ?></td>
                                                <td>₵ <?php echo number_format($billType['avg_amount'], 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Collections -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">⏰ Recent Collections</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Payment Reference</th>
                                    <th>Payer</th>
                                    <th>Bill Number</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Collected By</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentCollections)): ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center; color: #718096;">No collections found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recentCollections as $collection): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($collection['payment_reference']); ?></td>
                                            <td><?php echo htmlspecialchars($collection['payer_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($collection['bill_number']); ?></td>
                                            <td>₵ <?php echo number_format($collection['amount_paid'], 2); ?></td>
                                            <td>
                                                <span class="badge badge-info">
                                                    <?php echo htmlspecialchars($collection['payment_method']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($collection['collected_by'] ?? 'System'); ?></td>
                                            <td><?php echo date('M j, Y g:i A', strtotime($collection['payment_date'])); ?></td>
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
        const methodLabels = <?php echo json_encode(array_column($collectionsByMethod, 'payment_method')); ?>;
        const methodData = <?php echo json_encode(array_column($collectionsByMethod, 'total_amount')); ?>;

        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            initializeCharts();
        });

        function initializeCharts() {
            if (typeof Chart === 'undefined') {
                console.log('Chart.js not available from local file');
                showChartFallbacks();
                return;
            }

            try {
                // Daily Collections Chart
                const dailyCtx = document.getElementById('dailyCollectionsChart').getContext('2d');
                new Chart(dailyCtx, {
                    type: 'line',
                    data: {
                        labels: dailyLabels,
                        datasets: [{
                            label: 'Daily Collections (₵)',
                            data: dailyData,
                            borderColor: '#48bb78',
                            backgroundColor: 'rgba(72, 187, 120, 0.1)',
                            fill: true,
                            tension: 0.4,
                            borderWidth: 3,
                            pointBackgroundColor: '#48bb78',
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
                                ticks: {
                                    callback: function(value) {
                                        return '₵ ' + value.toLocaleString();
                                    }
                                }
                            },
                            x: {
                                grid: {
                                    display: false
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
                                        return 'Collections: ₵ ' + context.parsed.y.toLocaleString();
                                    }
                                }
                            }
                        }
                    }
                });

                // Payment Method Chart
                const methodCtx = document.getElementById('paymentMethodChart').getContext('2d');
                new Chart(methodCtx, {
                    type: 'doughnut',
                    data: {
                        labels: methodLabels.length > 0 ? methodLabels : ['No Data'],
                        datasets: [{
                            data: methodData.length > 0 ? methodData : [1],
                            backgroundColor: methodData.length > 0 ? [
                                '#48bb78',
                                '#4299e1',
                                '#ed8936',
                                '#9f7aea',
                                '#667eea',
                                '#f56565'
                            ] : ['#e2e8f0'],
                            borderColor: '#fff',
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    padding: 15,
                                    usePointStyle: true
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        if (methodData.length === 0) return 'No data available';
                                        return context.label + ': ₵ ' + context.parsed.toLocaleString();
                                    }
                                }
                            }
                        }
                    }
                });

                console.log('Charts created successfully');
            } catch (error) {
                console.error('Error creating charts:', error);
                showChartFallbacks();
            }
        }

        function showChartFallbacks() {
            const dailyContainer = document.getElementById('dailyCollectionsChart').parentElement;
            const methodContainer = document.getElementById('paymentMethodChart').parentElement;
            
            dailyContainer.innerHTML = '<div style="height: 350px; display: flex; align-items: center; justify-content: center; color: #718096; font-style: italic;">📊 Chart will appear here when Chart.js is available</div>';
            methodContainer.innerHTML = '<div style="height: 350px; display: flex; align-items: center; justify-content: center; color: #718096; font-style: italic;">🍰 Chart will appear here when Chart.js is available</div>';
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
        });
    </script>
</body>
</html>