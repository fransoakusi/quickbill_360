<?php
/**
 * Defaulters Report - UPDATED VERSION
 * QUICKBILL 305 - Admin Panel
 * Only flags defaulters after September if amount_payable not fully paid
 * Removed bill generation date restriction - checks all bills
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

$pageTitle = 'Defaulters Report';
$currentUser = getCurrentUser();
$userDisplayName = getUserDisplayName($currentUser);

// Get filter parameters with validation
$zoneId = isset($_GET['zone_id']) && is_numeric($_GET['zone_id']) ? intval($_GET['zone_id']) : '';
$billType = isset($_GET['bill_type']) && in_array($_GET['bill_type'], ['Business', 'Property']) ? $_GET['bill_type'] : '';
$minAmount = isset($_GET['min_amount']) && is_numeric($_GET['min_amount']) ? floatval($_GET['min_amount']) : '';
$agingPeriod = isset($_GET['aging_period']) && in_array($_GET['aging_period'], ['30', '60', '90', '180', 'over180']) ? $_GET['aging_period'] : '';
$export = $_GET['export'] ?? '';

// September cutoff logic
$currentYear = date('Y');
$currentDate = new DateTime();
$septemberCutoff = new DateTime($currentYear . '-09-30'); // End of September
$isAfterSeptember = $currentDate > $septemberCutoff;

// Initialize variables with defaults - ENSURE THEY'RE ALWAYS ARRAYS
$defaultersSummary = [
    'total_bills' => 0,
    'total_accounts' => 0,
    'total_outstanding' => 0,
    'average_outstanding' => 0,
    'min_outstanding' => 0,
    'max_outstanding' => 0,
    'average_age_days' => 0
];
$defaultersList = [];
$agingBreakdown = [];
$zoneBreakdown = [];
$typeBreakdown = [];
$zones = [];
$errorMessages = []; // Track any database errors

try {
    $db = new Database();
    
    // Test database connection first
    if (!$db) {
        throw new Exception("Failed to establish database connection");
    }
    
    // Build WHERE clause for filters
    $whereConditions = ["1 = 1"];
    $params = [];
    
    // UPDATED: Only flag defaulters after September 30th - removed bill generation date check
    if (!$isAfterSeptember) {
        // Before September 30th - no defaulters should be flagged
        $whereConditions[] = "1 = 0"; // This will return no results
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
    
    if ($agingPeriod) {
        // Age calculation from September 30th
        $septemberCutoffStr = $currentYear . '-09-30';
        switch ($agingPeriod) {
            case '30':
                $whereConditions[] = "DATEDIFF(NOW(), ?) <= 30";
                $params[] = $septemberCutoffStr;
                break;
            case '60':
                $whereConditions[] = "DATEDIFF(NOW(), ?) BETWEEN 31 AND 60";
                $params[] = $septemberCutoffStr;
                break;
            case '90':
                $whereConditions[] = "DATEDIFF(NOW(), ?) BETWEEN 61 AND 90";
                $params[] = $septemberCutoffStr;
                break;
            case '180':
                $whereConditions[] = "DATEDIFF(NOW(), ?) BETWEEN 91 AND 180";
                $params[] = $septemberCutoffStr;
                break;
            case 'over180':
                $whereConditions[] = "DATEDIFF(NOW(), ?) > 180";
                $params[] = $septemberCutoffStr;
                break;
        }
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Get zones first (this is simpler and helps test database connection)
    try {
        $zonesResult = $db->fetchAll("SELECT zone_id, zone_name FROM zones ORDER BY zone_name");
        if ($zonesResult !== false && is_array($zonesResult)) {
            $zones = $zonesResult;
        } else {
            $errorMessages[] = "Failed to fetch zones data";
        }
    } catch (Exception $e) {
        $errorMessages[] = "Zone query error: " . $e->getMessage();
        writeLog("Zone query error: " . $e->getMessage(), 'ERROR');
    }
    
    // Only proceed with complex queries if we're after September
    if ($isAfterSeptember) {
        // UPDATED: Get defaulters summary - removed bill generation date restriction
        try {
            $summaryQuery = "
                SELECT 
                    COUNT(*) as total_bills,
                    COUNT(DISTINCT 
                        CASE 
                            WHEN bill_type = 'Business' THEN CONCAT('B-', reference_id)
                            WHEN bill_type = 'Property' THEN CONCAT('P-', reference_id)
                        END
                    ) as total_accounts,
                    COALESCE(SUM(outstanding_amount), 0) as total_outstanding,
                    COALESCE(AVG(outstanding_amount), 0) as average_outstanding,
                    COALESCE(MIN(outstanding_amount), 0) as min_outstanding,
                    COALESCE(MAX(outstanding_amount), 0) as max_outstanding,
                    COALESCE(AVG(age_days), 0) as average_age_days
                FROM (
                    SELECT 
                        b.bill_id,
                        b.bill_type,
                        b.reference_id,
                        b.amount_payable,
                        COALESCE(payments.total_paid, 0) as total_paid,
                        (b.amount_payable - COALESCE(payments.total_paid, 0)) as outstanding_amount,
                        DATEDIFF(NOW(), ?) as age_days
                    FROM bills b
                    LEFT JOIN businesses bs ON b.bill_type = 'Business' AND b.reference_id = bs.business_id
                    LEFT JOIN properties pr ON b.bill_type = 'Property' AND b.reference_id = pr.property_id
                    LEFT JOIN (
                        SELECT bill_id, SUM(amount_paid) as total_paid
                        FROM payments 
                        WHERE payment_status = 'Successful'
                        GROUP BY bill_id
                    ) payments ON b.bill_id = payments.bill_id
                    WHERE $whereClause
                ) defaulters_calc
                WHERE outstanding_amount > 0
            ";
            
            $summaryParams = array_merge([$currentYear . '-09-30'], $params);
            $summaryResult = $db->fetchRow($summaryQuery, $summaryParams);
            
            // SAFE MERGE: Only merge if we get a valid array result
            if ($summaryResult !== false && is_array($summaryResult)) {
                $defaultersSummary = array_merge($defaultersSummary, $summaryResult);
            } else {
                $errorMessages[] = "Failed to fetch summary data";
            }
        } catch (Exception $e) {
            $errorMessages[] = "Summary query error: " . $e->getMessage();
            writeLog("Summary query error: " . $e->getMessage(), 'ERROR');
        }
        
        // Apply minimum amount filter if specified
        $minAmountFilter = "";
        $minAmountParams = [];
        if ($minAmount && $minAmount > 0) {
            $minAmountFilter = "AND outstanding_amount >= ?";
            $minAmountParams[] = $minAmount;
        }
        
        // UPDATED: Get aging breakdown - all ages calculated from September 30th
        try {
            $agingQuery = "
                SELECT 
                    age_group,
                    COUNT(*) as bills_count,
                    COUNT(DISTINCT account_key) as accounts_count,
                    COALESCE(SUM(outstanding_amount), 0) as total_outstanding,
                    COALESCE(AVG(outstanding_amount), 0) as average_outstanding
                FROM (
                    SELECT 
                        CASE 
                            WHEN DATEDIFF(NOW(), ?) <= 30 THEN '0-30 days'
                            WHEN DATEDIFF(NOW(), ?) <= 60 THEN '31-60 days'
                            WHEN DATEDIFF(NOW(), ?) <= 90 THEN '61-90 days'
                            WHEN DATEDIFF(NOW(), ?) <= 180 THEN '91-180 days'
                            ELSE 'Over 180 days'
                        END as age_group,
                        CASE 
                            WHEN b.bill_type = 'Business' THEN CONCAT('B-', b.reference_id)
                            WHEN b.bill_type = 'Property' THEN CONCAT('P-', b.reference_id)
                        END as account_key,
                        (b.amount_payable - COALESCE(payments.total_paid, 0)) as outstanding_amount
                    FROM bills b
                    LEFT JOIN businesses bs ON b.bill_type = 'Business' AND b.reference_id = bs.business_id
                    LEFT JOIN properties pr ON b.bill_type = 'Property' AND b.reference_id = pr.property_id
                    LEFT JOIN (
                        SELECT bill_id, SUM(amount_paid) as total_paid
                        FROM payments 
                        WHERE payment_status = 'Successful'
                        GROUP BY bill_id
                    ) payments ON b.bill_id = payments.bill_id
                    WHERE $whereClause
                ) aging_calc
                WHERE outstanding_amount > 0 $minAmountFilter
                GROUP BY age_group
                ORDER BY 
                    CASE 
                        WHEN age_group = '0-30 days' THEN 1
                        WHEN age_group = '31-60 days' THEN 2
                        WHEN age_group = '61-90 days' THEN 3
                        WHEN age_group = '91-180 days' THEN 4
                        ELSE 5
                    END
            ";
            
            $agingParams = array_merge([
                $currentYear . '-09-30', 
                $currentYear . '-09-30', 
                $currentYear . '-09-30', 
                $currentYear . '-09-30'
            ], $params, $minAmountParams);
            
            $agingResult = $db->fetchAll($agingQuery, $agingParams);
            
            if ($agingResult !== false && is_array($agingResult)) {
                $agingBreakdown = $agingResult;
            } else {
                $errorMessages[] = "Failed to fetch aging breakdown";
            }
        } catch (Exception $e) {
            $errorMessages[] = "Aging query error: " . $e->getMessage();
            writeLog("Aging query error: " . $e->getMessage(), 'ERROR');
        }
        
        // UPDATED: Get zone breakdown
        try {
            $zoneQuery = "
                SELECT 
                    zone_name,
                    zone_id,
                    COUNT(*) as bills_count,
                    COUNT(DISTINCT account_key) as accounts_count,
                    COALESCE(SUM(outstanding_amount), 0) as total_outstanding,
                    COALESCE(AVG(outstanding_amount), 0) as average_outstanding
                FROM (
                    SELECT 
                        z.zone_name,
                        z.zone_id,
                        CASE 
                            WHEN b.bill_type = 'Business' THEN CONCAT('B-', b.reference_id)
                            WHEN b.bill_type = 'Property' THEN CONCAT('P-', b.reference_id)
                        END as account_key,
                        (b.amount_payable - COALESCE(payments.total_paid, 0)) as outstanding_amount
                    FROM bills b
                    LEFT JOIN businesses bs ON b.bill_type = 'Business' AND b.reference_id = bs.business_id
                    LEFT JOIN properties pr ON b.bill_type = 'Property' AND b.reference_id = pr.property_id
                    LEFT JOIN zones z ON (
                        (b.bill_type = 'Business' AND bs.zone_id = z.zone_id) OR
                        (b.bill_type = 'Property' AND pr.zone_id = z.zone_id)
                    )
                    LEFT JOIN (
                        SELECT bill_id, SUM(amount_paid) as total_paid
                        FROM payments 
                        WHERE payment_status = 'Successful'
                        GROUP BY bill_id
                    ) payments ON b.bill_id = payments.bill_id
                    WHERE $whereClause AND z.zone_id IS NOT NULL
                ) zone_calc
                WHERE outstanding_amount > 0 $minAmountFilter
                GROUP BY zone_id, zone_name
                ORDER BY total_outstanding DESC
                LIMIT 10
            ";
            
            $zoneParams = array_merge($params, $minAmountParams);
            $zoneResult = $db->fetchAll($zoneQuery, $zoneParams);
            
            if ($zoneResult !== false && is_array($zoneResult)) {
                $zoneBreakdown = $zoneResult;
            } else {
                $errorMessages[] = "Failed to fetch zone breakdown";
            }
        } catch (Exception $e) {
            $errorMessages[] = "Zone breakdown query error: " . $e->getMessage();
            writeLog("Zone breakdown query error: " . $e->getMessage(), 'ERROR');
        }
        
        // UPDATED: Get type breakdown
        try {
            $typeQuery = "
                SELECT 
                    bill_type,
                    COUNT(*) as bills_count,
                    COUNT(DISTINCT account_key) as accounts_count,
                    COALESCE(SUM(outstanding_amount), 0) as total_outstanding,
                    COALESCE(AVG(outstanding_amount), 0) as average_outstanding
                FROM (
                    SELECT 
                        b.bill_type,
                        CASE 
                            WHEN b.bill_type = 'Business' THEN CONCAT('B-', b.reference_id)
                            WHEN b.bill_type = 'Property' THEN CONCAT('P-', b.reference_id)
                        END as account_key,
                        (b.amount_payable - COALESCE(payments.total_paid, 0)) as outstanding_amount
                    FROM bills b
                    LEFT JOIN businesses bs ON b.bill_type = 'Business' AND b.reference_id = bs.business_id
                    LEFT JOIN properties pr ON b.bill_type = 'Property' AND b.reference_id = pr.property_id
                    LEFT JOIN (
                        SELECT bill_id, SUM(amount_paid) as total_paid
                        FROM payments 
                        WHERE payment_status = 'Successful'
                        GROUP BY bill_id
                    ) payments ON b.bill_id = payments.bill_id
                    WHERE $whereClause
                ) type_calc
                WHERE outstanding_amount > 0 $minAmountFilter
                GROUP BY bill_type
                ORDER BY total_outstanding DESC
            ";
            
            $typeParams = array_merge($params, $minAmountParams);
            $typeResult = $db->fetchAll($typeQuery, $typeParams);
            
            if ($typeResult !== false && is_array($typeResult)) {
                $typeBreakdown = $typeResult;
            } else {
                $errorMessages[] = "Failed to fetch type breakdown";
            }
        } catch (Exception $e) {
            $errorMessages[] = "Type breakdown query error: " . $e->getMessage();
            writeLog("Type breakdown query error: " . $e->getMessage(), 'ERROR');
        }
        
        // UPDATED: Get detailed defaulters list - age always calculated from September 30th
        try {
            $defaultersQuery = "
                SELECT 
                    b.bill_number,
                    b.bill_type,
                    b.status,
                    b.amount_payable,
                    b.generated_at,
                    DATEDIFF(NOW(), ?) as age_days,
                    CASE 
                        WHEN b.bill_type = 'Business' THEN bs.business_name
                        WHEN b.bill_type = 'Property' THEN pr.owner_name
                    END as payer_name,
                    CASE 
                        WHEN b.bill_type = 'Business' THEN bs.account_number
                        WHEN b.bill_type = 'Property' THEN pr.property_number
                    END as account_number,
                    CASE 
                        WHEN b.bill_type = 'Business' THEN bs.telephone
                        WHEN b.bill_type = 'Property' THEN pr.telephone
                    END as contact_number,
                    z.zone_name,
                    COALESCE(payments.total_paid, 0) as total_paid,
                    (b.amount_payable - COALESCE(payments.total_paid, 0)) as outstanding_amount,
                    COALESCE(payments.payment_count, 0) as payment_count,
                    payments.last_payment_date,
                    ? as september_cutoff_date
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
                        SUM(amount_paid) as total_paid,
                        COUNT(*) as payment_count,
                        MAX(payment_date) as last_payment_date
                    FROM payments 
                    WHERE payment_status = 'Successful'
                    GROUP BY bill_id
                ) payments ON b.bill_id = payments.bill_id
                WHERE $whereClause
                AND (b.amount_payable - COALESCE(payments.total_paid, 0)) > 0
                " . ($minAmount ? "AND (b.amount_payable - COALESCE(payments.total_paid, 0)) >= ?" : "") . "
                ORDER BY outstanding_amount DESC, age_days DESC
                LIMIT 500
            ";
            
            $defaultersParams = [
                $currentYear . '-09-30', 
                $currentYear . '-09-30'
            ];
            $defaultersParams = array_merge($defaultersParams, $params);
            
            if ($minAmount) {
                $defaultersParams[] = $minAmount;
            }
            
            $defaultersResult = $db->fetchAll($defaultersQuery, $defaultersParams);
            
            if ($defaultersResult !== false && is_array($defaultersResult)) {
                $defaultersList = $defaultersResult;
            } else {
                $errorMessages[] = "Failed to fetch defaulters list";
            }
        } catch (Exception $e) {
            $errorMessages[] = "Defaulters list query error: " . $e->getMessage();
            writeLog("Defaulters list query error: " . $e->getMessage(), 'ERROR');
        }
    }
    
} catch (Exception $e) {
    $errorMessages[] = "Database connection error: " . $e->getMessage();
    writeLog("Defaulters report database error: " . $e->getMessage(), 'ERROR');
    setFlashMessage('error', 'Database connection error. Please try again later.');
}

// Handle CSV export
if ($export === 'csv' && $isAfterSeptember && !empty($defaultersList)) {
    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="defaulters_report_' . date('Y-m-d') . '.csv"');
    
    // Open output stream
    $output = fopen('php://output', 'w');
    
    // Add CSV headers
    fputcsv($output, [
        'Bill Number',
        'Payer Name',
        'Account Number',
        'Contact',
        'Type',
        'Zone',
        'Total Bill',
        'Paid Amount',
        'Outstanding',
        'Status',
        'Age (Days)',
        'Last Payment',
        'Days Since September 30'
    ]);
    
    // Add data rows
    foreach ($defaultersList as $defaulter) {
        fputcsv($output, [
            $defaulter['bill_number'] ?? '',
            $defaulter['payer_name'] ?? 'Unknown',
            $defaulter['account_number'] ?? '',
            $defaulter['contact_number'] ?? '',
            $defaulter['bill_type'] ?? '',
            $defaulter['zone_name'] ?? 'N/A',
            number_format($defaulter['amount_payable'] ?? 0, 2),
            number_format($defaulter['total_paid'] ?? 0, 2),
            number_format($defaulter['outstanding_amount'] ?? 0, 2),
            $defaulter['status'] ?? '',
            $defaulter['age_days'] ?? 0,
            $defaulter['last_payment_date'] ?? 'Never',
            $isAfterSeptember ? ($defaulter['age_days'] ?? 0) : 'N/A'
        ]);
    }
    
    fclose($output);
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
        .icon-warning::before { content: "⚠️"; }
        .icon-money::before { content: "💰"; }
        .icon-calendar::before { content: "📅"; }
        .icon-download::before { content: "⬇️"; }
        .icon-filter::before { content: "🔍"; }
        .icon-print::before { content: "🖨️"; }
        .icon-back::before { content: "↩️"; }
        .icon-receipt::before { content: "🧾"; }
        .icon-bell::before { content: "🔔"; }
        .icon-chart::before { content: "📊"; }
        .icon-users::before { content: "👥"; }
        .icon-clock::before { content: "⏰"; }
        .icon-phone::before { content: "📞"; }
        .icon-map::before { content: "🗺️"; }
        .icon-info::before { content: "ℹ️"; }
        
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
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
            border: 1px solid #fca5a5;
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
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
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
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 24px;
            box-shadow: 0 8px 25px rgba(239, 68, 68, 0.3);
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
        
        /* September Notice */
        .september-notice {
            background: linear-gradient(135deg, #e0f2fe 0%, #b3e5fc 100%);
            border: 1px solid #4fc3f7;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
        }
        
        .september-notice::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #0288d1 0%, #0277bd 100%);
        }
        
        .notice-content {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .notice-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #0288d1 0%, #0277bd 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            flex-shrink: 0;
        }
        
        .notice-text {
            flex: 1;
        }
        
        .notice-title {
            font-weight: 600;
            color: #0277bd;
            margin-bottom: 5px;
            font-size: 16px;
        }
        
        .notice-description {
            color: #0277bd;
            font-size: 14px;
            line-height: 1.5;
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
            border-color: #ef4444;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
        }
        
        .form-control:disabled {
            background: #f8fafc;
            color: #64748b;
            cursor: not-allowed;
        }
        
        /* Summary Cards */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .summary-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid #ef4444;
            transition: all 0.3s;
        }
        
        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        
        .summary-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .summary-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
        }
        
        .summary-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }
        
        .summary-stats {
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
        
        .summary-amount {
            font-family: monospace;
            color: #dc2626;
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
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
        }
        
        .chart-wrapper {
            position: relative;
            height: 300px;
            width: 100%;
        }
        
        .chart-wrapper canvas {
            max-height: 300px !important;
        }
        
        /* Zone Performance List */
        .zone-list {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .zone-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .zone-item:last-child {
            border-bottom: none;
        }
        
        .zone-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .zone-rank {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #ef4444;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
        }
        
        .zone-details {
            flex: 1;
        }
        
        .zone-name {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 5px;
        }
        
        .zone-stats {
            font-size: 12px;
            color: #64748b;
        }
        
        .zone-amount {
            text-align: right;
        }
        
        .zone-outstanding {
            font-size: 18px;
            font-weight: bold;
            color: #dc2626;
            font-family: monospace;
        }
        
        .zone-accounts {
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
        
        .status-overdue {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .status-partially-paid {
            background: #dbeafe;
            color: #1e40af;
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
        }
        
        .amount.outstanding {
            color: #dc2626;
        }
        
        .amount.paid {
            color: #059669;
        }
        
        .priority-indicator {
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
        }
        
        .priority-high {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .priority-medium {
            background: #fed7aa;
            color: #9a3412;
        }
        
        .priority-low {
            background: #fef3c7;
            color: #92400e;
        }
        
        .contact-info {
            font-size: 12px;
            color: #059669;
            text-decoration: none;
        }
        
        .contact-info:hover {
            text-decoration: underline;
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
            background: #ef4444;
            color: white;
        }
        
        .btn-primary:hover {
            background: #dc2626;
            color: white;
            text-decoration: none;
            transform: translateY(-2px);
        }
        
        .btn-primary:disabled {
            background: #9ca3af;
            cursor: not-allowed;
            transform: none;
        }
        
        .btn-secondary {
            background: #6b7280;
            color: white;
        }
        
        .btn-outline {
            background: transparent;
            color: #ef4444;
            border: 2px solid #ef4444;
        }
        
        .btn-outline:hover {
            background: #ef4444;
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
        
        .alert-warning {
            background: #fef3c7;
            border: 1px solid #fbbf24;
            color: #92400e;
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
            
            .summary-grid {
                grid-template-columns: 1fr;
            }
            
            .charts-grid {
                display: block !important;
            }
            
            .charts-grid > div {
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
                <span style="color: #2d3748; font-weight: 600;">Defaulters Report</span>
            </div>
        </div>

        <!-- Flash Messages -->
        <?php if ($flashMessage): ?>
            <div class="alert alert-<?php echo $flashMessage['type']; ?>">
                <i class="fas fa-<?php echo $flashMessage['type'] === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                <div><?php echo htmlspecialchars($flashMessage['message']); ?></div>
            </div>
        <?php endif; ?>

        <!-- Database Error Messages -->
        <?php if (!empty($errorMessages)): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>Database Issues Detected:</strong>
                    <ul style="margin: 10px 0 0 20px;">
                        <?php foreach ($errorMessages as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <small>Some data may be incomplete. Please contact the system administrator if issues persist.</small>
                </div>
            </div>
        <?php endif; ?>

        <!-- September Notice -->
        <div class="september-notice">
            <div class="notice-content">
                <div class="notice-icon">
                    <i class="fas fa-info-circle"></i>
                    <span class="icon-info" style="display: none;"></span>
                </div>
                <div class="notice-text">
                    <div class="notice-title">September Cutoff Policy</div>
                    <div class="notice-description">
                        <?php if ($isAfterSeptember): ?>
                            Defaulters are now being tracked. Any bills with outstanding amounts are flagged as defaults. 
                            Aging is calculated from September 30, <?php echo $currentYear; ?>.
                        <?php else: ?>
                            Defaulter tracking starts after September 30, <?php echo $currentYear; ?>. 
                            Currently showing no defaulters as we are still within the grace period (until September 30).
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Report Header -->
        <div class="report-header">
            <div class="header-content">
                <div class="header-info">
                    <div class="header-avatar">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span class="icon-warning" style="display: none;"></span>
                    </div>
                    <div class="header-details">
                        <h1>Defaulters Report</h1>
                        <div class="header-description">
                            Outstanding bills after September 30, <?php echo $currentYear; ?> cutoff date
                        </div>
                    </div>
                </div>
                
                <div class="header-actions">
                    <button onclick="window.print()" class="btn btn-outline">
                        <i class="fas fa-print"></i>
                        <span class="icon-print" style="display: none;"></span>
                        Print Report
                    </button>
                    <a href="defaulters_report.php?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" 
                       class="btn btn-primary <?php echo !$isAfterSeptember ? 'btn-primary:disabled' : ''; ?>"
                       <?php echo !$isAfterSeptember ? 'style="pointer-events: none; opacity: 0.6;"' : ''; ?>>
                        <i class="fas fa-download"></i>
                        <span class="icon-download" style="display: none;"></span>
                        Export CSV
                    </a>
                </div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <form class="filter-form" method="GET" action="">
                <div class="form-group">
                    <label class="form-label">Zone</label>
                    <select name="zone_id" class="form-control" <?php echo !$isAfterSeptember ? 'disabled' : ''; ?>>
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
                    <select name="bill_type" class="form-control" <?php echo !$isAfterSeptember ? 'disabled' : ''; ?>>
                        <option value="">All Types</option>
                        <option value="Business" <?php echo $billType === 'Business' ? 'selected' : ''; ?>>Business</option>
                        <option value="Property" <?php echo $billType === 'Property' ? 'selected' : ''; ?>>Property</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Minimum Amount (GHS)</label>
                    <input type="number" name="min_amount" class="form-control" value="<?php echo htmlspecialchars($minAmount); ?>" 
                           placeholder="0.00" step="0.01" <?php echo !$isAfterSeptember ? 'disabled' : ''; ?>>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Aging Period (from Sept 30)</label>
                    <select name="aging_period" class="form-control" <?php echo !$isAfterSeptember ? 'disabled' : ''; ?>>
                        <option value="">All Periods</option>
                        <option value="30" <?php echo $agingPeriod === '30' ? 'selected' : ''; ?>>0-30 days</option>
                        <option value="60" <?php echo $agingPeriod === '60' ? 'selected' : ''; ?>>31-60 days</option>
                        <option value="90" <?php echo $agingPeriod === '90' ? 'selected' : ''; ?>>61-90 days</option>
                        <option value="180" <?php echo $agingPeriod === '180' ? 'selected' : ''; ?>>91-180 days</option>
                        <option value="over180" <?php echo $agingPeriod === 'over180' ? 'selected' : ''; ?>>Over 180 days</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn btn-primary" <?php echo !$isAfterSeptember ? 'disabled' : ''; ?>>
                        <i class="fas fa-search"></i>
                        <span class="icon-filter" style="display: none;"></span>
                        Apply Filters
                    </button>
                </div>
            </form>
        </div>

        <?php if (!$isAfterSeptember): ?>
            <!-- Before September Notice -->
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                <div>
                    <strong>Defaulter Tracking Inactive:</strong> 
                    Defaulter tracking will begin after September 30, <?php echo $currentYear; ?>. 
                    Currently, no accounts are flagged as defaulters regardless of outstanding amounts.
                    <br><small>Days remaining until tracking begins: <?php echo $septemberCutoff->diff($currentDate)->days + 1; ?> days</small>
                </div>
            </div>
        <?php elseif (($defaultersSummary['total_outstanding'] ?? 0) > 50000): ?>
            <!-- High Priority Alert (only after September) -->
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>High Priority Alert:</strong> Total outstanding amount exceeds GHS 50,000. 
                    Immediate collection action recommended for high-value defaulters.
                </div>
            </div>
        <?php endif; ?>

        <!-- Summary Cards -->
        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-header">
                    <div class="summary-title">Total Outstanding</div>
                    <div class="summary-icon">
                        <i class="fas fa-dollar-sign"></i>
                        <span class="icon-money" style="display: none;"></span>
                    </div>
                </div>
                <div class="summary-stats">
                    <div class="stat-item" style="grid-column: span 2;">
                        <div class="stat-value summary-amount">
                            GHS <?php echo $isAfterSeptember ? number_format($defaultersSummary['total_outstanding'], 2) : '0.00'; ?>
                        </div>
                        <div class="stat-label">Total Outstanding Amount</div>
                    </div>
                </div>
            </div>
            
            <div class="summary-card">
                <div class="summary-header">
                    <div class="summary-title">Defaulting Accounts</div>
                    <div class="summary-icon">
                        <i class="fas fa-users"></i>
                        <span class="icon-users" style="display: none;"></span>
                    </div>
                </div>
                <div class="summary-stats">
                    <div class="stat-item">
                        <div class="stat-value">
                            <?php echo $isAfterSeptember ? number_format($defaultersSummary['total_accounts']) : '0'; ?>
                        </div>
                        <div class="stat-label">Accounts</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value">
                            <?php echo $isAfterSeptember ? number_format($defaultersSummary['total_bills']) : '0'; ?>
                        </div>
                        <div class="stat-label">Bills</div>
                    </div>
                </div>
            </div>
            
            <div class="summary-card">
                <div class="summary-header">
                    <div class="summary-title">Average Outstanding</div>
                    <div class="summary-icon">
                        <i class="fas fa-calculator"></i>
                    </div>
                </div>
                <div class="summary-stats">
                    <div class="stat-item" style="grid-column: span 2;">
                        <div class="stat-value summary-amount">
                            GHS <?php echo $isAfterSeptember ? number_format($defaultersSummary['average_outstanding'], 2) : '0.00'; ?>
                        </div>
                        <div class="stat-label">Per Account</div>
                    </div>
                </div>
            </div>
            
            <div class="summary-card">
                <div class="summary-header">
                    <div class="summary-title">Average Age</div>
                    <div class="summary-icon">
                        <i class="fas fa-clock"></i>
                        <span class="icon-clock" style="display: none;"></span>
                    </div>
                </div>
                <div class="summary-stats">
                    <div class="stat-item" style="grid-column: span 2;">
                        <div class="stat-value">
                            <?php echo $isAfterSeptember ? round($defaultersSummary['average_age_days']) : '0'; ?> days
                        </div>
                        <div class="stat-label">Since September 30</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts and Analysis -->
        <?php if ($isAfterSeptember && (!empty($agingBreakdown) || !empty($typeBreakdown))): ?>
        <div class="charts-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 30px;">
            <!-- Aging Breakdown Chart -->
            <?php if (!empty($agingBreakdown)): ?>
            <div class="chart-container">
                <div class="chart-header">
                    <div class="chart-title">
                        <div class="chart-icon">
                            <i class="fas fa-chart-bar"></i>
                            <span class="icon-chart" style="display: none;"></span>
                        </div>
                        Outstanding by Age (Since Sept 30)
                    </div>
                </div>
                <div class="chart-wrapper">
                    <canvas id="agingChart"></canvas>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Type Breakdown Chart -->
            <?php if (!empty($typeBreakdown)): ?>
            <div class="chart-container">
                <div class="chart-header">
                    <div class="chart-title">
                        <div class="chart-icon">
                            <i class="fas fa-chart-pie"></i>
                        </div>
                        Outstanding by Type
                    </div>
                </div>
                <div class="chart-wrapper">
                    <canvas id="typeChart"></canvas>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Top Defaulting Zones -->
        <?php if ($isAfterSeptember && !empty($zoneBreakdown)): ?>
        <div class="zone-list">
            <div class="chart-header">
                <div class="chart-title">
                    <div class="chart-icon">
                        <i class="fas fa-map-marked-alt"></i>
                        <span class="icon-map" style="display: none;"></span>
                    </div>
                    Top Defaulting Zones
                </div>
            </div>
            
            <?php foreach ($zoneBreakdown as $index => $zone): ?>
                <div class="zone-item">
                    <div class="zone-info">
                        <div class="zone-rank"><?php echo $index + 1; ?></div>
                        <div class="zone-details">
                            <div class="zone-name"><?php echo htmlspecialchars($zone['zone_name']); ?></div>
                            <div class="zone-stats">
                                <?php echo number_format($zone['bills_count']); ?> bills • 
                                <?php echo number_format($zone['accounts_count']); ?> accounts
                            </div>
                        </div>
                    </div>
                    <div class="zone-amount">
                        <div class="zone-outstanding">GHS <?php echo number_format($zone['total_outstanding'], 2); ?></div>
                        <div class="zone-accounts">Avg: GHS <?php echo number_format($zone['average_outstanding'], 2); ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Detailed Defaulters List -->
        <div class="chart-container">
            <div class="chart-header">
                <div class="chart-title">
                    <div class="chart-icon">
                        <i class="fas fa-table"></i>
                    </div>
                    <?php echo $isAfterSeptember ? 'Detailed Defaulters List (Top 500)' : 'Defaulters List (Available After September 30)'; ?>
                </div>
                <?php if ($isAfterSeptember): ?>
                <div>
                    <a href="defaulters_report.php?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="btn btn-outline">
                        <i class="fas fa-download"></i>
                        Export CSV
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!$isAfterSeptember): ?>
                <div style="text-align: center; padding: 40px; color: #64748b;">
                    <i class="fas fa-calendar-times" style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;"></i>
                    <h3>Defaulter Tracking Inactive</h3>
                    <p>Defaulter tracking will begin after September 30, <?php echo $currentYear; ?>.</p>
                    <p>Check back after the cutoff date to view defaulting accounts.</p>
                </div>
            <?php elseif (empty($defaultersList)): ?>
                <div style="text-align: center; padding: 40px; color: #64748b;">
                    <i class="fas fa-check-circle" style="font-size: 48px; margin-bottom: 15px; opacity: 0.5; color: #059669;"></i>
                    <h3>No Defaulters Found</h3>
                    <p>No defaulting accounts found for the selected filters. Excellent payment compliance!</p>
                </div>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Priority</th>
                            <th>Bill Number</th>
                            <th>Payer</th>
                            <th>Contact</th>
                            <th>Type</th>
                            <th>Zone</th>
                            <th>Total Bill</th>
                            <th>Paid</th>
                            <th>Outstanding</th>
                            <th>Status</th>
                            <th>Days Since Sept 30</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($defaultersList as $defaulter): ?>
                            <?php
                            // Determine priority based on outstanding amount and age (from September 30)
                            $outstanding = $defaulter['outstanding_amount'] ?? 0;
                            $age = $defaulter['age_days'] ?? 0;
                            
                            if ($outstanding >= 1000 || $age >= 90) {
                                $priority = 'high';
                                $priorityText = 'HIGH';
                            } elseif ($outstanding >= 500 || $age >= 60) {
                                $priority = 'medium';
                                $priorityText = 'MEDIUM';
                            } else {
                                $priority = 'low';
                                $priorityText = 'LOW';
                            }
                            ?>
                            <tr>
                                <td>
                                    <span class="priority-indicator priority-<?php echo $priority; ?>">
                                        <?php echo $priorityText; ?>
                                    </span>
                                </td>
                                <td style="font-family: monospace; font-weight: 600;"><?php echo htmlspecialchars($defaulter['bill_number'] ?? ''); ?></td>
                                <td>
                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($defaulter['payer_name'] ?? 'Unknown'); ?></div>
                                    <div style="font-size: 12px; color: #64748b;"><?php echo htmlspecialchars($defaulter['account_number'] ?? ''); ?></div>
                                </td>
                                <td>
                                    <?php if ($defaulter['contact_number'] ?? ''): ?>
                                        <a href="tel:<?php echo htmlspecialchars($defaulter['contact_number']); ?>" class="contact-info">
                                            <i class="fas fa-phone"></i>
                                            <span class="icon-phone" style="display: none;"></span>
                                            <?php echo htmlspecialchars($defaulter['contact_number']); ?>
                                        </a>
                                    <?php else: ?>
                                        <span style="color: #9ca3af;">No contact</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="bill-type-badge type-<?php echo strtolower($defaulter['bill_type'] ?? ''); ?>">
                                        <?php echo htmlspecialchars($defaulter['bill_type'] ?? ''); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($defaulter['zone_name'] ?? 'N/A'); ?></td>
                                <td class="amount">GHS <?php echo number_format($defaulter['amount_payable'] ?? 0, 2); ?></td>
                                <td class="amount paid">GHS <?php echo number_format($defaulter['total_paid'] ?? 0, 2); ?></td>
                                <td class="amount outstanding">GHS <?php echo number_format($defaulter['outstanding_amount'] ?? 0, 2); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $defaulter['status'] ?? '')); ?>">
                                        <?php echo htmlspecialchars($defaulter['status'] ?? ''); ?>
                                    </span>
                                </td>
                                <td>
                                    <div><strong><?php echo $defaulter['age_days'] ?? 0; ?> days</strong></div>
                                    <?php if ($defaulter['last_payment_date'] ?? ''): ?>
                                        <div style="font-size: 11px; color: #64748b;">
                                            Last paid: <?php echo date('M j', strtotime($defaulter['last_payment_date'])); ?>
                                        </div>
                                    <?php else: ?>
                                        <div style="font-size: 11px; color: #ef4444;">
                                            Never paid
                                        </div>
                                    <?php endif; ?>
                                </td>
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
            
            // Initialize charts only if we're after September
            <?php if ($isAfterSeptember): ?>
            initializeCharts();
            <?php endif; ?>
        });

        function initializeCharts() {
            // Aging Breakdown Chart
            <?php if (!empty($agingBreakdown)): ?>
            const agingCtx = document.getElementById('agingChart');
            if (agingCtx) {
                new Chart(agingCtx, {
                    type: 'bar',
                    data: {
                        labels: [<?php echo "'" . implode("', '", array_column($agingBreakdown, 'age_group')) . "'"; ?>],
                        datasets: [{
                            label: 'Outstanding Amount',
                            data: [<?php echo implode(', ', array_column($agingBreakdown, 'total_outstanding')); ?>],
                            backgroundColor: ['#fbbf24', '#f59e0b', '#d97706', '#b45309', '#92400e'],
                            borderColor: '#92400e',
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
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return 'Outstanding: GHS ' + context.parsed.y.toLocaleString();
                                    }
                                }
                            }
                        }
                    }
                });
            }
            <?php endif; ?>

            // Type Breakdown Chart
            <?php if (!empty($typeBreakdown)): ?>
            const typeCtx = document.getElementById('typeChart');
            if (typeCtx) {
                new Chart(typeCtx, {
                    type: 'doughnut',
                    data: {
                        labels: [<?php echo "'" . implode("', '", array_column($typeBreakdown, 'bill_type')) . "'"; ?>],
                        datasets: [{
                            data: [<?php echo implode(', ', array_column($typeBreakdown, 'total_outstanding')); ?>],
                            backgroundColor: ['#ef4444', '#f59e0b'],
                            borderWidth: 2,
                            borderColor: '#ffffff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
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
            }
            <?php endif; ?>
        }
    </script>
</body>
</html>