<?php
/**
 * Revenue Analysis Report - FIXED
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
if (!hasPermission('reports.view')) {
    setFlashMessage('error', 'Access denied. You do not have permission to view reports.');
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

$pageTitle = 'Revenue Analysis Report';
$currentUser = getCurrentUser();
$userDisplayName = getUserDisplayName($currentUser);

// Get filter parameters
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$billType = $_GET['bill_type'] ?? '';
$zoneId = $_GET['zone_id'] ?? '';
$export = $_GET['export'] ?? '';

// Initialize variables
$revenueData = [];
$monthlyTrends = [];
$typeBreakdown = [];
$zoneBreakdown = [];
$totalRevenue = 0;
$totalBills = 0;
$averageBill = 0;

try {
    $db = new Database();
    
    // Build WHERE clause for filters
    $whereConditions = ["1 = 1"];
    $params = [];
    
    if ($dateFrom) {
        $whereConditions[] = "DATE(b.generated_at) >= ?";
        $params[] = $dateFrom;
    }
    
    if ($dateTo) {
        $whereConditions[] = "DATE(b.generated_at) <= ?";
        $params[] = $dateTo;
    }
    
    if ($billType) {
        $whereConditions[] = "b.bill_type = ?";
        $params[] = $billType;
    }
    
    if ($zoneId) {
        $whereConditions[] = "(
            (b.bill_type = 'Business' AND bs.zone_id = ?) OR
            (b.bill_type = 'Property' AND pr.zone_id = ?)
        )";
        $params[] = $zoneId;
        $params[] = $zoneId;
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Get overall revenue statistics with FIXED collected revenue calculation
    $revenueStats = $db->fetchRow("
        SELECT 
            COUNT(*) as total_bills,
            COALESCE(SUM(b.amount_payable), 0) as total_revenue,
            COALESCE(SUM(b.current_bill), 0) as total_current_bill,
            COALESCE(SUM(payments.total_paid), 0) as collected_revenue,
            COALESCE(SUM(b.amount_payable - COALESCE(payments.total_paid, 0)), 0) as pending_revenue,
            COALESCE(AVG(b.amount_payable), 0) as average_bill
        FROM bills b
        LEFT JOIN businesses bs ON b.bill_type = 'Business' AND b.reference_id = bs.business_id
        LEFT JOIN properties pr ON b.bill_type = 'Property' AND b.reference_id = pr.property_id
        LEFT JOIN (
            SELECT 
                bill_id, 
                SUM(CASE WHEN payment_status = 'Successful' THEN amount_paid ELSE 0 END) as total_paid
            FROM payments 
            GROUP BY bill_id
        ) payments ON b.bill_id = payments.bill_id
        WHERE $whereClause
    ", $params);
    
    $totalRevenue = $revenueStats['total_revenue'] ?? 0;
    $totalBills = $revenueStats['total_bills'] ?? 0;
    $averageBill = $revenueStats['average_bill'] ?? 0;
    $collectedRevenue = $revenueStats['collected_revenue'] ?? 0;
    $pendingRevenue = $revenueStats['pending_revenue'] ?? 0;
    
    // Get monthly revenue trends with FIXED calculations
    $monthlyTrends = $db->fetchAll("
        SELECT 
            DATE_FORMAT(b.generated_at, '%Y-%m') as month,
            COUNT(*) as bills_count,
            SUM(b.amount_payable) as total_revenue,
            SUM(COALESCE(payments.total_paid, 0)) as collected_revenue,
            AVG(b.amount_payable) as average_bill
        FROM bills b
        LEFT JOIN businesses bs ON b.bill_type = 'Business' AND b.reference_id = bs.business_id
        LEFT JOIN properties pr ON b.bill_type = 'Property' AND b.reference_id = pr.property_id
        LEFT JOIN (
            SELECT 
                bill_id, 
                SUM(CASE WHEN payment_status = 'Successful' THEN amount_paid ELSE 0 END) as total_paid
            FROM payments 
            GROUP BY bill_id
        ) payments ON b.bill_id = payments.bill_id
        WHERE $whereClause
        GROUP BY DATE_FORMAT(b.generated_at, '%Y-%m')
        ORDER BY month ASC
    ", $params);
    
    // Get revenue by bill type with FIXED calculations
    $typeBreakdown = $db->fetchAll("
        SELECT 
            b.bill_type,
            COUNT(*) as bills_count,
            SUM(b.amount_payable) as total_revenue,
            SUM(COALESCE(payments.total_paid, 0)) as collected_revenue,
            AVG(b.amount_payable) as average_bill
        FROM bills b
        LEFT JOIN businesses bs ON b.bill_type = 'Business' AND b.reference_id = bs.business_id
        LEFT JOIN properties pr ON b.bill_type = 'Property' AND b.reference_id = pr.property_id
        LEFT JOIN (
            SELECT 
                bill_id, 
                SUM(CASE WHEN payment_status = 'Successful' THEN amount_paid ELSE 0 END) as total_paid
            FROM payments 
            GROUP BY bill_id
        ) payments ON b.bill_id = payments.bill_id
        WHERE $whereClause
        GROUP BY b.bill_type
        ORDER BY total_revenue DESC
    ", $params);
    
    // Get revenue by zone with FIXED calculations
    $zoneBreakdown = $db->fetchAll("
        SELECT 
            z.zone_name,
            z.zone_id,
            COUNT(*) as bills_count,
            SUM(b.amount_payable) as total_revenue,
            SUM(COALESCE(payments.total_paid, 0)) as collected_revenue,
            AVG(b.amount_payable) as average_bill
        FROM bills b
        LEFT JOIN businesses bs ON b.bill_type = 'Business' AND b.reference_id = bs.business_id
        LEFT JOIN properties pr ON b.bill_type = 'Property' AND b.reference_id = pr.property_id
        LEFT JOIN zones z ON (
            (b.bill_type = 'Business' AND bs.zone_id = z.zone_id) OR
            (b.bill_type = 'Property' AND pr.zone_id = z.zone_id)
        )
        LEFT JOIN (
            SELECT 
                bill_id, 
                SUM(CASE WHEN payment_status = 'Successful' THEN amount_paid ELSE 0 END) as total_paid
            FROM payments 
            GROUP BY bill_id
        ) payments ON b.bill_id = payments.bill_id
        WHERE $whereClause AND z.zone_id IS NOT NULL
        GROUP BY z.zone_id, z.zone_name
        ORDER BY total_revenue DESC
    ", $params);
    
    // Get detailed revenue data for table with payment information
    $revenueData = $db->fetchAll("
        SELECT 
            b.bill_number,
            b.bill_type,
            b.amount_payable,
            b.status,
            b.generated_at,
            COALESCE(payments.total_paid, 0) as amount_paid,
            CASE 
                WHEN b.bill_type = 'Business' THEN bs.business_name
                WHEN b.bill_type = 'Property' THEN pr.owner_name
            END as payer_name,
            CASE 
                WHEN b.bill_type = 'Business' THEN bs.account_number
                WHEN b.bill_type = 'Property' THEN pr.property_number
            END as account_number,
            z.zone_name,
            CASE 
                WHEN COALESCE(payments.total_paid, 0) >= b.amount_payable THEN 'Fully Paid'
                WHEN COALESCE(payments.total_paid, 0) > 0 THEN 'Partially Paid'
                ELSE 'Pending'
            END as payment_status
        FROM bills b
        LEFT JOIN businesses bs ON b.bill_type = 'Business' AND b.reference_id = bs.business_id
        LEFT JOIN properties pr ON b.bill_type = 'Property' AND b.reference_id = pr.property_id
        LEFT JOIN zones z ON (
            (b.bill_type = 'Business' AND bs.zone_id = z.zone_id) OR
            (b.bill_type = 'Property' AND pr.zone_id = z.zone_id)
        )
        LEFT JOIN (
            SELECT 
                bill_id, 
                SUM(CASE WHEN payment_status = 'Successful' THEN amount_paid ELSE 0 END) as total_paid
            FROM payments 
            GROUP BY bill_id
        ) payments ON b.bill_id = payments.bill_id
        WHERE $whereClause
        ORDER BY b.generated_at DESC
        LIMIT 100
    ", $params);
    
    // Get zones for filter dropdown
    $zones = $db->fetchAll("SELECT zone_id, zone_name FROM zones ORDER BY zone_name");
    
} catch (Exception $e) {
    writeLog("Revenue report error: " . $e->getMessage(), 'ERROR');
    setFlashMessage('error', 'An error occurred while loading revenue data.');
}

// Handle PDF export
if ($export === 'pdf') {
    // Here you would implement PDF generation
    // For now, we'll just redirect back with a message
    setFlashMessage('info', 'PDF export functionality will be implemented soon.');
    header('Location: revenue_report.php?' . http_build_query($_GET));
    exit();
}

// Get flash messages
$flashMessages = getFlashMessages();
$flashMessage = !empty($flashMessages) ? $flashMessages[0] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - <?php echo APP_NAME; ?></title>
    
    <!-- Icon Sources -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
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
        
        /* Custom Icons (fallback) */
        .icon-chart::before { content: "📊"; }
        .icon-money::before { content: "💰"; }
        .icon-calendar::before { content: "📅"; }
        .icon-download::before { content: "⬇️"; }
        .icon-filter::before { content: "🔍"; }
        .icon-print::before { content: "🖨️"; }
        .icon-back::before { content: "↩️"; }
        .icon-receipt::before { content: "🧾"; }
        .icon-menu::before { content: "☰"; }
        .icon-logout::before { content: "🚪"; }
        .icon-bell::before { content: "🔔"; }
        .icon-cog::before { content: "⚙️"; }
        
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
        
        /* Main Layout */
        .main-container {
            margin-top: 80px;
            padding: 30px;
            background: #f8f9fa;
            min-height: calc(100vh - 80px);
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
        
        /* Header */
        .report-header {
            background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
            border: 1px solid #9ae6b4;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
        }
        
        .report-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }
        
        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .header-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .header-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 24px;
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.3);
        }
        
        .header-details h1 {
            font-size: 24px;
            font-weight: bold;
            color: #2d3748;
            margin: 0 0 5px 0;
        }
        
        .header-description {
            color: #64748b;
            font-size: 16px;
        }
        
        .header-actions {
            display: flex;
            gap: 15px;
        }
        
        /* Filter Section */
        .filter-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
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
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #10b981;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
        }
        
        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid #10b981;
            transition: all 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .stat-title {
            font-size: 14px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 5px;
        }
        
        .stat-change {
            font-size: 14px;
            color: #059669;
            font-weight: 600;
        }
        
        /* Charts */
        .chart-container {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .chart-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .chart-icon {
            width: 35px;
            height: 35px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
        }
        
        /* Data Table */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .data-table th {
            background: #f8fafc;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #2d3748;
            border-bottom: 1px solid #e2e8f0;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
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
        
        .status-badge {
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }
        
        .status-fully-paid {
            background: #d1fae5;
            color: #065f46;
        }
        
        .status-partially-paid {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .status-overdue {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .bill-type-badge {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .type-business {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .type-property {
            background: #d1fae5;
            color: #065f46;
        }
        
        .amount {
            font-weight: bold;
            font-family: monospace;
            color: #059669;
        }
        
        .amount-paid {
            font-weight: bold;
            font-family: monospace;
            color: #10b981;
            font-size: 12px;
        }
        
        /* Buttons */
        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            justify-content: center;
        }
        
        .btn-primary {
            background: #10b981;
            color: white;
        }
        
        .btn-primary:hover {
            background: #059669;
            color: white;
            text-decoration: none;
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: #6b7280;
            color: white;
        }
        
        .btn-outline {
            background: transparent;
            color: #10b981;
            border: 2px solid #10b981;
        }
        
        .btn-outline:hover {
            background: #10b981;
            color: white;
        }
        
        /* Alert */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background: #d1fae5;
            border: 1px solid #9ae6b4;
            color: #065f46;
        }
        
        .alert-error {
            background: #fee2e2;
            border: 1px solid #fca5a5;
            color: #991b1b;
        }
        
        .alert-info {
            background: #dbeafe;
            border: 1px solid #93c5fd;
            color: #1e40af;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .main-container {
                padding: 15px;
            }
            
            .header-content {
                flex-direction: column;
                gap: 20px;
            }
            
            .header-actions {
                width: 100%;
                justify-content: center;
            }
            
            .filter-form {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            /* Stack charts vertically on mobile */
            div[style*="grid-template-columns: 1fr 1fr"] {
                display: block !important;
            }
            
            div[style*="grid-template-columns: 1fr 1fr"] > div {
                margin-bottom: 20px;
            }
        }
        
        /* Print Styles */
        @media print {
            .top-nav, .filter-section, .header-actions {
                display: none !important;
            }
            
            .main-container {
                margin-top: 0;
                padding: 20px;
            }
            
            .chart-container {
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <div class="top-nav">
        <div class="nav-left">
            <a href="reports.php" class="toggle-btn" title="Back to Reports">
                <i class="fas fa-arrow-left"></i>
                <span class="icon-back" style="display: none;"></span>
            </a>
            
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
            
            <div class="user-profile">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($currentUser['first_name'], 0, 1)); ?>
                </div>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($userDisplayName); ?></div>
                    <div class="user-role"><?php echo htmlspecialchars(getCurrentUserRole()); ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-container">
        <!-- Breadcrumb -->
        <div class="breadcrumb-nav">
            <div class="breadcrumb">
                <a href="../index.php">Dashboard</a>
                <span>/</span>
                <a href="index.php">Billing</a>
                <span>/</span>
                <a href="reports.php">Reports</a>
                <span>/</span>
                <span style="color: #2d3748; font-weight: 600;">Revenue Analysis</span>
            </div>
        </div>

        <!-- Flash Messages -->
        <?php if ($flashMessage): ?>
            <div class="alert alert-<?php echo $flashMessage['type']; ?>">
                <i class="fas fa-<?php echo $flashMessage['type'] === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                <div><?php echo htmlspecialchars($flashMessage['message']); ?></div>
            </div>
        <?php endif; ?>

        <!-- Report Header -->
        <div class="report-header">
            <div class="header-content">
                <div class="header-info">
                    <div class="header-avatar">
                        <i class="fas fa-dollar-sign"></i>
                        <span class="icon-money" style="display: none;"></span>
                    </div>
                    <div class="header-details">
                        <h1>Revenue Analysis Report</h1>
                        <div class="header-description">
                            Comprehensive revenue analysis from <?php echo date('M j, Y', strtotime($dateFrom)); ?> 
                            to <?php echo date('M j, Y', strtotime($dateTo)); ?>
                        </div>
                    </div>
                </div>
                
                <div class="header-actions">
                    <button onclick="window.print()" class="btn btn-outline">
                        <i class="fas fa-print"></i>
                        <span class="icon-print" style="display: none;"></span>
                        Print Report
                    </button>
                    <a href="revenue_report.php?<?php echo http_build_query(array_merge($_GET, ['export' => 'pdf'])); ?>" class="btn btn-primary">
                        <i class="fas fa-download"></i>
                        <span class="icon-download" style="display: none;"></span>
                        Export PDF
                    </a>
                </div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <form class="filter-form" method="GET" action="">
                <div class="form-group">
                    <label class="form-label">Date From</label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($dateFrom); ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Date To</label>
                    <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($dateTo); ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Bill Type</label>
                    <select name="bill_type" class="form-control">
                        <option value="">All Types</option>
                        <option value="Business" <?php echo $billType === 'Business' ? 'selected' : ''; ?>>Business</option>
                        <option value="Property" <?php echo $billType === 'Property' ? 'selected' : ''; ?>>Property</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Zone</label>
                    <select name="zone_id" class="form-control">
                        <option value="">All Zones</option>
                        <?php foreach ($zones as $zone): ?>
                            <option value="<?php echo $zone['zone_id']; ?>" <?php echo $zoneId == $zone['zone_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($zone['zone_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i>
                        <span class="icon-filter" style="display: none;"></span>
                        Apply Filters
                    </button>
                </div>
            </form>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Total Revenue</div>
                    <div class="stat-icon">
                        <i class="fas fa-dollar-sign"></i>
                        <span class="icon-money" style="display: none;"></span>
                    </div>
                </div>
                <div class="stat-value">GHS <?php echo number_format($totalRevenue, 2); ?></div>
                <div class="stat-change">From <?php echo number_format($totalBills); ?> bills</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Collected Revenue</div>
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
                <div class="stat-value">GHS <?php echo number_format($collectedRevenue, 2); ?></div>
                <div class="stat-change">
                    <?php echo $totalRevenue > 0 ? round(($collectedRevenue / $totalRevenue) * 100, 1) : 0; ?>% collection rate
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Pending Revenue</div>
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                </div>
                <div class="stat-value">GHS <?php echo number_format($pendingRevenue, 2); ?></div>
                <div class="stat-change">
                    <?php echo $totalRevenue > 0 ? round(($pendingRevenue / $totalRevenue) * 100, 1) : 0; ?>% pending
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Average Bill</div>
                    <div class="stat-icon">
                        <i class="fas fa-calculator"></i>
                    </div>
                </div>
                <div class="stat-value">GHS <?php echo number_format($averageBill, 2); ?></div>
                <div class="stat-change">Per bill amount</div>
            </div>
        </div>

        <!-- Monthly Trends Chart -->
        <?php if (!empty($monthlyTrends)): ?>
        <div class="chart-container">
            <div class="chart-header">
                <div class="chart-title">
                    <div class="chart-icon">
                        <i class="fas fa-chart-line"></i>
                        <span class="icon-chart" style="display: none;"></span>
                    </div>
                    Monthly Revenue Trends
                </div>
            </div>
            <canvas id="monthlyTrendsChart" height="100"></canvas>
        </div>
        <?php endif; ?>

        <!-- Revenue by Type and Zone Charts -->
        <?php if (!empty($typeBreakdown)): ?>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 30px;">
            <div class="chart-container" style="height: fit-content;">
                <div class="chart-header">
                    <div class="chart-title">
                        <div class="chart-icon">
                            <i class="fas fa-chart-pie"></i>
                            <span class="icon-chart" style="display: none;"></span>
                        </div>
                        Revenue by Bill Type
                    </div>
                </div>
                <div style="height: 280px; padding: 10px;">
                    <canvas id="typeBreakdownChart" height="80" style="max-height: 250px;"></canvas>
                </div>
            </div>
            
            <div class="chart-container">
                <div class="chart-header">
                    <div class="chart-title">
                        <div class="chart-icon">
                            <i class="fas fa-map-marked-alt"></i>
                        </div>
                        Top Performing Zones
                    </div>
                </div>
                <div style="padding: 20px 0;">
                    <?php foreach (array_slice($zoneBreakdown, 0, 5) as $zone): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding: 10px; background: #f8fafc; border-radius: 8px;">
                            <div>
                                <div style="font-weight: 600; color: #2d3748;"><?php echo htmlspecialchars($zone['zone_name']); ?></div>
                                <div style="font-size: 12px; color: #64748b;"><?php echo number_format($zone['bills_count']); ?> bills</div>
                            </div>
                            <div style="text-align: right;">
                                <div style="font-weight: bold; color: #059669;">GHS <?php echo number_format($zone['total_revenue'], 2); ?></div>
                                <div style="font-size: 12px; color: #64748b;">
                                    <?php echo $totalRevenue > 0 ? round(($zone['total_revenue'] / $totalRevenue) * 100, 1) : 0; ?>%
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Detailed Revenue Data -->
        <div class="chart-container">
            <div class="chart-header">
                <div class="chart-title">
                    <div class="chart-icon">
                        <i class="fas fa-table"></i>
                    </div>
                    Recent Revenue Transactions (Last 100)
                </div>
                <div>
                    <a href="#" onclick="exportToCSV()" class="btn btn-outline">
                        <i class="fas fa-download"></i>
                        Export CSV
                    </a>
                </div>
            </div>

            <?php if (empty($revenueData)): ?>
                <div style="text-align: center; padding: 40px; color: #64748b;">
                    <i class="fas fa-chart-bar" style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;"></i>
                    <h3>No Revenue Data</h3>
                    <p>No revenue data found for the selected filters.</p>
                </div>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Bill Number</th>
                            <th>Payer</th>
                            <th>Type</th>
                            <th>Zone</th>
                            <th>Bill Amount</th>
                            <th>Amount Paid</th>
                            <th>Payment Status</th>
                            <th>Generated Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($revenueData as $data): ?>
                            <tr>
                                <td style="font-family: monospace; font-weight: 600;"><?php echo htmlspecialchars($data['bill_number']); ?></td>
                                <td>
                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($data['payer_name'] ?? 'Unknown'); ?></div>
                                    <div style="font-size: 12px; color: #64748b;"><?php echo htmlspecialchars($data['account_number'] ?? ''); ?></div>
                                </td>
                                <td>
                                    <span class="bill-type-badge type-<?php echo strtolower($data['bill_type']); ?>">
                                        <?php echo htmlspecialchars($data['bill_type']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($data['zone_name'] ?? 'N/A'); ?></td>
                                <td class="amount">GHS <?php echo number_format($data['amount_payable'], 2); ?></td>
                                <td class="amount-paid">GHS <?php echo number_format($data['amount_paid'], 2); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $data['payment_status'])); ?>">
                                        <?php echo htmlspecialchars($data['payment_status']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M j, Y', strtotime($data['generated_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
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
            
            // Initialize charts
            initializeCharts();
        });

        function initializeCharts() {
            // Monthly Trends Chart
            <?php if (!empty($monthlyTrends)): ?>
            const monthlyCtx = document.getElementById('monthlyTrendsChart').getContext('2d');
            new Chart(monthlyCtx, {
                type: 'line',
                data: {
                    labels: [<?php echo "'" . implode("', '", array_map(function($item) { return date('M Y', strtotime($item['month'] . '-01')); }, $monthlyTrends)) . "'"; ?>],
                    datasets: [{
                        label: 'Total Revenue',
                        data: [<?php echo implode(', ', array_column($monthlyTrends, 'total_revenue')); ?>],
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        tension: 0.4,
                        fill: true
                    }, {
                        label: 'Collected Revenue',
                        data: [<?php echo implode(', ', array_column($monthlyTrends, 'collected_revenue')); ?>],
                        borderColor: '#059669',
                        backgroundColor: 'rgba(5, 150, 105, 0.1)',
                        tension: 0.4,
                        fill: false
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return 'GHS ' + value.toLocaleString();
                                }
                            }
                        }
                    },
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': GHS ' + context.parsed.y.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
            <?php endif; ?>

            // Type Breakdown Chart
            <?php if (!empty($typeBreakdown)): ?>
            const typeCtx = document.getElementById('typeBreakdownChart').getContext('2d');
            new Chart(typeCtx, {
                type: 'doughnut',
                data: {
                    labels: [<?php echo "'" . implode("', '", array_column($typeBreakdown, 'bill_type')) . "'"; ?>],
                    datasets: [{
                        data: [<?php echo implode(', ', array_column($typeBreakdown, 'total_revenue')); ?>],
                        backgroundColor: ['#10b981', '#3b82f6', '#f59e0b', '#ef4444'],
                        borderWidth: 2,
                        borderColor: '#ffffff'
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
                                font: {
                                    size: 12
                                }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.label + ': GHS ' + context.parsed.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
            <?php endif; ?>
        }

        function exportToCSV() {
            // Create CSV content
            let csvContent = "data:text/csv;charset=utf-8,";
            csvContent += "Bill Number,Payer Name,Account Number,Bill Type,Zone,Bill Amount,Amount Paid,Payment Status,Generated Date\n";
            
            // Add table data
            const table = document.querySelector('.data-table tbody');
            const rows = table.querySelectorAll('tr');
            
            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                let rowData = [];
                
                // Extract data from each cell
                rowData.push('"' + cells[0].textContent.trim() + '"');
                rowData.push('"' + cells[1].querySelector('div').textContent.trim() + '"');
                rowData.push('"' + cells[1].querySelector('div:last-child').textContent.trim() + '"');
                rowData.push('"' + cells[2].textContent.trim() + '"');
                rowData.push('"' + cells[3].textContent.trim() + '"');
                rowData.push('"' + cells[4].textContent.trim() + '"');
                rowData.push('"' + cells[5].textContent.trim() + '"');
                rowData.push('"' + cells[6].textContent.trim() + '"');
                rowData.push('"' + cells[7].textContent.trim() + '"');
                
                csvContent += rowData.join(',') + '\n';
            });
            
            // Download CSV
            const encodedUri = encodeURI(csvContent);
            const link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", "revenue_report_" + new Date().toISOString().split('T')[0] + ".csv");
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    </script>
</body>
</html>