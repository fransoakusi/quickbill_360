<?php
/**
 * Zone Performance Report for QUICKBILL 305
 * Enhanced with Export Functions
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
$selectedZone = isset($_GET['zone']) ? intval($_GET['zone']) : 0;
$metricType = isset($_GET['metric']) ? $_GET['metric'] : 'revenue'; // revenue, collection_rate, transactions

// Export function for Zone Performance Report CSV
function exportZonePerformanceCSV($zonePerformance, $subZonePerformance, $monthlyPerformance, $selectedYear, $selectedZone, $metricType, $availableZones, $totalZones, $totalAccounts, $totalBills, $totalCollected, $totalOutstanding) {
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Zone_Performance_Report_' . date('Y-m-d_H-i-s') . '.csv"');
    
    // Create file pointer
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Report header
    fputcsv($output, ['QUICKBILL 305 - ZONE PERFORMANCE REPORT']);
    fputcsv($output, ['Generated on: ' . date('F j, Y g:i A')]);
    
    // Period information
    $periodText = 'Report Period: ' . $selectedYear;
    fputcsv($output, [$periodText]);
    
    // Filter information
    $filterInfo = 'Filters Applied: ';
    $filters = [];
    if ($selectedZone > 0) {
        $zoneName = 'Unknown Zone';
        foreach ($availableZones as $zone) {
            if ($zone['zone_id'] == $selectedZone) {
                $zoneName = $zone['zone_name'];
                break;
            }
        }
        $filters[] = 'Zone: ' . $zoneName;
    }
    $filters[] = 'Metric Type: ' . ucfirst(str_replace('_', ' ', $metricType));
    fputcsv($output, [$filterInfo . implode(', ', $filters)]);
    fputcsv($output, []); // Empty line
    
    // Overall Statistics
    fputcsv($output, ['OVERALL STATISTICS']);
    fputcsv($output, ['Metric', 'Value']);
    fputcsv($output, ['Total Zones', number_format($totalZones)]);
    fputcsv($output, ['Total Accounts', number_format($totalAccounts)]);
    fputcsv($output, ['Total Bills Amount', 'GH₵ ' . number_format($totalBills, 2)]);
    fputcsv($output, ['Total Collected', 'GH₵ ' . number_format($totalCollected, 2)]);
    fputcsv($output, ['Total Outstanding', 'GH₵ ' . number_format($totalOutstanding, 2)]);
    
    if ($totalBills > 0) {
        $overallCollectionRate = ($totalCollected / $totalBills) * 100;
        fputcsv($output, ['Overall Collection Rate', round($overallCollectionRate, 2) . '%']);
    }
    fputcsv($output, []); // Empty line
    
    // Zone Performance Summary
    if (!empty($zonePerformance)) {
        fputcsv($output, ['ZONE PERFORMANCE SUMMARY']);
        fputcsv($output, [
            'Zone Name', 'Zone Code', 'Total Accounts', 'Business Count', 'Property Count',
            'Total Bills (GH₵)', 'Total Collected (GH₵)', 'Outstanding (GH₵)', 
            'Collection Rate (%)', 'Compliance Rate (%)', 'Performance Rating'
        ]);
        
        foreach ($zonePerformance as $zone) {
            // Determine performance rating
            $collectionRate = $zone['collection_rate'];
            $complianceRate = $zone['compliance_rate'];
            $avgRate = ($collectionRate + $complianceRate) / 2;
            
            if ($avgRate >= 80) {
                $performanceText = 'Excellent';
            } elseif ($avgRate >= 60) {
                $performanceText = 'Good';
            } elseif ($avgRate >= 40) {
                $performanceText = 'Fair';
            } else {
                $performanceText = 'Poor';
            }
            
            fputcsv($output, [
                $zone['zone_name'],
                $zone['zone_code'] ?? 'N/A',
                number_format($zone['total_accounts']),
                number_format($zone['business_count']),
                number_format($zone['property_count']),
                number_format($zone['total_bills'], 2),
                number_format($zone['total_collected'], 2),
                number_format($zone['total_outstanding'], 2),
                round($zone['collection_rate'], 2),
                round($zone['compliance_rate'], 2),
                $performanceText
            ]);
        }
        fputcsv($output, []); // Empty line
    }
    
    // Sub-Zone Performance (if specific zone selected)
    if (!empty($subZonePerformance)) {
        fputcsv($output, ['SUB-ZONE PERFORMANCE']);
        fputcsv($output, [
            'Sub-Zone Name', 'Sub-Zone Code', 'Total Accounts', 'Business Count', 
            'Property Count', 'Total Bills (GH₵)', 'Outstanding (GH₵)'
        ]);
        
        foreach ($subZonePerformance as $subZone) {
            fputcsv($output, [
                $subZone['sub_zone_name'],
                $subZone['sub_zone_code'] ?? 'N/A',
                number_format($subZone['total_accounts']),
                number_format($subZone['business_count']),
                number_format($subZone['property_count']),
                number_format($subZone['total_bills'], 2),
                number_format($subZone['total_outstanding'], 2)
            ]);
        }
        fputcsv($output, []); // Empty line
    }
    
    // Monthly Performance (if data available)
    if (!empty($monthlyPerformance)) {
        fputcsv($output, ['MONTHLY PERFORMANCE - ' . $selectedYear]);
        fputcsv($output, ['Zone Name', 'Month', 'Monthly Collections (GH₵)']);
        
        foreach ($monthlyPerformance as $monthly) {
            $monthName = date('F', mktime(0, 0, 0, $monthly['month'], 1));
            fputcsv($output, [
                $monthly['zone_name'],
                $monthName,
                number_format($monthly['monthly_collected'], 2)
            ]);
        }
        fputcsv($output, []); // Empty line
    }
    
    // Top Performing Zones
    if (!empty($zonePerformance)) {
        $sortedByCollection = $zonePerformance;
        usort($sortedByCollection, function($a, $b) {
            return $b['total_collected'] <=> $a['total_collected'];
        });
        
        fputcsv($output, ['TOP PERFORMING ZONES BY COLLECTION']);
        fputcsv($output, ['Rank', 'Zone Name', 'Total Collected (GH₵)', 'Collection Rate (%)']);
        
        $rank = 1;
        foreach (array_slice($sortedByCollection, 0, 10) as $zone) {
            fputcsv($output, [
                $rank,
                $zone['zone_name'],
                number_format($zone['total_collected'], 2),
                round($zone['collection_rate'], 2)
            ]);
            $rank++;
        }
        fputcsv($output, []); // Empty line
    }
    
    // Performance Distribution
    if (!empty($zonePerformance)) {
        $excellent = $good = $fair = $poor = 0;
        
        foreach ($zonePerformance as $zone) {
            $avgRate = ($zone['collection_rate'] + $zone['compliance_rate']) / 2;
            if ($avgRate >= 80) $excellent++;
            elseif ($avgRate >= 60) $good++;
            elseif ($avgRate >= 40) $fair++;
            else $poor++;
        }
        
        fputcsv($output, ['PERFORMANCE DISTRIBUTION']);
        fputcsv($output, ['Performance Level', 'Number of Zones', 'Percentage']);
        fputcsv($output, ['Excellent (80%+)', $excellent, round(($excellent / count($zonePerformance)) * 100, 1) . '%']);
        fputcsv($output, ['Good (60-79%)', $good, round(($good / count($zonePerformance)) * 100, 1) . '%']);
        fputcsv($output, ['Fair (40-59%)', $fair, round(($fair / count($zonePerformance)) * 100, 1) . '%']);
        fputcsv($output, ['Poor (<40%)', $poor, round(($poor / count($zonePerformance)) * 100, 1) . '%']);
    }
    
    fclose($output);
    exit();
}

// Get zone performance data
try {
    $db = new Database();
    
    // Get all zones with their performance metrics
    $zonePerformanceQuery = "
        SELECT 
            z.zone_id,
            z.zone_name,
            z.zone_code,
            z.description,
            -- Business metrics
            COUNT(DISTINCT b.business_id) as business_count,
            COALESCE(SUM(b.current_bill), 0) as total_business_bills,
            COALESCE(SUM(CASE WHEN b.amount_payable > 0 THEN b.amount_payable ELSE 0 END), 0) as business_outstanding,
            COALESCE(SUM(CASE WHEN b.amount_payable <= 0 THEN 1 ELSE 0 END), 0) as business_up_to_date,
            -- Property metrics
            COUNT(DISTINCT p.property_id) as property_count,
            COALESCE(SUM(p.current_bill), 0) as total_property_bills,
            COALESCE(SUM(CASE WHEN p.amount_payable > 0 THEN p.amount_payable ELSE 0 END), 0) as property_outstanding,
            COALESCE(SUM(CASE WHEN p.amount_payable <= 0 THEN 1 ELSE 0 END), 0) as property_up_to_date
        FROM zones z
        LEFT JOIN businesses b ON z.zone_id = b.zone_id
        LEFT JOIN properties p ON z.zone_id = p.zone_id
        " . ($selectedZone > 0 ? "WHERE z.zone_id = ?" : "") . "
        GROUP BY z.zone_id, z.zone_name, z.zone_code, z.description
        ORDER BY z.zone_name
    ";
    
    $zoneParams = $selectedZone > 0 ? [$selectedZone] : [];
    $zonePerformance = $db->fetchAll($zonePerformanceQuery, $zoneParams);
    
    // Get payment performance by zone
    $paymentPerformanceQuery = "
        SELECT 
            z.zone_id,
            z.zone_name,
            COUNT(pay.payment_id) as total_payments,
            COALESCE(SUM(pay.amount_paid), 0) as total_collected,
            COALESCE(AVG(pay.amount_paid), 0) as avg_payment
        FROM zones z
        LEFT JOIN businesses b ON z.zone_id = b.zone_id
        LEFT JOIN properties p ON z.zone_id = p.zone_id
        LEFT JOIN bills bill ON (
            (bill.bill_type = 'Business' AND bill.reference_id = b.business_id) OR
            (bill.bill_type = 'Property' AND bill.reference_id = p.property_id)
        )
        LEFT JOIN payments pay ON bill.bill_id = pay.bill_id AND pay.payment_status = 'Successful'
        WHERE YEAR(pay.payment_date) = ? OR pay.payment_date IS NULL
        " . ($selectedZone > 0 ? "AND z.zone_id = ?" : "") . "
        GROUP BY z.zone_id, z.zone_name
        ORDER BY total_collected DESC
    ";
    
    $paymentParams = [$selectedYear];
    if ($selectedZone > 0) {
        $paymentParams[] = $selectedZone;
    }
    $paymentPerformance = $db->fetchAll($paymentPerformanceQuery, $paymentParams);
    
    // Merge zone and payment performance data
    foreach ($zonePerformance as &$zone) {
        $zone['total_payments'] = 0;
        $zone['total_collected'] = 0;
        $zone['avg_payment'] = 0;
        $zone['collection_rate'] = 0;
        
        foreach ($paymentPerformance as $payment) {
            if ($payment['zone_id'] == $zone['zone_id']) {
                $zone['total_payments'] = $payment['total_payments'];
                $zone['total_collected'] = $payment['total_collected'];
                $zone['avg_payment'] = $payment['avg_payment'];
                break;
            }
        }
        
        // Calculate totals and rates
        $zone['total_accounts'] = $zone['business_count'] + $zone['property_count'];
        $zone['total_bills'] = $zone['total_business_bills'] + $zone['total_property_bills'];
        $zone['total_outstanding'] = $zone['business_outstanding'] + $zone['property_outstanding'];
        $zone['total_up_to_date'] = $zone['business_up_to_date'] + $zone['property_up_to_date'];
        
        // Collection rate calculation
        if ($zone['total_bills'] > 0) {
            $zone['collection_rate'] = (($zone['total_collected'] / $zone['total_bills']) * 100);
        }
        
        // Payment compliance rate
        if ($zone['total_accounts'] > 0) {
            $zone['compliance_rate'] = (($zone['total_up_to_date'] / $zone['total_accounts']) * 100);
        } else {
            $zone['compliance_rate'] = 0;
        }
    }
    unset($zone); // Break reference
    
    // Get sub-zone performance for selected zone
    $subZonePerformance = [];
    if ($selectedZone > 0) {
        $subZoneQuery = "
            SELECT 
                sz.sub_zone_id,
                sz.sub_zone_name,
                sz.sub_zone_code,
                -- Business metrics
                COUNT(DISTINCT b.business_id) as business_count,
                COALESCE(SUM(b.current_bill), 0) as total_business_bills,
                COALESCE(SUM(CASE WHEN b.amount_payable > 0 THEN b.amount_payable ELSE 0 END), 0) as business_outstanding,
                -- Property metrics
                COUNT(DISTINCT p.property_id) as property_count,
                COALESCE(SUM(p.current_bill), 0) as total_property_bills,
                COALESCE(SUM(CASE WHEN p.amount_payable > 0 THEN p.amount_payable ELSE 0 END), 0) as property_outstanding
            FROM sub_zones sz
            LEFT JOIN businesses b ON sz.sub_zone_id = b.sub_zone_id
            LEFT JOIN properties p ON sz.sub_zone_id = p.sub_zone_id
            WHERE sz.zone_id = ?
            GROUP BY sz.sub_zone_id, sz.sub_zone_name, sz.sub_zone_code
            ORDER BY sz.sub_zone_name
        ";
        
        $subZonePerformance = $db->fetchAll($subZoneQuery, [$selectedZone]);
        
        // Calculate totals for sub-zones
        foreach ($subZonePerformance as &$subZone) {
            $subZone['total_accounts'] = $subZone['business_count'] + $subZone['property_count'];
            $subZone['total_bills'] = $subZone['total_business_bills'] + $subZone['total_property_bills'];
            $subZone['total_outstanding'] = $subZone['business_outstanding'] + $subZone['property_outstanding'];
        }
        unset($subZone);
    }
    
    // Get monthly performance for charts
    $monthlyPerformanceQuery = "
        SELECT 
            z.zone_name,
            MONTH(pay.payment_date) as month,
            COALESCE(SUM(pay.amount_paid), 0) as monthly_collected
        FROM zones z
        LEFT JOIN businesses b ON z.zone_id = b.zone_id
        LEFT JOIN properties p ON z.zone_id = p.zone_id
        LEFT JOIN bills bill ON (
            (bill.bill_type = 'Business' AND bill.reference_id = b.business_id) OR
            (bill.bill_type = 'Property' AND bill.reference_id = p.property_id)
        )
        LEFT JOIN payments pay ON bill.bill_id = pay.bill_id AND pay.payment_status = 'Successful'
        WHERE YEAR(pay.payment_date) = ?
        " . ($selectedZone > 0 ? "AND z.zone_id = ?" : "") . "
        GROUP BY z.zone_id, z.zone_name, MONTH(pay.payment_date)
        ORDER BY z.zone_name, MONTH(pay.payment_date)
    ";
    
    $monthlyParams = [$selectedYear];
    if ($selectedZone > 0) {
        $monthlyParams[] = $selectedZone;
    }
    $monthlyPerformance = $db->fetchAll($monthlyPerformanceQuery, $monthlyParams);
    
    // Calculate overall statistics
    $totalZones = count($zonePerformance);
    $totalAccounts = array_sum(array_column($zonePerformance, 'total_accounts'));
    $totalBills = array_sum(array_column($zonePerformance, 'total_bills'));
    $totalCollected = array_sum(array_column($zonePerformance, 'total_collected'));
    $totalOutstanding = array_sum(array_column($zonePerformance, 'total_outstanding'));
    
    // Get available zones and years for filters
    $zonesQuery = "SELECT zone_id, zone_name FROM zones ORDER BY zone_name";
    $availableZones = $db->fetchAll($zonesQuery);
    
    $yearsQuery = "
        SELECT DISTINCT YEAR(payment_date) as year 
        FROM payments 
        WHERE payment_status = 'Successful'
        ORDER BY year DESC
    ";
    $availableYears = $db->fetchAll($yearsQuery);
    
} catch (Exception $e) {
    $zonePerformance = [];
    $subZonePerformance = [];
    $monthlyPerformance = [];
    $totalZones = 0;
    $totalAccounts = 0;
    $totalBills = 0;
    $totalCollected = 0;
    $totalOutstanding = 0;
    $availableZones = [];
    $availableYears = [];
}

// Handle Export Request - This MUST come after all data is loaded
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    exportZonePerformanceCSV($zonePerformance, $subZonePerformance, $monthlyPerformance, $selectedYear, $selectedZone, $metricType, $availableZones, $totalZones, $totalAccounts, $totalBills, $totalCollected, $totalOutstanding);
}

// Handle PDF Export placeholder
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    setFlashMessage('info', 'PDF export functionality will be implemented soon. Use CSV export for now.');
    header('Location: zone_performance.php?' . http_build_query(array_filter($_GET, function($key) { return $key !== 'export'; }, ARRAY_FILTER_USE_KEY)));
    exit();
}

// Prepare chart data
$chartData = [];
if (!empty($monthlyPerformance)) {
    $zoneNames = array_unique(array_column($monthlyPerformance, 'zone_name'));
    $months = range(1, 12);
    
    foreach ($zoneNames as $zoneName) {
        $monthlyData = array_fill(0, 12, 0);
        foreach ($monthlyPerformance as $record) {
            if ($record['zone_name'] == $zoneName && $record['month']) {
                $monthlyData[$record['month'] - 1] = floatval($record['monthly_collected']);
            }
        }
        $chartData[$zoneName] = $monthlyData;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zone Performance Report - <?php echo APP_NAME; ?></title>

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
        .icon-warning::before { content: "⚠️"; }
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
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
        }

        .toggle-btn:hover {
            background: rgba(255,255,255,0.3);
            transform: scale(1.05);
        }

        .toggle-btn:active {
            transform: scale(0.95);
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

        .user-profile:hover .user-avatar {
            transform: scale(1.05);
            border-color: rgba(255,255,255,0.4);
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

        .user-profile.active .dropdown-arrow {
            transform: rotate(180deg);
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

        /* Sidebar - FIXED */
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, #2d3748 0%, #1a202c 100%);
            color: white;
            transition: all 0.3s ease;
            overflow: hidden;
            flex-shrink: 0;
        }

        .sidebar.hidden {
            width: 0;
            min-width: 0;
        }

        .sidebar-content {
            width: 280px;
            padding: 20px 0;
            transition: all 0.3s ease;
        }

        .sidebar.hidden .sidebar-content {
            opacity: 0;
            transform: translateX(-20px);
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

        /* Main Content - FIXED */
        .main-content {
            flex: 1;
            padding: 30px;
            background: #f8f9fa;
            transition: all 0.3s ease;
            min-width: 0; /* Allows flex item to shrink */
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
        .chart-container {
            position: relative;
            height: 400px;
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

        /* Performance Indicators */
        .performance-bar {
            width: 100%;
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 5px;
        }

        .performance-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        .performance-excellent {
            background: linear-gradient(90deg, #48bb78, #38a169);
        }

        .performance-good {
            background: linear-gradient(90deg, #4299e1, #3182ce);
        }

        .performance-fair {
            background: linear-gradient(90deg, #ed8936, #dd6b20);
        }

        .performance-poor {
            background: linear-gradient(90deg, #f56565, #e53e3e);
        }

        .performance-text {
            font-size: 12px;
            font-weight: 600;
        }

        .badge {
            padding: 4px 8px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .badge-excellent {
            background: #c6f6d5;
            color: #22543d;
        }

        .badge-good {
            background: #bee3f8;
            color: #2a4365;
        }

        .badge-fair {
            background: #fbd38d;
            color: #744210;
        }

        .badge-poor {
            background: #fed7d7;
            color: #c53030;
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

        /* Mobile Responsive - FIXED */
        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                height: calc(100vh - 80px);
                top: 80px;
                left: 0;
                z-index: 999;
                transform: translateX(-100%);
                width: 280px !important; /* Force width on mobile */
            }

            .sidebar.mobile-show {
                transform: translateX(0);
            }

            .sidebar.hidden {
                transform: translateX(-100%);
            }

            .sidebar-content {
                opacity: 1;
                transform: none;
            }

            .main-content {
                margin-left: 0;
            }

            .stats-grid {
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

            .container {
                flex-direction: row; /* Keep flex direction */
            }

            /* Mobile overlay */
            .mobile-overlay {
                position: fixed;
                top: 80px;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0,0,0,0.5);
                z-index: 998;
                display: none;
            }

            .mobile-overlay.show {
                display: block;
            }
        }

        /* Sidebar indicator */
        .sidebar-indicator {
            position: fixed;
            top: 50%;
            left: 10px;
            transform: translateY(-50%);
            background: #667eea;
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: none;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 1001;
            transition: all 0.3s;
        }

        .sidebar-indicator:hover {
            background: #5a67d8;
            transform: translateY(-50%) scale(1.1);
        }
    </style>
</head>
<body>
    <!-- Mobile Overlay -->
    <div class="mobile-overlay" id="mobileOverlay" onclick="closeMobileSidebar()"></div>

    <!-- Top Navigation -->
    <div class="top-nav">
        <div class="nav-left">
            <button class="toggle-btn" onclick="toggleSidebar()" id="toggleBtn" title="Toggle Sidebar">
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

        <!-- Sidebar Indicator for when sidebar is hidden -->
        <div class="sidebar-indicator" id="sidebarIndicator" onclick="showSidebar()" title="Show Sidebar">
            <span class="icon-menu"></span>
        </div>

        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1 class="page-title">🗺️ Zone Performance Report</h1>
                    <p class="page-subtitle">Performance analysis by geographical zones and areas</p>
                </div>
                <div class="page-actions">
                    <a href="index.php" class="btn btn-outline">
                        <span class="icon-back"></span>
                        Back to Reports
                    </a>
                    <a href="zone_performance.php?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="btn btn-success">
                        <span class="icon-download"></span>
                        Export CSV
                    </a>
                </div>
            </div>

            <!-- Export Info -->
            <div class="export-info">
                <div class="info-icon">ℹ️</div>
                <div class="info-text">
                    <strong>Export Feature:</strong> Click "Export CSV" to download a comprehensive report containing zone performance summary, sub-zone details, monthly trends, top performers, and performance distribution analysis.
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-card">
                <form method="GET" action="">
                    <div class="filters-grid">
                        <div class="form-group">
                            <label class="form-label">Year</label>
                            <select name="year" class="form-control">
                                <?php foreach ($availableYears as $year): ?>
                                    <option value="<?php echo $year['year']; ?>" 
                                        <?php echo $selectedYear == $year['year'] ? 'selected' : ''; ?>>
                                        <?php echo $year['year']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Zone</label>
                            <select name="zone" class="form-control">
                                <option value="0">All Zones</option>
                                <?php foreach ($availableZones as $zone): ?>
                                    <option value="<?php echo $zone['zone_id']; ?>" 
                                        <?php echo $selectedZone == $zone['zone_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($zone['zone_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Metric</label>
                            <select name="metric" class="form-control">
                                <option value="revenue" <?php echo $metricType == 'revenue' ? 'selected' : ''; ?>>Revenue Performance</option>
                                <option value="collection_rate" <?php echo $metricType == 'collection_rate' ? 'selected' : ''; ?>>Collection Rate</option>
                                <option value="transactions" <?php echo $metricType == 'transactions' ? 'selected' : ''; ?>>Transaction Volume</option>
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
                        <div class="stat-title">Total Zones</div>
                        <div class="stat-icon">
                            <span class="icon-map"></span>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($totalZones); ?></div>
                    <div class="stat-subtitle">Active zones</div>
                </div>

                <div class="stat-card success">
                    <div class="stat-header">
                        <div class="stat-title">Total Accounts</div>
                        <div class="stat-icon">
                            <span class="icon-users"></span>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($totalAccounts); ?></div>
                    <div class="stat-subtitle">All zones combined</div>
                </div>

                <div class="stat-card info">
                    <div class="stat-header">
                        <div class="stat-title">Total Collected</div>
                        <div class="stat-icon">
                            <span class="icon-money"></span>
                        </div>
                    </div>
                    <div class="stat-value">₵ <?php echo number_format($totalCollected, 2); ?></div>
                    <div class="stat-subtitle"><?php echo $selectedYear; ?> collections</div>
                </div>

                <div class="stat-card warning">
                    <div class="stat-header">
                        <div class="stat-title">Outstanding</div>
                        <div class="stat-icon">
                            <span class="icon-warning"></span>
                        </div>
                    </div>
                    <div class="stat-value">₵ <?php echo number_format($totalOutstanding, 2); ?></div>
                    <div class="stat-subtitle">Pending amounts</div>
                </div>
            </div>

            <!-- Monthly Performance Chart -->
            <?php if (!empty($chartData)): ?>
            <div class="card" style="margin-bottom: 30px;">
                <div class="card-header">
                    <h5 class="card-title">📈 Monthly Performance by Zone - <?php echo $selectedYear; ?></h5>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="monthlyPerformanceChart"></canvas>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Zone Performance Table -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">🏘️ Zone Performance Summary</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Zone</th>
                                    <th>Accounts</th>
                                    <th>Total Bills</th>
                                    <th>Collected</th>
                                    <th>Outstanding</th>
                                    <th>Collection Rate</th>
                                    <th>Compliance Rate</th>
                                    <th>Performance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($zonePerformance)): ?>
                                    <tr>
                                        <td colspan="8" style="text-align: center; color: #718096;">No zone data available</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($zonePerformance as $zone): ?>
                                        <?php
                                        // Determine performance rating
                                        $collectionRate = $zone['collection_rate'];
                                        $complianceRate = $zone['compliance_rate'];
                                        $avgRate = ($collectionRate + $complianceRate) / 2;
                                        
                                        if ($avgRate >= 80) {
                                            $performanceClass = 'excellent';
                                            $performanceBadge = 'badge-excellent';
                                            $performanceText = 'Excellent';
                                        } elseif ($avgRate >= 60) {
                                            $performanceClass = 'good';
                                            $performanceBadge = 'badge-good';
                                            $performanceText = 'Good';
                                        } elseif ($avgRate >= 40) {
                                            $performanceClass = 'fair';
                                            $performanceBadge = 'badge-fair';
                                            $performanceText = 'Fair';
                                        } else {
                                            $performanceClass = 'poor';
                                            $performanceBadge = 'badge-poor';
                                            $performanceText = 'Poor';
                                        }
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($zone['zone_name']); ?></strong>
                                                <?php if ($zone['zone_code']): ?>
                                                    <br><small style="color: #718096;">
                                                        <?php echo htmlspecialchars($zone['zone_code']); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo number_format($zone['total_accounts']); ?>
                                                <br><small style="color: #718096;">
                                                    <?php echo number_format($zone['business_count']); ?>B / 
                                                    <?php echo number_format($zone['property_count']); ?>P
                                                </small>
                                            </td>
                                            <td>₵ <?php echo number_format($zone['total_bills'], 2); ?></td>
                                            <td>₵ <?php echo number_format($zone['total_collected'], 2); ?></td>
                                            <td>₵ <?php echo number_format($zone['total_outstanding'], 2); ?></td>
                                            <td>
                                                <div class="performance-bar">
                                                    <div class="performance-fill performance-<?php echo $performanceClass; ?>" 
                                                         style="width: <?php echo min(100, $collectionRate); ?>%"></div>
                                                </div>
                                                <div class="performance-text"><?php echo round($collectionRate, 1); ?>%</div>
                                            </td>
                                            <td>
                                                <div class="performance-bar">
                                                    <div class="performance-fill performance-<?php echo $performanceClass; ?>" 
                                                         style="width: <?php echo min(100, $complianceRate); ?>%"></div>
                                                </div>
                                                <div class="performance-text"><?php echo round($complianceRate, 1); ?>%</div>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo $performanceBadge; ?>">
                                                    <?php echo $performanceText; ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Sub-Zone Performance -->
            <?php if (!empty($subZonePerformance)): ?>
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">🏠 Sub-Zone Performance</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Sub-Zone</th>
                                    <th>Code</th>
                                    <th>Accounts</th>
                                    <th>Total Bills</th>
                                    <th>Outstanding</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($subZonePerformance as $subZone): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($subZone['sub_zone_name']); ?></td>
                                        <td><?php echo htmlspecialchars($subZone['sub_zone_code'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php echo number_format($subZone['total_accounts']); ?>
                                            <br><small style="color: #718096;">
                                                <?php echo number_format($subZone['business_count']); ?>B / 
                                                <?php echo number_format($subZone['property_count']); ?>P
                                            </small>
                                        </td>
                                        <td>₵ <?php echo number_format($subZone['total_bills'], 2); ?></td>
                                        <td>₵ <?php echo number_format($subZone['total_outstanding'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Chart data
        const chartData = <?php echo json_encode($chartData); ?>;

        // Global variables
        let isMobile = window.innerWidth <= 768;

        // Initialize application
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Initializing zone performance report...');
            
            // Initialize mobile detection
            checkMobile();
            
            // Restore sidebar state
            restoreSidebarState();
            
            // Initialize charts
            setTimeout(function() {
                initializeCharts();
            }, 300);
        });

        // Check if mobile
        function checkMobile() {
            isMobile = window.innerWidth <= 768;
        }

        // FIXED: Sidebar toggle function
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const indicator = document.getElementById('sidebarIndicator');
            const overlay = document.getElementById('mobileOverlay');
            
            if (isMobile) {
                // Mobile behavior
                if (sidebar.classList.contains('mobile-show')) {
                    closeMobileSidebar();
                } else {
                    openMobileSidebar();
                }
            } else {
                // Desktop behavior
                sidebar.classList.toggle('hidden');
                const isHidden = sidebar.classList.contains('hidden');
                
                // Show/hide indicator
                if (isHidden) {
                    setTimeout(() => {
                        indicator.style.display = 'flex';
                    }, 300);
                } else {
                    indicator.style.display = 'none';
                }
                
                // Save state
                localStorage.setItem('sidebarHidden', isHidden);
                
                console.log('Sidebar toggled, hidden:', isHidden);
            }
        }

        // Mobile sidebar functions
        function openMobileSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('mobileOverlay');
            
            sidebar.classList.add('mobile-show');
            overlay.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeMobileSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('mobileOverlay');
            
            sidebar.classList.remove('mobile-show');
            overlay.classList.remove('show');
            document.body.style.overflow = 'auto';
        }

        // Show sidebar (from indicator)
        function showSidebar() {
            const sidebar = document.getElementById('sidebar');
            const indicator = document.getElementById('sidebarIndicator');
            
            sidebar.classList.remove('hidden');
            indicator.style.display = 'none';
            localStorage.setItem('sidebarHidden', false);
        }

        // Restore sidebar state
        function restoreSidebarState() {
            const sidebar = document.getElementById('sidebar');
            const indicator = document.getElementById('sidebarIndicator');
            const sidebarHidden = localStorage.getItem('sidebarHidden');
            
            if (!isMobile && sidebarHidden === 'true') {
                sidebar.classList.add('hidden');
                indicator.style.display = 'flex';
            }
        }

        function initializeCharts() {
            if (typeof Chart === 'undefined') {
                console.log('Chart.js not available from local file');
                showChartFallback();
                return;
            }

            if (Object.keys(chartData).length === 0) {
                console.log('No chart data available');
                showChartFallback();
                return;
            }

            try {
                // Monthly Performance Chart
                const ctx = document.getElementById('monthlyPerformanceChart');
                if (ctx) {
                    const datasets = [];
                    const colors = [
                        '#667eea', '#48bb78', '#4299e1', '#ed8936', '#9f7aea', '#f56565',
                        '#38b2ac', '#805ad5', '#dd6b20', '#e53e3e', '#319795', '#553c9a'
                    ];
                    let colorIndex = 0;

                    for (const [zoneName, data] of Object.entries(chartData)) {
                        datasets.push({
                            label: zoneName,
                            data: data,
                            borderColor: colors[colorIndex % colors.length],
                            backgroundColor: colors[colorIndex % colors.length] + '20',
                            fill: false,
                            tension: 0.4,
                            borderWidth: 3,
                            pointBackgroundColor: colors[colorIndex % colors.length],
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2,
                            pointRadius: 4
                        });
                        colorIndex++;
                    }

                    new Chart(ctx.getContext('2d'), {
                        type: 'line',
                        data: {
                            labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                            datasets: datasets
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
                                    title: {
                                        display: true,
                                        text: 'Amount Collected (₵)'
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
                                    },
                                    title: {
                                        display: true,
                                        text: 'Month'
                                    }
                                }
                            },
                            plugins: {
                                legend: {
                                    position: 'top',
                                    labels: {
                                        padding: 20,
                                        usePointStyle: true
                                    }
                                },
                                tooltip: {
                                    mode: 'index',
                                    intersect: false,
                                    callbacks: {
                                        label: function(context) {
                                            return context.dataset.label + ': ₵ ' + context.parsed.y.toLocaleString();
                                        }
                                    }
                                }
                            },
                            interaction: {
                                mode: 'nearest',
                                axis: 'x',
                                intersect: false
                            }
                        }
                    });
                }

                console.log('Chart created successfully');
            } catch (error) {
                console.error('Error creating chart:', error);
                showChartFallback();
            }
        }

        function showChartFallback() {
            const chartContainer = document.querySelector('.chart-container');
            if (chartContainer) {
                chartContainer.innerHTML = '<div style="height: 400px; display: flex; align-items: center; justify-content: center; color: #718096; font-style: italic;">📊 Chart will appear here when Chart.js is available and data exists</div>';
            }
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

        // Handle window resize
        window.addEventListener('resize', function() {
            const wasMobile = isMobile;
            checkMobile();
            
            // If switching between mobile and desktop
            if (wasMobile !== isMobile) {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('mobileOverlay');
                const indicator = document.getElementById('sidebarIndicator');
                
                // Reset all states
                sidebar.classList.remove('mobile-show', 'hidden');
                overlay.classList.remove('show');
                indicator.style.display = 'none';
                document.body.style.overflow = 'auto';
                
                // Apply appropriate state
                if (!isMobile) {
                    restoreSidebarState();
                }
            }
        });

        // Add enhanced interactions
        document.addEventListener('DOMContentLoaded', function() {
            // Add click feedback to toggle button
            const toggleBtn = document.getElementById('toggleBtn');
            toggleBtn.addEventListener('click', function() {
                this.style.transform = 'scale(0.95)';
                setTimeout(() => {
                    this.style.transform = 'scale(1)';
                }, 100);
            });

            // Add hover effects to stat cards
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-8px) scale(1.02)';
                });
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                });
            });

            // Animate performance bars
            const performanceBars = document.querySelectorAll('.performance-fill');
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const width = entry.target.style.width;
                        entry.target.style.width = '0%';
                        setTimeout(() => {
                            entry.target.style.width = width;
                        }, 100);
                        observer.unobserve(entry.target);
                    }
                });
            });

            performanceBars.forEach(bar => observer.observe(bar));
        });
    </script>
</body>
</html>