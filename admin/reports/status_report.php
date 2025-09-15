<?php
/**
 * Bills by Status Report
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

$pageTitle = 'Bills by Status Report';
$currentUser = getCurrentUser();
$userDisplayName = getUserDisplayName($currentUser);

// Get filter parameters
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$billType = $_GET['bill_type'] ?? '';
$zoneId = $_GET['zone_id'] ?? '';
$status = $_GET['status'] ?? '';
$export = $_GET['export'] ?? '';

// Export function - Define before data processing
function exportBillsStatusCSV($statusSummary, $statusBreakdown, $agingAnalysis, $detailedData, $dateFrom, $dateTo, $billType, $zoneId, $status, $zones) {
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Bills_Status_Report_' . date('Y-m-d_H-i-s') . '.csv"');
    
    // Create file pointer
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Report header
    fputcsv($output, ['QUICKBILL 305 - BILLS BY STATUS REPORT']);
    fputcsv($output, ['Generated on: ' . date('F j, Y g:i A')]);
    fputcsv($output, ['Report Period: ' . date('M j, Y', strtotime($dateFrom)) . ' to ' . date('M j, Y', strtotime($dateTo))]);
    
    // Filter information
    $filterInfo = 'Filters Applied: ';
    $filters = [];
    if ($billType) $filters[] = 'Bill Type: ' . $billType;
    if ($zoneId) {
        $zoneName = 'Unknown Zone';
        foreach ($zones as $zone) {
            if ($zone['zone_id'] == $zoneId) {
                $zoneName = $zone['zone_name'];
                break;
            }
        }
        $filters[] = 'Zone: ' . $zoneName;
    }
    if ($status) $filters[] = 'Status: ' . $status;
    if (empty($filters)) $filters[] = 'None';
    fputcsv($output, [$filterInfo . implode(', ', $filters)]);
    fputcsv($output, []); // Empty line
    
    // Status Summary Section
    fputcsv($output, ['STATUS SUMMARY']);
    fputcsv($output, ['Status', 'Bills Count', 'Total Amount (GHS)', 'Average Amount (GHS)', 'Min Amount (GHS)', 'Max Amount (GHS)', 'Percentage of Total']);
    
    $totalAmount = array_sum(array_column($statusSummary, 'total_amount'));
    foreach ($statusSummary as $statusData) {
        $percentage = $totalAmount > 0 ? round(($statusData['total_amount'] / $totalAmount) * 100, 2) : 0;
        fputcsv($output, [
            $statusData['status'],
            number_format($statusData['bills_count']),
            number_format($statusData['total_amount'], 2),
            number_format($statusData['average_amount'], 2),
            number_format($statusData['min_amount'], 2),
            number_format($statusData['max_amount'], 2),
            $percentage . '%'
        ]);
    }
    fputcsv($output, []); // Empty line
    
    // Status Breakdown by Bill Type
    if (!empty($statusBreakdown)) {
        fputcsv($output, ['STATUS BREAKDOWN BY BILL TYPE']);
        fputcsv($output, ['Status', 'Bill Type', 'Bills Count', 'Total Amount (GHS)', 'Average Amount (GHS)']);
        foreach ($statusBreakdown as $breakdown) {
            fputcsv($output, [
                $breakdown['status'],
                $breakdown['bill_type'],
                number_format($breakdown['bills_count']),
                number_format($breakdown['total_amount'], 2),
                number_format($breakdown['average_amount'], 2)
            ]);
        }
        fputcsv($output, []); // Empty line
    }
    
    // Aging Analysis
    if (!empty($agingAnalysis)) {
        fputcsv($output, ['AGING ANALYSIS (PENDING & OVERDUE BILLS)']);
        fputcsv($output, ['Age Group', 'Bills Count', 'Total Amount (GHS)', 'Average Amount (GHS)']);
        foreach ($agingAnalysis as $aging) {
            fputcsv($output, [
                $aging['age_group'],
                number_format($aging['bills_count']),
                number_format($aging['total_amount'], 2),
                number_format($aging['average_amount'], 2)
            ]);
        }
        fputcsv($output, []); // Empty line
    }
    
    // Detailed Bill Data
    if (!empty($detailedData)) {
        fputcsv($output, ['DETAILED BILL STATUS DATA']);
        fputcsv($output, [
            'Bill Number', 'Payer Name', 'Account Number', 'Bill Type', 'Zone', 
            'Amount Payable (GHS)', 'Total Paid (GHS)', 'Balance (GHS)', 'Status', 
            'Age (Days)', 'Generated Date', 'Payment Count', 'Last Payment Date'
        ]);
        
        foreach ($detailedData as $data) {
            $balance = $data['amount_payable'] - $data['total_paid'];
            fputcsv($output, [
                $data['bill_number'],
                $data['payer_name'] ?? 'Unknown',
                $data['account_number'] ?? '',
                $data['bill_type'],
                $data['zone_name'] ?? 'N/A',
                number_format($data['amount_payable'], 2),
                number_format($data['total_paid'], 2),
                number_format($balance, 2),
                $data['status'],
                $data['age_days'],
                date('M j, Y', strtotime($data['generated_at'])),
                $data['payment_count'],
                $data['last_payment_date'] ? date('M j, Y', strtotime($data['last_payment_date'])) : 'N/A'
            ]);
        }
    }
    
    fclose($output);
    exit();
}

// Initialize variables
$statusSummary = [];
$statusBreakdown = [];
$agingAnalysis = [];
$detailedData = [];
$zones = [];

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
    
    if ($status) {
        $whereConditions[] = "b.status = ?";
        $params[] = $status;
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Get status summary
    $statusSummary = $db->fetchAll("
        SELECT 
            b.status,
            COUNT(*) as bills_count,
            SUM(b.amount_payable) as total_amount,
            AVG(b.amount_payable) as average_amount,
            MIN(b.amount_payable) as min_amount,
            MAX(b.amount_payable) as max_amount
        FROM bills b
        LEFT JOIN businesses bs ON b.bill_type = 'Business' AND b.reference_id = bs.business_id
        LEFT JOIN properties pr ON b.bill_type = 'Property' AND b.reference_id = pr.property_id
        WHERE $whereClause
        GROUP BY b.status
        ORDER BY total_amount DESC
    ", $params);
    
    // Get status breakdown by bill type
    $statusBreakdown = $db->fetchAll("
        SELECT 
            b.status,
            b.bill_type,
            COUNT(*) as bills_count,
            SUM(b.amount_payable) as total_amount,
            AVG(b.amount_payable) as average_amount
        FROM bills b
        LEFT JOIN businesses bs ON b.bill_type = 'Business' AND b.reference_id = bs.business_id
        LEFT JOIN properties pr ON b.bill_type = 'Property' AND b.reference_id = pr.property_id
        WHERE $whereClause
        GROUP BY b.status, b.bill_type
        ORDER BY b.status, total_amount DESC
    ", $params);
    
    // Get aging analysis for pending bills
    $agingAnalysis = $db->fetchAll("
        SELECT 
            CASE 
                WHEN DATEDIFF(NOW(), b.generated_at) <= 30 THEN '0-30 days'
                WHEN DATEDIFF(NOW(), b.generated_at) <= 60 THEN '31-60 days'
                WHEN DATEDIFF(NOW(), b.generated_at) <= 90 THEN '61-90 days'
                WHEN DATEDIFF(NOW(), b.generated_at) <= 180 THEN '91-180 days'
                ELSE 'Over 180 days'
            END as age_group,
            COUNT(*) as bills_count,
            SUM(b.amount_payable) as total_amount,
            AVG(b.amount_payable) as average_amount
        FROM bills b
        LEFT JOIN businesses bs ON b.bill_type = 'Business' AND b.reference_id = bs.business_id
        LEFT JOIN properties pr ON b.bill_type = 'Property' AND b.reference_id = pr.property_id
        WHERE $whereClause AND b.status IN ('Pending', 'Overdue')
        GROUP BY age_group
        ORDER BY 
            CASE 
                WHEN age_group = '0-30 days' THEN 1
                WHEN age_group = '31-60 days' THEN 2
                WHEN age_group = '61-90 days' THEN 3
                WHEN age_group = '91-180 days' THEN 4
                ELSE 5
            END
    ", $params);
    
    // Get detailed data with payment information
    $detailedData = $db->fetchAll("
        SELECT 
            b.bill_number,
            b.bill_type,
            b.status,
            b.amount_payable,
            b.generated_at,
            DATEDIFF(NOW(), b.generated_at) as age_days,
            CASE 
                WHEN b.bill_type = 'Business' THEN bs.business_name
                WHEN b.bill_type = 'Property' THEN pr.owner_name
            END as payer_name,
            CASE 
                WHEN b.bill_type = 'Business' THEN bs.account_number
                WHEN b.bill_type = 'Property' THEN pr.property_number
            END as account_number,
            z.zone_name,
            COALESCE(SUM(p.amount_paid), 0) as total_paid,
            COUNT(p.payment_id) as payment_count,
            MAX(p.payment_date) as last_payment_date
        FROM bills b
        LEFT JOIN businesses bs ON b.bill_type = 'Business' AND b.reference_id = bs.business_id
        LEFT JOIN properties pr ON b.bill_type = 'Property' AND b.reference_id = pr.property_id
        LEFT JOIN zones z ON (
            (b.bill_type = 'Business' AND bs.zone_id = z.zone_id) OR
            (b.bill_type = 'Property' AND pr.zone_id = z.zone_id)
        )
        LEFT JOIN payments p ON b.bill_id = p.bill_id AND p.payment_status = 'Successful'
        WHERE $whereClause
        GROUP BY b.bill_id
        ORDER BY 
            CASE b.status 
                WHEN 'Overdue' THEN 1
                WHEN 'Pending' THEN 2
                WHEN 'Partially Paid' THEN 3
                WHEN 'Paid' THEN 4
            END,
            b.generated_at DESC
        LIMIT 200
    ", $params);
    
    // Get zones for filter dropdown
    $zones = $db->fetchAll("SELECT zone_id, zone_name FROM zones ORDER BY zone_name");
    
    // Calculate totals
    $totalBills = array_sum(array_column($statusSummary, 'bills_count'));
    $totalAmount = array_sum(array_column($statusSummary, 'total_amount'));
    
} catch (Exception $e) {
    writeLog("Status report error: " . $e->getMessage(), 'ERROR');
    setFlashMessage('error', 'An error occurred while loading status data.');
}

// Handle Export Request - This MUST come after all data is loaded
if ($export === 'excel' || $export === 'csv') {
    exportBillsStatusCSV($statusSummary, $statusBreakdown, $agingAnalysis, $detailedData, $dateFrom, $dateTo, $billType, $zoneId, $status, $zones);
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
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
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
        .icon-pie::before { content: "🥧"; }
        .icon-calendar::before { content: "📅"; }
        .icon-download::before { content: "⬇️"; }
        .icon-filter::before { content: "🔍"; }
        .icon-print::before { content: "🖨️"; }
        .icon-back::before { content: "↩️"; }
        .icon-receipt::before { content: "🧾"; }
        .icon-menu::before { content: "☰"; }
        .icon-logout::before { content: "🚪"; }
        .icon-bell::before { content: "🔔"; }
        .icon-clock::before { content: "⏰"; }
        .icon-check::before { content: "✅"; }
        .icon-warning::before { content: "⚠️"; }
        .icon-pending::before { content: "⏳"; }
        
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
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            border: 1px solid #93c5fd;
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
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
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
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 24px;
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.3);
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
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        /* Status Cards */
        .status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .status-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }
        
        .status-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        
        .status-card.paid {
            border-left: 4px solid #10b981;
        }
        
        .status-card.pending {
            border-left: 4px solid #f59e0b;
        }
        
        .status-card.partiallypaid {
            border-left: 4px solid #3b82f6;
        }
        
        .status-card.overdue {
            border-left: 4px solid #ef4444;
        }
        
        .status-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .status-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .status-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
        }
        
        .status-icon.paid {
            background: #10b981;
        }
        
        .status-icon.pending {
            background: #f59e0b;
        }
        
        .status-icon.partiallypaid {
            background: #3b82f6;
        }
        
        .status-icon.overdue {
            background: #ef4444;
        }
        
        .status-stats {
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
        
        .status-amount {
            font-family: monospace;
            color: #059669;
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
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
        }
        
        /* Aging Analysis */
        .aging-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .aging-item {
            background: white;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-top: 3px solid #3b82f6;
        }
        
        .aging-period {
            font-size: 14px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 10px;
        }
        
        .aging-count {
            font-size: 24px;
            font-weight: bold;
            color: #3b82f6;
            margin-bottom: 5px;
        }
        
        .aging-amount {
            font-size: 14px;
            color: #059669;
            font-weight: 600;
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
        
        .status-paid {
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
        
        .age-indicator {
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
        }
        
        .age-current {
            background: #d1fae5;
            color: #065f46;
        }
        
        .age-30 {
            background: #fef3c7;
            color: #92400e;
        }
        
        .age-60 {
            background: #fed7aa;
            color: #9a3412;
        }
        
        .age-90 {
            background: #fecaca;
            color: #991b1b;
        }
        
        .age-old {
            background: #fee2e2;
            color: #7f1d1d;
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
            background: #3b82f6;
            color: white;
        }
        
        .btn-primary:hover {
            background: #1d4ed8;
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
            color: #3b82f6;
            border: 2px solid #3b82f6;
        }
        
        .btn-outline:hover {
            background: #3b82f6;
            color: white;
        }
        
        .btn-success {
            background: #10b981;
            color: white;
        }
        
        .btn-success:hover {
            background: #059669;
            color: white;
            text-decoration: none;
            transform: translateY(-2px);
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
        
        /* Export Info Alert */
        .export-info {
            background: linear-gradient(135deg, #e0f2fe 0%, #b3e5fc 100%);
            border: 1px solid #4fc3f7;
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .export-info .info-icon {
            color: #0277bd;
            font-size: 20px;
        }
        
        .export-info .info-text {
            color: #01579b;
            font-weight: 500;
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
            
            .status-grid {
                grid-template-columns: 1fr;
            }
            
            .aging-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        /* Print Styles */
        @media print {
            .top-nav, .filter-section, .header-actions, .export-info {
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
            <a href="index.php" class="toggle-btn" title="Back to Reports">
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
                <a href="index.php">Reports</a>
                <span>/</span>
                <span style="color: #2d3748; font-weight: 600;">Bills by Status</span>
            </div>
        </div>

        <!-- Flash Messages -->
        <?php if ($flashMessage): ?>
            <div class="alert alert-<?php echo $flashMessage['type']; ?>">
                <i class="fas fa-<?php echo $flashMessage['type'] === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                <div><?php echo htmlspecialchars($flashMessage['message']); ?></div>
            </div>
        <?php endif; ?>

        <!-- Export Info -->
        <div class="export-info">
            <i class="fas fa-info-circle info-icon"></i>
            <div class="info-text">
                <strong>Export Feature:</strong> Click "Export Report" to download a comprehensive CSV file containing all bill status data, summary statistics, aging analysis, and detailed records.
            </div>
        </div>

        <!-- Report Header -->
        <div class="report-header">
            <div class="header-content">
                <div class="header-info">
                    <div class="header-avatar">
                        <i class="fas fa-chart-pie"></i>
                        <span class="icon-pie" style="display: none;"></span>
                    </div>
                    <div class="header-details">
                        <h1>Bills by Status Report</h1>
                        <div class="header-description">
                            Comprehensive status breakdown from <?php echo date('M j, Y', strtotime($dateFrom)); ?> 
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
                    <a href="status_report.php?<?php echo http_build_query(array_merge($_GET, ['export' => 'excel'])); ?>" class="btn btn-success">
                        <i class="fas fa-download"></i>
                        <span class="icon-download" style="display: none;"></span>
                        Export Report
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
                    <label class="form-label">Status</label>
                    <select name="status" class="form-control">
                        <option value="">All Status</option>
                        <option value="Pending" <?php echo $status === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="Paid" <?php echo $status === 'Paid' ? 'selected' : ''; ?>>Paid</option>
                        <option value="Partially Paid" <?php echo $status === 'Partially Paid' ? 'selected' : ''; ?>>Partially Paid</option>
                        <option value="Overdue" <?php echo $status === 'Overdue' ? 'selected' : ''; ?>>Overdue</option>
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

        <!-- Status Summary Cards -->
        <div class="status-grid">
            <?php foreach ($statusSummary as $statusData): ?>
                <div class="status-card <?php echo strtolower(str_replace(' ', '', $statusData['status'])); ?>">
                    <div class="status-header">
                        <div class="status-title">
                            <?php echo htmlspecialchars($statusData['status']); ?> Bills
                        </div>
                        <div class="status-icon <?php echo strtolower(str_replace(' ', '', $statusData['status'])); ?>">
                            <?php
                            switch ($statusData['status']) {
                                case 'Paid':
                                    echo '<i class="fas fa-check-circle"></i><span class="icon-check" style="display: none;"></span>';
                                    break;
                                case 'Pending':
                                    echo '<i class="fas fa-clock"></i><span class="icon-pending" style="display: none;"></span>';
                                    break;
                                case 'Partially Paid':
                                    echo '<i class="fas fa-clock"></i><span class="icon-clock" style="display: none;"></span>';
                                    break;
                                case 'Overdue':
                                    echo '<i class="fas fa-exclamation-triangle"></i><span class="icon-warning" style="display: none;"></span>';
                                    break;
                                default:
                                    echo '<i class="fas fa-file-invoice"></i>';
                            }
                            ?>
                        </div>
                    </div>
                    
                    <div class="status-stats">
                        <div class="stat-item">
                            <div class="stat-value"><?php echo number_format($statusData['bills_count']); ?></div>
                            <div class="stat-label">Bills Count</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value status-amount">GHS <?php echo number_format($statusData['total_amount'], 2); ?></div>
                            <div class="stat-label">Total Amount</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value status-amount">GHS <?php echo number_format($statusData['average_amount'], 2); ?></div>
                            <div class="stat-label">Average Bill</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?php echo $totalAmount > 0 ? round(($statusData['total_amount'] / $totalAmount) * 100, 1) : 0; ?>%</div>
                            <div class="stat-label">Of Total</div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Charts Section -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 30px;">
            <!-- Status Distribution Chart -->
            <?php if (!empty($statusSummary)): ?>
            <div class="chart-container">
                <div class="chart-header">
                    <div class="chart-title">
                        <div class="chart-icon">
                            <i class="fas fa-chart-pie"></i>
                            <span class="icon-pie" style="display: none;"></span>
                        </div>
                        Status Distribution by Amount
                    </div>
                </div>
                <canvas id="statusDistributionChart" height="150"></canvas>
            </div>
            <?php endif; ?>
            
            <!-- Type vs Status Chart -->
            <?php if (!empty($statusBreakdown)): ?>
            <div class="chart-container">
                <div class="chart-header">
                    <div class="chart-title">
                        <div class="chart-icon">
                            <i class="fas fa-chart-bar"></i>
                            <span class="icon-chart" style="display: none;"></span>
                        </div>
                        Status by Bill Type
                    </div>
                </div>
                <canvas id="typeStatusChart" height="150"></canvas>
            </div>
            <?php endif; ?>
        </div>

        <!-- Aging Analysis -->
        <?php if (!empty($agingAnalysis)): ?>
        <div class="chart-container">
            <div class="chart-header">
                <div class="chart-title">
                    <div class="chart-icon">
                        <i class="fas fa-calendar-alt"></i>
                        <span class="icon-calendar" style="display: none;"></span>
                    </div>
                    Aging Analysis (Pending & Overdue Bills)
                </div>
            </div>
            
            <div class="aging-grid">
                <?php foreach ($agingAnalysis as $aging): ?>
                    <div class="aging-item">
                        <div class="aging-period"><?php echo htmlspecialchars($aging['age_group']); ?></div>
                        <div class="aging-count"><?php echo number_format($aging['bills_count']); ?></div>
                        <div class="aging-amount">GHS <?php echo number_format($aging['total_amount'], 2); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Detailed Status Data -->
        <div class="chart-container">
            <div class="chart-header">
                <div class="chart-title">
                    <div class="chart-icon">
                        <i class="fas fa-table"></i>
                    </div>
                    Detailed Bill Status (Last 200 records)
                </div>
                <div>
                    <a href="status_report.php?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="btn btn-outline">
                        <i class="fas fa-download"></i>
                        Export CSV
                    </a>
                </div>
            </div>

            <?php if (empty($detailedData)): ?>
                <div style="text-align: center; padding: 40px; color: #64748b;">
                    <i class="fas fa-chart-pie" style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;"></i>
                    <h3>No Data Found</h3>
                    <p>No bill status data found for the selected filters.</p>
                </div>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Bill Number</th>
                            <th>Payer</th>
                            <th>Type</th>
                            <th>Zone</th>
                            <th>Amount</th>
                            <th>Paid</th>
                            <th>Status</th>
                            <th>Age</th>
                            <th>Generated Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($detailedData as $data): ?>
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
                                <td class="amount">GHS <?php echo number_format($data['total_paid'], 2); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $data['status'])); ?>">
                                        <?php echo htmlspecialchars($data['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="age-indicator age-<?php 
                                        $days = $data['age_days'];
                                        if ($days <= 30) echo 'current';
                                        elseif ($days <= 60) echo '30';
                                        elseif ($days <= 90) echo '60';
                                        elseif ($days <= 180) echo '90';
                                        else echo 'old';
                                    ?>">
                                        <?php echo $data['age_days']; ?> days
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
            // Status Distribution Chart
            <?php if (!empty($statusSummary)): ?>
            const statusCtx = document.getElementById('statusDistributionChart').getContext('2d');
            new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: [<?php echo "'" . implode("', '", array_column($statusSummary, 'status')) . "'"; ?>],
                    datasets: [{
                        data: [<?php echo implode(', ', array_column($statusSummary, 'total_amount')); ?>],
                        backgroundColor: ['#10b981', '#f59e0b', '#3b82f6', '#ef4444'],
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

            // Type vs Status Chart
            <?php if (!empty($statusBreakdown)): ?>
            const typeStatusCtx = document.getElementById('typeStatusChart').getContext('2d');
            
            // Group data by status
            const statusGroups = {};
            <?php foreach ($statusBreakdown as $item): ?>
                if (!statusGroups['<?php echo $item['status']; ?>']) {
                    statusGroups['<?php echo $item['status']; ?>'] = { Business: 0, Property: 0 };
                }
                statusGroups['<?php echo $item['status']; ?>']['<?php echo $item['bill_type']; ?>'] = <?php echo $item['total_amount']; ?>;
            <?php endforeach; ?>
            
            const statuses = Object.keys(statusGroups);
            const businessData = statuses.map(status => statusGroups[status].Business || 0);
            const propertyData = statuses.map(status => statusGroups[status].Property || 0);
            
            new Chart(typeStatusCtx, {
                type: 'bar',
                data: {
                    labels: statuses,
                    datasets: [{
                        label: 'Business',
                        data: businessData,
                        backgroundColor: '#3b82f6',
                        borderColor: '#1d4ed8',
                        borderWidth: 1
                    }, {
                        label: 'Property',
                        data: propertyData,
                        backgroundColor: '#10b981',
                        borderColor: '#059669',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            stacked: true,
                            ticks: {
                                callback: function(value) {
                                    return 'GHS ' + value.toLocaleString();
                                }
                            }
                        },
                        x: {
                            stacked: true
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
        }
    </script>
</body>
</html>