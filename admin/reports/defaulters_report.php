<?php
/**
 * Defaulters Report for QUICKBILL 305
 * Updated with Bill Serving-Based Defaulter Detection
 * 
 * NEW DEFAULTER LOGIC:
 * 1. Bill Must Be Served: Only accounts with served bills (served_status = 'Served')
 * 2. Grace Period: 90 days from bill serving date (served_at)
 * 3. Outstanding Amount: Must have amount_payable > 0 after grace period
 * 4. Persistent: Once flagged (after grace period), remains defaulter until full payment
 * 5. Multi-Year: Accounts with overdue bills from previous years
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
$selectedZone = isset($_GET['zone']) ? intval($_GET['zone']) : 0;
$selectedType = isset($_GET['type']) ? $_GET['type'] : 'all'; // all, business, property
$minAmount = isset($_GET['min_amount']) ? floatval($_GET['min_amount']) : 0;
$sortBy = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'amount_desc'; // amount_desc, amount_asc, name_asc, days_overdue_desc
$gracePeriodDays = 90; // 90 days grace period after bill serving (3 months)

// Get current date info
$currentDate = new DateTime();
$currentYear = (int)$currentDate->format('Y');

// Export handling
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    // Export to Excel functionality would go here
    setFlashMessage('info', 'Excel export functionality will be implemented soon.');
    header('Location: defaulters_report.php');
    exit();
}

// Function to calculate remaining balance for an account
function calculateRemainingBalance($db, $accountType, $accountId, $amountPayable) {
    try {
        $totalPaymentsQuery = "SELECT COALESCE(SUM(p.amount_paid), 0) as total_paid
                              FROM payments p 
                              INNER JOIN bills b ON p.bill_id = b.bill_id 
                              WHERE b.bill_type = ? AND b.reference_id = ? 
                              AND p.payment_status = 'Successful'";
        $totalPaymentsResult = $db->fetchRow($totalPaymentsQuery, [ucfirst($accountType), $accountId]);
        $totalPaid = $totalPaymentsResult['total_paid'] ?? 0;
        
        return [
            'remaining_balance' => max(0, $amountPayable - $totalPaid),
            'total_paid' => $totalPaid,
            'amount_payable' => $amountPayable
        ];
    } catch (Exception $e) {
        return [
            'remaining_balance' => $amountPayable,
            'total_paid' => 0,
            'amount_payable' => $amountPayable
        ];
    }
}

// Get defaulters data
try {
    $db = new Database();
    
    // Initialize arrays
    $allDefaulters = [];
    $businessDefaulters = [];
    $propertyDefaulters = [];
    
    // Build WHERE clause for businesses - bill serving based defaulter detection
    $businessWhereConditions = [
        "bl.served_status = 'Served'",
        "bl.served_at IS NOT NULL",
        "DATEDIFF(CURDATE(), bl.served_at) > $gracePeriodDays"
    ];
    $businessParams = [];
    
    if ($selectedZone > 0) {
        $businessWhereConditions[] = "b.zone_id = ?";
        $businessParams[] = $selectedZone;
    }
    
    if ($minAmount > 0) {
        $businessWhereConditions[] = "(b.amount_payable - COALESCE(total_paid.total_paid, 0)) >= ?";
        $businessParams[] = $minAmount;
    }
    
    $businessWhereClause = implode(' AND ', $businessWhereConditions);
    
    // Build WHERE clause for properties - bill serving based defaulter detection
    $propertyWhereConditions = [
        "bl.served_status = 'Served'",
        "bl.served_at IS NOT NULL",
        "DATEDIFF(CURDATE(), bl.served_at) > $gracePeriodDays"
    ];
    $propertyParams = [];
    
    if ($selectedZone > 0) {
        $propertyWhereConditions[] = "p.zone_id = ?";
        $propertyParams[] = $selectedZone;
    }
    
    if ($minAmount > 0) {
        $propertyWhereConditions[] = "(p.amount_payable - COALESCE(total_paid.total_paid, 0)) >= ?";
        $propertyParams[] = $minAmount;
    }
    
    $propertyWhereClause = implode(' AND ', $propertyWhereConditions);
    
    // Get business defaulters
    if ($selectedType === 'all' || $selectedType === 'business') {
        $businessQuery = "
            SELECT 
                'Business' as type,
                b.business_id as id,
                b.account_number as account_number,
                b.business_name as name,
                b.owner_name,
                b.telephone,
                b.business_type as category,
                b.exact_location as location,
                b.amount_payable,
                b.old_bill,
                b.current_bill,
                b.previous_payments,
                b.arrears,
                z.zone_name,
                sz.sub_zone_name,
                bl.due_date,
                bl.bill_number,
                bl.billing_year,
                bl.served_at,
                bl.served_by,
                bl.delivery_notes,
                DATEDIFF(CURDATE(), bl.served_at) as days_since_served,
                DATEDIFF(CURDATE(), bl.due_date) as days_overdue,
                (b.amount_payable - COALESCE(total_paid.total_paid, 0)) as remaining_balance,
                COALESCE(total_paid.total_paid, 0) as total_paid,
                u.first_name as served_by_first_name,
                u.last_name as served_by_last_name,
                CASE 
                    WHEN bl.billing_year < $currentYear THEN 'Multi-Year'
                    ELSE 'Current Year'
                END as defaulter_type,
                CASE 
                    WHEN DATEDIFF(CURDATE(), bl.served_at) > 180 THEN 'Critical'
                    WHEN DATEDIFF(CURDATE(), bl.served_at) > 150 THEN 'High'
                    ELSE 'Moderate'
                END as urgency_level
            FROM businesses b
            LEFT JOIN zones z ON b.zone_id = z.zone_id
            LEFT JOIN sub_zones sz ON b.sub_zone_id = sz.sub_zone_id
            INNER JOIN bills bl ON bl.reference_id = b.business_id AND bl.bill_type = 'Business'
            LEFT JOIN users u ON bl.served_by = u.user_id
            LEFT JOIN (
                SELECT 
                    b_inner.reference_id,
                    SUM(p.amount_paid) as total_paid
                FROM payments p 
                INNER JOIN bills b_inner ON p.bill_id = b_inner.bill_id 
                WHERE b_inner.bill_type = 'Business' AND p.payment_status = 'Successful'
                GROUP BY b_inner.reference_id
            ) total_paid ON total_paid.reference_id = b.business_id
            WHERE $businessWhereClause
            AND (b.amount_payable - COALESCE(total_paid.total_paid, 0)) > 0
        ";
        
        $result = $db->fetchAll($businessQuery, $businessParams);
        $businessDefaulters = is_array($result) ? $result : [];
    }
    
    // Get property defaulters
    if ($selectedType === 'all' || $selectedType === 'property') {
        $propertyQuery = "
            SELECT 
                'Property' as type,
                p.property_id as id,
                p.property_number as account_number,
                p.owner_name as name,
                p.owner_name,
                p.telephone,
                CONCAT(p.structure, ' - ', p.property_use) as category,
                p.location,
                p.amount_payable,
                p.old_bill,
                p.current_bill,
                p.previous_payments,
                p.arrears,
                z.zone_name,
                sz.sub_zone_name,
                bl.due_date,
                bl.bill_number,
                bl.billing_year,
                bl.served_at,
                bl.served_by,
                bl.delivery_notes,
                DATEDIFF(CURDATE(), bl.served_at) as days_since_served,
                DATEDIFF(CURDATE(), bl.due_date) as days_overdue,
                (p.amount_payable - COALESCE(total_paid.total_paid, 0)) as remaining_balance,
                COALESCE(total_paid.total_paid, 0) as total_paid,
                u.first_name as served_by_first_name,
                u.last_name as served_by_last_name,
                CASE 
                    WHEN bl.billing_year < $currentYear THEN 'Multi-Year'
                    ELSE 'Current Year'
                END as defaulter_type,
                CASE 
                    WHEN DATEDIFF(CURDATE(), bl.served_at) > 180 THEN 'Critical'
                    WHEN DATEDIFF(CURDATE(), bl.served_at) > 150 THEN 'High'
                    ELSE 'Moderate'
                END as urgency_level
            FROM properties p
            LEFT JOIN zones z ON p.zone_id = z.zone_id
            LEFT JOIN sub_zones sz ON p.sub_zone_id = sz.sub_zone_id
            INNER JOIN bills bl ON bl.reference_id = p.property_id AND bl.bill_type = 'Property'
            LEFT JOIN users u ON bl.served_by = u.user_id
            LEFT JOIN (
                SELECT 
                    b_inner.reference_id,
                    SUM(py.amount_paid) as total_paid
                FROM payments py 
                INNER JOIN bills b_inner ON py.bill_id = b_inner.bill_id 
                WHERE b_inner.bill_type = 'Property' AND py.payment_status = 'Successful'
                GROUP BY b_inner.reference_id
            ) total_paid ON total_paid.reference_id = p.property_id
            WHERE $propertyWhereClause
            AND (p.amount_payable - COALESCE(total_paid.total_paid, 0)) > 0
        ";
        
        $result = $db->fetchAll($propertyQuery, $propertyParams);
        $propertyDefaulters = is_array($result) ? $result : [];
    }
    
    // Combine defaulters
    $allDefaulters = array_merge($businessDefaulters, $propertyDefaulters);
    
    // Sort based on selected criteria
    switch ($sortBy) {
        case 'amount_asc':
            usort($allDefaulters, function($a, $b) {
                return $a['remaining_balance'] <=> $b['remaining_balance'];
            });
            break;
        case 'name_asc':
            usort($allDefaulters, function($a, $b) {
                return strcmp($a['name'], $b['name']);
            });
            break;
        case 'days_overdue_desc':
            usort($allDefaulters, function($a, $b) {
                return $b['days_since_served'] <=> $a['days_since_served'];
            });
            break;
        case 'amount_desc':
        default:
            usort($allDefaulters, function($a, $b) {
                return $b['remaining_balance'] <=> $a['remaining_balance'];
            });
            break;
    }
    
    // Calculate statistics
    $totalDefaulters = count($allDefaulters);
    $totalOutstanding = $totalDefaulters > 0 ? array_sum(array_column($allDefaulters, 'remaining_balance')) : 0;
    $businessCount = count($businessDefaulters);
    $propertyCount = count($propertyDefaulters);
    
    // Calculate urgency statistics
    $criticalCount = 0;
    $highCount = 0;
    $moderateCount = 0;
    $multiYearDefaulters = 0;
    $currentYearDefaulters = 0;
    
    foreach ($allDefaulters as $defaulter) {
        switch ($defaulter['urgency_level']) {
            case 'Critical':
                $criticalCount++;
                break;
            case 'High':
                $highCount++;
                break;
            case 'Moderate':
                $moderateCount++;
                break;
        }
        
        if (isset($defaulter['defaulter_type']) && $defaulter['defaulter_type'] === 'Multi-Year') {
            $multiYearDefaulters++;
        } else {
            $currentYearDefaulters++;
        }
    }
    
    // Get zone breakdown
    $zoneBreakdown = [];
    foreach ($allDefaulters as $defaulter) {
        $zoneName = $defaulter['zone_name'] ?? 'Unassigned';
        if (!isset($zoneBreakdown[$zoneName])) {
            $zoneBreakdown[$zoneName] = [
                'count' => 0,
                'total_amount' => 0
            ];
        }
        $zoneBreakdown[$zoneName]['count']++;
        $zoneBreakdown[$zoneName]['total_amount'] += $defaulter['remaining_balance'];
    }
    
    // Sort zone breakdown by amount
    if (!empty($zoneBreakdown)) {
        uasort($zoneBreakdown, function($a, $b) {
            return $b['total_amount'] <=> $a['total_amount'];
        });
    }
    
    // Get available zones for filter
    $zonesQuery = "SELECT zone_id, zone_name FROM zones ORDER BY zone_name";
    $zonesResult = $db->fetchAll($zonesQuery);
    $availableZones = is_array($zonesResult) ? $zonesResult : [];
    
    // Get serving statistics
    $servingStatsQuery = "
        SELECT 
            COUNT(*) as total_bills,
            SUM(CASE WHEN served_status = 'Served' THEN 1 ELSE 0 END) as served_bills,
            SUM(CASE WHEN served_status = 'Served' AND served_at IS NOT NULL AND DATEDIFF(CURDATE(), served_at) > $gracePeriodDays THEN 1 ELSE 0 END) as eligible_for_defaulter_check,
            AVG(CASE WHEN served_status = 'Served' AND served_at IS NOT NULL THEN DATEDIFF(CURDATE(), served_at) ELSE NULL END) as avg_days_since_serving
        FROM bills 
        WHERE billing_year >= " . ($currentYear - 1);
    
    $servingStats = $db->fetchRow($servingStatsQuery);
    $servingStats = $servingStats ?: [
        'total_bills' => 0,
        'served_bills' => 0,
        'eligible_for_defaulter_check' => 0,
        'avg_days_since_serving' => 0
    ];
    
    // Prepare chart data safely
    $chartLabels = !empty($zoneBreakdown) ? array_keys($zoneBreakdown) : [];
    $chartCountData = !empty($zoneBreakdown) ? array_values(array_column($zoneBreakdown, 'count')) : [];
    $chartAmountData = !empty($zoneBreakdown) ? array_values(array_column($zoneBreakdown, 'total_amount')) : [];
    
} catch (Exception $e) {
    $allDefaulters = [];
    $totalDefaulters = 0;
    $totalOutstanding = 0;
    $businessCount = 0;
    $propertyCount = 0;
    $zoneBreakdown = [];
    $availableZones = [];
    $criticalCount = 0;
    $highCount = 0;
    $moderateCount = 0;
    $multiYearDefaulters = 0;
    $currentYearDefaulters = 0;
    $servingStats = [
        'total_bills' => 0,
        'served_bills' => 0,
        'eligible_for_defaulter_check' => 0,
        'avg_days_since_serving' => 0
    ];
    $chartLabels = [];
    $chartCountData = [];
    $chartAmountData = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Defaulters Report - <?php echo APP_NAME; ?></title>

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
        .icon-exclamation::before { content: "❗"; }
        .icon-filter::before { content: "🔍"; }
        .icon-download::before { content: "📥"; }
        .icon-back::before { content: "⬅️"; }
        .icon-search::before { content: "🔍"; }
        .icon-excel::before { content: "📊"; }
        .icon-user::before { content: "👤"; }
        .icon-chevron-down::before { content: "⌄"; }
        .icon-smile::before { content: "😊"; }
        .icon-calendar::before { content: "📅"; }
        .icon-clock::before { content: "🕐"; }
        .icon-truck::before { content: "🚛"; }

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

        /* Alert */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-warning {
            background: #fef5e7;
            color: #744210;
            border: 1px solid #fbd38d;
        }

        .alert-info {
            background: #ebf8ff;
            color: #2a4365;
            border: 1px solid #90cdf4;
        }

        .alert-success {
            background: #f0fff4;
            color: #22543d;
            border: 1px solid #9ae6b4;
        }

        /* Billing Status Card */
        .billing-status-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 5px solid #667eea;
        }

        .billing-status-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }

        .billing-status-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
        }

        .billing-status-info h3 {
            margin: 0;
            color: #2d3748;
            font-size: 18px;
        }

        .billing-status-info p {
            margin: 0;
            color: #718096;
            font-size: 14px;
        }

        .billing-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .billing-detail {
            text-align: center;
            padding: 10px;
            background: #f7fafc;
            border-radius: 8px;
        }

        .billing-detail-label {
            font-size: 12px;
            color: #718096;
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .billing-detail-value {
            font-size: 16px;
            font-weight: bold;
            color: #2d3748;
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

        .stat-card.danger {
            background: linear-gradient(135deg, #f56565 0%, #e53e3e 100%);
            color: white;
        }

        .stat-card.warning {
            background: linear-gradient(135deg, #ed8936 0%, #dd6b20 100%);
            color: white;
        }

        .stat-card.info {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
        }

        .stat-card.success {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
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
            grid-template-columns: 1fr 1fr;
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
            position: sticky;
            top: 0;
            z-index: 10;
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

        .badge-danger {
            background: #fed7d7;
            color: #c53030;
        }

        .badge-warning {
            background: #fbd38d;
            color: #744210;
        }

        .badge-info {
            background: #bee3f8;
            color: #2a4365;
        }

        .badge-success {
            background: #c6f6d5;
            color: #22543d;
        }

        /* Amount styling */
        .amount-danger {
            color: #e53e3e;
            font-weight: 600;
        }

        .amount-warning {
            color: #dd6b20;
            font-weight: 600;
        }

        .amount-moderate {
            color: #4299e1;
            font-weight: 600;
        }

        /* Urgency level styling */
        .urgency-critical {
            background: #fed7d7;
            color: #c53030;
            font-weight: bold;
            border: 1px solid #f56565;
            animation: pulse 2s infinite;
        }

        .urgency-high {
            background: #fbd38d;
            color: #744210;
            font-weight: bold;
        }

        .urgency-moderate {
            background: #bee3f8;
            color: #2a4365;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }

        /* Days since served styling */
        .days-served {
            font-size: 12px;
            padding: 2px 6px;
            border-radius: 12px;
            font-weight: 600;
        }

        .days-served.critical {
            background: #fed7d7;
            color: #c53030;
        }

        .days-served.high {
            background: #fbd38d;
            color: #744210;
        }

        .days-served.moderate {
            background: #bee3f8;
            color: #2a4365;
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

        .btn-danger {
            background: linear-gradient(135deg, #f56565 0%, #e53e3e 100%);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(245, 101, 101, 0.4);
            color: white;
        }

        /* Search Box */
        .search-box {
            position: relative;
            margin-bottom: 20px;
        }

        .search-input {
            width: 100%;
            padding: 12px 15px 12px 45px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .search-input:focus {
            border-color: #667eea;
            outline: none;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #718096;
        }

        /* Served by styling */
        .served-by {
            font-size: 11px;
            color: #718096;
            font-style: italic;
            margin-top: 2px;
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

            .billing-details {
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
                    <h1 class="page-title">⚠️ Defaulters Report</h1>
                    <p class="page-subtitle">Bill serving-based defaulter detection with 90-day grace period</p>
                </div>
                <div class="page-actions">
                    <a href="index.php" class="btn btn-outline">
                        <span class="icon-back"></span>
                        Back to Reports
                    </a>
                    <?php if ($totalDefaulters > 0): ?>
                    <a href="?export=excel&<?php echo http_build_query($_GET); ?>" class="btn btn-success">
                        <span class="icon-excel"></span>
                        Export Excel
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Billing Status Card -->
            <div class="billing-status-card">
                <div class="billing-status-header">
                    <div class="billing-status-icon">
                        <span class="icon-truck"></span>
                    </div>
                    <div class="billing-status-info">
                        <h3>Bill Serving-Based Defaulter Detection</h3>
                        <p>Only accounts with served bills past 90-day grace period are flagged</p>
                    </div>
                </div>
                
                <div class="billing-details">
                    <div class="billing-detail">
                        <div class="billing-detail-label">Grace Period</div>
                        <div class="billing-detail-value"><?php echo $gracePeriodDays; ?> Days</div>
                    </div>
                    <div class="billing-detail">
                        <div class="billing-detail-label">Total Bills</div>
                        <div class="billing-detail-value"><?php echo number_format($servingStats['total_bills']); ?></div>
                    </div>
                    <div class="billing-detail">
                        <div class="billing-detail-label">Bills Served</div>
                        <div class="billing-detail-value"><?php echo number_format($servingStats['served_bills']); ?></div>
                    </div>
                    <div class="billing-detail">
                        <div class="billing-detail-label">Eligible for Check</div>
                        <div class="billing-detail-value"><?php echo number_format($servingStats['eligible_for_defaulter_check']); ?></div>
                    </div>
                    <div class="billing-detail">
                        <div class="billing-detail-label">Avg Days Since Serving</div>
                        <div class="billing-detail-value"><?php echo number_format($servingStats['avg_days_since_serving'] ?? 0, 1); ?></div>
                    </div>
                    <div class="billing-detail">
                        <div class="billing-detail-label">Detection Logic</div>
                        <div class="billing-detail-value" style="font-size: 12px; line-height: 1.3;">
                            Served + 90 Days<br>
                            + Outstanding Balance
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($totalDefaulters > 0): ?>
            <!-- Active Defaulters Alert -->
            <div class="alert alert-warning">
                <span class="icon-exclamation"></span>
                <strong>Action Required:</strong> There are <?php echo number_format($totalDefaulters); ?> accounts 
                with outstanding payments totaling ₵ <?php echo number_format($totalOutstanding, 2); ?> 
                past the 90-day grace period from bill serving.
            </div>
            <?php else: ?>
            <!-- No Defaulters Alert -->
            <div class="alert alert-success">
                <span class="icon-smile"></span>
                <strong>Excellent!</strong> No accounts are defaulting past the 90-day grace period after bill serving.
            </div>
            <?php endif; ?>

            <!-- Filters -->
            <div class="filters-card">
                <form method="GET" action="">
                    <div class="filters-grid">
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
                            <label class="form-label">Type</label>
                            <select name="type" class="form-control">
                                <option value="all" <?php echo $selectedType == 'all' ? 'selected' : ''; ?>>All Types</option>
                                <option value="business" <?php echo $selectedType == 'business' ? 'selected' : ''; ?>>Businesses Only</option>
                                <option value="property" <?php echo $selectedType == 'property' ? 'selected' : ''; ?>>Properties Only</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Minimum Amount</label>
                            <input type="number" name="min_amount" class="form-control" 
                                value="<?php echo $minAmount; ?>" step="0.01" min="0" placeholder="0.00">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Sort By</label>
                            <select name="sort_by" class="form-control">
                                <option value="amount_desc" <?php echo $sortBy == 'amount_desc' ? 'selected' : ''; ?>>Amount (High to Low)</option>
                                <option value="amount_asc" <?php echo $sortBy == 'amount_asc' ? 'selected' : ''; ?>>Amount (Low to High)</option>
                                <option value="days_overdue_desc" <?php echo $sortBy == 'days_overdue_desc' ? 'selected' : ''; ?>>Days Since Served (Most to Least)</option>
                                <option value="name_asc" <?php echo $sortBy == 'name_asc' ? 'selected' : ''; ?>>Name (A to Z)</option>
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
                <div class="stat-card danger">
                    <div class="stat-header">
                        <div class="stat-title">Total Defaulters</div>
                        <div class="stat-icon">
                            <span class="icon-exclamation"></span>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($totalDefaulters); ?></div>
                    <div class="stat-subtitle">Past 90-day grace period</div>
                </div>

                <div class="stat-card warning">
                    <div class="stat-header">
                        <div class="stat-title">Total Outstanding</div>
                        <div class="stat-icon">
                            <span class="icon-money"></span>
                        </div>
                    </div>
                    <div class="stat-value">₵ <?php echo number_format($totalOutstanding, 2); ?></div>
                    <div class="stat-subtitle">Total amount due</div>
                </div>

                <div class="stat-card info">
                    <div class="stat-header">
                        <div class="stat-title">Critical Cases</div>
                        <div class="stat-icon">
                            <span class="icon-clock"></span>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($criticalCount); ?></div>
                    <div class="stat-subtitle">>180 days since served</div>
                </div>

                <div class="stat-card success">
                    <div class="stat-header">
                        <div class="stat-title">High Priority</div>
                        <div class="stat-icon">
                            <span class="icon-warning"></span>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($highCount); ?></div>
                    <div class="stat-subtitle">151-180 days since served</div>
                </div>
            </div>

            <!-- Charts -->
            <?php if (!empty($zoneBreakdown)): ?>
            <div class="charts-grid">
                <!-- Zone Breakdown Chart -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">🗺️ Defaulters by Zone</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="zoneBreakdownChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Outstanding Amount by Zone -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">💰 Outstanding Amount by Zone</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="outstandingAmountChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Defaulters List -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">📋 Defaulters List (<?php echo number_format($totalDefaulters); ?> records)</h5>
                </div>
                <div class="card-body">
                    <?php if ($totalDefaulters > 0): ?>
                    <!-- Search Box -->
                    <div class="search-box">
                        <span class="icon-search search-icon"></span>
                        <input type="text" id="searchInput" class="search-input" 
                            placeholder="Search by name, account number, location, or urgency level...">
                    </div>

                    <div class="table-responsive">
                        <table class="table" id="defaultersTable">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Account Number</th>
                                    <th>Name</th>
                                    <th>Contact</th>
                                    <th>Zone</th>
                                    <th>Outstanding Amount</th>
                                    <th>Days Since Served</th>
                                    <th>Urgency Level</th>
                                    <th>Served By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allDefaulters as $defaulter): ?>
                                    <tr>
                                        <td>
                                            <span class="badge badge-<?php echo $defaulter['type'] == 'Business' ? 'info' : 'success'; ?>">
                                                <?php echo htmlspecialchars($defaulter['type']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($defaulter['account_number']); ?></strong>
                                            <?php if (isset($defaulter['defaulter_type']) && $defaulter['defaulter_type'] === 'Multi-Year'): ?>
                                                <span style="color: #e53e3e; font-weight: bold;">⚠️</span>
                                            <?php endif; ?>
                                            <br>
                                            <small style="color: #718096;">
                                                <?php echo htmlspecialchars($defaulter['bill_number'] ?? 'N/A'); ?>
                                                <?php if (isset($defaulter['billing_year'])): ?>
                                                    (<?php echo $defaulter['billing_year']; ?>)
                                                <?php endif; ?>
                                            </small>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($defaulter['name']); ?></strong><br>
                                            <small style="color: #718096;">
                                                <?php echo htmlspecialchars($defaulter['owner_name']); ?>
                                            </small>
                                        </td>
                                        <td><?php echo htmlspecialchars($defaulter['telephone'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php echo htmlspecialchars($defaulter['zone_name'] ?? 'Unassigned'); ?>
                                            <?php if ($defaulter['sub_zone_name']): ?>
                                                <br><small style="color: #718096;">
                                                    <?php echo htmlspecialchars($defaulter['sub_zone_name']); ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                            $amount = $defaulter['remaining_balance'];
                                            $class = $amount >= 1000 ? 'amount-danger' : 
                                                    ($amount >= 500 ? 'amount-warning' : 'amount-moderate');
                                            ?>
                                            <span class="<?php echo $class; ?>">
                                                ₵ <?php echo number_format($amount, 2); ?>
                                            </span>
                                            <br>
                                            <small style="color: #718096;">
                                                Paid: ₵ <?php echo number_format($defaulter['total_paid'], 2); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?php 
                                            $daysSinceServed = $defaulter['days_since_served'];
                                            $daysClass = $daysSinceServed > 180 ? 'critical' : 
                                                        ($daysSinceServed > 150 ? 'high' : 'moderate');
                                            ?>
                                            <span class="days-served <?php echo $daysClass; ?>">
                                                <?php echo $daysSinceServed; ?> days
                                            </span>
                                            <br>
                                            <small style="color: #718096;">
                                                Served: <?php echo date('M d, Y', strtotime($defaulter['served_at'])); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?php 
                                            $urgencyLevel = $defaulter['urgency_level'];
                                            $urgencyClass = 'urgency-' . strtolower($urgencyLevel);
                                            ?>
                                            <span class="badge <?php echo $urgencyClass; ?>">
                                                <?php echo htmlspecialchars($urgencyLevel); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($defaulter['served_by_first_name']): ?>
                                                <div class="served-by">
                                                    <?php echo htmlspecialchars($defaulter['served_by_first_name'] . ' ' . $defaulter['served_by_last_name']); ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: #718096;">Not recorded</span>
                                            <?php endif; ?>
                                            <?php if ($defaulter['delivery_notes']): ?>
                                                <br>
                                                <small style="color: #718096; font-style: italic;">
                                                    "<?php echo htmlspecialchars(substr($defaulter['delivery_notes'], 0, 50)) . (strlen($defaulter['delivery_notes']) > 50 ? '...' : ''); ?>"
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                        <div style="text-align: center; color: #718096; padding: 40px;">
                            <div style="font-size: 48px; margin-bottom: 10px; opacity: 0.5;">
                                <span class="icon-smile"></span>
                            </div>
                            <h3>No defaulters found!</h3>
                            <p>No accounts have outstanding payments past the 90-day grace period after bill serving.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Chart data (prepared safely in PHP)
        const zoneLabels = <?php echo json_encode($chartLabels); ?>;
        const zoneCountData = <?php echo json_encode($chartCountData); ?>;
        const zoneAmountData = <?php echo json_encode($chartAmountData); ?>;
        const hasDefaulters = <?php echo json_encode($totalDefaulters > 0); ?>;

        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            if (hasDefaulters && zoneLabels.length > 0) {
                initializeCharts();
            }
            if (hasDefaulters) {
                initializeSearch();
            }
        });

        function initializeCharts() {
            if (typeof Chart === 'undefined' || zoneLabels.length === 0) {
                console.log('Chart.js not available or no data');
                return;
            }

            // Zone Breakdown Chart
            const zoneCtx = document.getElementById('zoneBreakdownChart');
            if (zoneCtx) {
                new Chart(zoneCtx.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: zoneLabels,
                        datasets: [{
                            data: zoneCountData,
                            backgroundColor: [
                                '#f56565',
                                '#ed8936',
                                '#4299e1',
                                '#9f7aea',
                                '#48bb78',
                                '#667eea'
                            ],
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
                                    padding: 20,
                                    usePointStyle: true
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return context.label + ': ' + context.parsed + ' defaulters';
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Outstanding Amount Chart
            const amountCtx = document.getElementById('outstandingAmountChart');
            if (amountCtx) {
                new Chart(amountCtx.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: zoneLabels,
                        datasets: [{
                            label: 'Outstanding Amount (₵)',
                            data: zoneAmountData,
                            backgroundColor: 'rgba(245, 101, 101, 0.8)',
                            borderColor: '#f56565',
                            borderWidth: 2,
                            borderRadius: 5
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
                                        return 'Outstanding: ₵ ' + context.parsed.y.toLocaleString();
                                    }
                                }
                            }
                        }
                    }
                });
            }
        }

        function initializeSearch() {
            const searchInput = document.getElementById('searchInput');
            const table = document.getElementById('defaultersTable');
            
            if (!searchInput || !table) return;
            
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));

            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    if (text.includes(searchTerm)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            });
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