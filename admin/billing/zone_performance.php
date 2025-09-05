<?php
/**
 * Zone Performance Report - FIXED VERSION
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

$pageTitle = 'Zone Performance Report';
$currentUser = getCurrentUser();
$userDisplayName = getUserDisplayName($currentUser);

// Get filter parameters
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$billType = $_GET['bill_type'] ?? '';
$view = $_GET['view'] ?? 'report'; // 'report' or 'map'
$zoneId = $_GET['zone_id'] ?? '';

// Initialize variables with defaults
$zoneOverall = [];
$zoneComparison = [];
$subZonePerformance = [];
$zoneCollectionRates = [];
$zoneGrowth = [];
$zoneDetails = [];
$allZones = [];

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
        $whereConditions[] = "z.zone_id = ?";
        $params[] = $zoneId;
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Get overall zone performance with FIXED revenue calculation
    $zoneResult = $db->fetchAll("
        SELECT 
            z.zone_id,
            z.zone_name,
            z.zone_code,
            COUNT(DISTINCT b.bill_id) as total_bills,
            COUNT(DISTINCT CASE 
                WHEN b.bill_type = 'Business' THEN bs.business_id
                WHEN b.bill_type = 'Property' THEN pr.property_id
            END) as total_accounts,
            COALESCE(SUM(b.amount_payable), 0) as total_revenue,
            COALESCE(SUM(CASE 
                WHEN b.status = 'Paid' THEN b.amount_payable 
                WHEN b.status = 'Partially Paid' THEN COALESCE(payments_made.total_paid, 0)
                ELSE 0 
            END), 0) as collected_revenue,
            COALESCE(SUM(CASE WHEN b.status = 'Pending' THEN b.amount_payable ELSE 0 END), 0) as pending_revenue,
            COALESCE(SUM(CASE WHEN b.status = 'Overdue' THEN b.amount_payable ELSE 0 END), 0) as overdue_revenue,
            COALESCE(AVG(b.amount_payable), 0) as average_bill,
            COUNT(DISTINCT CASE WHEN b.bill_type = 'Business' THEN b.bill_id END) as business_bills,
            COUNT(DISTINCT CASE WHEN b.bill_type = 'Property' THEN b.bill_id END) as property_bills,
            COALESCE(all_payments.total_payments, 0) as total_payments,
            COALESCE(all_payments.payment_transactions, 0) as payment_transactions
        FROM zones z
        LEFT JOIN businesses bs ON z.zone_id = bs.zone_id
        LEFT JOIN properties pr ON z.zone_id = pr.zone_id
        LEFT JOIN bills b ON (
            (b.bill_type = 'Business' AND b.reference_id = bs.business_id) OR
            (b.bill_type = 'Property' AND b.reference_id = pr.property_id)
        )
        LEFT JOIN (
            SELECT 
                b2.bill_id,
                SUM(p.amount_paid) as total_paid
            FROM payments p
            JOIN bills b2 ON p.bill_id = b2.bill_id
            WHERE p.payment_status = 'Successful'
            GROUP BY b2.bill_id
        ) payments_made ON b.bill_id = payments_made.bill_id
        LEFT JOIN (
            SELECT 
                CASE 
                    WHEN b3.bill_type = 'Business' THEN bs3.zone_id
                    WHEN b3.bill_type = 'Property' THEN pr3.zone_id
                END as zone_id,
                SUM(p2.amount_paid) as total_payments,
                COUNT(p2.payment_id) as payment_transactions
            FROM payments p2
            JOIN bills b3 ON p2.bill_id = b3.bill_id
            LEFT JOIN businesses bs3 ON b3.bill_type = 'Business' AND b3.reference_id = bs3.business_id
            LEFT JOIN properties pr3 ON b3.bill_type = 'Property' AND b3.reference_id = pr3.property_id
            WHERE p2.payment_status = 'Successful'
            GROUP BY zone_id
        ) all_payments ON z.zone_id = all_payments.zone_id
        WHERE $whereClause
        GROUP BY z.zone_id, z.zone_name, z.zone_code
        HAVING total_bills > 0
        ORDER BY total_revenue DESC
    ", $params);
    
    if ($zoneResult !== false && is_array($zoneResult)) {
        $zoneOverall = $zoneResult;
    }
    
    // Calculate collection rates for each zone with IMPROVED validation
    foreach ($zoneOverall as &$zone) {
        // Use actual payments instead of just status-based calculation
        if (is_numeric($zone['total_revenue']) && $zone['total_revenue'] > 0) {
            // Use the total_payments (actual money collected) for more accurate rate
            $actualCollected = max($zone['collected_revenue'], $zone['total_payments']);
            $zone['collection_rate'] = round(($actualCollected / $zone['total_revenue']) * 100, 2);
            $zone['pending_rate'] = round(($zone['pending_revenue'] / $zone['total_revenue']) * 100, 2);
            $zone['overdue_rate'] = round(($zone['overdue_revenue'] / $zone['total_revenue']) * 100, 2);
            // Update collected_revenue to reflect actual payments
            $zone['collected_revenue'] = $actualCollected;
        } else {
            $zone['collection_rate'] = 0;
            $zone['pending_rate'] = 0;
            $zone['overdue_rate'] = 0;
        }
        
        // Ensure percentages don't exceed 100%
        $zone['collection_rate'] = min($zone['collection_rate'], 100);
    }
    
    // Get sub-zone performance for the top performing zones
    if (!empty($zoneOverall)) {
        $topZoneIds = array_slice(array_column($zoneOverall, 'zone_id'), 0, 5);
        if (!empty($topZoneIds)) {
            $subZoneResult = $db->fetchAll("
                SELECT 
                    sz.sub_zone_id,
                    sz.sub_zone_name,
                    sz.zone_id,
                    z.zone_name,
                    COUNT(DISTINCT b.bill_id) as total_bills,
                    COUNT(DISTINCT CASE 
                        WHEN b.bill_type = 'Business' THEN bs.business_id
                        WHEN b.bill_type = 'Property' THEN pr.property_id
                    END) as total_accounts,
                    COALESCE(SUM(b.amount_payable), 0) as total_revenue,
                    COALESCE(SUM(CASE 
                        WHEN b.status = 'Paid' THEN b.amount_payable 
                        WHEN b.status = 'Partially Paid' THEN COALESCE(payments_made.total_paid, 0)
                        ELSE 0 
                    END), 0) as collected_revenue,
                    COALESCE(AVG(b.amount_payable), 0) as average_bill
                FROM sub_zones sz
                JOIN zones z ON sz.zone_id = z.zone_id
                LEFT JOIN businesses bs ON sz.sub_zone_id = bs.sub_zone_id
                LEFT JOIN properties pr ON sz.sub_zone_id = pr.sub_zone_id
                LEFT JOIN bills b ON (
                    (b.bill_type = 'Business' AND b.reference_id = bs.business_id) OR
                    (b.bill_type = 'Property' AND b.reference_id = pr.property_id)
                )
                LEFT JOIN (
                    SELECT 
                        b2.bill_id,
                        SUM(p.amount_paid) as total_paid
                    FROM payments p
                    JOIN bills b2 ON p.bill_id = b2.bill_id
                    WHERE p.payment_status = 'Successful'
                    GROUP BY b2.bill_id
                ) payments_made ON b.bill_id = payments_made.bill_id
                WHERE sz.zone_id IN (" . implode(',', array_map('intval', $topZoneIds)) . ")
                AND DATE(b.generated_at) BETWEEN ? AND ?
                GROUP BY sz.sub_zone_id, sz.sub_zone_name, sz.zone_id, z.zone_name
                HAVING total_bills > 0
                ORDER BY z.zone_name, total_revenue DESC
            ", [$dateFrom, $dateTo]);
            
            if ($subZoneResult !== false && is_array($subZoneResult)) {
                $subZonePerformance = $subZoneResult;
            }
        }
    }
    
    // Get zone growth comparison (current period vs previous period)
    $daysDiff = (strtotime($dateTo) - strtotime($dateFrom)) / (60 * 60 * 24);
    $prevDateFrom = date('Y-m-d', strtotime($dateFrom . " -$daysDiff days"));
    $prevDateTo = date('Y-m-d', strtotime($dateTo . " -$daysDiff days"));
    
    $growthResult = $db->fetchAll("
        SELECT 
            z.zone_id,
            z.zone_name,
            COALESCE(SUM(CASE WHEN DATE(b.generated_at) BETWEEN ? AND ? THEN b.amount_payable ELSE 0 END), 0) as current_revenue,
            COALESCE(SUM(CASE WHEN DATE(b.generated_at) BETWEEN ? AND ? THEN b.amount_payable ELSE 0 END), 0) as previous_revenue,
            COUNT(CASE WHEN DATE(b.generated_at) BETWEEN ? AND ? THEN b.bill_id END) as current_bills,
            COUNT(CASE WHEN DATE(b.generated_at) BETWEEN ? AND ? THEN b.bill_id END) as previous_bills
        FROM zones z
        LEFT JOIN businesses bs ON z.zone_id = bs.zone_id
        LEFT JOIN properties pr ON z.zone_id = pr.zone_id
        LEFT JOIN bills b ON (
            (b.bill_type = 'Business' AND b.reference_id = bs.business_id) OR
            (b.bill_type = 'Property' AND b.reference_id = pr.property_id)
        )
        WHERE DATE(b.generated_at) BETWEEN ? AND ?
        OR DATE(b.generated_at) BETWEEN ? AND ?
        GROUP BY z.zone_id, z.zone_name
        HAVING current_revenue > 0 OR previous_revenue > 0
        ORDER BY current_revenue DESC
    ", [$dateFrom, $dateTo, $prevDateFrom, $prevDateTo, 
        $dateFrom, $dateTo, $prevDateFrom, $prevDateTo,
        $prevDateFrom, $dateTo]);
    
    if ($growthResult !== false && is_array($growthResult)) {
        $zoneGrowth = $growthResult;
        
        // Calculate growth percentages
        foreach ($zoneGrowth as &$growth) {
            if ($growth['previous_revenue'] > 0) {
                $growth['revenue_growth'] = round((($growth['current_revenue'] - $growth['previous_revenue']) / $growth['previous_revenue']) * 100, 2);
            } else {
                $growth['revenue_growth'] = $growth['current_revenue'] > 0 ? 100 : 0;
            }
            
            if ($growth['previous_bills'] > 0) {
                $growth['bills_growth'] = round((($growth['current_bills'] - $growth['previous_bills']) / $growth['previous_bills']) * 100, 2);
            } else {
                $growth['bills_growth'] = $growth['current_bills'] > 0 ? 100 : 0;
            }
        }
    }
    
    // Get detailed zone information for map view
    if ($view === 'map') {
        $detailsResult = $db->fetchAll("
            SELECT 
                z.zone_id,
                z.zone_name,
                z.zone_code,
                z.description,
                COUNT(DISTINCT bs.business_id) as business_count,
                COUNT(DISTINCT pr.property_id) as property_count,
                COUNT(DISTINCT sz.sub_zone_id) as sub_zone_count,
                COALESCE(zone_stats.total_revenue, 0) as total_revenue,
                COALESCE(zone_stats.collection_rate, 0) as collection_rate
            FROM zones z
            LEFT JOIN businesses bs ON z.zone_id = bs.zone_id AND bs.status = 'Active'
            LEFT JOIN properties pr ON z.zone_id = pr.zone_id
            LEFT JOIN sub_zones sz ON z.zone_id = sz.zone_id
            LEFT JOIN (
                SELECT 
                    CASE 
                        WHEN b.bill_type = 'Business' THEN bs2.zone_id
                        WHEN b.bill_type = 'Property' THEN pr2.zone_id
                    END as zone_id,
                    SUM(b.amount_payable) as total_revenue,
                    ROUND((SUM(CASE 
                        WHEN b.status = 'Paid' THEN b.amount_payable 
                        WHEN b.status = 'Partially Paid' THEN COALESCE(payments_made.total_paid, 0)
                        ELSE 0 
                    END) / NULLIF(SUM(b.amount_payable), 0)) * 100, 2) as collection_rate
                FROM bills b
                LEFT JOIN businesses bs2 ON b.bill_type = 'Business' AND b.reference_id = bs2.business_id
                LEFT JOIN properties pr2 ON b.bill_type = 'Property' AND b.reference_id = pr2.property_id
                LEFT JOIN (
                    SELECT 
                        b3.bill_id,
                        SUM(p.amount_paid) as total_paid
                    FROM payments p
                    JOIN bills b3 ON p.bill_id = b3.bill_id
                    WHERE p.payment_status = 'Successful'
                    GROUP BY b3.bill_id
                ) payments_made ON b.bill_id = payments_made.bill_id
                WHERE DATE(b.generated_at) BETWEEN ? AND ?
                GROUP BY zone_id
                HAVING zone_id IS NOT NULL
            ) zone_stats ON z.zone_id = zone_stats.zone_id
            GROUP BY z.zone_id, z.zone_name, z.zone_code, z.description
            ORDER BY z.zone_name
        ", [$dateFrom, $dateTo]);
        
        if ($detailsResult !== false && is_array($detailsResult)) {
            $zoneDetails = $detailsResult;
        }
    }
    
    // Get all zones for filter dropdown
    $zonesResult = $db->fetchAll("SELECT zone_id, zone_name FROM zones ORDER BY zone_name");
    if ($zonesResult !== false && is_array($zonesResult)) {
        $allZones = $zonesResult;
    }
    
} catch (Exception $e) {
    writeLog("Zone performance report error: " . $e->getMessage(), 'ERROR');
    setFlashMessage('error', 'An error occurred while loading zone performance data.');
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
    <!-- Local Chart.js as primary -->
    <script src="../../assets/js/chart.min.js"></script>
    
    <!-- Google Maps API (for map view) -->
    <?php if ($view === 'map'): ?>
    <script async defer src="https://maps.googleapis.com/maps/api/js?key=AIzaSyDg1CWNtJ8BHeclYP7VfltZZLIcY3TVHaI&callback=initMap"></script>
    <?php endif; ?>
    
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
        .icon-map::before { content: "🗺️"; }
        .icon-chart::before { content: "📊"; }
        .icon-building::before { content: "🏢"; }
        .icon-home::before { content: "🏠"; }
        .icon-trending-up::before { content: "📈"; }
        .icon-trending-down::before { content: "📉"; }
        .icon-equal::before { content: "➖"; }
        .icon-download::before { content: "⬇️"; }
        .icon-filter::before { content: "🔍"; }
        .icon-print::before { content: "🖨️"; }
        .icon-back::before { content: "↩️"; }
        .icon-receipt::before { content: "🧾"; }
        .icon-bell::before { content: "🔔"; }
        .icon-users::before { content: "👥"; }
        .icon-money::before { content: "💰"; }
        .icon-target::before { content: "🎯"; }
        .icon-star::before { content: "⭐"; }
        .icon-trophy::before { content: "🏆"; }
        
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
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border: 1px solid #86efac;
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
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
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
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 24px;
            box-shadow: 0 8px 25px rgba(34, 197, 94, 0.3);
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
        
        /* View Toggle */
        .view-toggle {
            background: white;
            border-radius: 10px;
            padding: 5px;
            display: flex;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .view-option {
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            color: #64748b;
            font-weight: 600;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .view-option.active {
            background: #22c55e;
            color: white;
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
            border-color: #22c55e;
            box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.1);
        }
        
        /* Zone Performance Cards */
        .zone-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .zone-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }
        
        .zone-card.rank-1 {
            border-left: 4px solid #fbbf24;
        }
        
        .zone-card.rank-2 {
            border-left: 4px solid #9ca3af;
        }
        
        .zone-card.rank-3 {
            border-left: 4px solid #cd7c2f;
        }
        
        .zone-card.rank-other {
            border-left: 4px solid #22c55e;
        }
        
        .zone-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .zone-title {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .zone-rank {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 18px;
        }
        
        .zone-rank.rank-1 {
            background: #fbbf24;
        }
        
        .zone-rank.rank-2 {
            background: #9ca3af;
        }
        
        .zone-rank.rank-3 {
            background: #cd7c2f;
        }
        
        .zone-rank.rank-other {
            background: #22c55e;
        }
        
        .zone-info h3 {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            margin: 0 0 5px 0;
        }
        
        .zone-code {
            font-size: 12px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .zone-collection-rate {
            font-size: 24px;
            font-weight: bold;
            color: #22c55e;
        }
        
        .zone-stats {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .zone-stat {
            text-align: center;
        }
        
        .zone-stat-value {
            font-size: 20px;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 5px;
        }
        
        .zone-stat-label {
            font-size: 11px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .zone-amount {
            font-family: monospace;
            color: #16a34a;
        }
        
        .zone-progress {
            margin-top: 15px;
        }
        
        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 12px;
            color: #64748b;
        }
        
        .progress-bar-container {
            height: 8px;
            background: #f1f5f9;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .progress-bar {
            height: 100%;
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            border-radius: 4px;
            transition: width 0.3s ease;
        }
        
        .progress-bar.low {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        }
        
        .progress-bar.medium {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }
        
        /* Growth Indicators */
        .growth-indicator {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .growth-indicator.positive {
            color: #16a34a;
        }
        
        .growth-indicator.negative {
            color: #dc2626;
        }
        
        .growth-indicator.neutral {
            color: #64748b;
        }
        
        /* Map Container */
        .map-container {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            height: 500px;
        }
        
        #map {
            width: 100%;
            height: 100%;
            border-radius: 10px;
        }
        
        /* Charts */
        .chart-container {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .chart-canvas {
            display: block;
            width: 100% !important;
            height: 300px !important;
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
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
        }
        
        /* Sub-zone Performance */
        .subzone-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .subzone-group {
            margin-bottom: 30px;
        }
        
        .subzone-group:last-child {
            margin-bottom: 0;
        }
        
        .subzone-group-title {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .subzone-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .subzone-item {
            padding: 20px;
            background: #f8fafc;
            border-radius: 10px;
            border-left: 3px solid #22c55e;
        }
        
        .subzone-name {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 10px;
        }
        
        .subzone-stats {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 10px;
            font-size: 12px;
            color: #64748b;
        }
        
        .subzone-stat-value {
            font-weight: 600;
            color: #16a34a;
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
            background: #22c55e;
            color: white;
        }
        
        .btn-primary:hover {
            background: #16a34a;
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
            color: #22c55e;
            border: 2px solid #22c55e;
        }
        
        .btn-outline:hover {
            background: #22c55e;
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
        
        /* No Data Message */
        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #64748b;
        }
        
        .no-data i {
            font-size: 48px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .no-data h3 {
            margin-bottom: 10px;
            color: #2d3748;
        }
        
        /* Debug info */
        .debug-info {
            background: #f3f4f6;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 15px;
            margin: 10px 0;
            font-family: monospace;
            font-size: 12px;
            color: #374151;
            display: none; /* Hidden by default, show for debugging */
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
            
            .zone-grid {
                grid-template-columns: 1fr;
            }
            
            .view-toggle {
                justify-content: center;
            }
        }
        
        /* Print Styles */
        @media print {
            .top-nav, .filter-section, .header-actions, .view-toggle {
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
                <span style="color: #2d3748; font-weight: 600;">Zone Performance</span>
            </div>
        </div>

        <!-- Flash Messages -->
        <?php if ($flashMessage): ?>
            <div class="alert alert-<?php echo $flashMessage['type']; ?>">
                <i class="fas fa-<?php echo $flashMessage['type'] === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                <div><?php echo htmlspecialchars($flashMessage['message']); ?></div>
            </div>
        <?php endif; ?>

        <!-- Debug Info (uncomment for debugging) -->
        <!-- <div class="debug-info" style="display: block;">
            <strong>Debug Information:</strong><br>
            Zone Data Count: <?php echo count($zoneOverall); ?><br>
            Sample Zone Collection Rate: <?php echo !empty($zoneOverall) ? $zoneOverall[0]['collection_rate'] : 'No data'; ?><br>
            Date Range: <?php echo $dateFrom; ?> to <?php echo $dateTo; ?>
        </div> -->

        <!-- Report Header -->
        <div class="report-header">
            <div class="header-content">
                <div class="header-info">
                    <div class="header-avatar">
                        <i class="fas fa-map-marked-alt"></i>
                        <span class="icon-map" style="display: none;"></span>
                    </div>
                    <div class="header-details">
                        <h1>Zone Performance Report</h1>
                        <div class="header-description">
                            Revenue and collection performance by geographical zones from <?php echo date('M j, Y', strtotime($dateFrom)); ?> 
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
                    <a href="zone_performance.php?<?php echo http_build_query(array_merge($_GET, ['export' => 'excel'])); ?>" class="btn btn-primary">
                        <i class="fas fa-download"></i>
                        <span class="icon-download" style="display: none;"></span>
                        Export Excel
                    </a>
                </div>
            </div>
        </div>

        <!-- View Toggle -->
        <div class="view-toggle">
            <a href="zone_performance.php?<?php echo http_build_query(array_merge($_GET, ['view' => 'report'])); ?>" 
               class="view-option <?php echo $view === 'report' ? 'active' : ''; ?>">
                <i class="fas fa-chart-bar"></i>
                <span class="icon-chart" style="display: none;"></span>
                Performance Report
            </a>
            <a href="zone_performance.php?<?php echo http_build_query(array_merge($_GET, ['view' => 'map'])); ?>" 
               class="view-option <?php echo $view === 'map' ? 'active' : ''; ?>">
                <i class="fas fa-map"></i>
                <span class="icon-map" style="display: none;"></span>
                Map View
            </a>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <form class="filter-form" method="GET" action="">
                <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
                
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
                    <label class="form-label">Specific Zone</label>
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
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i>
                        <span class="icon-filter" style="display: none;"></span>
                        Apply Filters
                    </button>
                </div>
            </form>
        </div>

        <?php if ($view === 'map'): ?>
            <!-- Map View -->
            <div class="map-container">
                <div id="map"></div>
            </div>
            
            <!-- Zone Details for Map -->
            <div class="chart-container">
                <div class="chart-header">
                    <div class="chart-title">
                        <div class="chart-icon">
                            <i class="fas fa-info-circle"></i>
                        </div>
                        Zone Details Summary
                    </div>
                </div>
                
                <?php if (empty($zoneDetails)): ?>
                    <div class="no-data">
                        <i class="fas fa-map-marked-alt"></i>
                        <h3>No Zone Data Available</h3>
                        <p>No zone data found for the selected date range and filters.</p>
                    </div>
                <?php else: ?>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
                        <?php foreach ($zoneDetails as $zone): ?>
                            <div style="padding: 20px; background: #f8fafc; border-radius: 10px; border-left: 3px solid #22c55e;">
                                <h4 style="color: #2d3748; margin-bottom: 10px;"><?php echo htmlspecialchars($zone['zone_name']); ?></h4>
                                <div style="font-size: 12px; color: #64748b; margin-bottom: 15px;">
                                    Code: <?php echo htmlspecialchars($zone['zone_code'] ?? 'N/A'); ?>
                                </div>
                                
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                                    <div style="text-align: center;">
                                        <div style="font-size: 20px; font-weight: bold; color: #1e40af;"><?php echo number_format($zone['business_count']); ?></div>
                                        <div style="font-size: 11px; color: #64748b; text-transform: uppercase;">Businesses</div>
                                    </div>
                                    <div style="text-align: center;">
                                        <div style="font-size: 20px; font-weight: bold; color: #16a34a;"><?php echo number_format($zone['property_count']); ?></div>
                                        <div style="font-size: 11px; color: #64748b; text-transform: uppercase;">Properties</div>
                                    </div>
                                </div>
                                
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <div>
                                        <div style="font-size: 14px; font-weight: 600; color: #16a34a;">GHS <?php echo number_format($zone['total_revenue'], 2); ?></div>
                                        <div style="font-size: 11px; color: #64748b;">Total Revenue</div>
                                    </div>
                                    <div style="text-align: right;">
                                        <div style="font-size: 14px; font-weight: 600; color: #22c55e;"><?php echo number_format($zone['collection_rate'], 1); ?>%</div>
                                        <div style="font-size: 11px; color: #64748b;">Collection Rate</div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
        <?php else: ?>
            <!-- Report View -->
            
            <!-- Zone Performance Cards -->
            <?php if (empty($zoneOverall)): ?>
                <div class="chart-container">
                    <div class="no-data">
                        <i class="fas fa-chart-bar"></i>
                        <h3>No Zone Performance Data</h3>
                        <p>No zone performance data found for the selected date range and filters.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="zone-grid">
                    <?php foreach ($zoneOverall as $index => $zone): ?>
                        <div class="zone-card rank-<?php echo $index < 3 ? ($index + 1) : 'other'; ?>">
                            <div class="zone-header">
                                <div class="zone-title">
                                    <div class="zone-rank rank-<?php echo $index < 3 ? ($index + 1) : 'other'; ?>">
                                        <?php echo $index + 1; ?>
                                    </div>
                                    <div class="zone-info">
                                        <h3><?php echo htmlspecialchars($zone['zone_name']); ?></h3>
                                        <div class="zone-code"><?php echo htmlspecialchars($zone['zone_code'] ?? 'N/A'); ?></div>
                                    </div>
                                </div>
                                <div class="zone-collection-rate"><?php echo number_format($zone['collection_rate'], 1); ?>%</div>
                            </div>
                            
                            <div class="zone-stats">
                                <div class="zone-stat">
                                    <div class="zone-stat-value zone-amount">GHS <?php echo number_format($zone['total_revenue'], 0); ?></div>
                                    <div class="zone-stat-label">Total Revenue</div>
                                </div>
                                <div class="zone-stat">
                                    <div class="zone-stat-value"><?php echo number_format($zone['total_bills']); ?></div>
                                    <div class="zone-stat-label">Total Bills</div>
                                </div>
                                <div class="zone-stat">
                                    <div class="zone-stat-value"><?php echo number_format($zone['total_accounts']); ?></div>
                                    <div class="zone-stat-label">Accounts</div>
                                </div>
                            </div>
                            
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                                <div style="text-align: center;">
                                    <div style="font-size: 16px; font-weight: bold; color: #1e40af;"><?php echo number_format($zone['business_bills']); ?></div>
                                    <div style="font-size: 11px; color: #64748b; text-transform: uppercase;">Business Bills</div>
                                </div>
                                <div style="text-align: center;">
                                    <div style="font-size: 16px; font-weight: bold; color: #16a34a;"><?php echo number_format($zone['property_bills']); ?></div>
                                    <div style="font-size: 11px; color: #64748b; text-transform: uppercase;">Property Bills</div>
                                </div>
                            </div>
                            
                            <div class="zone-progress">
                                <div class="progress-label">
                                    <span>Collection Progress</span>
                                    <span><?php echo number_format($zone['collection_rate'], 1); ?>%</span>
                                </div>
                                <div class="progress-bar-container">
                                    <div class="progress-bar <?php 
                                        echo $zone['collection_rate'] >= 80 ? '' : ($zone['collection_rate'] >= 60 ? 'medium' : 'low'); 
                                    ?>" style="width: <?php echo min(max($zone['collection_rate'], 0), 100); ?>%"></div>
                                </div>
                            </div>
                            
                            <!-- Growth Indicator -->
                            <?php
                            $growthData = null;
                            if (is_array($zoneGrowth)) {
                                $growth = array_filter($zoneGrowth, function($g) use ($zone) {
                                    return isset($g['zone_id']) && $g['zone_id'] == $zone['zone_id'];
                                });
                                $growthData = !empty($growth) ? array_values($growth)[0] : null;
                            }
                            ?>
                            <?php if ($growthData): ?>
                            <div style="margin-top: 15px; display: flex; justify-content: space-between;">
                                <div class="growth-indicator <?php 
                                    echo $growthData['revenue_growth'] > 0 ? 'positive' : ($growthData['revenue_growth'] < 0 ? 'negative' : 'neutral'); 
                                ?>">
                                    <i class="fas fa-<?php 
                                        echo $growthData['revenue_growth'] > 0 ? 'arrow-up' : ($growthData['revenue_growth'] < 0 ? 'arrow-down' : 'minus'); 
                                    ?>"></i>
                                    <?php echo abs($growthData['revenue_growth']); ?>% Revenue
                                </div>
                                <div class="growth-indicator <?php 
                                    echo $growthData['bills_growth'] > 0 ? 'positive' : ($growthData['bills_growth'] < 0 ? 'negative' : 'neutral'); 
                                ?>">
                                    <i class="fas fa-<?php 
                                        echo $growthData['bills_growth'] > 0 ? 'arrow-up' : ($growthData['bills_growth'] < 0 ? 'arrow-down' : 'minus'); 
                                    ?>"></i>
                                    <?php echo abs($growthData['bills_growth']); ?>% Bills
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Charts Section -->
                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 30px; margin-bottom: 30px;">
                    <!-- Zone Revenue Comparison Chart -->
                    <div class="chart-container">
                        <div class="chart-header">
                            <div class="chart-title">
                                <div class="chart-icon">
                                    <i class="fas fa-chart-bar"></i>
                                    <span class="icon-chart" style="display: none;"></span>
                                </div>
                                Revenue by Zone
                            </div>
                        </div>
                        <canvas id="zoneRevenueChart" class="chart-canvas"></canvas>
                    </div>
                    
                    <!-- Collection Rate Comparison -->
                    <div class="chart-container">
                        <div class="chart-header">
                            <div class="chart-title">
                                <div class="chart-icon">
                                    <i class="fas fa-bullseye"></i>
                                    <span class="icon-target" style="display: none;"></span>
                                </div>
                                Collection Rates
                            </div>
                        </div>
                        <canvas id="collectionRateChart" class="chart-canvas"></canvas>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Sub-zone Performance -->
            <?php if (!empty($subZonePerformance)): ?>
            <div class="subzone-section">
                <div class="chart-header">
                    <div class="chart-title">
                        <div class="chart-icon">
                            <i class="fas fa-sitemap"></i>
                        </div>
                        Sub-Zone Performance Breakdown
                    </div>
                </div>
                
                <?php
                $groupedSubZones = [];
                foreach ($subZonePerformance as $subzone) {
                    $groupedSubZones[$subzone['zone_name']][] = $subzone;
                }
                ?>
                
                <?php foreach ($groupedSubZones as $zoneName => $subzones): ?>
                    <div class="subzone-group">
                        <div class="subzone-group-title"><?php echo htmlspecialchars($zoneName); ?></div>
                        <div class="subzone-grid">
                            <?php foreach ($subzones as $subzone): ?>
                                <div class="subzone-item">
                                    <div class="subzone-name"><?php echo htmlspecialchars($subzone['sub_zone_name']); ?></div>
                                    <div class="subzone-stats">
                                        <div>
                                            <div class="subzone-stat-value">GHS <?php echo number_format($subzone['total_revenue'], 0); ?></div>
                                            <div>Revenue</div>
                                        </div>
                                        <div>
                                            <div class="subzone-stat-value"><?php echo number_format($subzone['total_bills']); ?></div>
                                            <div>Bills</div>
                                        </div>
                                        <div>
                                            <div class="subzone-stat-value"><?php echo number_format($subzone['total_accounts']); ?></div>
                                            <div>Accounts</div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>

    <script>
        // Check if Font Awesome loaded, if not show emoji icons
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM loaded, initializing...');
            
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
                        initializeChartsIfNeeded();
                    } else {
                        console.error('Chart.js failed to load from both local and CDN sources');
                    }
                }, 1000);
            } else {
                console.log('Chart.js loaded successfully');
                initializeChartsIfNeeded();
            }
        });

        function initializeChartsIfNeeded() {
            <?php if ($view === 'report' && !empty($zoneOverall)): ?>
                console.log('Initializing charts with zone data...');
                console.log('Zone count:', <?php echo count($zoneOverall); ?>);
                initializeCharts();
            <?php else: ?>
                console.log('Charts not initialized: view=<?php echo $view; ?>, zoneCount=<?php echo count($zoneOverall); ?>');
            <?php endif; ?>
        }

        <?php if ($view === 'report' && !empty($zoneOverall)): ?>
        function initializeCharts() {
            try {
                console.log('Starting chart initialization...');
                
                // Zone Revenue Chart
                const revenueCanvas = document.getElementById('zoneRevenueChart');
                if (!revenueCanvas) {
                    console.error('Revenue chart canvas not found');
                    return;
                }
                
                const revenueCtx = revenueCanvas.getContext('2d');
                if (!revenueCtx) {
                    console.error('Could not get revenue chart context');
                    return;
                }

                console.log('Creating revenue chart...');
                new Chart(revenueCtx, {
                    type: 'bar',
                    data: {
                        labels: [<?php echo "'" . implode("', '", array_slice(array_column($zoneOverall, 'zone_name'), 0, 10)) . "'"; ?>],
                        datasets: [{
                            label: 'Total Revenue',
                            data: [<?php echo implode(', ', array_slice(array_column($zoneOverall, 'total_revenue'), 0, 10)); ?>],
                            backgroundColor: '#22c55e',
                            borderColor: '#16a34a',
                            borderWidth: 1
                        }, {
                            label: 'Collected Revenue',
                            data: [<?php echo implode(', ', array_slice(array_column($zoneOverall, 'collected_revenue'), 0, 10)); ?>],
                            backgroundColor: '#16a34a',
                            borderColor: '#15803d',
                            borderWidth: 1
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

                // Collection Rate Chart
                const rateCanvas = document.getElementById('collectionRateChart');
                if (!rateCanvas) {
                    console.error('Collection rate chart canvas not found');
                    return;
                }
                
                const rateCtx = rateCanvas.getContext('2d');
                if (!rateCtx) {
                    console.error('Could not get collection rate chart context');
                    return;
                }

                const collectionRateData = [<?php echo implode(', ', array_slice(array_column($zoneOverall, 'collection_rate'), 0, 8)); ?>];
                const zoneLabels = [<?php echo "'" . implode("', '", array_slice(array_column($zoneOverall, 'zone_name'), 0, 8)) . "'"; ?>];
                
                console.log('Collection Rate Data:', collectionRateData);
                console.log('Zone Labels:', zoneLabels);

                // Filter out zones with 0% collection rate for better visualization
                const filteredData = [];
                const filteredLabels = [];
                const colors = [
                    '#22c55e', '#16a34a', '#15803d', '#14532d',
                    '#84cc16', '#65a30d', '#4d7c0f', '#365314'
                ];
                const filteredColors = [];

                for (let i = 0; i < collectionRateData.length; i++) {
                    if (collectionRateData[i] > 0) {
                        filteredData.push(collectionRateData[i]);
                        filteredLabels.push(zoneLabels[i]);
                        filteredColors.push(colors[i % colors.length]);
                    }
                }

                console.log('Filtered Data:', filteredData);
                
                if (filteredData.length === 0) {
                    // Show a message if no collection data
                    rateCtx.fillStyle = '#64748b';
                    rateCtx.font = '14px Arial';
                    rateCtx.textAlign = 'center';
                    rateCtx.fillText('No collection data available', rateCanvas.width / 2, rateCanvas.height / 2);
                    console.log('No collection data to display');
                    return;
                }

                console.log('Creating collection rate chart...');
                new Chart(rateCtx, {
                    type: 'doughnut',
                    data: {
                        labels: filteredLabels,
                        datasets: [{
                            data: filteredData,
                            backgroundColor: filteredColors,
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
                                    usePointStyle: true,
                                    padding: 15
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return context.label + ': ' + context.parsed + '%';
                                    }
                                }
                            }
                        }
                    }
                });
                
                console.log('Charts initialized successfully');
                
            } catch (error) {
                console.error('Error initializing charts:', error);
            }
        }
        <?php endif; ?>

        <?php if ($view === 'map'): ?>
        // Initialize Google Maps
        function initMap() {
            console.log('Initializing Google Maps...');
            
            // Default center (Accra coordinates)
            const mapCenter = { lat: 5.6037, lng: -0.1870 };
            
            const map = new google.maps.Map(document.getElementById('map'), {
                zoom: 10,
                center: mapCenter,
                mapTypeId: 'roadmap'
            });

            // Add markers for zones
            <?php foreach ($zoneDetails as $zone): ?>
                const zone<?php echo $zone['zone_id']; ?>Marker = new google.maps.Marker({
                    position: { 
                        lat: <?php echo 5.6037 + (rand(-100, 100) / 1000); ?>, 
                        lng: <?php echo -0.1870 + (rand(-100, 100) / 1000); ?> 
                    },
                    map: map,
                    title: '<?php echo htmlspecialchars($zone['zone_name']); ?>',
                    icon: {
                        url: 'data:image/svg+xml;charset=UTF-8,<svg xmlns="http://www.w3.org/2000/svg" width="30" height="40" viewBox="0 0 30 40"><path d="M15 0C6.7 0 0 6.7 0 15c0 8.3 15 25 15 25s15-16.7 15-25c0-8.3-6.7-15-15-15z" fill="%2322c55e"/><circle cx="15" cy="15" r="8" fill="white"/><text x="15" y="20" text-anchor="middle" font-family="Arial" font-size="10" fill="%2322c55e"><?php echo substr($zone['zone_name'], 0, 2); ?></text></svg>',
                        scaledSize: new google.maps.Size(30, 40)
                    }
                });

                const zone<?php echo $zone['zone_id']; ?>InfoWindow = new google.maps.InfoWindow({
                    content: `
                        <div style="padding: 10px; max-width: 200px;">
                            <h4 style="margin: 0 0 10px 0; color: #22c55e;"><?php echo htmlspecialchars($zone['zone_name']); ?></h4>
                            <div style="font-size: 12px; margin-bottom: 5px;"><strong>Businesses:</strong> <?php echo number_format($zone['business_count']); ?></div>
                            <div style="font-size: 12px; margin-bottom: 5px;"><strong>Properties:</strong> <?php echo number_format($zone['property_count']); ?></div>
                            <div style="font-size: 12px; margin-bottom: 5px;"><strong>Revenue:</strong> GHS <?php echo number_format($zone['total_revenue'], 2); ?></div>
                            <div style="font-size: 12px;"><strong>Collection Rate:</strong> <?php echo number_format($zone['collection_rate'], 1); ?>%</div>
                        </div>
                    `
                });

                zone<?php echo $zone['zone_id']; ?>Marker.addListener('click', function() {
                    zone<?php echo $zone['zone_id']; ?>InfoWindow.open(map, zone<?php echo $zone['zone_id']; ?>Marker);
                });
            <?php endforeach; ?>
            
            console.log('Google Maps initialized with <?php echo count($zoneDetails); ?> zone markers');
        }
        <?php endif; ?>
    </script>
</body>
</html>