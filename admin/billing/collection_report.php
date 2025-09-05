<?php
/**
 * Collection Performance Report
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

$pageTitle = 'Collection Performance Report';
$currentUser = getCurrentUser();
$userDisplayName = getUserDisplayName($currentUser);

// Get filter parameters
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$zoneId = $_GET['zone_id'] ?? '';
$billType = $_GET['bill_type'] ?? '';
$paymentMethod = $_GET['payment_method'] ?? '';
$print = $_GET['print'] ?? '';

// Initialize variables
$collectionSummary = [];
$dailyCollections = [];
$methodBreakdown = [];
$collectorPerformance = [];
$zonePerformance = [];
$recentCollections = [];

try {
    $db = new Database();
    
    // Build WHERE clause for filters
    $whereConditions = ["p.payment_status = 'Successful'"];
    $params = [];
    
    if ($dateFrom) {
        $whereConditions[] = "DATE(p.payment_date) >= ?";
        $params[] = $dateFrom;
    }
    
    if ($dateTo) {
        $whereConditions[] = "DATE(p.payment_date) <= ?";
        $params[] = $dateTo;
    }
    
    if ($zoneId) {
        $whereConditions[] = "(
            (b.bill_type = 'Business' AND bs.zone_id = ?) OR
            (b.bill_type = 'Property' AND pr.zone_id = ?)
        )";
        $params[] = $zoneId;
        $params[] = $zoneId;
    }
    
    if ($billType) {
        $whereConditions[] = "b.bill_type = ?";
        $params[] = $billType;
    }
    
    if ($paymentMethod) {
        $whereConditions[] = "p.payment_method = ?";
        $params[] = $paymentMethod;
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Get collection summary
    $collectionSummary = $db->fetchRow("
        SELECT 
            COUNT(DISTINCT p.payment_id) as total_transactions,
            COUNT(DISTINCT b.bill_id) as bills_paid,
            SUM(p.amount_paid) as total_collected,
            AVG(p.amount_paid) as average_payment,
            MIN(p.amount_paid) as min_payment,
            MAX(p.amount_paid) as max_payment,
            COUNT(DISTINCT p.processed_by) as active_collectors,
            COUNT(DISTINCT DATE(p.payment_date)) as collection_days
        FROM payments p
        JOIN bills b ON p.bill_id = b.bill_id
        LEFT JOIN businesses bs ON b.bill_type = 'Business' AND b.reference_id = bs.business_id
        LEFT JOIN properties pr ON b.bill_type = 'Property' AND b.reference_id = pr.property_id
        WHERE $whereClause
    ", $params);
    
    // Calculate collection rate
    $totalBillsAmount = $db->fetchRow("
        SELECT 
            COUNT(*) as total_bills,
            SUM(b.amount_payable) as total_billable
        FROM bills b
        LEFT JOIN businesses bs ON b.bill_type = 'Business' AND b.reference_id = bs.business_id
        LEFT JOIN properties pr ON b.bill_type = 'Property' AND b.reference_id = pr.property_id
        WHERE DATE(b.generated_at) BETWEEN ? AND ?
        " . ($zoneId ? " AND ((b.bill_type = 'Business' AND bs.zone_id = $zoneId) OR (b.bill_type = 'Property' AND pr.zone_id = $zoneId))" : "") .
        ($billType ? " AND b.bill_type = '$billType'" : ""),
        [$dateFrom, $dateTo]
    );
    
    $collectionRate = 0;
    if ($totalBillsAmount['total_billable'] > 0) {
        $collectionRate = round(($collectionSummary['total_collected'] / $totalBillsAmount['total_billable']) * 100, 2);
    }
    
    // Get daily collections
    $dailyCollections = $db->fetchAll("
        SELECT 
            DATE(p.payment_date) as collection_date,
            COUNT(p.payment_id) as transaction_count,
            SUM(p.amount_paid) as daily_total,
            AVG(p.amount_paid) as daily_average,
            COUNT(DISTINCT p.processed_by) as collectors_active
        FROM payments p
        JOIN bills b ON p.bill_id = b.bill_id
        LEFT JOIN businesses bs ON b.bill_type = 'Business' AND b.reference_id = bs.business_id
        LEFT JOIN properties pr ON b.bill_type = 'Property' AND b.reference_id = pr.property_id
        WHERE $whereClause
        GROUP BY DATE(p.payment_date)
        ORDER BY collection_date DESC
        LIMIT 30
    ", $params);
    
    // Get payment method breakdown
    $methodBreakdown = $db->fetchAll("
        SELECT 
            p.payment_method,
            COUNT(p.payment_id) as transaction_count,
            SUM(p.amount_paid) as total_amount,
            AVG(p.amount_paid) as average_amount,
            COUNT(DISTINCT DATE(p.payment_date)) as active_days
        FROM payments p
        JOIN bills b ON p.bill_id = b.bill_id
        LEFT JOIN businesses bs ON b.bill_type = 'Business' AND b.reference_id = bs.business_id
        LEFT JOIN properties pr ON b.bill_type = 'Property' AND b.reference_id = pr.property_id
        WHERE $whereClause
        GROUP BY p.payment_method
        ORDER BY total_amount DESC
    ", $params);
    
    // Get collector performance
    $collectorPerformance = $db->fetchAll("
        SELECT 
            u.first_name,
            u.last_name,
            u.user_id,
            COUNT(p.payment_id) as transaction_count,
            SUM(p.amount_paid) as total_collected,
            AVG(p.amount_paid) as average_transaction,
            COUNT(DISTINCT DATE(p.payment_date)) as active_days,
            COUNT(DISTINCT b.bill_id) as unique_bills,
            MAX(p.payment_date) as last_collection
        FROM payments p
        JOIN bills b ON p.bill_id = b.bill_id
        JOIN users u ON p.processed_by = u.user_id
        LEFT JOIN businesses bs ON b.bill_type = 'Business' AND b.reference_id = bs.business_id
        LEFT JOIN properties pr ON b.bill_type = 'Property' AND b.reference_id = pr.property_id
        WHERE $whereClause
        GROUP BY u.user_id, u.first_name, u.last_name
        ORDER BY total_collected DESC
        LIMIT 10
    ", $params);
    
    // Get zone performance
    $zonePerformance = $db->fetchAll("
        SELECT 
            z.zone_name,
            z.zone_id,
            COUNT(p.payment_id) as transaction_count,
            SUM(p.amount_paid) as total_collected,
            AVG(p.amount_paid) as average_transaction,
            COUNT(DISTINCT b.bill_id) as unique_bills,
            COUNT(DISTINCT p.processed_by) as collectors_involved
        FROM payments p
        JOIN bills b ON p.bill_id = b.bill_id
        LEFT JOIN businesses bs ON b.bill_type = 'Business' AND b.reference_id = bs.business_id
        LEFT JOIN properties pr ON b.bill_type = 'Property' AND b.reference_id = pr.property_id
        LEFT JOIN zones z ON (
            (b.bill_type = 'Business' AND bs.zone_id = z.zone_id) OR
            (b.bill_type = 'Property' AND pr.zone_id = z.zone_id)
        )
        WHERE $whereClause AND z.zone_id IS NOT NULL
        GROUP BY z.zone_id, z.zone_name
        ORDER BY total_collected DESC
        LIMIT 10
    ", $params);
    
    // Get recent collections
    $recentCollections = $db->fetchAll("
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
            CASE 
                WHEN b.bill_type = 'Business' THEN bs.account_number
                WHEN b.bill_type = 'Property' THEN pr.property_number
            END as account_number,
            u.first_name as collector_name,
            z.zone_name
        FROM payments p
        JOIN bills b ON p.bill_id = b.bill_id
        LEFT JOIN businesses bs ON b.bill_type = 'Business' AND b.reference_id = bs.business_id
        LEFT JOIN properties pr ON b.bill_type = 'Property' AND b.reference_id = pr.property_id
        LEFT JOIN users u ON p.processed_by = u.user_id
        LEFT JOIN zones z ON (
            (b.bill_type = 'Business' AND bs.zone_id = z.zone_id) OR
            (b.bill_type = 'Property' AND pr.zone_id = z.zone_id)
        )
        WHERE $whereClause
        ORDER BY p.payment_date DESC
        LIMIT 100
    ", $params);
    
    // Get zones and payment methods for filter dropdowns
    $zones = $db->fetchAll("SELECT zone_id, zone_name FROM zones ORDER BY zone_name");
    $paymentMethods = $db->fetchAll("
        SELECT DISTINCT payment_method 
        FROM payments 
        WHERE payment_method IS NOT NULL AND payment_method != ''
        ORDER BY payment_method
    ");
    
} catch (Exception $e) {
    writeLog("Collection report error: " . $e->getMessage(), 'ERROR');
    setFlashMessage('error', 'An error occurred while loading collection data.');
}

// Handle print mode
if ($print === '1') {
    // Add print-specific styles
    echo '<style>@media print { .no-print { display: none !important; } }</style>';
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
        .icon-money::before { content: "💰"; }
        .icon-chart::before { content: "📊"; }
        .icon-calendar::before { content: "📅"; }
        .icon-download::before { content: "⬇️"; }
        .icon-filter::before { content: "🔍"; }
        .icon-print::before { content: "🖨️"; }
        .icon-back::before { content: "↩️"; }
        .icon-receipt::before { content: "🧾"; }
        .icon-bell::before { content: "🔔"; }
        .icon-users::before { content: "👥"; }
        .icon-target::before { content: "🎯"; }
        .icon-trending::before { content: "📈"; }
        .icon-clock::before { content: "⏰"; }
        .icon-star::before { content: "⭐"; }
        .icon-map::before { content: "🗺️"; }
        
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
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border: 1px solid #7dd3fc;
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
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
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
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 24px;
            box-shadow: 0 8px 25px rgba(14, 165, 233, 0.3);
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
            border-color: #0ea5e9;
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1);
        }
        
        /* Performance Cards */
        .performance-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .performance-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid #0ea5e9;
            transition: all 0.3s;
        }
        
        .performance-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        
        .performance-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .performance-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
        }
        
        .performance-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }
        
        .performance-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 12px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .performance-amount {
            font-family: monospace;
            color: #0284c7;
        }
        
        .performance-rate {
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
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
        }
        
        /* Performance Lists */
        .performance-list {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .performer-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .performer-item:last-child {
            border-bottom: none;
        }
        
        .performer-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .performer-rank {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background: #0ea5e9;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
        }
        
        .performer-rank.top3 {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }
        
        .performer-details {
            flex: 1;
        }
        
        .performer-name {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 5px;
        }
        
        .performer-stats {
            font-size: 12px;
            color: #64748b;
        }
        
        .performer-amount {
            text-align: right;
        }
        
        .performer-total {
            font-size: 18px;
            font-weight: bold;
            color: #0284c7;
            font-family: monospace;
        }
        
        .performer-transactions {
            font-size: 12px;
            color: #64748b;
            margin-top: 5px;
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
        
        .method-badge {
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .method-cash {
            background: #d1fae5;
            color: #065f46;
        }
        
        .method-mobile-money {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .method-bank-transfer {
            background: #fef3c7;
            color: #92400e;
        }
        
        .method-online {
            background: #f3e8ff;
            color: #7c3aed;
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
            background: #0ea5e9;
            color: white;
        }
        
        .btn-primary:hover {
            background: #0284c7;
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
            color: #0ea5e9;
            border: 2px solid #0ea5e9;
        }
        
        .btn-outline:hover {
            background: #0ea5e9;
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
            
            .performance-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* Print Styles */
        @media print {
            .no-print {
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
    <div class="top-nav no-print">
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
        <div class="breadcrumb-nav no-print">
            <div class="breadcrumb">
                <a href="../index.php">Dashboard</a>
                <span>/</span>
                <a href="index.php">Billing</a>
                <span>/</span>
                <a href="reports.php">Reports</a>
                <span>/</span>
                <span style="color: #2d3748; font-weight: 600;">Collection Performance</span>
            </div>
        </div>

        <!-- Flash Messages -->
        <?php if ($flashMessage): ?>
            <div class="alert alert-<?php echo $flashMessage['type']; ?> no-print">
                <i class="fas fa-<?php echo $flashMessage['type'] === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                <div><?php echo htmlspecialchars($flashMessage['message']); ?></div>
            </div>
        <?php endif; ?>

        <!-- Report Header -->
        <div class="report-header">
            <div class="header-content">
                <div class="header-info">
                    <div class="header-avatar">
                        <i class="fas fa-hand-holding-usd"></i>
                        <span class="icon-money" style="display: none;"></span>
                    </div>
                    <div class="header-details">
                        <h1>Collection Performance Report</h1>
                        <div class="header-description">
                            Payment collection analysis from <?php echo date('M j, Y', strtotime($dateFrom)); ?> 
                            to <?php echo date('M j, Y', strtotime($dateTo)); ?>
                        </div>
                    </div>
                </div>
                
                <div class="header-actions no-print">
                    <button onclick="window.print()" class="btn btn-outline">
                        <i class="fas fa-print"></i>
                        <span class="icon-print" style="display: none;"></span>
                        Print Report
                    </button>
                    <a href="collection_report.php?<?php echo http_build_query(array_merge($_GET, ['export' => 'excel'])); ?>" class="btn btn-primary">
                        <i class="fas fa-download"></i>
                        <span class="icon-download" style="display: none;"></span>
                        Export Excel
                    </a>
                </div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section no-print">
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
                    <label class="form-label">Bill Type</label>
                    <select name="bill_type" class="form-control">
                        <option value="">All Types</option>
                        <option value="Business" <?php echo $billType === 'Business' ? 'selected' : ''; ?>>Business</option>
                        <option value="Property" <?php echo $billType === 'Property' ? 'selected' : ''; ?>>Property</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Payment Method</label>
                    <select name="payment_method" class="form-control">
                        <option value="">All Methods</option>
                        <?php foreach ($paymentMethods as $method): ?>
                            <option value="<?php echo $method['payment_method']; ?>" <?php echo $paymentMethod === $method['payment_method'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($method['payment_method']); ?>
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

        <!-- Performance Summary Cards -->
        <div class="performance-grid">
            <div class="performance-card">
                <div class="performance-header">
                    <div class="performance-title">Total Collections</div>
                    <div class="performance-icon">
                        <i class="fas fa-dollar-sign"></i>
                        <span class="icon-money" style="display: none;"></span>
                    </div>
                </div>
                <div class="performance-stats">
                    <div class="stat-item" style="grid-column: span 2;">
                        <div class="stat-value performance-amount">GHS <?php echo number_format($collectionSummary['total_collected'] ?? 0, 2); ?></div>
                        <div class="stat-label">Total Collected</div>
                    </div>
                </div>
            </div>
            
            <div class="performance-card">
                <div class="performance-header">
                    <div class="performance-title">Transaction Volume</div>
                    <div class="performance-icon">
                        <i class="fas fa-chart-line"></i>
                        <span class="icon-trending" style="display: none;"></span>
                    </div>
                </div>
                <div class="performance-stats">
                    <div class="stat-item">
                        <div class="stat-value"><?php echo number_format($collectionSummary['total_transactions'] ?? 0); ?></div>
                        <div class="stat-label">Transactions</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo number_format($collectionSummary['bills_paid'] ?? 0); ?></div>
                        <div class="stat-label">Bills Paid</div>
                    </div>
                </div>
            </div>
            
            <div class="performance-card">
                <div class="performance-header">
                    <div class="performance-title">Collection Rate</div>
                    <div class="performance-icon">
                        <i class="fas fa-bullseye"></i>
                        <span class="icon-target" style="display: none;"></span>
                    </div>
                </div>
                <div class="performance-stats">
                    <div class="stat-item" style="grid-column: span 2;">
                        <div class="stat-value performance-rate"><?php echo $collectionRate; ?>%</div>
                        <div class="stat-label">Collection Rate</div>
                    </div>
                </div>
            </div>
            
            <div class="performance-card">
                <div class="performance-header">
                    <div class="performance-title">Average Transaction</div>
                    <div class="performance-icon">
                        <i class="fas fa-calculator"></i>
                    </div>
                </div>
                <div class="performance-stats">
                    <div class="stat-item" style="grid-column: span 2;">
                        <div class="stat-value performance-amount">GHS <?php echo number_format($collectionSummary['average_payment'] ?? 0, 2); ?></div>
                        <div class="stat-label">Average Payment</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Section -->
        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 30px; margin-bottom: 30px;">
            <!-- Daily Collections Chart -->
            <?php if (!empty($dailyCollections)): ?>
            <div class="chart-container">
                <div class="chart-header">
                    <div class="chart-title">
                        <div class="chart-icon">
                            <i class="fas fa-chart-line"></i>
                            <span class="icon-chart" style="display: none;"></span>
                        </div>
                        Daily Collection Trends (Last 30 days)
                    </div>
                </div>
                <canvas id="dailyCollectionsChart" height="100"></canvas>
            </div>
            <?php endif; ?>
            
            <!-- Payment Methods Chart -->
            <?php if (!empty($methodBreakdown)): ?>
            <div class="chart-container">
                <div class="chart-header">
                    <div class="chart-title">
                        <div class="chart-icon">
                            <i class="fas fa-chart-pie"></i>
                        </div>
                        Payment Methods
                    </div>
                </div>
                <canvas id="paymentMethodsChart" height="150"></canvas>
            </div>
            <?php endif; ?>
        </div>

        <!-- Performance Lists -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 30px;">
            <!-- Top Collectors -->
            <?php if (!empty($collectorPerformance)): ?>
            <div class="performance-list">
                <div class="chart-header">
                    <div class="chart-title">
                        <div class="chart-icon">
                            <i class="fas fa-star"></i>
                            <span class="icon-star" style="display: none;"></span>
                        </div>
                        Top Performing Collectors
                    </div>
                </div>
                
                <?php foreach ($collectorPerformance as $index => $collector): ?>
                    <div class="performer-item">
                        <div class="performer-info">
                            <div class="performer-rank <?php echo $index < 3 ? 'top3' : ''; ?>"><?php echo $index + 1; ?></div>
                            <div class="performer-details">
                                <div class="performer-name"><?php echo htmlspecialchars($collector['first_name'] . ' ' . $collector['last_name']); ?></div>
                                <div class="performer-stats">
                                    <?php echo number_format($collector['transaction_count']); ?> transactions • 
                                    <?php echo $collector['active_days']; ?> active days • 
                                    Avg: GHS <?php echo number_format($collector['average_transaction'], 2); ?>
                                </div>
                            </div>
                        </div>
                        <div class="performer-amount">
                            <div class="performer-total">GHS <?php echo number_format($collector['total_collected'], 2); ?></div>
                            <div class="performer-transactions"><?php echo number_format($collector['unique_bills']); ?> bills</div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <!-- Top Zones -->
            <?php if (!empty($zonePerformance)): ?>
            <div class="performance-list">
                <div class="chart-header">
                    <div class="chart-title">
                        <div class="chart-icon">
                            <i class="fas fa-map-marked-alt"></i>
                            <span class="icon-map" style="display: none;"></span>
                        </div>
                        Top Performing Zones
                    </div>
                </div>
                
                <?php foreach ($zonePerformance as $index => $zone): ?>
                    <div class="performer-item">
                        <div class="performer-info">
                            <div class="performer-rank <?php echo $index < 3 ? 'top3' : ''; ?>"><?php echo $index + 1; ?></div>
                            <div class="performer-details">
                                <div class="performer-name"><?php echo htmlspecialchars($zone['zone_name']); ?></div>
                                <div class="performer-stats">
                                    <?php echo number_format($zone['transaction_count']); ?> transactions • 
                                    <?php echo $zone['collectors_involved']; ?> collectors • 
                                    Avg: GHS <?php echo number_format($zone['average_transaction'], 2); ?>
                                </div>
                            </div>
                        </div>
                        <div class="performer-amount">
                            <div class="performer-total">GHS <?php echo number_format($zone['total_collected'], 2); ?></div>
                            <div class="performer-transactions"><?php echo number_format($zone['unique_bills']); ?> bills</div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Recent Collections -->
        <div class="chart-container">
            <div class="chart-header">
                <div class="chart-title">
                    <div class="chart-icon">
                        <i class="fas fa-clock"></i>
                        <span class="icon-clock" style="display: none;"></span>
                    </div>
                    Recent Collections (Last 100 transactions)
                </div>
                <div class="no-print">
                    <a href="#" onclick="exportToCSV()" class="btn btn-outline">
                        <i class="fas fa-download"></i>
                        Export CSV
                    </a>
                </div>
            </div>

            <?php if (empty($recentCollections)): ?>
                <div style="text-align: center; padding: 40px; color: #64748b;">
                    <i class="fas fa-hand-holding-usd" style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;"></i>
                    <h3>No Collections Found</h3>
                    <p>No payment collections found for the selected filters.</p>
                </div>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Payment Ref</th>
                            <th>Payer</th>
                            <th>Bill Type</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Zone</th>
                            <th>Collector</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentCollections as $collection): ?>
                            <tr>
                                <td style="font-family: monospace; font-weight: 600;"><?php echo htmlspecialchars($collection['payment_reference']); ?></td>
                                <td>
                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($collection['payer_name'] ?? 'Unknown'); ?></div>
                                    <div style="font-size: 12px; color: #64748b;"><?php echo htmlspecialchars($collection['account_number'] ?? ''); ?></div>
                                </td>
                                <td>
                                    <span class="bill-type-badge type-<?php echo strtolower($collection['bill_type']); ?>">
                                        <?php echo htmlspecialchars($collection['bill_type']); ?>
                                    </span>
                                </td>
                                <td class="amount">GHS <?php echo number_format($collection['amount_paid'], 2); ?></td>
                                <td>
                                    <span class="method-badge method-<?php echo strtolower(str_replace(' ', '-', $collection['payment_method'])); ?>">
                                        <?php echo htmlspecialchars($collection['payment_method']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($collection['zone_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($collection['collector_name'] ?? 'System'); ?></td>
                                <td><?php echo date('M j, Y H:i', strtotime($collection['payment_date'])); ?></td>
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
            // Daily Collections Chart
            <?php if (!empty($dailyCollections)): ?>
            const dailyCtx = document.getElementById('dailyCollectionsChart').getContext('2d');
            
            // Reverse array for chronological order
            const dailyData = <?php echo json_encode(array_reverse($dailyCollections)); ?>;
            
            new Chart(dailyCtx, {
                type: 'line',
                data: {
                    labels: dailyData.map(item => {
                        const date = new Date(item.collection_date);
                        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                    }),
                    datasets: [{
                        label: 'Daily Collections',
                        data: dailyData.map(item => parseFloat(item.daily_total)),
                        borderColor: '#0ea5e9',
                        backgroundColor: 'rgba(14, 165, 233, 0.1)',
                        tension: 0.4,
                        fill: true
                    }, {
                        label: 'Transaction Count',
                        data: dailyData.map(item => parseInt(item.transaction_count)),
                        borderColor: '#f59e0b',
                        backgroundColor: 'rgba(245, 158, 11, 0.1)',
                        tension: 0.4,
                        fill: false,
                        yAxisID: 'y1'
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return 'GHS ' + value.toLocaleString();
                                }
                            }
                        },
                        y1: {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            beginAtZero: true,
                            grid: {
                                drawOnChartArea: false,
                            },
                        }
                    },
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    if (context.datasetIndex === 0) {
                                        return 'Collections: GHS ' + context.parsed.y.toLocaleString();
                                    } else {
                                        return 'Transactions: ' + context.parsed.y;
                                    }
                                }
                            }
                        }
                    }
                }
            });
            <?php endif; ?>

            // Payment Methods Chart
            <?php if (!empty($methodBreakdown)): ?>
            const methodCtx = document.getElementById('paymentMethodsChart').getContext('2d');
            new Chart(methodCtx, {
                type: 'doughnut',
                data: {
                    labels: [<?php echo "'" . implode("', '", array_column($methodBreakdown, 'payment_method')) . "'"; ?>],
                    datasets: [{
                        data: [<?php echo implode(', ', array_column($methodBreakdown, 'total_amount')); ?>],
                        backgroundColor: ['#0ea5e9', '#10b981', '#f59e0b', '#8b5cf6', '#ef4444'],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom'
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
            // This would implement CSV export functionality
            alert('CSV export functionality will be implemented soon.');
        }
    </script>
</body>
</html>