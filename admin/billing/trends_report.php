<?php
/**
 * Billing Trends Report - FIXED VERSION
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
$pageTitle = 'Billing Trends Report';
$currentUser = getCurrentUser();
$userDisplayName = getUserDisplayName($currentUser);

// Get filter parameters with validation
$period = in_array($_GET['period'] ?? '', ['6months', '12months', '24months', '36months']) ? $_GET['period'] : '12months';
$billType = in_array($_GET['bill_type'] ?? '', ['Business', 'Property']) ? $_GET['bill_type'] : '';
$zoneId = filter_var($_GET['zone_id'] ?? '', FILTER_VALIDATE_INT);
$forecast = $_GET['forecast'] ?? '0';
$comparison = in_array($_GET['comparison'] ?? '', ['yoy', 'mom']) ? $_GET['comparison'] : 'yoy';

// Initialize variables
$monthlyTrends = [];
$yearlyTrends = [];
$seasonalAnalysis = [];
$growthMetrics = [];
$collectionTrends = [];
$paymentMethodTrends = [];
$forecastData = [];
$revenueComparison = [];
$billCountTrends = [];
$allZones = [];

try {
    $db = new Database();
    
    // Build date range based on period
    $endDate = date('Y-m-d');
    switch ($period) {
        case '6months':
            $startDate = date('Y-m-d', strtotime('-6 months'));
            break;
        case '12months':
            $startDate = date('Y-m-d', strtotime('-12 months'));
            break;
        case '24months':
            $startDate = date('Y-m-d', strtotime('-24 months'));
            break;
        case '36months':
            $startDate = date('Y-m-d', strtotime('-36 months'));
            break;
        default:
            $startDate = date('Y-m-d', strtotime('-12 months'));
    }
    
    // Build parameterized WHERE clause for filters
    $whereConditions = ["DATE(b.generated_at) >= ?"];
    $params = [$startDate];
    $paymentParams = [$startDate];
    
    if ($billType) {
        $whereConditions[] = "b.bill_type = ?";
        $params[] = $billType;
    }
    
    if ($zoneId) {
        $whereConditions[] = "((b.bill_type = 'Business' AND bs.zone_id = ?) OR (b.bill_type = 'Property' AND pr.zone_id = ?))";
        $params[] = $zoneId;
        $params[] = $zoneId;
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Get monthly revenue trends with FIXED calculation
    $monthlyResult = $db->fetchAll("
        SELECT 
            DATE_FORMAT(b.generated_at, '%Y-%m') as month_year,
            DATE_FORMAT(b.generated_at, '%M %Y') as month_label,
            YEAR(b.generated_at) as year,
            MONTH(b.generated_at) as month,
            COUNT(b.bill_id) as total_bills,
            SUM(b.amount_payable) as total_revenue,
            SUM(CASE 
                WHEN b.status = 'Paid' THEN b.amount_payable 
                WHEN b.status = 'Partially Paid' THEN COALESCE(payments_made.total_paid, 0)
                ELSE 0 
            END) as collected_revenue,
            SUM(CASE WHEN b.status = 'Pending' THEN b.amount_payable ELSE 0 END) as pending_revenue,
            SUM(CASE WHEN b.bill_type = 'Business' THEN b.amount_payable ELSE 0 END) as business_revenue,
            SUM(CASE WHEN b.bill_type = 'Property' THEN b.amount_payable ELSE 0 END) as property_revenue,
            COUNT(CASE WHEN b.bill_type = 'Business' THEN 1 END) as business_bills,
            COUNT(CASE WHEN b.bill_type = 'Property' THEN 1 END) as property_bills,
            AVG(b.amount_payable) as average_bill_amount
        FROM bills b
        LEFT JOIN businesses bs ON b.bill_type = 'Business' AND b.reference_id = bs.business_id
        LEFT JOIN properties pr ON b.bill_type = 'Property' AND b.reference_id = pr.property_id
        LEFT JOIN (
            SELECT 
                b2.bill_id,
                SUM(p.amount_paid) as total_paid
            FROM payments p
            JOIN bills b2 ON p.bill_id = b2.bill_id
            WHERE p.payment_status = 'Successful'
            GROUP BY b2.bill_id
        ) payments_made ON b.bill_id = payments_made.bill_id
        WHERE $whereClause
        GROUP BY DATE_FORMAT(b.generated_at, '%Y-%m')
        ORDER BY b.generated_at ASC
    ", $params);
    
    if ($monthlyResult !== false && is_array($monthlyResult)) {
        $monthlyTrends = $monthlyResult;
    }
    
    // Calculate collection rates and growth for monthly trends
    foreach ($monthlyTrends as &$trend) {
        $trend['collection_rate'] = $trend['total_revenue'] > 0 ? 
            round(($trend['collected_revenue'] / $trend['total_revenue']) * 100, 2) : 0;
        
        // Ensure collection rate doesn't exceed 100%
        $trend['collection_rate'] = min($trend['collection_rate'], 100);
    }
    
    // Calculate month-over-month and year-over-year growth
    for ($i = 1; $i < count($monthlyTrends); $i++) {
        $current = $monthlyTrends[$i];
        $previous = $monthlyTrends[$i - 1];
        
        // Month-over-month growth
        if ($previous['total_revenue'] > 0) {
            $monthlyTrends[$i]['mom_growth'] = round((($current['total_revenue'] - $previous['total_revenue']) / $previous['total_revenue']) * 100, 2);
        } else {
            $monthlyTrends[$i]['mom_growth'] = $current['total_revenue'] > 0 ? 100 : 0;
        }
        
        // Year-over-year growth (if data exists)
        $currentMonthLastYear = null;
        foreach ($monthlyTrends as $trend) {
            if ($trend['month'] == $current['month'] && $trend['year'] == ($current['year'] - 1)) {
                $currentMonthLastYear = $trend;
                break;
            }
        }
        
        if ($currentMonthLastYear && $currentMonthLastYear['total_revenue'] > 0) {
            $monthlyTrends[$i]['yoy_growth'] = round((($current['total_revenue'] - $currentMonthLastYear['total_revenue']) / $currentMonthLastYear['total_revenue']) * 100, 2);
        } else {
            $monthlyTrends[$i]['yoy_growth'] = $current['total_revenue'] > 0 ? 100 : 0;
        }
    }
    
    // Get yearly trends
    $yearlyParams = $params; // Use same parameters
    $yearlyResult = $db->fetchAll("
        SELECT 
            YEAR(b.generated_at) as year,
            COUNT(b.bill_id) as total_bills,
            SUM(b.amount_payable) as total_revenue,
            SUM(CASE 
                WHEN b.status = 'Paid' THEN b.amount_payable 
                WHEN b.status = 'Partially Paid' THEN COALESCE(payments_made.total_paid, 0)
                ELSE 0 
            END) as collected_revenue,
            AVG(b.amount_payable) as average_bill_amount,
            COUNT(DISTINCT CASE 
                WHEN b.bill_type = 'Business' THEN bs.business_id
                WHEN b.bill_type = 'Property' THEN pr.property_id
            END) as unique_accounts
        FROM bills b
        LEFT JOIN businesses bs ON b.bill_type = 'Business' AND b.reference_id = bs.business_id
        LEFT JOIN properties pr ON b.bill_type = 'Property' AND b.reference_id = pr.property_id
        LEFT JOIN (
            SELECT 
                b2.bill_id,
                SUM(p.amount_paid) as total_paid
            FROM payments p
            JOIN bills b2 ON p.bill_id = b2.bill_id
            WHERE p.payment_status = 'Successful'
            GROUP BY b2.bill_id
        ) payments_made ON b.bill_id = payments_made.bill_id
        WHERE $whereClause
        GROUP BY YEAR(b.generated_at)
        ORDER BY YEAR(b.generated_at) ASC
    ", $yearlyParams);
    
    if ($yearlyResult !== false && is_array($yearlyResult)) {
        $yearlyTrends = $yearlyResult;
    }
    
    // Calculate yearly growth rates
    for ($i = 1; $i < count($yearlyTrends); $i++) {
        $current = $yearlyTrends[$i];
        $previous = $yearlyTrends[$i - 1];
        
        if ($previous['total_revenue'] > 0) {
            $yearlyTrends[$i]['revenue_growth'] = round((($current['total_revenue'] - $previous['total_revenue']) / $previous['total_revenue']) * 100, 2);
        } else {
            $yearlyTrends[$i]['revenue_growth'] = $current['total_revenue'] > 0 ? 100 : 0;
        }
        
        if ($previous['total_bills'] > 0) {
            $yearlyTrends[$i]['bill_growth'] = round((($current['total_bills'] - $previous['total_bills']) / $previous['total_bills']) * 100, 2);
        } else {
            $yearlyTrends[$i]['bill_growth'] = $current['total_bills'] > 0 ? 100 : 0;
        }
    }
    
    // Get seasonal analysis (by month across all years) - FIXED QUERY
    $seasonalParams = [$startDate];
    if ($billType) {
        $seasonalParams[] = $billType;
    }
    if ($zoneId) {
        $seasonalParams[] = $zoneId;
        $seasonalParams[] = $zoneId;
    }
    
    $seasonalResult = $db->fetchAll("
        SELECT 
            month_num,
            month_name,
            AVG(monthly_revenue) as avg_monthly_revenue,
            AVG(monthly_bills) as avg_monthly_bills,
            AVG(collection_rate) as avg_collection_rate,
            MAX(monthly_revenue) as max_monthly_revenue,
            MIN(monthly_revenue) as min_monthly_revenue
        FROM (
            SELECT 
                MONTH(b.generated_at) as month_num,
                MONTHNAME(b.generated_at) as month_name,
                SUM(b.amount_payable) as monthly_revenue,
                COUNT(b.bill_id) as monthly_bills,
                CASE WHEN SUM(b.amount_payable) > 0 THEN
                    (SUM(CASE 
                        WHEN b.status = 'Paid' THEN b.amount_payable 
                        WHEN b.status = 'Partially Paid' THEN COALESCE(payments_made.total_paid, 0)
                        ELSE 0 
                    END) / SUM(b.amount_payable)) * 100
                ELSE 0 END as collection_rate
            FROM bills b
            LEFT JOIN businesses bs ON b.bill_type = 'Business' AND b.reference_id = bs.business_id
            LEFT JOIN properties pr ON b.bill_type = 'Property' AND b.reference_id = pr.property_id
            LEFT JOIN (
                SELECT 
                    b2.bill_id,
                    SUM(p.amount_paid) as total_paid
                FROM payments p
                JOIN bills b2 ON p.bill_id = b2.bill_id
                WHERE p.payment_status = 'Successful'
                GROUP BY b2.bill_id
            ) payments_made ON b.bill_id = payments_made.bill_id
            WHERE DATE(b.generated_at) >= ?
            " . ($billType ? "AND b.bill_type = ?" : "") . "
            " . ($zoneId ? "AND ((b.bill_type = 'Business' AND bs.zone_id = ?) OR (b.bill_type = 'Property' AND pr.zone_id = ?))" : "") . "
            GROUP BY YEAR(b.generated_at), MONTH(b.generated_at)
        ) monthly_data
        GROUP BY month_num, month_name
        ORDER BY month_num
    ", $seasonalParams);
    
    if ($seasonalResult !== false && is_array($seasonalResult)) {
        $seasonalAnalysis = $seasonalResult;
    }
    
    // Get collection trends over time - FIXED PARAMETERIZED QUERY
    $collectionParams = [$startDate];
    $collectionWhere = "p.payment_status = 'Successful' AND DATE(p.payment_date) >= ?";
    
    if ($billType) {
        $collectionWhere .= " AND b.bill_type = ?";
        $collectionParams[] = $billType;
    }
    
    if ($zoneId) {
        $collectionWhere .= " AND ((b.bill_type = 'Business' AND bs.zone_id = ?) OR (b.bill_type = 'Property' AND pr.zone_id = ?))";
        $collectionParams[] = $zoneId;
        $collectionParams[] = $zoneId;
    }
    
    $collectionResult = $db->fetchAll("
        SELECT 
            DATE_FORMAT(p.payment_date, '%Y-%m') as month_year,
            DATE_FORMAT(p.payment_date, '%M %Y') as month_label,
            COUNT(p.payment_id) as total_payments,
            SUM(p.amount_paid) as total_collected,
            AVG(p.amount_paid) as average_payment,
            COUNT(CASE WHEN p.payment_method = 'Mobile Money' THEN 1 END) as mobile_money_count,
            COUNT(CASE WHEN p.payment_method = 'Cash' THEN 1 END) as cash_count,
            COUNT(CASE WHEN p.payment_method = 'Bank Transfer' THEN 1 END) as bank_transfer_count,
            COUNT(CASE WHEN p.payment_method = 'Online' THEN 1 END) as online_count,
            SUM(CASE WHEN p.payment_method = 'Mobile Money' THEN p.amount_paid ELSE 0 END) as mobile_money_amount,
            SUM(CASE WHEN p.payment_method = 'Cash' THEN p.amount_paid ELSE 0 END) as cash_amount,
            SUM(CASE WHEN p.payment_method = 'Bank Transfer' THEN p.amount_paid ELSE 0 END) as bank_transfer_amount,
            SUM(CASE WHEN p.payment_method = 'Online' THEN p.amount_paid ELSE 0 END) as online_amount
        FROM payments p
        JOIN bills b ON p.bill_id = b.bill_id
        LEFT JOIN businesses bs ON b.bill_type = 'Business' AND b.reference_id = bs.business_id
        LEFT JOIN properties pr ON b.bill_type = 'Property' AND b.reference_id = pr.property_id
        WHERE $collectionWhere
        GROUP BY DATE_FORMAT(p.payment_date, '%Y-%m')
        ORDER BY p.payment_date ASC
    ", $collectionParams);
    
    if ($collectionResult !== false && is_array($collectionResult)) {
        $collectionTrends = $collectionResult;
    }
    
    // Calculate payment method percentages
    foreach ($collectionTrends as &$trend) {
        if ($trend['total_collected'] > 0) {
            $trend['mobile_money_percentage'] = round(($trend['mobile_money_amount'] / $trend['total_collected']) * 100, 1);
            $trend['cash_percentage'] = round(($trend['cash_amount'] / $trend['total_collected']) * 100, 1);
            $trend['bank_transfer_percentage'] = round(($trend['bank_transfer_amount'] / $trend['total_collected']) * 100, 1);
            $trend['online_percentage'] = round(($trend['online_amount'] / $trend['total_collected']) * 100, 1);
        } else {
            $trend['mobile_money_percentage'] = 0;
            $trend['cash_percentage'] = 0;
            $trend['bank_transfer_percentage'] = 0;
            $trend['online_percentage'] = 0;
        }
    }
    
    // Generate forecast data if requested
    if ($forecast == '1' && count($monthlyTrends) >= 6) {
        $forecastData = generateImprovedForecast($monthlyTrends, 6); // Forecast next 6 months
    }
    
    // Get overall growth metrics
    if (count($monthlyTrends) > 1) {
        $firstMonth = $monthlyTrends[0];
        $lastMonth = end($monthlyTrends);
        
        $totalGrowthPeriod = count($monthlyTrends);
        $totalRevenueGrowth = $firstMonth['total_revenue'] > 0 ? 
            round((($lastMonth['total_revenue'] - $firstMonth['total_revenue']) / $firstMonth['total_revenue']) * 100, 2) : 0;
        
        $avgMonthlyGrowth = 0;
        $growthCount = 0;
        foreach ($monthlyTrends as $trend) {
            if (isset($trend['mom_growth'])) {
                $avgMonthlyGrowth += $trend['mom_growth'];
                $growthCount++;
            }
        }
        $avgMonthlyGrowth = $growthCount > 0 ? round($avgMonthlyGrowth / $growthCount, 2) : 0;
        
        $growthMetrics = [
            'total_revenue_growth' => $totalRevenueGrowth,
            'average_monthly_growth' => $avgMonthlyGrowth,
            'growth_period_months' => $totalGrowthPeriod,
            'revenue_start' => $firstMonth['total_revenue'],
            'revenue_end' => $lastMonth['total_revenue'],
            'bills_start' => $firstMonth['total_bills'],
            'bills_end' => $lastMonth['total_bills']
        ];
    }
    
    // Get all zones for filter dropdown
    $zonesResult = $db->fetchAll("SELECT zone_id, zone_name FROM zones ORDER BY zone_name");
    if ($zonesResult !== false && is_array($zonesResult)) {
        $allZones = $zonesResult;
    }
    
} catch (Exception $e) {
    writeLog("Billing trends report error: " . $e->getMessage(), 'ERROR');
    setFlashMessage('error', 'An error occurred while loading trends data.');
}

// Improved forecast function with trend analysis
function generateImprovedForecast($historicalData, $periods) {
    if (count($historicalData) < 3) return [];
    
    $revenues = array_column($historicalData, 'total_revenue');
    $n = count($revenues);
    
    // Calculate moving average for trend detection
    $movingAvg = [];
    $windowSize = min(3, $n);
    
    for ($i = $windowSize - 1; $i < $n; $i++) {
        $sum = 0;
        for ($j = $i - $windowSize + 1; $j <= $i; $j++) {
            $sum += $revenues[$j];
        }
        $movingAvg[] = $sum / $windowSize;
    }
    
    // Linear regression on recent data (last 6 months or available data)
    $recentData = array_slice($revenues, -min(6, $n));
    $recentN = count($recentData);
    
    $sumX = 0; $sumY = 0; $sumXY = 0; $sumX2 = 0;
    
    for ($i = 0; $i < $recentN; $i++) {
        $x = $i + 1;
        $y = $recentData[$i];
        $sumX += $x;
        $sumY += $y;
        $sumXY += $x * $y;
        $sumX2 += $x * $x;
    }
    
    $denominator = ($recentN * $sumX2 - $sumX * $sumX);
    if ($denominator == 0) {
        // Fallback to simple average
        $slope = 0;
        $intercept = array_sum($recentData) / $recentN;
    } else {
        $slope = ($recentN * $sumXY - $sumX * $sumY) / $denominator;
        $intercept = ($sumY - $slope * $sumX) / $recentN;
    }
    
    // Calculate variance for confidence intervals
    $variance = 0;
    $avgRevenue = array_sum($recentData) / $recentN;
    foreach ($recentData as $revenue) {
        $variance += pow($revenue - $avgRevenue, 2);
    }
    $stdDev = sqrt($variance / $recentN);
    
    $forecast = [];
    $lastMonth = end($historicalData);
    
    for ($i = 1; $i <= $periods; $i++) {
        $nextMonth = date('Y-m', strtotime($lastMonth['month_year'] . " +$i month"));
        $forecastRevenue = max(0, $intercept + $slope * ($recentN + $i));
        
        // Add some seasonal adjustment based on historical patterns
        $monthNum = date('n', strtotime($nextMonth . '-01'));
        $seasonalMultiplier = 1.0; // Could be enhanced with historical seasonal data
        
        $adjustedForecast = $forecastRevenue * $seasonalMultiplier;
        
        // Calculate confidence based on data stability and time distance
        $baseConfidence = 85;
        $dataStabilityFactor = min(20, $stdDev / max(1, $avgRevenue) * 100);
        $timeFactor = $i * 3; // Decrease confidence over time
        $confidence = max(50, $baseConfidence - $dataStabilityFactor - $timeFactor);
        
        $forecast[] = [
            'month_year' => $nextMonth,
            'month_label' => date('M Y', strtotime($nextMonth . '-01')),
            'forecasted_revenue' => round($adjustedForecast, 2),
            'confidence' => round($confidence, 0),
            'lower_bound' => round(max(0, $adjustedForecast - $stdDev), 2),
            'upper_bound' => round($adjustedForecast + $stdDev, 2)
        ];
    }
    
    return $forecast;
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
    
    <!-- Chart.js from CDN as fallback -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>
    <script src="https://cdn.jsdelivr.net/npm/date-fns@2.29.3/index.min.js"></script>
    <!-- Local Chart.js as primary -->
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
        .icon-chart-line::before { content: "📈"; }
        .icon-chart::before { content: "📊"; }
        .icon-trending-up::before { content: "📈"; }
        .icon-trending-down::before { content: "📉"; }
        .icon-calendar::before { content: "📅"; }
        .icon-download::before { content: "⬇️"; }
        .icon-filter::before { content: "🔍"; }
        .icon-print::before { content: "🖨️"; }
        .icon-back::before { content: "↩️"; }
        .icon-receipt::before { content: "🧾"; }
        .icon-bell::before { content: "🔔"; }
        .icon-crystal-ball::before { content: "🔮"; }
        .icon-money::before { content: "💰"; }
        .icon-target::before { content: "🎯"; }
        .icon-growth::before { content: "📈"; }
        .icon-seasonal::before { content: "🌱"; }
        .icon-payment::before { content: "💳"; }
        .icon-analytics::before { content: "📊"; }
        
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
        
        /* Header - Trends theme (blue/cyan) */
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
        
        /* Metrics Overview */
        .metrics-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .metric-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }
        
        .metric-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        
        .metric-card.growth {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border-left: 4px solid #22c55e;
        }
        
        .metric-card.revenue {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border-left: 4px solid #0ea5e9;
        }
        
        .metric-card.collection {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-left: 4px solid #f59e0b;
        }
        
        .metric-card.forecast {
            background: linear-gradient(135deg, #f3e8ff 0%, #e9d5ff 100%);
            border-left: 4px solid #a855f7;
        }
        
        .metric-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: 15px;
            color: white;
        }
        
        .growth .metric-icon {
            background: #22c55e;
        }
        
        .revenue .metric-icon {
            background: #0ea5e9;
        }
        
        .collection .metric-icon {
            background: #f59e0b;
        }
        
        .forecast .metric-icon {
            background: #a855f7;
        }
        
        .metric-value {
            font-size: 28px;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 5px;
        }
        
        .metric-label {
            color: #64748b;
            font-size: 14px;
            margin-bottom: 10px;
        }
        
        .metric-change {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .metric-change.positive {
            color: #16a34a;
        }
        
        .metric-change.negative {
            color: #dc2626;
        }
        
        .metric-change.neutral {
            color: #64748b;
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
        
        .chart-options {
            display: flex;
            gap: 10px;
        }
        
        .chart-option {
            padding: 8px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 6px;
            background: white;
            color: #64748b;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .chart-option.active {
            background: #0ea5e9;
            border-color: #0ea5e9;
            color: white;
        }
        
        .chart-canvas {
            display: block;
            width: 100% !important;
            height: 300px !important;
        }
        
        /* Seasonal Analysis */
        .seasonal-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .seasonal-item {
            background: white;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: all 0.3s;
        }
        
        .seasonal-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }
        
        .seasonal-month {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 10px;
        }
        
        .seasonal-value {
            font-size: 20px;
            font-weight: bold;
            color: #0ea5e9;
            margin-bottom: 5px;
        }
        
        .seasonal-label {
            font-size: 12px;
            color: #64748b;
        }
        
        /* Forecast Section */
        .forecast-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .forecast-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .forecast-item:last-child {
            border-bottom: none;
        }
        
        .forecast-month {
            font-weight: 600;
            color: #2d3748;
        }
        
        .forecast-value {
            font-weight: bold;
            color: #a855f7;
        }
        
        .forecast-confidence {
            font-size: 12px;
            color: #64748b;
            background: #f8fafc;
            padding: 4px 8px;
            border-radius: 4px;
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
            
            .metrics-overview {
                grid-template-columns: 1fr;
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
                <span style="color: #2d3748; font-weight: 600;">Billing Trends</span>
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
                        <i class="fas fa-chart-line"></i>
                        <span class="icon-chart-line" style="display: none;"></span>
                    </div>
                    <div class="header-details">
                        <h1>Billing Trends & Analytics</h1>
                        <div class="header-description">
                            Historical trends, seasonal patterns, and growth projections for 
                            <?php echo ucfirst(str_replace('months', ' months', $period)); ?> period
                            <?php if ($forecast == '1'): ?> with 6-month forecast<?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="header-actions">
                    <button onclick="window.print()" class="btn btn-outline">
                        <i class="fas fa-print"></i>
                        <span class="icon-print" style="display: none;"></span>
                        Print Report
                    </button>
                    <a href="trends_report.php?<?php echo http_build_query(array_merge($_GET, ['export' => 'excel'])); ?>" class="btn btn-primary">
                        <i class="fas fa-download"></i>
                        <span class="icon-download" style="display: none;"></span>
                        Export Excel
                    </a>
                </div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <form class="filter-form" method="GET" action="">
                <div class="form-group">
                    <label class="form-label">Time Period</label>
                    <select name="period" class="form-control">
                        <option value="6months" <?php echo $period === '6months' ? 'selected' : ''; ?>>Last 6 Months</option>
                        <option value="12months" <?php echo $period === '12months' ? 'selected' : ''; ?>>Last 12 Months</option>
                        <option value="24months" <?php echo $period === '24months' ? 'selected' : ''; ?>>Last 24 Months</option>
                        <option value="36months" <?php echo $period === '36months' ? 'selected' : ''; ?>>Last 36 Months</option>
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
                    <label class="form-label">Zone</label>
                    <select name="zone_id" class="form-control">
                        <option value="">All Zones</option>
                        <?php foreach ($allZones as $zone): ?>
                            <option value="<?php echo $zone['zone_id']; ?>" <?php echo $zoneId == $zone['zone_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($zone['zone_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Comparison</label>
                    <select name="comparison" class="form-control">
                        <option value="yoy" <?php echo $comparison === 'yoy' ? 'selected' : ''; ?>>Year-over-Year</option>
                        <option value="mom" <?php echo $comparison === 'mom' ? 'selected' : ''; ?>>Month-over-Month</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Include Forecast</label>
                    <select name="forecast" class="form-control">
                        <option value="0" <?php echo $forecast === '0' ? 'selected' : ''; ?>>No</option>
                        <option value="1" <?php echo $forecast === '1' ? 'selected' : ''; ?>>Yes (6 months)</option>
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

        <!-- Metrics Overview -->
        <?php if (!empty($growthMetrics)): ?>
        <div class="metrics-overview">
            <div class="metric-card growth">
                <div class="metric-icon">
                    <i class="fas fa-trending-up"></i>
                    <span class="icon-trending-up" style="display: none;"></span>
                </div>
                <div class="metric-value"><?php echo $growthMetrics['total_revenue_growth'] > 0 ? '+' : ''; ?><?php echo number_format($growthMetrics['total_revenue_growth'], 1); ?>%</div>
                <div class="metric-label">Total Revenue Growth</div>
                <div class="metric-change <?php echo $growthMetrics['total_revenue_growth'] >= 0 ? 'positive' : 'negative'; ?>">
                    <i class="fas fa-<?php echo $growthMetrics['total_revenue_growth'] >= 0 ? 'arrow-up' : 'arrow-down'; ?>"></i>
                    Over <?php echo $growthMetrics['growth_period_months']; ?> months
                </div>
            </div>
            
            <div class="metric-card revenue">
                <div class="metric-icon">
                    <i class="fas fa-dollar-sign"></i>
                    <span class="icon-money" style="display: none;"></span>
                </div>
                <div class="metric-value">GHS <?php echo number_format($growthMetrics['revenue_end'], 0); ?></div>
                <div class="metric-label">Current Period Revenue</div>
                <div class="metric-change <?php echo $growthMetrics['revenue_end'] >= $growthMetrics['revenue_start'] ? 'positive' : 'negative'; ?>">
                    <i class="fas fa-info-circle"></i>
                    From GHS <?php echo number_format($growthMetrics['revenue_start'], 0); ?>
                </div>
            </div>
            
            <div class="metric-card collection">
                <div class="metric-icon">
                    <i class="fas fa-chart-bar"></i>
                    <span class="icon-chart" style="display: none;"></span>
                </div>
                <div class="metric-value"><?php echo $growthMetrics['average_monthly_growth'] > 0 ? '+' : ''; ?><?php echo number_format($growthMetrics['average_monthly_growth'], 1); ?>%</div>
                <div class="metric-label">Avg Monthly Growth</div>
                <div class="metric-change <?php echo $growthMetrics['average_monthly_growth'] >= 0 ? 'positive' : 'negative'; ?>">
                    <i class="fas fa-calendar"></i>
                    <span class="icon-calendar" style="display: none;"></span>
                    Per month average
                </div>
            </div>
            
            <?php if ($forecast == '1' && !empty($forecastData)): ?>
            <div class="metric-card forecast">
                <div class="metric-icon">
                    <i class="fas fa-crystal-ball"></i>
                    <span class="icon-crystal-ball" style="display: none;"></span>
                </div>
                <div class="metric-value">GHS <?php echo number_format($forecastData[0]['forecasted_revenue'], 0); ?></div>
                <div class="metric-label">Next Month Forecast</div>
                <div class="metric-change neutral">
                    <i class="fas fa-percentage"></i>
                    <?php echo $forecastData[0]['confidence']; ?>% confidence
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Revenue Trends Chart -->
        <?php if (!empty($monthlyTrends)): ?>
        <div class="chart-container">
            <div class="chart-header">
                <div class="chart-title">
                    <div class="chart-icon">
                        <i class="fas fa-chart-line"></i>
                        <span class="icon-chart-line" style="display: none;"></span>
                    </div>
                    Revenue Trends Over Time
                </div>
                <div class="chart-options">
                    <button class="chart-option active" onclick="toggleChartData('revenue')">Revenue</button>
                    <button class="chart-option" onclick="toggleChartData('bills')">Bill Count</button>
                    <button class="chart-option" onclick="toggleChartData('collection')">Collection Rate</button>
                </div>
            </div>
            <canvas id="revenueTrendsChart" class="chart-canvas"></canvas>
        </div>
        <?php endif; ?>

        <!-- Charts Grid -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 30px;">
            <!-- Business vs Property Trends -->
            <?php if (!empty($monthlyTrends)): ?>
            <div class="chart-container">
                <div class="chart-header">
                    <div class="chart-title">
                        <div class="chart-icon">
                            <i class="fas fa-building"></i>
                        </div>
                        Business vs Property Revenue
                    </div>
                </div>
                <canvas id="typeComparisonChart" class="chart-canvas"></canvas>
            </div>
            <?php endif; ?>
            
            <!-- Collection Method Trends -->
            <?php if (!empty($collectionTrends)): ?>
            <div class="chart-container">
                <div class="chart-header">
                    <div class="chart-title">
                        <div class="chart-icon">
                            <i class="fas fa-credit-card"></i>
                            <span class="icon-payment" style="display: none;"></span>
                        </div>
                        Payment Method Trends
                    </div>
                </div>
                <canvas id="paymentMethodChart" class="chart-canvas"></canvas>
            </div>
            <?php endif; ?>
        </div>

        <!-- Seasonal Analysis -->
        <?php if (!empty($seasonalAnalysis)): ?>
        <div class="chart-container">
            <div class="chart-header">
                <div class="chart-title">
                    <div class="chart-icon">
                        <i class="fas fa-seedling"></i>
                        <span class="icon-seasonal" style="display: none;"></span>
                    </div>
                    Seasonal Patterns Analysis
                </div>
            </div>
            
            <div class="seasonal-grid">
                <?php foreach ($seasonalAnalysis as $season): ?>
                    <div class="seasonal-item">
                        <div class="seasonal-month"><?php echo htmlspecialchars($season['month_name']); ?></div>
                        <div class="seasonal-value">GHS <?php echo number_format($season['avg_monthly_revenue'], 0); ?></div>
                        <div class="seasonal-label">Avg Revenue</div>
                        <div style="margin-top: 10px; font-size: 12px; color: #64748b;">
                            <?php echo number_format($season['avg_collection_rate'], 1); ?>% collection rate
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <canvas id="seasonalChart" class="chart-canvas" style="margin-top: 20px;"></canvas>
        </div>
        <?php endif; ?>

        <!-- Forecast Section -->
        <?php if ($forecast == '1' && !empty($forecastData)): ?>
        <div class="forecast-section">
            <div class="chart-header">
                <div class="chart-title">
                    <div class="chart-icon">
                        <i class="fas fa-crystal-ball"></i>
                        <span class="icon-crystal-ball" style="display: none;"></span>
                    </div>
                    6-Month Revenue Forecast
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 30px;">
                <div>
                    <canvas id="forecastChart" class="chart-canvas"></canvas>
                </div>
                
                <div>
                    <?php foreach ($forecastData as $forecast_item): ?>
                        <div class="forecast-item">
                            <div class="forecast-month"><?php echo htmlspecialchars($forecast_item['month_label']); ?></div>
                            <div class="forecast-value">GHS <?php echo number_format($forecast_item['forecasted_revenue'], 0); ?></div>
                            <div class="forecast-confidence"><?php echo $forecast_item['confidence']; ?>% confidence</div>
                        </div>
                    <?php endforeach; ?>
                    
                    <div style="margin-top: 20px; padding: 15px; background: #f8fafc; border-radius: 8px; font-size: 12px; color: #64748b;">
                        <strong>Note:</strong> Forecasts are based on improved linear regression with trend analysis. 
                        Confidence levels consider data stability and decrease over time.
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Growth Analysis Chart -->
        <?php if (!empty($monthlyTrends) && count($monthlyTrends) > 1): ?>
        <div class="chart-container">
            <div class="chart-header">
                <div class="chart-title">
                    <div class="chart-icon">
                        <i class="fas fa-percentage"></i>
                        <span class="icon-growth" style="display: none;"></span>
                    </div>
                    Growth Rate Analysis (<?php echo ucfirst($comparison); ?>)
                </div>
            </div>
            <canvas id="growthChart" class="chart-canvas"></canvas>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // Check if Font Awesome loaded, if not show emoji icons
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM loaded, initializing trends report...');
            
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
            
            // Check if Chart.js is loaded and initialize charts
            if (typeof Chart === 'undefined') {
                console.error('Chart.js not loaded. Trying to use CDN version...');
                // The CDN version should be available as backup
                setTimeout(function() {
                    if (typeof Chart !== 'undefined') {
                        console.log('Chart.js loaded from CDN');
                        initializeCharts();
                    } else {
                        console.error('Chart.js failed to load from both local and CDN sources');
                    }
                }, 1000);
            } else {
                console.log('Chart.js loaded successfully');
                initializeCharts();
            }
        });

        // Chart instances
        let revenueTrendsChart, typeComparisonChart, paymentMethodChart, seasonalChart, forecastChart, growthChart;

        function initializeCharts() {
            try {
                console.log('Starting chart initialization...');
                
                // Revenue Trends Chart
                <?php if (!empty($monthlyTrends)): ?>
                console.log('Creating revenue trends chart...');
                const revenueCtx = document.getElementById('revenueTrendsChart');
                if (revenueCtx) {
                    revenueTrendsChart = new Chart(revenueCtx, {
                        type: 'line',
                        data: {
                            labels: [<?php echo "'" . implode("', '", array_column($monthlyTrends, 'month_label')) . "'"; ?>],
                            datasets: [{
                                label: 'Total Revenue',
                                data: [<?php echo implode(', ', array_column($monthlyTrends, 'total_revenue')); ?>],
                                borderColor: '#0ea5e9',
                                backgroundColor: 'rgba(14, 165, 233, 0.1)',
                                tension: 0.4,
                                fill: true
                            }, {
                                label: 'Collected Revenue',
                                data: [<?php echo implode(', ', array_column($monthlyTrends, 'collected_revenue')); ?>],
                                borderColor: '#22c55e',
                                backgroundColor: 'rgba(34, 197, 94, 0.1)',
                                tension: 0.4,
                                fill: false
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
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
                }
                <?php endif; ?>

                // Business vs Property Chart
                <?php if (!empty($monthlyTrends)): ?>
                console.log('Creating business vs property chart...');
                const typeCtx = document.getElementById('typeComparisonChart');
                if (typeCtx) {
                    typeComparisonChart = new Chart(typeCtx, {
                        type: 'bar',
                        data: {
                            labels: [<?php echo "'" . implode("', '", array_slice(array_column($monthlyTrends, 'month_label'), -6)) . "'"; ?>],
                            datasets: [{
                                label: 'Business Revenue',
                                data: [<?php echo implode(', ', array_slice(array_column($monthlyTrends, 'business_revenue'), -6)); ?>],
                                backgroundColor: '#1e40af',
                                borderColor: '#1d4ed8',
                                borderWidth: 1
                            }, {
                                label: 'Property Revenue',
                                data: [<?php echo implode(', ', array_slice(array_column($monthlyTrends, 'property_revenue'), -6)); ?>],
                                backgroundColor: '#16a34a',
                                borderColor: '#15803d',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                x: {
                                    stacked: true
                                },
                                y: {
                                    stacked: true,
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
                }
                <?php endif; ?>

                // Payment Method Chart
                <?php if (!empty($collectionTrends)): ?>
                console.log('Creating payment method chart...');
                const paymentCtx = document.getElementById('paymentMethodChart');
                if (paymentCtx) {
                    paymentMethodChart = new Chart(paymentCtx, {
                        type: 'line',
                        data: {
                            labels: [<?php echo "'" . implode("', '", array_column($collectionTrends, 'month_label')) . "'"; ?>],
                            datasets: [{
                                label: 'Mobile Money',
                                data: [<?php echo implode(', ', array_column($collectionTrends, 'mobile_money_percentage')); ?>],
                                borderColor: '#8b5cf6',
                                backgroundColor: 'rgba(139, 92, 246, 0.1)',
                                tension: 0.4
                            }, {
                                label: 'Cash',
                                data: [<?php echo implode(', ', array_column($collectionTrends, 'cash_percentage')); ?>],
                                borderColor: '#f59e0b',
                                backgroundColor: 'rgba(245, 158, 11, 0.1)',
                                tension: 0.4
                            }, {
                                label: 'Bank Transfer',
                                data: [<?php echo implode(', ', array_column($collectionTrends, 'bank_transfer_percentage')); ?>],
                                borderColor: '#06b6d4',
                                backgroundColor: 'rgba(6, 182, 212, 0.1)',
                                tension: 0.4
                            }, {
                                label: 'Online',
                                data: [<?php echo implode(', ', array_column($collectionTrends, 'online_percentage')); ?>],
                                borderColor: '#ef4444',
                                backgroundColor: 'rgba(239, 68, 68, 0.1)',
                                tension: 0.4
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    max: 100,
                                    ticks: {
                                        callback: function(value) {
                                            return value + '%';
                                        }
                                    }
                                }
                            },
                            plugins: {
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            return context.dataset.label + ': ' + context.parsed.y + '%';
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
                <?php endif; ?>

                // Seasonal Chart
                <?php if (!empty($seasonalAnalysis)): ?>
                console.log('Creating seasonal chart...');
                const seasonalCtx = document.getElementById('seasonalChart');
                if (seasonalCtx) {
                    seasonalChart = new Chart(seasonalCtx, {
                        type: 'radar',
                        data: {
                            labels: [<?php echo "'" . implode("', '", array_column($seasonalAnalysis, 'month_name')) . "'"; ?>],
                            datasets: [{
                                label: 'Average Revenue (GHS)',
                                data: [<?php echo implode(', ', array_column($seasonalAnalysis, 'avg_monthly_revenue')); ?>],
                                borderColor: '#0ea5e9',
                                backgroundColor: 'rgba(14, 165, 233, 0.2)',
                                pointBackgroundColor: '#0ea5e9'
                            }, {
                                label: 'Collection Rate (%)',
                                data: [<?php echo implode(', ', array_column($seasonalAnalysis, 'avg_collection_rate')); ?>],
                                borderColor: '#22c55e',
                                backgroundColor: 'rgba(34, 197, 94, 0.2)',
                                pointBackgroundColor: '#22c55e'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                r: {
                                    beginAtZero: true
                                }
                            }
                        }
                    });
                }
                <?php endif; ?>

                // Forecast Chart
                <?php if ($forecast == '1' && !empty($forecastData)): ?>
                console.log('Creating forecast chart...');
                const forecastCtx = document.getElementById('forecastChart');
                if (forecastCtx) {
                    // Combine historical and forecast data
                    const historicalLabels = [<?php echo "'" . implode("', '", array_slice(array_column($monthlyTrends, 'month_label'), -6)) . "'"; ?>];
                    const forecastLabels = [<?php echo "'" . implode("', '", array_column($forecastData, 'month_label')) . "'"; ?>];
                    const allLabels = [...historicalLabels, ...forecastLabels];
                    
                    const historicalData = [<?php echo implode(', ', array_slice(array_column($monthlyTrends, 'total_revenue'), -6)); ?>];
                    const forecastValues = [<?php echo implode(', ', array_column($forecastData, 'forecasted_revenue')); ?>];
                    
                    forecastChart = new Chart(forecastCtx, {
                        type: 'line',
                        data: {
                            labels: allLabels,
                            datasets: [{
                                label: 'Historical Revenue',
                                data: [...historicalData, ...Array(forecastValues.length).fill(null)],
                                borderColor: '#0ea5e9',
                                backgroundColor: 'rgba(14, 165, 233, 0.1)',
                                tension: 0.4
                            }, {
                                label: 'Forecasted Revenue',
                                data: [...Array(historicalData.length).fill(null), ...forecastValues],
                                borderColor: '#a855f7',
                                backgroundColor: 'rgba(168, 85, 247, 0.1)',
                                borderDash: [5, 5],
                                tension: 0.4
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
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
                }
                <?php endif; ?>

                // Growth Chart
                <?php if (!empty($monthlyTrends) && count($monthlyTrends) > 1): ?>
                console.log('Creating growth chart...');
                const growthCtx = document.getElementById('growthChart');
                if (growthCtx) {
                    let growthData, growthLabel;
                    <?php if ($comparison === 'yoy'): ?>
                    growthData = [<?php echo implode(', ', array_map(function($trend) { return isset($trend['yoy_growth']) ? $trend['yoy_growth'] : 'null'; }, $monthlyTrends)); ?>];
                    growthLabel = 'Year-over-Year Growth';
                    <?php else: ?>
                    growthData = [<?php echo implode(', ', array_map(function($trend) { return isset($trend['mom_growth']) ? $trend['mom_growth'] : 'null'; }, $monthlyTrends)); ?>];
                    growthLabel = 'Month-over-Month Growth';
                    <?php endif; ?>
                    
                    growthChart = new Chart(growthCtx, {
                        type: 'bar',
                        data: {
                            labels: [<?php echo "'" . implode("', '", array_column($monthlyTrends, 'month_label')) . "'"; ?>],
                            datasets: [{
                                label: growthLabel,
                                data: growthData,
                                backgroundColor: function(context) {
                                    const value = context.parsed.y;
                                    return value >= 0 ? '#22c55e' : '#ef4444';
                                },
                                borderColor: function(context) {
                                    const value = context.parsed.y;
                                    return value >= 0 ? '#16a34a' : '#dc2626';
                                },
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                y: {
                                    ticks: {
                                        callback: function(value) {
                                            return value + '%';
                                        }
                                    }
                                }
                            },
                            plugins: {
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            return context.dataset.label + ': ' + context.parsed.y + '%';
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
                <?php endif; ?>
                
                console.log('All charts initialized successfully');
                
            } catch (error) {
                console.error('Error initializing charts:', error);
            }
        }

        // Toggle chart data function
        function toggleChartData(type) {
            console.log('Toggling chart data to:', type);
            
            // Update active button
            document.querySelectorAll('.chart-option').forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');
            
            // Update chart based on type
            if (revenueTrendsChart) {
                switch(type) {
                    case 'revenue':
                        revenueTrendsChart.data.datasets[0].data = [<?php echo implode(', ', array_column($monthlyTrends, 'total_revenue')); ?>];
                        revenueTrendsChart.data.datasets[1].data = [<?php echo implode(', ', array_column($monthlyTrends, 'collected_revenue')); ?>];
                        revenueTrendsChart.data.datasets[0].label = 'Total Revenue';
                        revenueTrendsChart.data.datasets[1].label = 'Collected Revenue';
                        break;
                    case 'bills':
                        revenueTrendsChart.data.datasets[0].data = [<?php echo implode(', ', array_column($monthlyTrends, 'total_bills')); ?>];
                        revenueTrendsChart.data.datasets[1].data = [<?php echo implode(', ', array_column($monthlyTrends, 'business_bills')); ?>];
                        revenueTrendsChart.data.datasets[0].label = 'Total Bills';
                        revenueTrendsChart.data.datasets[1].label = 'Business Bills';
                        break;
                    case 'collection':
                        revenueTrendsChart.data.datasets[0].data = [<?php echo implode(', ', array_column($monthlyTrends, 'collection_rate')); ?>];
                        revenueTrendsChart.data.datasets[1].data = Array(<?php echo count($monthlyTrends); ?>).fill(null);
                        revenueTrendsChart.data.datasets[0].label = 'Collection Rate (%)';
                        revenueTrendsChart.data.datasets[1].label = '';
                        break;
                }
                revenueTrendsChart.update();
            }
        }
    </script>
</body>
</html>