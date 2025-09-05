<?php
/**
 * Businesses and Properties Data Management Report
 * QUICKBILL 305 - Admin Panel
 * Shows data entry tracking, timestamps, and comprehensive analytics
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
    session_unset();
    session_destroy();
    setFlashMessage('error', 'Your session has expired. Please log in again.');
    header('Location: ../../index.php');
    exit();
}

$pageTitle = 'Data Management Report';
$currentUser = getCurrentUser();
$userDisplayName = getUserDisplayName($currentUser);

// Get filter parameters with better defaults
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$dataType = $_GET['data_type'] ?? 'all'; // all, business, property
$createdBy = $_GET['created_by'] ?? '';
$zoneId = $_GET['zone_id'] ?? '';
$status = $_GET['status'] ?? '';
$period = $_GET['period'] ?? 'created'; // created, updated, both
$export = $_GET['export'] ?? '';

// Initialize variables
$summaryStats = [];
$trendData = [];
$businessData = [];
$propertyData = [];
$combinedData = [];

try {
    $db = new Database();
    
    // Build WHERE conditions - More flexible approach
    $businessWhereConditions = ["1 = 1"];
    $propertyWhereConditions = ["1 = 1"];
    $businessParams = [];
    $propertyParams = [];
    
    // Date filtering - only apply if dates are provided
    if ($dateFrom) {
        if ($period === 'both') {
            $businessWhereConditions[] = "(DATE(b.created_at) >= ? OR DATE(b.updated_at) >= ?)";
            $businessParams[] = $dateFrom;
            $businessParams[] = $dateFrom;
            $propertyWhereConditions[] = "(DATE(p.created_at) >= ? OR DATE(p.updated_at) >= ?)";
            $propertyParams[] = $dateFrom;
            $propertyParams[] = $dateFrom;
        } else {
            $dateField = $period === 'updated' ? 'updated_at' : 'created_at';
            $businessWhereConditions[] = "DATE(b.$dateField) >= ?";
            $businessParams[] = $dateFrom;
            $propertyWhereConditions[] = "DATE(p.$dateField) >= ?";
            $propertyParams[] = $dateFrom;
        }
    }
    
    if ($dateTo) {
        if ($period === 'both') {
            $businessWhereConditions[] = "(DATE(b.created_at) <= ? OR DATE(b.updated_at) <= ?)";
            $businessParams[] = $dateTo;
            $businessParams[] = $dateTo;
            $propertyWhereConditions[] = "(DATE(p.created_at) <= ? OR DATE(p.updated_at) <= ?)";
            $propertyParams[] = $dateTo;
            $propertyParams[] = $dateTo;
        } else {
            $dateField = $period === 'updated' ? 'updated_at' : 'created_at';
            $businessWhereConditions[] = "DATE(b.$dateField) <= ?";
            $businessParams[] = $dateTo;
            $propertyWhereConditions[] = "DATE(p.$dateField) <= ?";
            $propertyParams[] = $dateTo;
        }
    }
    
    // Other filters
    if ($createdBy) {
        $businessWhereConditions[] = "b.created_by = ?";
        $businessParams[] = $createdBy;
        $propertyWhereConditions[] = "p.created_by = ?";
        $propertyParams[] = $createdBy;
    }
    
    if ($zoneId) {
        $businessWhereConditions[] = "b.zone_id = ?";
        $businessParams[] = $zoneId;
        $propertyWhereConditions[] = "p.zone_id = ?";
        $propertyParams[] = $zoneId;
    }
    
    if ($status && $dataType !== 'property') {
        $businessWhereConditions[] = "b.status = ?";
        $businessParams[] = $status;
    }
    
    $businessWhereClause = implode(' AND ', $businessWhereConditions);
    $propertyWhereClause = implode(' AND ', $propertyWhereConditions);
    
    // Get summary statistics
    $businessStats = [];
    $propertyStats = [];
    
    if ($dataType === 'all' || $dataType === 'business') {
        $businessStatsQuery = "
            SELECT 
                COUNT(*) as total_count,
                COALESCE(SUM(b.amount_payable), 0) as total_amount,
                COALESCE(AVG(b.amount_payable), 0) as avg_amount,
                MIN(b.created_at) as first_entry,
                MAX(b.created_at) as last_entry,
                COUNT(DISTINCT b.created_by) as unique_creators,
                COUNT(DISTINCT b.zone_id) as zones_covered,
                SUM(CASE WHEN b.status = 'Active' THEN 1 ELSE 0 END) as active_count,
                SUM(CASE WHEN b.status = 'Inactive' THEN 1 ELSE 0 END) as inactive_count,
                SUM(CASE WHEN b.status = 'Suspended' THEN 1 ELSE 0 END) as suspended_count
            FROM businesses b
            WHERE $businessWhereClause
        ";
        $businessStats = $db->fetchRow($businessStatsQuery, $businessParams) ?: [
            'total_count' => 0, 'total_amount' => 0, 'avg_amount' => 0,
            'unique_creators' => 0, 'zones_covered' => 0,
            'active_count' => 0, 'inactive_count' => 0, 'suspended_count' => 0
        ];
    }
    
    if ($dataType === 'all' || $dataType === 'property') {
        $propertyStatsQuery = "
            SELECT 
                COUNT(*) as total_count,
                COALESCE(SUM(p.amount_payable), 0) as total_amount,
                COALESCE(AVG(p.amount_payable), 0) as avg_amount,
                MIN(p.created_at) as first_entry,
                MAX(p.created_at) as last_entry,
                COUNT(DISTINCT p.created_by) as unique_creators,
                COUNT(DISTINCT p.zone_id) as zones_covered,
                SUM(CASE WHEN p.property_use = 'Commercial' THEN 1 ELSE 0 END) as commercial_count,
                SUM(CASE WHEN p.property_use = 'Residential' THEN 1 ELSE 0 END) as residential_count
            FROM properties p
            WHERE $propertyWhereClause
        ";
        $propertyStats = $db->fetchRow($propertyStatsQuery, $propertyParams) ?: [
            'total_count' => 0, 'total_amount' => 0, 'avg_amount' => 0,
            'unique_creators' => 0, 'zones_covered' => 0,
            'commercial_count' => 0, 'residential_count' => 0
        ];
    }
    
    // Get trend data (daily entries over time)
    $trendData = [];
    
    if ($dataType === 'all' || $dataType === 'business') {
        $trendDateField = $period === 'updated' ? 'b.updated_at' : 'b.created_at';
        $businessTrendQuery = "
            SELECT 
                DATE($trendDateField) as entry_date,
                COUNT(*) as daily_count,
                COALESCE(SUM(b.amount_payable), 0) as daily_amount,
                'Business' as data_type
            FROM businesses b
            WHERE $businessWhereClause
            GROUP BY DATE($trendDateField)
            ORDER BY entry_date DESC
            LIMIT 30
        ";
        $businessTrend = $db->fetchAll($businessTrendQuery, $businessParams);
        if ($businessTrend) {
            $trendData = array_merge($trendData, $businessTrend);
        }
    }
    
    if ($dataType === 'all' || $dataType === 'property') {
        $trendDateField = $period === 'updated' ? 'p.updated_at' : 'p.created_at';
        $propertyTrendQuery = "
            SELECT 
                DATE($trendDateField) as entry_date,
                COUNT(*) as daily_count,
                COALESCE(SUM(p.amount_payable), 0) as daily_amount,
                'Property' as data_type
            FROM properties p
            WHERE $propertyWhereClause
            GROUP BY DATE($trendDateField)
            ORDER BY entry_date DESC
            LIMIT 30
        ";
        $propertyTrend = $db->fetchAll($propertyTrendQuery, $propertyParams);
        if ($propertyTrend) {
            $trendData = array_merge($trendData, $propertyTrend);
        }
    }
    
    // Sort combined trend data
    if (!empty($trendData)) {
        usort($trendData, function($a, $b) {
            return strtotime($b['entry_date']) - strtotime($a['entry_date']);
        });
    }
    
    // Get detailed business data
    if ($dataType === 'all' || $dataType === 'business') {
        $orderField = $period === 'updated' ? 'b.updated_at' : 'b.created_at';
        $businessDetailQuery = "
            SELECT 
                b.business_id,
                b.account_number,
                b.business_name,
                b.owner_name,
                b.business_type,
                b.category,
                b.telephone,
                b.exact_location,
                b.amount_payable,
                b.status,
                b.batch,
                b.created_at,
                b.updated_at,
                COALESCE(z.zone_name, 'No Zone') as zone_name,
                COALESCE(sz.sub_zone_name, '') as sub_zone_name,
                COALESCE(CONCAT(u.first_name, ' ', u.last_name), 'System') as created_by_name,
                DATEDIFF(NOW(), b.created_at) as days_since_created,
                CASE 
                    WHEN b.updated_at > b.created_at THEN DATEDIFF(NOW(), b.updated_at)
                    ELSE NULL 
                END as days_since_updated
            FROM businesses b
            LEFT JOIN zones z ON b.zone_id = z.zone_id
            LEFT JOIN sub_zones sz ON b.sub_zone_id = sz.sub_zone_id
            LEFT JOIN users u ON b.created_by = u.user_id
            WHERE $businessWhereClause
            ORDER BY $orderField DESC
            LIMIT 500
        ";
        $businessData = $db->fetchAll($businessDetailQuery, $businessParams) ?: [];
    }
    
    // Get detailed property data  
    if ($dataType === 'all' || $dataType === 'property') {
        $orderField = $period === 'updated' ? 'p.updated_at' : 'p.created_at';
        $propertyDetailQuery = "
            SELECT 
                p.property_id,
                p.property_number,
                p.owner_name,
                p.telephone,
                p.gender,
                p.location,
                p.structure,
                p.ownership_type,
                p.property_type,
                p.number_of_rooms,
                p.property_use,
                p.amount_payable,
                p.batch,
                p.created_at,
                p.updated_at,
                COALESCE(z.zone_name, 'No Zone') as zone_name,
                COALESCE(sz.sub_zone_name, '') as sub_zone_name,
                COALESCE(CONCAT(u.first_name, ' ', u.last_name), 'System') as created_by_name,
                DATEDIFF(NOW(), p.created_at) as days_since_created,
                CASE 
                    WHEN p.updated_at > p.created_at THEN DATEDIFF(NOW(), p.updated_at)
                    ELSE NULL 
                END as days_since_updated
            FROM properties p
            LEFT JOIN zones z ON p.zone_id = z.zone_id
            LEFT JOIN sub_zones sz ON p.sub_zone_id = sz.sub_zone_id
            LEFT JOIN users u ON p.created_by = u.user_id
            WHERE $propertyWhereClause
            ORDER BY $orderField DESC
            LIMIT 500
        ";
        $propertyData = $db->fetchAll($propertyDetailQuery, $propertyParams) ?: [];
    }
    
    // Combine data for unified view
    $combinedData = [];
    if (!empty($businessData)) {
        foreach ($businessData as $business) {
            $combinedData[] = array_merge($business, ['record_type' => 'Business']);
        }
    }
    if (!empty($propertyData)) {
        foreach ($propertyData as $property) {
            $combinedData[] = array_merge($property, ['record_type' => 'Property']);
        }
    }
    
    // Sort combined data
    if (!empty($combinedData)) {
        $sortField = $period === 'updated' ? 'updated_at' : 'created_at';
        usort($combinedData, function($a, $b) use ($sortField) {
            return strtotime($b[$sortField]) - strtotime($a[$sortField]);
        });
    }
    
    // Get filter options
    $zones = $db->fetchAll("SELECT zone_id, zone_name FROM zones ORDER BY zone_name");
    
    $creators = $db->fetchAll("
        SELECT DISTINCT u.user_id, CONCAT(u.first_name, ' ', u.last_name) as full_name
        FROM users u
        WHERE u.user_id IN (
            SELECT DISTINCT created_by FROM businesses WHERE created_by IS NOT NULL
            UNION
            SELECT DISTINCT created_by FROM properties WHERE created_by IS NOT NULL
        )
        ORDER BY full_name
    ");
    
} catch (Exception $e) {
    writeLog("Data management report error: " . $e->getMessage(), 'ERROR');
    setFlashMessage('error', 'An error occurred while loading data management report: ' . $e->getMessage());
    
    // Initialize empty arrays to prevent errors
    $businessStats = ['total_count' => 0, 'total_amount' => 0, 'avg_amount' => 0, 'unique_creators' => 0, 'zones_covered' => 0, 'active_count' => 0, 'inactive_count' => 0, 'suspended_count' => 0];
    $propertyStats = ['total_count' => 0, 'total_amount' => 0, 'avg_amount' => 0, 'unique_creators' => 0, 'zones_covered' => 0, 'commercial_count' => 0, 'residential_count' => 0];
    $trendData = [];
    $combinedData = [];
    $zones = [];
    $creators = [];
}

// Handle Excel export
if ($export === 'excel') {
    exportToExcel();
    exit();
}

function exportToExcel() {
    global $combinedData, $businessData, $propertyData, $dataType, $dateFrom, $dateTo;
    
    // Set headers for Excel download
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="data_management_report_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');
    
    // Create Excel content
    echo "<table border='1'>\n";
    echo "<tr><td colspan='20' style='font-weight:bold; font-size:16px;'>Data Management Report - " . ($dateFrom ? date('F j, Y', strtotime($dateFrom)) : 'All Time') . " to " . ($dateTo ? date('F j, Y', strtotime($dateTo)) : 'Present') . "</td></tr>\n";
    echo "<tr><td colspan='20'>&nbsp;</td></tr>\n";
    
    if ($dataType === 'all') {
        // Combined export
        echo "<tr style='font-weight:bold; background-color:#f0f0f0;'>\n";
        echo "<td>Record Type</td><td>ID/Number</td><td>Name</td><td>Owner/Business Name</td><td>Type/Structure</td><td>Category/Use</td><td>Telephone</td><td>Location</td><td>Amount Payable</td><td>Zone</td><td>Sub Zone</td><td>Status/Type</td><td>Batch</td><td>Created By</td><td>Created Date</td><td>Updated Date</td><td>Days Since Created</td><td>Days Since Updated</td>\n";
        echo "</tr>\n";
        
        foreach ($combinedData as $record) {
            echo "<tr>\n";
            echo "<td>" . htmlspecialchars($record['record_type']) . "</td>";
            echo "<td>" . htmlspecialchars($record['record_type'] === 'Business' ? $record['account_number'] : $record['property_number']) . "</td>";
            echo "<td>" . htmlspecialchars($record['record_type'] === 'Business' ? $record['business_name'] : $record['owner_name']) . "</td>";
            echo "<td>" . htmlspecialchars($record['owner_name']) . "</td>";
            echo "<td>" . htmlspecialchars($record['record_type'] === 'Business' ? $record['business_type'] : $record['structure']) . "</td>";
            echo "<td>" . htmlspecialchars($record['record_type'] === 'Business' ? $record['category'] : $record['property_use']) . "</td>";
            echo "<td>" . htmlspecialchars($record['telephone'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($record['record_type'] === 'Business' ? $record['exact_location'] : $record['location']) . "</td>";
            echo "<td>" . number_format($record['amount_payable'], 2) . "</td>";
            echo "<td>" . htmlspecialchars($record['zone_name'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($record['sub_zone_name'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($record['record_type'] === 'Business' ? $record['status'] : $record['property_type']) . "</td>";
            echo "<td>" . htmlspecialchars($record['batch'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($record['created_by_name'] ?? '') . "</td>";
            echo "<td>" . date('M j, Y g:i A', strtotime($record['created_at'])) . "</td>";
            echo "<td>" . date('M j, Y g:i A', strtotime($record['updated_at'])) . "</td>";
            echo "<td>" . $record['days_since_created'] . "</td>";
            echo "<td>" . ($record['days_since_updated'] ?? 'N/A') . "</td>";
            echo "</tr>\n";
        }
    } else {
        // Separate export for businesses or properties
        $data = $dataType === 'business' ? $businessData : $propertyData;
        
        if ($dataType === 'business') {
            echo "<tr style='font-weight:bold; background-color:#f0f0f0;'>\n";
            echo "<td>Account Number</td><td>Business Name</td><td>Owner Name</td><td>Business Type</td><td>Category</td><td>Telephone</td><td>Location</td><td>Amount Payable</td><td>Status</td><td>Zone</td><td>Sub Zone</td><td>Batch</td><td>Created By</td><td>Created Date</td><td>Updated Date</td><td>Days Since Created</td><td>Days Since Updated</td>\n";
            echo "</tr>\n";
            
            foreach ($data as $record) {
                echo "<tr>\n";
                echo "<td>" . htmlspecialchars($record['account_number']) . "</td>";
                echo "<td>" . htmlspecialchars($record['business_name']) . "</td>";
                echo "<td>" . htmlspecialchars($record['owner_name']) . "</td>";
                echo "<td>" . htmlspecialchars($record['business_type']) . "</td>";
                echo "<td>" . htmlspecialchars($record['category']) . "</td>";
                echo "<td>" . htmlspecialchars($record['telephone'] ?? '') . "</td>";
                echo "<td>" . htmlspecialchars($record['exact_location']) . "</td>";
                echo "<td>" . number_format($record['amount_payable'], 2) . "</td>";
                echo "<td>" . htmlspecialchars($record['status']) . "</td>";
                echo "<td>" . htmlspecialchars($record['zone_name'] ?? '') . "</td>";
                echo "<td>" . htmlspecialchars($record['sub_zone_name'] ?? '') . "</td>";
                echo "<td>" . htmlspecialchars($record['batch'] ?? '') . "</td>";
                echo "<td>" . htmlspecialchars($record['created_by_name'] ?? '') . "</td>";
                echo "<td>" . date('M j, Y g:i A', strtotime($record['created_at'])) . "</td>";
                echo "<td>" . date('M j, Y g:i A', strtotime($record['updated_at'])) . "</td>";
                echo "<td>" . $record['days_since_created'] . "</td>";
                echo "<td>" . ($record['days_since_updated'] ?? 'N/A') . "</td>";
                echo "</tr>\n";
            }
        } else {
            echo "<tr style='font-weight:bold; background-color:#f0f0f0;'>\n";
            echo "<td>Property Number</td><td>Owner Name</td><td>Telephone</td><td>Gender</td><td>Location</td><td>Structure</td><td>Ownership Type</td><td>Property Type</td><td>Rooms</td><td>Property Use</td><td>Amount Payable</td><td>Zone</td><td>Sub Zone</td><td>Batch</td><td>Created By</td><td>Created Date</td><td>Updated Date</td><td>Days Since Created</td><td>Days Since Updated</td>\n";
            echo "</tr>\n";
            
            foreach ($data as $record) {
                echo "<tr>\n";
                echo "<td>" . htmlspecialchars($record['property_number']) . "</td>";
                echo "<td>" . htmlspecialchars($record['owner_name']) . "</td>";
                echo "<td>" . htmlspecialchars($record['telephone'] ?? '') . "</td>";
                echo "<td>" . htmlspecialchars($record['gender'] ?? '') . "</td>";
                echo "<td>" . htmlspecialchars($record['location']) . "</td>";
                echo "<td>" . htmlspecialchars($record['structure']) . "</td>";
                echo "<td>" . htmlspecialchars($record['ownership_type']) . "</td>";
                echo "<td>" . htmlspecialchars($record['property_type']) . "</td>";
                echo "<td>" . $record['number_of_rooms'] . "</td>";
                echo "<td>" . htmlspecialchars($record['property_use']) . "</td>";
                echo "<td>" . number_format($record['amount_payable'], 2) . "</td>";
                echo "<td>" . htmlspecialchars($record['zone_name'] ?? '') . "</td>";
                echo "<td>" . htmlspecialchars($record['sub_zone_name'] ?? '') . "</td>";
                echo "<td>" . htmlspecialchars($record['batch'] ?? '') . "</td>";
                echo "<td>" . htmlspecialchars($record['created_by_name'] ?? '') . "</td>";
                echo "<td>" . date('M j, Y g:i A', strtotime($record['created_at'])) . "</td>";
                echo "<td>" . date('M j, Y g:i A', strtotime($record['updated_at'])) . "</td>";
                echo "<td>" . $record['days_since_created'] . "</td>";
                echo "<td>" . ($record['days_since_updated'] ?? 'N/A') . "</td>";
                echo "</tr>\n";
            }
        }
    }
    
    echo "</table>\n";
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
        .icon-filter::before { content: "🔍"; }
        .icon-download::before { content: "📥"; }
        .icon-back::before { content: "⬅️"; }
        .icon-user::before { content: "👤"; }
        .icon-chevron-down::before { content: "⌄"; }
        .icon-database::before { content: "💾"; }
        .icon-print::before { content: "🖨️"; }
        .icon-search::before { content: "🔍"; }
        
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

        /* Summary Grid */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .summary-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }

        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }

        .summary-card.business {
            border-left: 4px solid #3b82f6;
        }

        .summary-card.property {
            border-left: 4px solid #10b981;
        }

        .summary-card.total {
            border-left: 4px solid #8b5cf6;
        }

        .summary-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .summary-title {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
        }

        .summary-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
        }

        .summary-icon.business {
            background: #3b82f6;
        }

        .summary-icon.property {
            background: #10b981;
        }

        .summary-icon.total {
            background: #8b5cf6;
        }

        .summary-value {
            font-size: 28px;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 5px;
        }

        .summary-label {
            font-size: 12px;
            color: #64748b;
            text-transform: uppercase;
            font-weight: 600;
        }

        .summary-amount {
            font-family: monospace;
            color: #059669;
        }

        .summary-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-top: 15px;
        }

        .stat-item {
            text-align: center;
        }

        .stat-value {
            font-size: 18px;
            font-weight: bold;
            color: #2d3748;
        }

        .stat-label {
            font-size: 11px;
            color: #64748b;
            margin-top: 2px;
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
            height: 350px;
            width: 100%;
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
            padding: 15px 12px;
            text-align: left;
            font-weight: 600;
            color: #2d3748;
            border-bottom: 1px solid #e2e8f0;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .data-table td {
            padding: 15px 12px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            font-size: 14px;
        }

        .data-table tr:hover {
            background: #f8fafc;
        }

        .record-type-badge {
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .type-business {
            background: #dbeafe;
            color: #1e40af;
        }

        .type-property {
            background: #d1fae5;
            color: #065f46;
        }

        .status-badge {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active {
            background: #d1fae5;
            color: #065f46;
        }

        .status-inactive {
            background: #fef3c7;
            color: #92400e;
        }

        .status-suspended {
            background: #fee2e2;
            color: #991b1b;
        }

        .amount {
            font-weight: bold;
            font-family: monospace;
            color: #059669;
        }

        .timestamp {
            font-size: 12px;
            color: #64748b;
        }

        .timestamp-primary {
            font-weight: 600;
            color: #374151;
            font-size: 13px;
        }

        .days-indicator {
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
        }

        .days-new {
            background: #d1fae5;
            color: #065f46;
        }

        .days-recent {
            background: #fef3c7;
            color: #92400e;
        }

        .days-old {
            background: #fee2e2;
            color: #991b1b;
        }

        .created-by {
            font-size: 12px;
            color: #64748b;
            font-style: italic;
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
            text-decoration: none;
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
            text-decoration: none;
        }

        .btn-success {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(72, 187, 120, 0.4);
            color: white;
            text-decoration: none;
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
            border-color: #22c55e;
            outline: none;
            box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.1);
        }

        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #718096;
        }

        /* Debug info box */
        .debug-info {
            background: #f0f9ff;
            border: 1px solid #93c5fd;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            font-size: 12px;
            color: #1e40af;
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

            .summary-grid {
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

        /* Print Styles */
        @media print {
            .top-nav, .sidebar, .filters-card, .page-actions {
                display: none !important;
            }

            .container {
                margin-top: 0;
            }

            .main-content {
                padding: 20px;
            }

            .card {
                page-break-inside: avoid;
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
                    <h1 class="page-title">💾 Data Management Report</h1>
                    <p class="page-subtitle">
                        <?php if ($dateFrom || $dateTo): ?>
                            Track businesses and properties data from <?php echo $dateFrom ? date('M j, Y', strtotime($dateFrom)) : 'beginning'; ?> 
                            to <?php echo $dateTo ? date('M j, Y', strtotime($dateTo)) : 'present'; ?> (<?php echo ucfirst($period); ?> dates)
                        <?php else: ?>
                            Showing all businesses and properties data (<?php echo ucfirst($period); ?> dates)
                        <?php endif; ?>
                    </p>
                </div>
                <div class="page-actions">
                    <a href="index.php" class="btn btn-outline">
                        <span class="icon-back"></span>
                        Back to Reports
                    </a>
                    <button onclick="window.print()" class="btn btn-outline">
                        <span class="icon-print"></span>
                        Print Report
                    </button>
                    <a href="data_management_report.php?<?php echo http_build_query(array_merge($_GET, ['export' => 'excel'])); ?>" class="btn btn-success">
                        <span class="icon-download"></span>
                        Export Excel
                    </a>
                </div>
            </div>

            <!-- Flash Messages -->
            <?php if ($flashMessage): ?>
                <div class="alert alert-<?php echo $flashMessage['type']; ?>">
                    <span class="icon-<?php echo $flashMessage['type'] === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></span>
                    <div><?php echo htmlspecialchars($flashMessage['message']); ?></div>
                </div>
            <?php endif; ?>

            <!-- Debug Information (remove in production) -->
            <?php if (isset($_GET['debug'])): ?>
            <div class="debug-info">
                <strong>Debug Information:</strong><br>
                Business Records: <?php echo count($businessData); ?><br>
                Property Records: <?php echo count($propertyData); ?><br>
                Combined Records: <?php echo count($combinedData); ?><br>
                Date From: <?php echo $dateFrom ?: 'Not set'; ?><br>
                Date To: <?php echo $dateTo ?: 'Not set'; ?><br>
                Data Type: <?php echo $dataType; ?><br>
                Period: <?php echo $period; ?>
            </div>
            <?php endif; ?>

            <!-- Filters -->
            <div class="filters-card">
                <form method="GET" action="">
                    <div class="filters-grid">
                        <div class="form-group">
                            <label class="form-label">Date From</label>
                            <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($dateFrom); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Date To</label>
                            <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($dateTo); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Data Type</label>
                            <select name="data_type" class="form-control">
                                <option value="all" <?php echo $dataType === 'all' ? 'selected' : ''; ?>>All Data</option>
                                <option value="business" <?php echo $dataType === 'business' ? 'selected' : ''; ?>>Businesses Only</option>
                                <option value="property" <?php echo $dataType === 'property' ? 'selected' : ''; ?>>Properties Only</option>
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
                            <label class="form-label">Created By</label>
                            <select name="created_by" class="form-control">
                                <option value="">All Users</option>
                                <?php foreach ($creators as $creator): ?>
                                    <option value="<?php echo $creator['user_id']; ?>" <?php echo $createdBy == $creator['user_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($creator['full_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Status (Business)</label>
                            <select name="status" class="form-control">
                                <option value="">All Status</option>
                                <option value="Active" <?php echo $status === 'Active' ? 'selected' : ''; ?>>Active</option>
                                <option value="Inactive" <?php echo $status === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                <option value="Suspended" <?php echo $status === 'Suspended' ? 'selected' : ''; ?>>Suspended</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Date Period</label>
                            <select name="period" class="form-control">
                                <option value="created" <?php echo $period === 'created' ? 'selected' : ''; ?>>Created Date</option>
                                <option value="updated" <?php echo $period === 'updated' ? 'selected' : ''; ?>>Updated Date</option>
                                <option value="both" <?php echo $period === 'both' ? 'selected' : ''; ?>>Created or Updated</option>
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

            <!-- Summary Statistics -->
            <div class="summary-grid">
                <?php if ($dataType === 'all' || $dataType === 'business'): ?>
                    <div class="summary-card business">
                        <div class="summary-header">
                            <div class="summary-title">Business Data</div>
                            <div class="summary-icon business">
                                <span class="icon-building"></span>
                            </div>
                        </div>
                        
                        <div class="summary-value"><?php echo number_format($businessStats['total_count'] ?? 0); ?></div>
                        <div class="summary-label">Total Records</div>
                        
                        <div class="summary-stats">
                            <div class="stat-item">
                                <div class="stat-value summary-amount">GHS <?php echo number_format($businessStats['total_amount'] ?? 0, 2); ?></div>
                                <div class="stat-label">Total Amount</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value summary-amount">GHS <?php echo number_format($businessStats['avg_amount'] ?? 0, 2); ?></div>
                                <div class="stat-label">Average Amount</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $businessStats['active_count'] ?? 0; ?></div>
                                <div class="stat-label">Active</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $businessStats['unique_creators'] ?? 0; ?></div>
                                <div class="stat-label">Data Entry Users</div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if ($dataType === 'all' || $dataType === 'property'): ?>
                    <div class="summary-card property">
                        <div class="summary-header">
                            <div class="summary-title">Property Data</div>
                            <div class="summary-icon property">
                                <span class="icon-home"></span>
                            </div>
                        </div>
                        
                        <div class="summary-value"><?php echo number_format($propertyStats['total_count'] ?? 0); ?></div>
                        <div class="summary-label">Total Records</div>
                        
                        <div class="summary-stats">
                            <div class="stat-item">
                                <div class="stat-value summary-amount">GHS <?php echo number_format($propertyStats['total_amount'] ?? 0, 2); ?></div>
                                <div class="stat-label">Total Amount</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value summary-amount">GHS <?php echo number_format($propertyStats['avg_amount'] ?? 0, 2); ?></div>
                                <div class="stat-label">Average Amount</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $propertyStats['commercial_count'] ?? 0; ?></div>
                                <div class="stat-label">Commercial</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $propertyStats['residential_count'] ?? 0; ?></div>
                                <div class="stat-label">Residential</div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if ($dataType === 'all'): ?>
                    <div class="summary-card total">
                        <div class="summary-header">
                            <div class="summary-title">Combined Totals</div>
                            <div class="summary-icon total">
                                <span class="icon-chart"></span>
                            </div>
                        </div>
                        
                        <div class="summary-value"><?php echo number_format(($businessStats['total_count'] ?? 0) + ($propertyStats['total_count'] ?? 0)); ?></div>
                        <div class="summary-label">Total Records</div>
                        
                        <div class="summary-stats">
                            <div class="stat-item">
                                <div class="stat-value summary-amount">GHS <?php echo number_format(($businessStats['total_amount'] ?? 0) + ($propertyStats['total_amount'] ?? 0), 2); ?></div>
                                <div class="stat-label">Combined Amount</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo ($businessStats['zones_covered'] ?? 0) + ($propertyStats['zones_covered'] ?? 0); ?></div>
                                <div class="stat-label">Zones Covered</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo max($businessStats['unique_creators'] ?? 0, $propertyStats['unique_creators'] ?? 0); ?></div>
                                <div class="stat-label">Data Entry Users</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo count($trendData); ?></div>
                                <div class="stat-label">Active Days</div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Data Entry Trend Chart -->
            <?php if (!empty($trendData)): ?>
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">📈 Daily Data Entry Trend (Last 30 Days)</h5>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="trendChart"></canvas>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Detailed Data Table -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">📋 Detailed Records (Last 500 entries)</h5>
                    <div>
                        <a href="#" onclick="exportTableToCSV()" class="btn btn-outline">
                            <span class="icon-download"></span>
                            Export CSV
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($combinedData)): ?>
                        <div style="text-align: center; padding: 40px; color: #64748b;">
                            <span class="icon-database" style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;"></span>
                            <h3>No Data Found</h3>
                            <p>No records found for the selected filters and date range.</p>
                            <p style="font-size: 14px; margin-top: 10px;">
                                Try removing some filters or expanding the date range to see more results.
                            </p>
                        </div>
                    <?php else: ?>
                        <!-- Search Box -->
                        <div class="search-box">
                            <span class="search-icon icon-search"></span>
                            <input type="text" id="searchInput" class="search-input" 
                                placeholder="Search by name, account number, location, or created by...">
                        </div>

                        <table class="data-table" id="dataTable">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>ID/Number</th>
                                    <th>Name/Business</th>
                                    <th>Contact</th>
                                    <th>Location/Zone</th>
                                    <th>Amount</th>
                                    <th>Status/Type</th>
                                    <th>Created By</th>
                                    <th>Created Date</th>
                                    <th>Updated Date</th>
                                    <th>Age</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($combinedData as $record): ?>
                                    <tr>
                                        <td>
                                            <span class="record-type-badge type-<?php echo strtolower($record['record_type']); ?>">
                                                <?php echo htmlspecialchars($record['record_type']); ?>
                                            </span>
                                        </td>
                                        <td style="font-family: monospace; font-weight: 600;">
                                            <?php echo htmlspecialchars($record['record_type'] === 'Business' ? $record['account_number'] : $record['property_number']); ?>
                                            <?php if ($record['batch']): ?>
                                                <div style="font-size: 10px; color: #64748b; margin-top: 2px;">
                                                    Batch: <?php echo htmlspecialchars($record['batch']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600;">
                                                <?php echo htmlspecialchars($record['record_type'] === 'Business' ? $record['business_name'] : $record['owner_name']); ?>
                                            </div>
                                            <div style="font-size: 12px; color: #64748b;">
                                                <?php echo htmlspecialchars($record['owner_name']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($record['telephone']): ?>
                                                <?php echo htmlspecialchars($record['telephone']); ?>
                                            <?php else: ?>
                                                <span style="color: #64748b; font-size: 12px;">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="font-size: 13px;">
                                                <?php echo htmlspecialchars($record['record_type'] === 'Business' ? ($record['exact_location'] ?? 'N/A') : ($record['location'] ?? 'N/A')); ?>
                                            </div>
                                            <div style="font-size: 12px; color: #64748b;">
                                                <?php echo htmlspecialchars($record['zone_name'] ?? 'No Zone'); ?>
                                                <?php if ($record['sub_zone_name']): ?>
                                                    • <?php echo htmlspecialchars($record['sub_zone_name']); ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="amount">GHS <?php echo number_format($record['amount_payable'], 2); ?></td>
                                        <td>
                                            <?php if ($record['record_type'] === 'Business'): ?>
                                                <span class="status-badge status-<?php echo strtolower($record['status']); ?>">
                                                    <?php echo htmlspecialchars($record['status']); ?>
                                                </span>
                                                <div style="font-size: 11px; color: #64748b; margin-top: 2px;">
                                                    <?php echo htmlspecialchars($record['business_type']); ?>
                                                </div>
                                            <?php else: ?>
                                                <div style="font-size: 13px; font-weight: 600;">
                                                    <?php echo htmlspecialchars($record['property_use']); ?>
                                                </div>
                                                <div style="font-size: 11px; color: #64748b;">
                                                    <?php echo htmlspecialchars($record['structure'] ?? 'N/A'); ?> • <?php echo $record['number_of_rooms']; ?> rooms
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($record['created_by_name'] && $record['created_by_name'] !== 'System'): ?>
                                                <div class="created-by">
                                                    <span class="icon-user" style="font-size: 10px; margin-right: 3px;"></span>
                                                    <?php echo htmlspecialchars($record['created_by_name']); ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: #64748b; font-size: 12px;">System</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="timestamp-primary">
                                                <?php echo date('M j, Y', strtotime($record['created_at'])); ?>
                                            </div>
                                            <div class="timestamp">
                                                <?php echo date('g:i A', strtotime($record['created_at'])); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($record['updated_at'] > $record['created_at']): ?>
                                                <div class="timestamp-primary">
                                                    <?php echo date('M j, Y', strtotime($record['updated_at'])); ?>
                                                </div>
                                                <div class="timestamp">
                                                    <?php echo date('g:i A', strtotime($record['updated_at'])); ?>
                                                    <span style="font-size: 10px; margin-left: 3px;">✏️</span>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: #64748b; font-size: 12px;">No updates</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="days-indicator days-<?php 
                                                $days = $record['days_since_created'];
                                                if ($days <= 7) echo 'new';
                                                elseif ($days <= 30) echo 'recent';
                                                else echo 'old';
                                            ?>">
                                                <?php echo $record['days_since_created']; ?> days
                                            </span>
                                            <?php if ($record['days_since_updated']): ?>
                                                <div class="timestamp">
                                                    Updated: <?php echo $record['days_since_updated']; ?> days ago
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
        </div>
    </div>

    <script>
        // Chart data
        const trendData = <?php echo json_encode($trendData); ?>;

        // Initialize components
        document.addEventListener('DOMContentLoaded', function() {
            initializeTrendChart();
            initializeSearch();
        });

        function initializeTrendChart() {
            if (typeof Chart === 'undefined') {
                console.log('Chart.js not loaded from local file');
                return;
            }

            if (!trendData || trendData.length === 0) return;

            const trendCtx = document.getElementById('trendChart');
            if (trendCtx) {
                // Group data by date
                const trendMap = new Map();
                
                trendData.forEach(trend => {
                    const date = trend.entry_date;
                    if (!trendMap.has(date)) {
                        trendMap.set(date, { business: 0, property: 0 });
                    }
                    trendMap.get(date)[trend.data_type.toLowerCase()] += parseInt(trend.daily_count);
                });
                
                const dates = Array.from(trendMap.keys()).sort();
                const businessData = dates.map(date => trendMap.get(date).business);
                const propertyData = dates.map(date => trendMap.get(date).property);
                
                new Chart(trendCtx.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: dates.map(date => new Date(date).toLocaleDateString('en-US', {month: 'short', day: 'numeric'})),
                        datasets: [{
                            label: 'Business Records',
                            data: businessData,
                            borderColor: '#3b82f6',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4
                        }, {
                            label: 'Property Records',
                            data: propertyData,
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: {
                                    color: '#f1f5f9'
                                },
                                ticks: {
                                    stepSize: 1
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
                                labels: {
                                    font: {
                                        size: 12
                                    }
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    title: function(context) {
                                        return 'Date: ' + dates[context[0].dataIndex];
                                    },
                                    label: function(context) {
                                        return context.dataset.label + ': ' + context.parsed.y + ' records';
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
            const table = document.getElementById('dataTable');
            
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

        function exportTableToCSV() {
            // Create CSV content
            let csvContent = "data:text/csv;charset=utf-8,";
            csvContent += "Type,ID/Number,Name/Business,Owner,Contact,Location,Zone,Sub Zone,Amount,Status/Use,Category/Structure,Batch,Created By,Created Date,Updated Date,Days Since Created,Days Since Updated\n";
            
            // Add table data
            const table = document.querySelector('#dataTable tbody');
            const rows = table.querySelectorAll('tr');
            
            rows.forEach(row => {
                if (row.style.display !== 'none') {
                    const cells = row.querySelectorAll('td');
                    let rowData = [];
                    
                    // Extract data from each cell
                    rowData.push('"' + cells[0].textContent.trim() + '"');
                    rowData.push('"' + cells[1].textContent.trim().split('\n')[0] + '"');
                    
                    // Name/Business
                    const nameCell = cells[2].querySelectorAll('div');
                    rowData.push('"' + (nameCell[0] ? nameCell[0].textContent.trim() : '') + '"');
                    rowData.push('"' + (nameCell[1] ? nameCell[1].textContent.trim() : '') + '"');
                    
                    rowData.push('"' + cells[3].textContent.trim() + '"');
                    
                    // Location
                    const locationCell = cells[4].querySelectorAll('div');
                    rowData.push('"' + (locationCell[0] ? locationCell[0].textContent.trim() : '') + '"');
                    rowData.push('"' + (locationCell[1] ? locationCell[1].textContent.trim().split('•')[0] : '') + '"');
                    rowData.push('"' + (locationCell[1] && locationCell[1].textContent.includes('•') ? locationCell[1].textContent.split('•')[1] : '') + '"');
                    
                    rowData.push('"' + cells[5].textContent.trim() + '"');
                    rowData.push('"' + cells[6].textContent.trim().split('\n')[0] + '"');
                    rowData.push('"' + cells[6].textContent.trim().split('\n')[1] || '' + '"');
                    rowData.push('"' + cells[1].textContent.trim().split('\n')[1] || '' + '"');
                    rowData.push('"' + cells[7].textContent.trim() + '"');
                    rowData.push('"' + cells[8].textContent.trim().replace('\n', ' ') + '"');
                    rowData.push('"' + cells[9].textContent.trim().replace('\n', ' ') + '"');
                    rowData.push('"' + cells[10].textContent.trim().split('\n')[0] + '"');
                    rowData.push('"' + (cells[10].textContent.trim().split('\n')[1] || 'N/A') + '"');
                    
                    csvContent += rowData.join(',') + '\n';
                }
            });
            
            // Download CSV
            const encodedUri = encodeURI(csvContent);
            const link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", "data_management_report_" + new Date().toISOString().split('T')[0] + ".csv");
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    </script>
</body>
</html>