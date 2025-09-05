<?php
/**
 * Payment Reports Page for QUICKBILL 305
 * Comprehensive payment analytics with detailed charts and insights
 * Fixed version with null safety for htmlspecialchars()
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

// Check for appropriate permissions (Admin, Officer, Revenue Officer)
$currentUser = getCurrentUser();
$userRole = getCurrentUserRole();
if (!in_array($userRole, ['Admin', 'Super Admin', 'Officer', 'Revenue Officer'])) {
    setFlashMessage('error', 'Access denied. Insufficient privileges.');
    header('Location: ../../admin/index.php');
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

$userDisplayName = getUserDisplayName($currentUser);

// Helper function to safely escape HTML
function safeHtml($value, $default = '') {
    return htmlspecialchars($value ?? $default, ENT_QUOTES, 'UTF-8');
}

// Helper function to safely format numbers
function safeNumber($value, $decimals = 2) {
    return number_format(floatval($value ?? 0), $decimals);
}

// Initialize database
$db = new Database();

// Get filter parameters
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : 0; // 0 = all months
$status = isset($_GET['status']) ? $_GET['status'] : 'all';
$method = isset($_GET['method']) ? $_GET['method'] : 'all';

// Build filter conditions
$whereConditions = [];
$params = [];

$whereConditions[] = "YEAR(p.payment_date) = ?";
$params[] = $year;

if ($month > 0) {
    $whereConditions[] = "MONTH(p.payment_date) = ?";
    $params[] = $month;
}

if ($status !== 'all') {
    $whereConditions[] = "p.payment_status = ?";
    $params[] = $status;
}

if ($method !== 'all') {
    $whereConditions[] = "p.payment_method = ?";
    $params[] = $method;
}

$whereClause = "WHERE " . implode(" AND ", $whereConditions);

// Get payment statistics
$totalRevenue = 0;
$totalPayments = 0;
$averagePayment = 0;
$successfulPayments = 0;

try {
    // Total revenue and payments
    $result = $db->fetchRow("
        SELECT 
            COUNT(*) as total_payments,
            SUM(amount_paid) as total_revenue,
            AVG(amount_paid) as average_payment,
            SUM(CASE WHEN payment_status = 'Successful' THEN 1 ELSE 0 END) as successful_payments
        FROM payments p 
        $whereClause
    ", $params);
    
    $totalPayments = $result['total_payments'] ?? 0;
    $totalRevenue = $result['total_revenue'] ?? 0;
    $averagePayment = $result['average_payment'] ?? 0;
    $successfulPayments = $result['successful_payments'] ?? 0;
    
} catch (Exception $e) {
    // Handle error silently
}

// Get payment method distribution
$paymentMethodData = [];
try {
    $paymentMethodData = $db->fetchAll("
        SELECT 
            payment_method,
            COUNT(*) as count,
            SUM(amount_paid) as total_amount,
            AVG(amount_paid) as avg_amount
        FROM payments p 
        $whereClause
        GROUP BY payment_method
        ORDER BY total_amount DESC
    ", $params);
} catch (Exception $e) {}

// Get payment status distribution
$paymentStatusData = [];
try {
    $paymentStatusData = $db->fetchAll("
        SELECT 
            payment_status,
            COUNT(*) as count,
            SUM(amount_paid) as total_amount
        FROM payments p 
        $whereClause
        GROUP BY payment_status
        ORDER BY total_amount DESC
    ", $params);
} catch (Exception $e) {}

// Get monthly trends
$monthlyData = [];
try {
    $monthlyData = $db->fetchAll("
        SELECT 
            MONTH(payment_date) as month,
            MONTHNAME(payment_date) as month_name,
            COUNT(*) as count,
            SUM(amount_paid) as total_amount,
            SUM(CASE WHEN payment_status = 'Successful' THEN amount_paid ELSE 0 END) as successful_amount
        FROM payments p 
        WHERE YEAR(p.payment_date) = ?
        " . ($status !== 'all' ? "AND p.payment_status = '$status'" : "") . "
        " . ($method !== 'all' ? "AND p.payment_method = '$method'" : "") . "
        GROUP BY MONTH(payment_date), MONTHNAME(payment_date)
        ORDER BY month
    ", [$year]);
} catch (Exception $e) {}

// Get bill type distribution
$billTypeData = [];
try {
    $billTypeData = $db->fetchAll("
        SELECT 
            b.bill_type,
            COUNT(*) as count,
            SUM(p.amount_paid) as total_amount,
            AVG(p.amount_paid) as avg_amount
        FROM payments p 
        JOIN bills b ON p.bill_id = b.bill_id
        $whereClause
        GROUP BY b.bill_type
        ORDER BY total_amount DESC
    ", $params);
} catch (Exception $e) {}

// Get top 10 largest payments
$topPayments = [];
try {
    $topPayments = $db->fetchAll("
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
                ELSE 'Unknown'
            END as payer_name,
            CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as processed_by_name
        FROM payments p
        JOIN bills b ON p.bill_id = b.bill_id
        LEFT JOIN businesses bs ON b.bill_type = 'Business' AND b.reference_id = bs.business_id
        LEFT JOIN properties pr ON b.bill_type = 'Property' AND b.reference_id = pr.property_id
        LEFT JOIN users u ON p.processed_by = u.user_id
        $whereClause
        ORDER BY p.amount_paid DESC
        LIMIT 10
    ", $params);
} catch (Exception $e) {}

// Get payment channels data
$channelData = [];
try {
    $channelData = $db->fetchAll("
        SELECT 
            COALESCE(NULLIF(payment_channel, ''), 'Direct Payment') as channel,
            COUNT(*) as count,
            SUM(amount_paid) as total_amount
        FROM payments p 
        $whereClause
        GROUP BY payment_channel
        ORDER BY total_amount DESC
    ", $params);
} catch (Exception $e) {}

// Get available years for filter
$availableYears = [];
try {
    $availableYears = $db->fetchAll("
        SELECT DISTINCT YEAR(payment_date) as year 
        FROM payments 
        ORDER BY year DESC
    ");
} catch (Exception $e) {}

// Get available payment methods and statuses for filters
$paymentMethods = ['Mobile Money', 'Cash', 'Bank Transfer', 'Online'];
$paymentStatuses = ['Pending', 'Successful', 'Failed', 'Cancelled'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Reports - <?php echo APP_NAME; ?></title>

    <!-- Multiple Icon Sources -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

    <!-- Note: Using inline styles and vanilla JS charts due to CSP restrictions -->

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
        .icon-history::before { content: "📜"; }
        .icon-question::before { content: "❓"; }

        /* Top Navigation - Same as Dashboard */
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

        /* Layout */
        .container {
            margin-top: 80px;
            display: flex;
            min-height: calc(100vh - 80px);
        }

        /* Sidebar - Same as Dashboard */
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
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .page-title {
            font-size: 28px;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .page-subtitle {
            color: #718096;
            font-size: 16px;
        }

        /* Filter Section */
        .filter-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .filter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-label {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .filter-control {
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .filter-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
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
            font-size: 12px;
            opacity: 0.8;
            margin-top: 5px;
        }

        /* Chart Container */
        .chart-container {
            position: relative;
            height: 400px;
            margin: 20px 0;
            width: 100%;
        }

        .chart-container canvas {
            width: 100% !important;
            height: 100% !important;
            border-radius: 8px;
        }

        .no-data-message {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 400px;
            color: #718096;
            font-size: 16px;
            background: #f7fafc;
            border-radius: 10px;
            border: 2px dashed #e2e8f0;
        }

        .chart-legend {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 15px;
            margin-top: 15px;
            padding: 15px;
            background: #f7fafc;
            border-radius: 8px;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            font-weight: 600;
        }

        .legend-color {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }

        .chart-tooltip {
            position: absolute;
            background: rgba(0,0,0,0.8);
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            pointer-events: none;
            z-index: 1000;
            display: none;
        }

        .chart-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 30px;
            margin-bottom: 30px;
        }

        /* Table Styles */
        .table-responsive {
            border-radius: 10px;
            overflow: hidden;
        }

        .table {
            margin: 0;
        }

        .table th {
            background: #f7fafc;
            font-weight: 600;
            color: #2d3748;
            border: none;
            padding: 15px;
        }

        .table td {
            padding: 15px;
            border-top: 1px solid #e2e8f0;
            vertical-align: middle;
        }

        /* Badge Styles */
        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-success {
            background: #c6f6d5;
            color: #22543d;
        }

        .badge-warning {
            background: #fefcbf;
            color: #744210;
        }

        .badge-danger {
            background: #fed7d7;
            color: #742a2a;
        }

        .badge-info {
            background: #bee3f8;
            color: #2a4365;
        }

        /* Export Buttons */
        .export-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        /* Progress bar for payment method analysis */
        .progress {
            width: 100%;
            height: 20px;
            background-color: #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 11px;
            transition: width 0.3s ease;
        }

        .progress-bar.bg-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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

            .chart-row {
                grid-template-columns: 1fr;
            }

            .filter-row {
                grid-template-columns: 1fr;
            }

            .container {
                flex-direction: column;
            }
        }

        /* Print Styles */
        @media print {
            .top-nav, .sidebar, .filter-section, .export-buttons {
                display: none !important;
            }
            
            .main-content {
                margin: 0 !important;
                padding: 20px !important;
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
                    <?php echo strtoupper(substr($currentUser['first_name'] ?? 'U', 0, 1)); ?>
                </div>
                <div class="user-info">
                    <div class="user-name"><?php echo safeHtml($userDisplayName); ?></div>
                    <div class="user-role"><?php echo safeHtml($userRole); ?></div>
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
                        <a href="index.php" class="nav-link">
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
                        <a href="reports.php" class="nav-link active">
                            <span class="nav-icon">
                                <i class="fas fa-chart-line"></i>
                                <span class="icon-chart" style="display: none;"></span>
                            </span>
                            Payment Reports
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
                <h1 class="page-title">
                    <i class="fas fa-chart-line"></i>
                    <span class="icon-chart" style="display: none;"></span>
                    Payment Analytics & Reports
                </h1>
                <p class="page-subtitle">Comprehensive payment insights and performance analytics for <?php echo $month > 0 ? date('F', mktime(0, 0, 0, $month, 1)) . ' ' : ''; ?><?php echo $year; ?></p>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" action="" class="filter-form">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label class="filter-label">Year</label>
                            <select name="year" class="filter-control">
                                <?php foreach ($availableYears as $yearOption): ?>
                                    <option value="<?php echo intval($yearOption['year']); ?>" <?php echo $year == $yearOption['year'] ? 'selected' : ''; ?>>
                                        <?php echo intval($yearOption['year']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">Month</label>
                            <select name="month" class="filter-control">
                                <option value="0" <?php echo $month == 0 ? 'selected' : ''; ?>>All Months</option>
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?php echo $m; ?>" <?php echo $month == $m ? 'selected' : ''; ?>>
                                        <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">Payment Status</label>
                            <select name="status" class="filter-control">
                                <option value="all" <?php echo $status == 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                <?php foreach ($paymentStatuses as $statusOption): ?>
                                    <option value="<?php echo safeHtml($statusOption); ?>" <?php echo $status == $statusOption ? 'selected' : ''; ?>>
                                        <?php echo safeHtml($statusOption); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">Payment Method</label>
                            <select name="method" class="filter-control">
                                <option value="all" <?php echo $method == 'all' ? 'selected' : ''; ?>>All Methods</option>
                                <?php foreach ($paymentMethods as $methodOption): ?>
                                    <option value="<?php echo safeHtml($methodOption); ?>" <?php echo $method == $methodOption ? 'selected' : ''; ?>>
                                        <?php echo safeHtml($methodOption); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Key Statistics -->
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="stat-header">
                        <div class="stat-title">Total Revenue</div>
                        <div class="stat-icon">
                            <i class="fas fa-money-bill-wave"></i>
                            <span class="icon-money" style="display: none;"></span>
                        </div>
                    </div>
                    <div class="stat-value">₵ <?php echo safeNumber($totalRevenue); ?></div>
                    <div class="stat-subtitle">From <?php echo safeNumber($totalPayments, 0); ?> total payments</div>
                </div>

                <div class="stat-card success">
                    <div class="stat-header">
                        <div class="stat-title">Successful Payments</div>
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo safeNumber($successfulPayments, 0); ?></div>
                    <div class="stat-subtitle"><?php echo $totalPayments > 0 ? round(($successfulPayments / $totalPayments) * 100, 1) : 0; ?>% success rate</div>
                </div>

                <div class="stat-card info">
                    <div class="stat-header">
                        <div class="stat-title">Average Payment</div>
                        <div class="stat-icon">
                            <i class="fas fa-calculator"></i>
                        </div>
                    </div>
                    <div class="stat-value">₵ <?php echo safeNumber($averagePayment); ?></div>
                    <div class="stat-subtitle">Per transaction</div>
                </div>

                <div class="stat-card warning">
                    <div class="stat-header">
                        <div class="stat-title">Total Transactions</div>
                        <div class="stat-icon">
                            <i class="fas fa-receipt"></i>
                            <span class="icon-receipt" style="display: none;"></span>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo safeNumber($totalPayments, 0); ?></div>
                    <div class="stat-subtitle">All payment attempts</div>
                </div>
            </div>

            <!-- Export Buttons -->
            <div class="export-buttons">
                <button onclick="exportToCSV()" class="btn btn-secondary">
                    <i class="fas fa-file-csv"></i> Export CSV
                </button>
                <button onclick="printReport()" class="btn btn-secondary">
                    <i class="fas fa-print"></i> Print Report
                </button>
            </div>

            <!-- Chart Section -->
            <div class="chart-row">
                <!-- Payment Methods Pie Chart -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">Payment Methods Distribution</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="paymentMethodChart" width="400" height="400"></canvas>
                            <div class="chart-tooltip" id="paymentMethodTooltip"></div>
                            <div id="paymentMethodLegend" class="chart-legend"></div>
                            <div id="paymentMethodNoData" class="no-data-message" style="display: none;">
                                <div>
                                    <i class="fas fa-chart-pie" style="font-size: 48px; margin-bottom: 10px; display: block; text-align: center;"></i>
                                    No payment method data available
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment Status Pie Chart -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">Payment Status Distribution</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="paymentStatusChart" width="400" height="400"></canvas>
                            <div class="chart-tooltip" id="paymentStatusTooltip"></div>
                            <div id="paymentStatusLegend" class="chart-legend"></div>
                            <div id="paymentStatusNoData" class="no-data-message" style="display: none;">
                                <div>
                                    <i class="fas fa-chart-pie" style="font-size: 48px; margin-bottom: 10px; display: block; text-align: center;"></i>
                                    No payment status data available
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="chart-row">
                <!-- Bill Type Distribution -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">Revenue by Bill Type</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="billTypeChart" width="400" height="400"></canvas>
                            <div class="chart-tooltip" id="billTypeTooltip"></div>
                            <div id="billTypeLegend" class="chart-legend"></div>
                            <div id="billTypeNoData" class="no-data-message" style="display: none;">
                                <div>
                                    <i class="fas fa-chart-pie" style="font-size: 48px; margin-bottom: 10px; display: block; text-align: center;"></i>
                                    No bill type data available
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Monthly Trend -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">Monthly Payment Trends (<?php echo $year; ?>)</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="monthlyTrendChart" width="600" height="400"></canvas>
                            <div class="chart-tooltip" id="monthlyTrendTooltip"></div>
                            <div id="monthlyTrendLegend" class="chart-legend"></div>
                            <div id="monthlyTrendNoData" class="no-data-message" style="display: none;">
                                <div>
                                    <i class="fas fa-chart-line" style="font-size: 48px; margin-bottom: 10px; display: block; text-align: center;"></i>
                                    No monthly trend data available
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Top Payments Table -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">Top 10 Largest Payments</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>Amount</th>
                                    <th>Payer</th>
                                    <th>Method</th>
                                    <th>Bill Type</th>
                                    <th>Date</th>
                                    <th>Processed By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($topPayments)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center">No payment data available</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($topPayments as $payment): ?>
                                        <tr>
                                            <td><strong><?php echo safeHtml($payment['payment_reference']); ?></strong></td>
                                            <td><span class="badge badge-success">₵ <?php echo safeNumber($payment['amount_paid']); ?></span></td>
                                            <td><?php echo safeHtml($payment['payer_name']); ?></td>
                                            <td><?php echo safeHtml($payment['payment_method']); ?></td>
                                            <td>
                                                <span class="badge <?php echo $payment['bill_type'] === 'Business' ? 'badge-info' : 'badge-warning'; ?>">
                                                    <?php echo safeHtml($payment['bill_type']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo $payment['payment_date'] ? date('M j, Y', strtotime($payment['payment_date'])) : 'N/A'; ?></td>
                                            <td><?php echo safeHtml(trim($payment['processed_by_name'] ?? ''), 'System'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Payment Method Analysis -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">Payment Method Analysis</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Payment Method</th>
                                    <th>Transaction Count</th>
                                    <th>Total Amount</th>
                                    <th>Average Amount</th>
                                    <th>Percentage of Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($paymentMethodData)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center">No payment method data available</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($paymentMethodData as $method): ?>
                                        <tr>
                                            <td><strong><?php echo safeHtml($method['payment_method']); ?></strong></td>
                                            <td><?php echo safeNumber($method['count'], 0); ?></td>
                                            <td>₵ <?php echo safeNumber($method['total_amount']); ?></td>
                                            <td>₵ <?php echo safeNumber($method['avg_amount']); ?></td>
                                            <td>
                                                <div class="progress" style="height: 20px;">
                                                    <div class="progress-bar bg-primary" style="width: <?php echo $totalRevenue > 0 ? ($method['total_amount'] / $totalRevenue) * 100 : 0; ?>%">
                                                        <?php echo $totalRevenue > 0 ? round(($method['total_amount'] / $totalRevenue) * 100, 1) : 0; ?>%
                                                    </div>
                                                </div>
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
        // Vanilla JavaScript Chart Implementation (CSP-friendly)
        
        class VanillaChart {
            constructor(canvasId, type = 'pie') {
                this.canvas = document.getElementById(canvasId);
                this.ctx = this.canvas ? this.canvas.getContext('2d') : null;
                this.type = type;
                this.data = [];
                this.colors = [
                    '#667eea', '#764ba2', '#48bb78', '#ed8936', 
                    '#4299e1', '#9f7aea', '#f56565', '#a0aec0'
                ];
                this.tooltip = document.getElementById(canvasId.replace('Chart', 'Tooltip'));
                this.legend = document.getElementById(canvasId.replace('Chart', 'Legend'));
                
                if (this.canvas) {
                    this.setupCanvas();
                    this.bindEvents();
                }
            }
            
            setupCanvas() {
                const container = this.canvas.parentElement;
                const rect = container.getBoundingClientRect();
                this.canvas.width = rect.width - 40;
                this.canvas.height = 350;
                this.centerX = this.canvas.width / 2;
                this.centerY = this.canvas.height / 2;
                this.radius = Math.min(this.centerX, this.centerY) - 60;
            }
            
            bindEvents() {
                this.canvas.addEventListener('mousemove', (e) => this.handleMouseMove(e));
                this.canvas.addEventListener('mouseleave', () => this.hideTooltip());
            }
            
            drawPieChart(data) {
                if (!this.ctx || !data || data.length === 0) return;
                
                this.data = data;
                const total = data.reduce((sum, item) => sum + parseFloat(item.value || item.total_amount || 0), 0);
                
                if (total === 0) return;
                
                let currentAngle = -Math.PI / 2; // Start from top
                
                // Clear canvas
                this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
                
                // Draw pie slices
                data.forEach((item, index) => {
                    const value = parseFloat(item.value || item.total_amount || 0);
                    const sliceAngle = (value / total) * 2 * Math.PI;
                    const color = this.colors[index % this.colors.length];
                    
                    // Draw slice
                    this.ctx.beginPath();
                    this.ctx.moveTo(this.centerX, this.centerY);
                    this.ctx.arc(this.centerX, this.centerY, this.radius, currentAngle, currentAngle + sliceAngle);
                    this.ctx.closePath();
                    this.ctx.fillStyle = color;
                    this.ctx.fill();
                    this.ctx.strokeStyle = '#fff';
                    this.ctx.lineWidth = 3;
                    this.ctx.stroke();
                    
                    // Store slice info for interactions
                    item.startAngle = currentAngle;
                    item.endAngle = currentAngle + sliceAngle;
                    item.color = color;
                    item.percentage = ((value / total) * 100).toFixed(1);
                    
                    currentAngle += sliceAngle;
                });
                
                this.createLegend(data);
            }
            
            drawDoughnutChart(data) {
                if (!this.ctx || !data || data.length === 0) return;
                
                this.data = data;
                const total = data.reduce((sum, item) => sum + parseFloat(item.value || item.total_amount || 0), 0);
                const innerRadius = this.radius * 0.6;
                
                if (total === 0) return;
                
                let currentAngle = -Math.PI / 2;
                
                // Clear canvas
                this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
                
                // Draw doughnut slices
                data.forEach((item, index) => {
                    const value = parseFloat(item.value || item.total_amount || 0);
                    const sliceAngle = (value / total) * 2 * Math.PI;
                    const color = this.colors[index % this.colors.length];
                    
                    // Draw outer arc
                    this.ctx.beginPath();
                    this.ctx.arc(this.centerX, this.centerY, this.radius, currentAngle, currentAngle + sliceAngle);
                    this.ctx.arc(this.centerX, this.centerY, innerRadius, currentAngle + sliceAngle, currentAngle, true);
                    this.ctx.closePath();
                    this.ctx.fillStyle = color;
                    this.ctx.fill();
                    this.ctx.strokeStyle = '#fff';
                    this.ctx.lineWidth = 3;
                    this.ctx.stroke();
                    
                    item.startAngle = currentAngle;
                    item.endAngle = currentAngle + sliceAngle;
                    item.color = color;
                    item.percentage = ((value / total) * 100).toFixed(1);
                    
                    currentAngle += sliceAngle;
                });
                
                this.createLegend(data);
            }
            
            drawLineChart(data, datasets) {
                if (!this.ctx || !data || data.length === 0) return;
                
                this.data = data;
                const padding = 60;
                const chartWidth = this.canvas.width - (padding * 2);
                const chartHeight = this.canvas.height - (padding * 2);
                
                // Clear canvas
                this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
                
                // Find max value for scaling
                let maxValue = 0;
                datasets.forEach(dataset => {
                    dataset.data.forEach(value => {
                        maxValue = Math.max(maxValue, parseFloat(value || 0));
                    });
                });
                
                if (maxValue === 0) maxValue = 100; // Prevent division by zero
                
                // Draw grid lines
                this.ctx.strokeStyle = 'rgba(0,0,0,0.1)';
                this.ctx.lineWidth = 1;
                
                // Horizontal grid lines
                for (let i = 0; i <= 5; i++) {
                    const y = padding + (chartHeight / 5) * i;
                    this.ctx.beginPath();
                    this.ctx.moveTo(padding, y);
                    this.ctx.lineTo(padding + chartWidth, y);
                    this.ctx.stroke();
                    
                    // Y-axis labels
                    this.ctx.fillStyle = '#666';
                    this.ctx.font = '12px Arial';
                    this.ctx.textAlign = 'right';
                    const value = maxValue - (maxValue / 5) * i;
                    this.ctx.fillText('₵' + value.toLocaleString(), padding - 10, y + 4);
                }
                
                // Vertical grid lines and labels
                const stepX = chartWidth / (data.length - 1 || 1);
                data.forEach((item, index) => {
                    const x = padding + stepX * index;
                    this.ctx.beginPath();
                    this.ctx.moveTo(x, padding);
                    this.ctx.lineTo(x, padding + chartHeight);
                    this.ctx.stroke();
                    
                    // X-axis labels
                    this.ctx.fillStyle = '#666';
                    this.ctx.font = '12px Arial';
                    this.ctx.textAlign = 'center';
                    this.ctx.fillText(item.month_name || item.label, x, padding + chartHeight + 20);
                });
                
                // Draw datasets
                datasets.forEach((dataset, datasetIndex) => {
                    const color = this.colors[datasetIndex % this.colors.length];
                    
                    // Draw line
                    this.ctx.strokeStyle = color;
                    this.ctx.lineWidth = 3;
                    this.ctx.beginPath();
                    
                    dataset.data.forEach((value, index) => {
                        const x = padding + stepX * index;
                        const y = padding + chartHeight - ((parseFloat(value || 0) / maxValue) * chartHeight);
                        
                        if (index === 0) {
                            this.ctx.moveTo(x, y);
                        } else {
                            this.ctx.lineTo(x, y);
                        }
                    });
                    
                    this.ctx.stroke();
                    
                    // Draw points
                    this.ctx.fillStyle = color;
                    dataset.data.forEach((value, index) => {
                        const x = padding + stepX * index;
                        const y = padding + chartHeight - ((parseFloat(value || 0) / maxValue) * chartHeight);
                        
                        this.ctx.beginPath();
                        this.ctx.arc(x, y, 4, 0, 2 * Math.PI);
                        this.ctx.fill();
                        this.ctx.strokeStyle = '#fff';
                        this.ctx.lineWidth = 2;
                        this.ctx.stroke();
                    });
                    
                    // Store dataset info
                    dataset.color = color;
                });
                
                this.createLineChartLegend(datasets);
            }
            
            createLegend(data) {
                if (!this.legend) return;
                
                this.legend.innerHTML = '';
                data.forEach((item, index) => {
                    const legendItem = document.createElement('div');
                    legendItem.className = 'legend-item';
                    
                    const colorBox = document.createElement('div');
                    colorBox.className = 'legend-color';
                    colorBox.style.backgroundColor = item.color;
                    
                    const label = document.createElement('span');
                    label.textContent = `${item.label || item.payment_method || item.payment_status || item.bill_type} (${item.percentage}%)`;
                    
                    legendItem.appendChild(colorBox);
                    legendItem.appendChild(label);
                    this.legend.appendChild(legendItem);
                });
            }
            
            createLineChartLegend(datasets) {
                if (!this.legend) return;
                
                this.legend.innerHTML = '';
                datasets.forEach((dataset, index) => {
                    const legendItem = document.createElement('div');
                    legendItem.className = 'legend-item';
                    
                    const colorBox = document.createElement('div');
                    colorBox.className = 'legend-color';
                    colorBox.style.backgroundColor = dataset.color;
                    
                    const label = document.createElement('span');
                    label.textContent = dataset.label;
                    
                    legendItem.appendChild(colorBox);
                    legendItem.appendChild(label);
                    this.legend.appendChild(legendItem);
                });
            }
            
            handleMouseMove(e) {
                if (this.type === 'line') return; // No hover for line charts yet
                
                const rect = this.canvas.getBoundingClientRect();
                const x = e.clientX - rect.left;
                const y = e.clientY - rect.top;
                
                const dx = x - this.centerX;
                const dy = y - this.centerY;
                const distance = Math.sqrt(dx * dx + dy * dy);
                
                if (distance <= this.radius && distance >= (this.type === 'doughnut' ? this.radius * 0.6 : 0)) {
                    const angle = Math.atan2(dy, dx);
                    const normalizedAngle = angle < -Math.PI / 2 ? angle + 2 * Math.PI : angle;
                    
                    // Find which slice the mouse is over
                    const hoveredItem = this.data.find(item => {
                        return normalizedAngle >= item.startAngle && normalizedAngle <= item.endAngle;
                    });
                    
                    if (hoveredItem) {
                        this.showTooltip(e, hoveredItem);
                        return;
                    }
                }
                
                this.hideTooltip();
            }
            
            showTooltip(e, item) {
                if (!this.tooltip) return;
                
                const value = parseFloat(item.value || item.total_amount || 0);
                this.tooltip.innerHTML = `
                    <strong>${item.label || item.payment_method || item.payment_status || item.bill_type}</strong><br>
                    Amount: ₵${value.toLocaleString()}<br>
                    Percentage: ${item.percentage}%
                `;
                
                this.tooltip.style.display = 'block';
                this.tooltip.style.left = (e.clientX + 10) + 'px';
                this.tooltip.style.top = (e.clientY - 10) + 'px';
            }
            
            hideTooltip() {
                if (this.tooltip) {
                    this.tooltip.style.display = 'none';
                }
            }
        }

        // Initialize charts when DOM is ready
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Initializing vanilla JavaScript charts...');
            
            // Payment Methods Chart
            const paymentMethodData = <?php echo json_encode($paymentMethodData); ?>;
            if (paymentMethodData && paymentMethodData.length > 0) {
                const methodChart = new VanillaChart('paymentMethodChart', 'pie');
                const processedMethodData = paymentMethodData.map(item => ({
                    label: item.payment_method,
                    value: item.total_amount
                }));
                methodChart.drawPieChart(processedMethodData);
                document.getElementById('paymentMethodChart').style.display = 'block';
            } else {
                document.getElementById('paymentMethodNoData').style.display = 'flex';
                document.getElementById('paymentMethodChart').style.display = 'none';
            }

            // Payment Status Chart  
            const paymentStatusData = <?php echo json_encode($paymentStatusData); ?>;
            if (paymentStatusData && paymentStatusData.length > 0) {
                const statusChart = new VanillaChart('paymentStatusChart', 'doughnut');
                const processedStatusData = paymentStatusData.map(item => ({
                    label: item.payment_status,
                    value: item.total_amount
                }));
                statusChart.drawDoughnutChart(processedStatusData);
                document.getElementById('paymentStatusChart').style.display = 'block';
            } else {
                document.getElementById('paymentStatusNoData').style.display = 'flex';
                document.getElementById('paymentStatusChart').style.display = 'none';
            }

            // Bill Type Chart
            const billTypeData = <?php echo json_encode($billTypeData); ?>;
            if (billTypeData && billTypeData.length > 0) {
                const billChart = new VanillaChart('billTypeChart', 'pie');
                const processedBillData = billTypeData.map(item => ({
                    label: item.bill_type + ' Bills',
                    value: item.total_amount
                }));
                billChart.drawPieChart(processedBillData);
                document.getElementById('billTypeChart').style.display = 'block';
            } else {
                document.getElementById('billTypeNoData').style.display = 'flex';
                document.getElementById('billTypeChart').style.display = 'none';
            }

            // Monthly Trend Chart
            const monthlyData = <?php echo json_encode($monthlyData); ?>;
            if (monthlyData && monthlyData.length > 0) {
                const trendChart = new VanillaChart('monthlyTrendChart', 'line');
                const datasets = [
                    {
                        label: 'Total Payments',
                        data: monthlyData.map(item => item.total_amount)
                    },
                    {
                        label: 'Successful Payments', 
                        data: monthlyData.map(item => item.successful_amount)
                    }
                ];
                trendChart.drawLineChart(monthlyData, datasets);
                document.getElementById('monthlyTrendChart').style.display = 'block';
            } else {
                document.getElementById('monthlyTrendNoData').style.display = 'flex';
                document.getElementById('monthlyTrendChart').style.display = 'none';
            }
        });

        // Sidebar toggle function
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('hidden');
        }

        // Export functions
        function exportToCSV() {
            // Create CSV data
            let csvData = "Payment Reference,Amount,Payer,Method,Bill Type,Date,Processed By\n";
            
            <?php foreach ($topPayments as $payment): ?>
                csvData += "<?php echo safeHtml($payment['payment_reference']); ?>,<?php echo floatval($payment['amount_paid']); ?>,\"<?php echo str_replace('"', '""', $payment['payer_name'] ?? ''); ?>\",<?php echo safeHtml($payment['payment_method']); ?>,<?php echo safeHtml($payment['bill_type']); ?>,<?php echo $payment['payment_date'] ? date('Y-m-d', strtotime($payment['payment_date'])) : ''; ?>,\"<?php echo str_replace('"', '""', trim($payment['processed_by_name'] ?? '')); ?>\"\n";
            <?php endforeach; ?>
            
            // Download CSV
            const blob = new Blob([csvData], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.style.display = 'none';
            a.href = url;
            a.download = `payment_report_<?php echo $year; ?><?php echo $month > 0 ? '_' . str_pad($month, 2, '0', STR_PAD_LEFT) : ''; ?>.csv`;
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
        }

        function printReport() {
            window.print();
        }

        // Check if Font Awesome loaded
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

        // Handle window resize for charts
        window.addEventListener('resize', function() {
            // Reinitialize charts on resize
            setTimeout(() => {
                location.reload(); // Simple solution for resize
            }, 250);
        });
    </script>
</body>
</html>