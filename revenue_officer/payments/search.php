<?php
/**
 * Search Accounts Page for QUICKBILL 305
 * Revenue Officer interface for quick account searches with delivery management
 * Updated with outstanding balance calculation, delivery status tracking, and improved error handling
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

// Check if user is revenue officer or admin
$currentUser = getCurrentUser();
if (!isRevenueOfficer() && !isAdmin()) {
    setFlashMessage('error', 'Access denied. Revenue Officer privileges required.');
    header('Location: ../../auth/login.php');
    exit();
}

// Check session expiration
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > 1800)) {
    // Session expired (30 minutes)
    session_unset();
    session_destroy();
    setFlashMessage('error', 'Your session has expired. Please log in again.');
    header('Location: ../../index.php');
    exit();
}

// Update last activity time
$_SESSION['LAST_ACTIVITY'] = time();

$userDisplayName = getUserDisplayName($currentUser);

// Initialize variables
$searchTerm = '';
$searchType = 'all';
$searchResults = [];
$error = '';
$searchPerformed = false;
$debugMode = isset($_GET['debug']) && $_GET['debug'] == '1';
$debugInfo = '';

// Database connection
try {
    $db = new Database();
    if ($debugMode) {
        $debugInfo .= "Database connection successful. ";
    }
} catch (Exception $e) {
    $error = 'Database connection failed. Please try again.';
    if ($debugMode) {
        $debugInfo .= "Database connection error: " . $e->getMessage() . ". ";
    }
}

// Handle AJAX request for updating serving status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_serving_status') {
    header('Content-Type: application/json');
    
    try {
        $billId = intval($_POST['bill_id'] ?? 0);
        $servedStatus = $_POST['served_status'] ?? 'Not Served';
        $deliveryNotes = trim($_POST['delivery_notes'] ?? '');
        
        if (!$billId) {
            throw new Exception('Invalid bill ID');
        }
        
        // Validate served status
        $validStatuses = ['Not Served', 'Served', 'Attempted', 'Returned'];
        if (!in_array($servedStatus, $validStatuses)) {
            throw new Exception('Invalid served status');
        }
        
        // Update serving status
        $updateQuery = "UPDATE bills SET 
                        served_status = ?, 
                        served_by = ?, 
                        served_at = CASE WHEN ? != 'Not Served' THEN NOW() ELSE NULL END,
                        delivery_notes = ?
                        WHERE bill_id = ?";
        
        $result = $db->execute($updateQuery, [
            $servedStatus,
            $servedStatus !== 'Not Served' ? $currentUser['user_id'] : null,
            $servedStatus,
            $deliveryNotes,
            $billId
        ]);
        
        if ($result) {
            // Log the action
            writeLog("Bill serving status updated - Bill ID: $billId, Status: $servedStatus", 'INFO');
            
            echo json_encode([
                'success' => true,
                'message' => 'Serving status updated successfully',
                'status' => $servedStatus,
                'served_at' => $servedStatus !== 'Not Served' ? date('M d, Y g:i A') : null,
                'served_by' => $servedStatus !== 'Not Served' ? $userDisplayName : null
            ]);
        } else {
            throw new Exception('Failed to update serving status');
        }
        
    } catch (Exception $e) {
        writeLog("Error updating serving status: " . $e->getMessage(), 'ERROR');
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
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

// Function to get delivery statistics for an account
function getDeliveryStats($db, $accountType, $accountId) {
    try {
        $statsQuery = "SELECT 
                          COUNT(*) as total_bills,
                          SUM(CASE WHEN served_status = 'Served' THEN 1 ELSE 0 END) as served_bills,
                          SUM(CASE WHEN served_status = 'Not Served' THEN 1 ELSE 0 END) as pending_delivery,
                          SUM(CASE WHEN served_status = 'Attempted' THEN 1 ELSE 0 END) as attempted_delivery,
                          SUM(CASE WHEN served_status = 'Returned' THEN 1 ELSE 0 END) as returned_bills
                       FROM bills 
                       WHERE bill_type = ? AND reference_id = ?";
        $stats = $db->fetchRow($statsQuery, [ucfirst($accountType), $accountId]);
        
        return [
            'total_bills' => $stats['total_bills'] ?? 0,
            'served_bills' => $stats['served_bills'] ?? 0,
            'pending_delivery' => $stats['pending_delivery'] ?? 0,
            'attempted_delivery' => $stats['attempted_delivery'] ?? 0,
            'returned_bills' => $stats['returned_bills'] ?? 0,
            'delivery_rate' => $stats['total_bills'] > 0 ? 
                ($stats['served_bills'] / $stats['total_bills']) * 100 : 0
        ];
    } catch (Exception $e) {
        return [
            'total_bills' => 0,
            'served_bills' => 0,
            'pending_delivery' => 0,
            'attempted_delivery' => 0,
            'returned_bills' => 0,
            'delivery_rate' => 0
        ];
    }
}

// Handle search request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'search') {
    if (!verifyCsrfToken()) {
        $error = 'Security validation failed. Please try again.';
    } else {
        $searchTerm = sanitizeInput($_POST['search_term'] ?? '');
        $searchType = sanitizeInput($_POST['search_type'] ?? 'all');
        
        if (empty($searchTerm)) {
            $error = 'Please enter a search term.';
        } else {
            $searchPerformed = true;
            try {
                $searchPattern = "%{$searchTerm}%";
                $searchResults = [];
                
                if ($debugMode) {
                    $debugInfo .= "Searching for '{$searchTerm}' in '{$searchType}' accounts. ";
                }
                
                // Search businesses
                if ($searchType === 'all' || $searchType === 'business') {
                    $businessQuery = "
                        SELECT 'business' as type, business_id as id, account_number, business_name as name, 
                               owner_name, telephone, amount_payable, exact_location as location, status,
                               business_type, category, zone_id, sub_zone_id, created_at
                        FROM businesses 
                        WHERE (account_number LIKE ? OR business_name LIKE ? OR owner_name LIKE ? OR telephone LIKE ?)
                        AND status = 'Active'
                        ORDER BY business_name
                    ";
                    
                    $businesses = $db->fetchAll($businessQuery, [$searchPattern, $searchPattern, $searchPattern, $searchPattern]);
                    if ($businesses !== false && is_array($businesses)) {
                        // Calculate remaining balance and delivery stats for each business
                        foreach ($businesses as &$business) {
                            $balanceInfo = calculateRemainingBalance($db, 'business', $business['id'], $business['amount_payable']);
                            $business['remaining_balance'] = $balanceInfo['remaining_balance'];
                            $business['total_paid'] = $balanceInfo['total_paid'];
                            
                            // Get delivery statistics
                            $deliveryStats = getDeliveryStats($db, 'business', $business['id']);
                            $business = array_merge($business, $deliveryStats);
                        }
                        $searchResults = array_merge($searchResults, $businesses);
                        if ($debugMode) {
                            $debugInfo .= "Found " . count($businesses) . " businesses with balance and delivery calculations. ";
                        }
                    } else {
                        if ($debugMode) {
                            $debugInfo .= "No businesses found or query failed. ";
                        }
                    }
                }
                
                // Search properties
                if ($searchType === 'all' || $searchType === 'property') {
                    $propertyQuery = "
                        SELECT 'property' as type, property_id as id, property_number as account_number, 
                               owner_name as name, owner_name, telephone, amount_payable, location, 'Active' as status,
                               structure, property_use, number_of_rooms, zone_id, created_at
                        FROM properties 
                        WHERE (property_number LIKE ? OR owner_name LIKE ? OR telephone LIKE ?)
                        ORDER BY owner_name
                    ";
                    
                    $properties = $db->fetchAll($propertyQuery, [$searchPattern, $searchPattern, $searchPattern]);
                    if ($properties !== false && is_array($properties)) {
                        // Calculate remaining balance and delivery stats for each property
                        foreach ($properties as &$property) {
                            $balanceInfo = calculateRemainingBalance($db, 'property', $property['id'], $property['amount_payable']);
                            $property['remaining_balance'] = $balanceInfo['remaining_balance'];
                            $property['total_paid'] = $balanceInfo['total_paid'];
                            
                            // Get delivery statistics
                            $deliveryStats = getDeliveryStats($db, 'property', $property['id']);
                            $property = array_merge($property, $deliveryStats);
                        }
                        $searchResults = array_merge($searchResults, $properties);
                        if ($debugMode) {
                            $debugInfo .= "Found " . count($properties) . " properties with balance and delivery calculations. ";
                        }
                    } else {
                        if ($debugMode) {
                            $debugInfo .= "No properties found or query failed. ";
                        }
                    }
                }
                
                // Get zone names for results
                if (!empty($searchResults)) {
                    $zoneIds = array_unique(array_column($searchResults, 'zone_id'));
                    $zoneIds = array_filter($zoneIds); // Remove null values
                    
                    if (!empty($zoneIds)) {
                        $zonePlaceholders = str_repeat('?,', count($zoneIds) - 1) . '?';
                        $zoneQuery = "SELECT zone_id, zone_name FROM zones WHERE zone_id IN ($zonePlaceholders)";
                        $zones = $db->fetchAll($zoneQuery, $zoneIds);
                        $zoneMap = [];
                        if ($zones !== false) {
                            foreach ($zones as $zone) {
                                $zoneMap[$zone['zone_id']] = $zone['zone_name'];
                            }
                        }
                        
                        // Add zone names to results
                        foreach ($searchResults as &$result) {
                            $result['zone_name'] = $zoneMap[$result['zone_id']] ?? 'Unknown';
                        }
                        
                        if ($debugMode) {
                            $debugInfo .= "Added zone names to " . count($searchResults) . " results. ";
                        }
                    }
                }
                
                if ($debugMode) {
                    $debugInfo .= "Total results: " . count($searchResults) . ". ";
                }
                
            } catch (Exception $e) {
                $error = 'Search failed. Please try again.';
                if ($debugMode) {
                    $debugInfo .= "Search error: " . $e->getMessage() . ". ";
                }
                error_log("Search error: " . $e->getMessage());
            }
        }
    }
}

// Get account details for modal display
$accountDetails = null;
$accountBills = [];
if (isset($_GET['view']) && !empty($_GET['view'])) {
    $viewData = explode(':', $_GET['view']);
    if (count($viewData) === 2) {
        $accountType = $viewData[0];
        $accountId = intval($viewData[1]);
        
        if ($debugMode) {
            $debugInfo .= "Fetching {$accountType} details for ID {$accountId}. ";
        }
        
        try {
            if ($accountType === 'business') {
                $accountDetails = $db->fetchRow("
                    SELECT b.*, z.zone_name, sz.sub_zone_name,
                           (SELECT COUNT(*) FROM bills WHERE bill_type = 'Business' AND reference_id = b.business_id) as total_bills,
                           (SELECT COUNT(*) FROM payments p JOIN bills bl ON p.bill_id = bl.bill_id 
                            WHERE bl.bill_type = 'Business' AND bl.reference_id = b.business_id AND p.payment_status = 'Successful') as successful_payments,
                           (SELECT COUNT(*) FROM payments p JOIN bills bl ON p.bill_id = bl.bill_id 
                            WHERE bl.bill_type = 'Business' AND bl.reference_id = b.business_id) as total_payments
                    FROM businesses b
                    LEFT JOIN zones z ON b.zone_id = z.zone_id
                    LEFT JOIN sub_zones sz ON b.sub_zone_id = sz.sub_zone_id
                    WHERE b.business_id = ?
                ", [$accountId]);
                
                if ($accountDetails !== false && $accountDetails !== null && !empty($accountDetails)) {
                    $accountDetails['type'] = 'business';
                    // Calculate detailed balance information
                    $balanceInfo = calculateRemainingBalance($db, 'business', $accountId, $accountDetails['amount_payable']);
                    $accountDetails['remaining_balance'] = $balanceInfo['remaining_balance'];
                    $accountDetails['total_paid'] = $balanceInfo['total_paid'];
                    $accountDetails['payment_progress'] = $accountDetails['amount_payable'] > 0 ? 
                        ($balanceInfo['total_paid'] / $accountDetails['amount_payable']) * 100 : 100;
                    
                    // Get delivery statistics
                    $deliveryStats = getDeliveryStats($db, 'business', $accountId);
                    $accountDetails = array_merge($accountDetails, $deliveryStats);
                    
                    // Get bills with serving information
                    $billsQuery = "SELECT b.*, 
                                          u.first_name as served_by_first_name, 
                                          u.last_name as served_by_last_name,
                                          u.username as served_by_username
                                   FROM bills b
                                   LEFT JOIN users u ON b.served_by = u.user_id
                                   WHERE b.bill_type = 'Business' AND b.reference_id = ? 
                                   ORDER BY b.billing_year DESC, b.generated_at DESC
                                   LIMIT 5";
                    $accountBills = $db->fetchAll($billsQuery, [$accountId]);
                    
                    if ($debugMode) {
                        $debugInfo .= "Business found: " . $accountDetails['business_name'] . 
                                     ". Remaining balance: " . $accountDetails['remaining_balance'] . 
                                     ". Delivery rate: " . $deliveryStats['delivery_rate'] . "%. ";
                    }
                } else {
                    $accountDetails = null;
                    if ($debugMode) {
                        $debugInfo .= "No business found with ID {$accountId}. ";
                    }
                }
                
            } elseif ($accountType === 'property') {
                $accountDetails = $db->fetchRow("
                    SELECT p.*, z.zone_name,
                           (SELECT COUNT(*) FROM bills WHERE bill_type = 'Property' AND reference_id = p.property_id) as total_bills,
                           (SELECT COUNT(*) FROM payments py JOIN bills bl ON py.bill_id = bl.bill_id 
                            WHERE bl.bill_type = 'Property' AND bl.reference_id = p.property_id AND py.payment_status = 'Successful') as successful_payments,
                           (SELECT COUNT(*) FROM payments py JOIN bills bl ON py.bill_id = bl.bill_id 
                            WHERE bl.bill_type = 'Property' AND bl.reference_id = p.property_id) as total_payments
                    FROM properties p
                    LEFT JOIN zones z ON p.zone_id = z.zone_id
                    WHERE p.property_id = ?
                ", [$accountId]);
                
                if ($accountDetails !== false && $accountDetails !== null && !empty($accountDetails)) {
                    $accountDetails['type'] = 'property';
                    // Calculate detailed balance information
                    $balanceInfo = calculateRemainingBalance($db, 'property', $accountId, $accountDetails['amount_payable']);
                    $accountDetails['remaining_balance'] = $balanceInfo['remaining_balance'];
                    $accountDetails['total_paid'] = $balanceInfo['total_paid'];
                    $accountDetails['payment_progress'] = $accountDetails['amount_payable'] > 0 ? 
                        ($balanceInfo['total_paid'] / $accountDetails['amount_payable']) * 100 : 100;
                    
                    // Get delivery statistics
                    $deliveryStats = getDeliveryStats($db, 'property', $accountId);
                    $accountDetails = array_merge($accountDetails, $deliveryStats);
                    
                    // Get bills with serving information
                    $billsQuery = "SELECT b.*, 
                                          u.first_name as served_by_first_name, 
                                          u.last_name as served_by_last_name,
                                          u.username as served_by_username
                                   FROM bills b
                                   LEFT JOIN users u ON b.served_by = u.user_id
                                   WHERE b.bill_type = 'Property' AND b.reference_id = ? 
                                   ORDER BY b.billing_year DESC, b.generated_at DESC
                                   LIMIT 5";
                    $accountBills = $db->fetchAll($billsQuery, [$accountId]);
                    
                    if ($debugMode) {
                        $debugInfo .= "Property found: " . $accountDetails['owner_name'] . 
                                     ". Remaining balance: " . $accountDetails['remaining_balance'] . 
                                     ". Delivery rate: " . $deliveryStats['delivery_rate'] . "%. ";
                    }
                } else {
                    $accountDetails = null;
                    if ($debugMode) {
                        $debugInfo .= "No property found with ID {$accountId}. ";
                    }
                }
            } else {
                if ($debugMode) {
                    $debugInfo .= "Invalid account type: {$accountType}. ";
                }
            }
            
        } catch (Exception $e) {
            $accountDetails = null;
            if ($debugMode) {
                $debugInfo .= "Database error fetching account details: " . $e->getMessage() . ". ";
            }
            error_log("Account details fetch error: " . $e->getMessage());
        }
    } else {
        if ($debugMode) {
            $debugInfo .= "Invalid view parameter format. ";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Accounts - <?php echo APP_NAME; ?></title>
    
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
            color: #2d3748;
        }
        
        /* Custom Icons */
        .icon-search::before { content: "🔍"; }
        .icon-money::before { content: "💰"; }
        .icon-building::before { content: "🏢"; }
        .icon-home::before { content: "🏠"; }
        .icon-phone::before { content: "📞"; }
        .icon-location::before { content: "📍"; }
        .icon-back::before { content: "←"; }
        .icon-eye::before { content: "👁️"; }
        .icon-filter::before { content: "🔧"; }
        .icon-info::before { content: "ℹ️"; }
        .icon-warning::before { content: "⚠️"; }
        .icon-balance::before { content: "⚖️"; }
        .icon-check::before { content: "✅"; }
        .icon-truck::before { content: "🚛"; }
        .icon-times::before { content: "❌"; }
        .icon-exclamation::before { content: "⚠️"; }
        .icon-undo::before { content: "↩️"; }
        
        /* Header */
        .header {
            background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%);
            color: white;
            padding: 20px 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .header-title {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .header-title h1 {
            font-size: 28px;
            font-weight: 700;
        }
        
        .header-icon {
            font-size: 32px;
            opacity: 0.9;
        }
        
        .back-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .back-btn:hover {
            background: rgba(255,255,255,0.3);
            color: white;
            text-decoration: none;
        }
        
        /* Main Content */
        .main-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px 20px;
        }
        
        /* Debug Info */
        .debug-info {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            font-family: monospace;
            max-height: 400px;
            overflow-y: auto;
            white-space: pre-line;
            line-height: 1.4;
        }

        .debug-info strong {
            color: #cc7a00;
            font-weight: bold;
        }

        .debug-toggle {
            background: #ffc107;
            color: #212529;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            margin-bottom: 10px;
            transition: all 0.3s;
        }

        .debug-toggle:hover {
            background: #e0a800;
            transform: translateY(-1px);
        }
        
        /* Search Section */
        .search-section {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .search-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 25px;
        }
        
        .search-title {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .search-title h2 {
            color: #2d3748;
            font-size: 24px;
            font-weight: 600;
        }
        
        .search-stats {
            color: #718096;
            font-size: 14px;
        }
        
        .search-form {
            display: grid;
            grid-template-columns: 2fr 1fr auto;
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
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .form-control {
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s;
            background: white;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #e53e3e;
            box-shadow: 0 0 0 0.2rem rgba(229, 62, 62, 0.25);
        }
        
        .form-control select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 12px center;
            background-repeat: no-repeat;
            background-size: 16px;
            padding-right: 40px;
        }
        
        .search-btn {
            background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            height: fit-content;
        }
        
        .search-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        /* Search Results */
        .results-section {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .results-title {
            color: #2d3748;
            font-size: 20px;
            font-weight: 600;
        }
        
        .results-count {
            background: #e53e3e;
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }
        
        .results-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .results-table th {
            background: #f7fafc;
            padding: 15px 12px;
            text-align: left;
            font-weight: 600;
            color: #2d3748;
            border-bottom: 2px solid #e2e8f0;
            font-size: 14px;
        }
        
        .results-table td {
            padding: 15px 12px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }
        
        .results-table tr:hover {
            background: #f7fafc;
        }
        
        .account-type-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-business {
            background: #e6fffa;
            color: #38a169;
        }
        
        .badge-property {
            background: #ebf8ff;
            color: #4299e1;
        }
        
        .status-badge {
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-paid {
            background: #c6f6d5;
            color: #276749;
        }
        
        .status-pending {
            background: #fed7d7;
            color: #c53030;
        }
        
        .status-partial {
            background: #fef3c7;
            color: #92400e;
        }
        
        .amount-highlight {
            color: #e53e3e;
            font-weight: 700;
            font-size: 16px;
        }
        
        .amount-paid {
            color: #38a169;
            font-weight: 600;
        }
        
        .balance-display {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        
        .balance-main {
            font-weight: 700;
            font-size: 16px;
        }
        
        .balance-detail {
            font-size: 11px;
            opacity: 0.8;
        }
        
        /* Delivery Status Badges */
        .delivery-stats {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .delivery-badge {
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }
        
        .delivery-badge.served {
            background: #d1fae5;
            color: #065f46;
        }
        
        .delivery-badge.pending {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .delivery-badge.attempted {
            background: #fef3c7;
            color: #92400e;
        }
        
        .delivery-badge.returned {
            background: #fecaca;
            color: #991b1b;
        }
        
        .delivery-rate {
            font-size: 11px;
            color: #64748b;
            font-weight: 500;
        }
        
        /* Serving Status Badges for Modal */
        .serving-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .serving-badge.served {
            background: #d1fae5;
            color: #065f46;
        }
        
        .serving-badge.not-served {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .serving-badge.attempted {
            background: #fef3c7;
            color: #92400e;
        }
        
        .serving-badge.returned {
            background: #fecaca;
            color: #991b1b;
        }
        
        /* Serving Actions */
        .serving-actions {
            display: flex;
            gap: 5px;
            align-items: center;
        }
        
        .serving-dropdown {
            position: relative;
            display: inline-block;
        }
        
        .serving-dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            top: 100%;
            background: white;
            min-width: 200px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            z-index: 1000;
            padding: 10px;
        }
        
        .serving-dropdown.active .serving-dropdown-content {
            display: block;
        }
        
        .serving-form {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .serving-form select,
        .serving-form input,
        .serving-form textarea {
            padding: 8px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 12px;
        }
        
        .serving-form textarea {
            resize: vertical;
            min-height: 60px;
        }
        
        .serving-form-buttons {
            display: flex;
            gap: 5px;
            justify-content: flex-end;
        }
        
        /* Loading Spinner */
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .action-btn {
            background: #4299e1;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .action-btn:hover {
            background: #3182ce;
            transform: translateY(-1px);
            color: white;
            text-decoration: none;
        }
        
        .action-btn.payment {
            background: #38a169;
        }
        
        .action-btn.payment:hover {
            background: #2f855a;
        }
        
        .btn-xs {
            padding: 4px 8px;
            font-size: 11px;
        }
        
        .btn-success {
            background: #38a169;
            color: white;
        }
        
        .btn-success:hover {
            background: #2f855a;
        }
        
        .btn-secondary {
            background: #64748b;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #475569;
        }
        
        .btn-primary {
            background: #4299e1;
            color: white;
        }
        
        .btn-primary:hover {
            background: #3182ce;
        }
        
        /* No Results */
        .no-results {
            text-align: center;
            padding: 60px 20px;
            color: #718096;
        }
        
        .no-results-icon {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .no-results h3 {
            font-size: 24px;
            margin-bottom: 10px;
            color: #2d3748;
        }
        
        .no-results p {
            font-size: 16px;
            max-width: 400px;
            margin: 0 auto;
        }
        
        /* Search Instructions */
        .search-instructions {
            background: #f7fafc;
            border-left: 4px solid #4299e1;
            padding: 20px;
            margin-bottom: 30px;
            border-radius: 0 10px 10px 0;
        }
        
        .search-instructions h4 {
            color: #2d3748;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .search-instructions ul {
            margin-left: 20px;
            color: #718096;
        }
        
        .search-instructions li {
            margin-bottom: 5px;
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
        
        .alert-danger {
            background: #fed7d7;
            border: 1px solid #fc8181;
            color: #c53030;
        }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            animation: fadeIn 0.3s ease;
        }
        
        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: white;
            border-radius: 15px;
            max-width: 1000px;
            width: 95%;
            max-height: 95vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: slideIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .modal-header {
            padding: 25px 30px 20px;
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-title {
            font-size: 24px;
            font-weight: 600;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            color: #718096;
            cursor: pointer;
            transition: color 0.3s;
        }
        
        .modal-close:hover {
            color: #e53e3e;
        }
        
        .modal-body {
            padding: 30px;
        }
        
        .account-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .info-label {
            font-weight: 600;
            color: #718096;
            font-size: 14px;
        }
        
        .info-value {
            font-size: 16px;
            color: #2d3748;
            font-weight: 500;
        }
        
        /* Balance Highlight for Modal */
        .balance-highlight {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: 2px solid #f59e0b;
            border-radius: 12px;
            padding: 25px;
            margin: 25px 0;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .balance-highlight.paid {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            border-color: #10b981;
        }
        
        .balance-highlight::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.3) 0%, transparent 70%);
            animation: shimmer 3s ease-in-out infinite;
            pointer-events: none;
        }
        
        @keyframes shimmer {
            0%, 100% { transform: rotate(0deg) translate(-50%, -50%); }
            50% { transform: rotate(180deg) translate(-50%, -50%); }
        }
        
        .balance-highlight h4 {
            margin: 0 0 15px 0;
            font-size: 20px;
            color: #92400e;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .balance-highlight.paid h4 {
            color: #065f46;
        }
        
        .balance-amount {
            font-size: 36px;
            font-weight: bold;
            color: #92400e;
            margin: 15px 0;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .balance-highlight.paid .balance-amount {
            color: #065f46;
        }
        
        .balance-subtitle {
            font-size: 14px;
            opacity: 0.8;
            color: #92400e;
        }
        
        .balance-highlight.paid .balance-subtitle {
            color: #065f46;
        }
        
        /* Payment Progress Bar */
        .payment-progress {
            background: #f7fafc;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            border-left: 4px solid #4299e1;
        }
        
        .progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .progress-bar-container {
            background: #e2e8f0;
            height: 12px;
            border-radius: 6px;
            overflow: hidden;
            margin-bottom: 10px;
        }
        
        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #10b981, #059669);
            transition: width 1s ease;
            border-radius: 6px;
        }
        
        .progress-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .progress-stat {
            text-align: center;
        }
        
        .progress-stat-value {
            font-size: 18px;
            font-weight: bold;
            color: #2d3748;
        }
        
        .progress-stat-label {
            font-size: 12px;
            color: #718096;
            margin-top: 2px;
        }
        
        /* Delivery Statistics Section */
        .delivery-stats-section {
            background: #f8fafc;
            border-radius: 12px;
            padding: 20px;
            margin: 25px 0;
            border-left: 4px solid #38a169;
        }
        
        .delivery-stats-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .delivery-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .delivery-stat {
            text-align: center;
            padding: 10px;
            background: white;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        
        .delivery-stat-value {
            font-size: 20px;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 5px;
        }
        
        .delivery-stat-label {
            font-size: 12px;
            color: #718096;
        }
        
        .delivery-rate-bar {
            background: #e2e8f0;
            height: 8px;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 10px;
        }
        
        .delivery-rate-fill {
            height: 100%;
            background: linear-gradient(90deg, #38a169, #2f855a);
            transition: width 1s ease;
            border-radius: 4px;
        }
        
        /* Bills Table in Modal */
        .bills-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        
        .bills-table th {
            background: #f8fafc;
            padding: 12px 10px;
            text-align: left;
            font-weight: 600;
            color: #2d3748;
            border-bottom: 1px solid #e2e8f0;
            font-size: 12px;
        }
        
        .bills-table td {
            padding: 10px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            font-size: 12px;
        }
        
        .bills-table tr:hover {
            background: #f8fafc;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .search-form {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .results-table {
                font-size: 14px;
            }
            
            .results-table th,
            .results-table td {
                padding: 10px 8px;
            }
            
            .modal-content {
                width: 95%;
                margin: 20px;
            }
            
            .account-info-grid {
                grid-template-columns: 1fr;
            }
            
            .progress-stats {
                grid-template-columns: 1fr;
            }
            
            .balance-amount {
                font-size: 28px;
            }
            
            .delivery-stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .serving-dropdown-content {
                left: 0;
                right: auto;
                min-width: 250px;
            }
        }
        
        /* Animation */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fadeInUp 0.6s ease-out;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        .pulse {
            animation: pulse 2s ease-in-out infinite;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <div class="header-content">
            <div class="header-title">
                <div class="header-icon">
                    <i class="fas fa-search"></i>
                    <span class="icon-search" style="display: none;"></span>
                </div>
                <h1>Search Accounts & Delivery Management</h1>
            </div>
            <a href="../index.php" class="back-btn">
                <i class="fas fa-arrow-left"></i>
                <span class="icon-back" style="display: none;"></span>
                Back to Dashboard
            </a>
        </div>
    </div>

    <div class="main-container">
        <!-- Debug Information -->
        <?php if ($debugMode && !empty($debugInfo)): ?>
            <button class="debug-toggle" onclick="toggleDebugInfo()">🔧 Hide Debug Info</button>
            <div class="debug-info fade-in" id="debugInfo" style="display: block;">
                <strong>🐛 Debug Information:</strong><br>
                <?php echo $debugInfo; ?>
            </div>
        <?php endif; ?>

        <!-- Alert -->
        <?php if ($error): ?>
            <div class="alert alert-danger fade-in">
                <i class="fas fa-exclamation-triangle"></i>
                <span class="icon-warning" style="display: none;"></span>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Search Instructions -->
        <div class="search-instructions fade-in">
            <h4>
                <i class="fas fa-info-circle"></i>
                <span class="icon-info" style="display: none;"></span>
                Search Instructions & Delivery Management
            </h4>
            <ul>
                <li><strong>Account Number:</strong> Enter exact account number (e.g., BIZ000001, PROP000001)</li>
                <li><strong>Name:</strong> Search by business name or property owner name</li>
                <li><strong>Phone:</strong> Search by contact telephone number</li>
                <li><strong>Filter:</strong> Choose to search all accounts, businesses only, or properties only</li>
                <li><strong>Balance:</strong> Outstanding balance shows remaining amount after all successful payments</li>
                <li><strong>Delivery Status:</strong> Track bill delivery with statuses: Served, Not Served, Attempted, Returned</li>
                <li><strong>Delivery Rate:</strong> Percentage of bills successfully delivered to each account</li>
                <?php if (!$debugMode): ?>
                <li><strong>Debug:</strong> Add ?debug=1 to URL for detailed debugging information</li>
                <?php endif; ?>
                <?php if ($debugMode): ?>
                <li><strong>Quick Tests:</strong> 
                    <a href="?view=business:6&debug=1" style="color: #e53e3e;">Test Business ID 6</a> | 
                    <a href="?view=property:4&debug=1" style="color: #e53e3e;">Test Property ID 4</a>
                </li>
                <?php endif; ?>
            </ul>
        </div>

        <!-- Search Section -->
        <div class="search-section fade-in">
            <div class="search-header">
                <div class="search-title">
                    <i class="fas fa-search" style="color: #e53e3e; font-size: 24px;"></i>
                    <span class="icon-search" style="display: none; font-size: 24px;"></span>
                    <h2>Search Accounts</h2>
                </div>
                <?php if ($searchPerformed): ?>
                    <div class="search-stats">
                        <?php echo count($searchResults); ?> result(s) found for "<?php echo htmlspecialchars($searchTerm); ?>"
                    </div>
                <?php endif; ?>
            </div>
            
            <form method="POST" class="search-form">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="search">
                
                <div class="form-group">
                    <label for="search_term" class="form-label">Search Term</label>
                    <input type="text" 
                           class="form-control" 
                           id="search_term" 
                           name="search_term"
                           value="<?php echo htmlspecialchars($searchTerm); ?>"
                           placeholder="Enter account number, business/property name, or phone number"
                           required>
                </div>
                
                <div class="form-group">
                    <label for="search_type" class="form-label">Filter</label>
                    <select class="form-control" id="search_type" name="search_type">
                        <option value="all" <?php echo $searchType === 'all' ? 'selected' : ''; ?>>All Accounts</option>
                        <option value="business" <?php echo $searchType === 'business' ? 'selected' : ''; ?>>Businesses Only</option>
                        <option value="property" <?php echo $searchType === 'property' ? 'selected' : ''; ?>>Properties Only</option>
                    </select>
                </div>
                
                <button type="submit" class="search-btn">
                    <i class="fas fa-search"></i>
                    <span class="icon-search" style="display: none;"></span>
                    Search
                </button>
            </form>
        </div>

        <!-- Search Results -->
        <?php if ($searchPerformed): ?>
            <div class="results-section fade-in">
                <?php if (!empty($searchResults)): ?>
                    <div class="results-header">
                        <h3 class="results-title">Search Results</h3>
                        <span class="results-count"><?php echo count($searchResults); ?> found</span>
                    </div>
                    
                    <div style="overflow-x: auto;">
                        <table class="results-table">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Account Number</th>
                                    <th>Name/Owner</th>
                                    <th>Phone</th>
                                    <th>Zone</th>
                                    <th>Outstanding Balance</th>
                                    <th>Delivery Status</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($searchResults as $result): ?>
                                    <?php 
                                    $remainingBalance = $result['remaining_balance'] ?? $result['amount_payable'];
                                    $totalPaid = $result['total_paid'] ?? 0;
                                    $amountPayable = $result['amount_payable'];
                                    
                                    // Determine status based on remaining balance
                                    if ($remainingBalance <= 0) {
                                        $status = 'paid';
                                        $statusText = 'Paid';
                                        $statusClass = 'status-paid';
                                    } elseif ($totalPaid > 0) {
                                        $status = 'partial';
                                        $statusText = 'Partial';
                                        $statusClass = 'status-partial';
                                    } else {
                                        $status = 'pending';
                                        $statusText = 'Pending';
                                        $statusClass = 'status-pending';
                                    }
                                    
                                    // Delivery statistics
                                    $totalBills = $result['total_bills'] ?? 0;
                                    $servedBills = $result['served_bills'] ?? 0;
                                    $pendingDelivery = $result['pending_delivery'] ?? 0;
                                    $deliveryRate = $result['delivery_rate'] ?? 0;
                                    ?>
                                    <tr>
                                        <td>
                                            <span class="account-type-badge <?php echo $result['type'] === 'business' ? 'badge-business' : 'badge-property'; ?>">
                                                <i class="fas <?php echo $result['type'] === 'business' ? 'fa-building' : 'fa-home'; ?>"></i>
                                                <span class="<?php echo $result['type'] === 'business' ? 'icon-building' : 'icon-home'; ?>" style="display: none;"></span>
                                                <?php echo ucfirst($result['type']); ?>
                                            </span>
                                        </td>
                                        <td><strong><?php echo htmlspecialchars($result['account_number']); ?></strong></td>
                                        <td>
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($result['name']); ?></div>
                                            <div style="font-size: 12px; color: #718096;"><?php echo htmlspecialchars($result['owner_name']); ?></div>
                                        </td>
                                        <td>
                                            <i class="fas fa-phone" style="color: #718096; margin-right: 5px;"></i>
                                            <span class="icon-phone" style="display: none;"></span>
                                            <?php echo htmlspecialchars($result['telephone'] ?: 'N/A'); ?>
                                        </td>
                                        <td>
                                            <i class="fas fa-map-marker-alt" style="color: #718096; margin-right: 5px;"></i>
                                            <span class="icon-location" style="display: none;"></span>
                                            <?php echo htmlspecialchars($result['zone_name'] ?? 'Unknown'); ?>
                                        </td>
                                        <td>
                                            <div class="balance-display">
                                                <?php if ($remainingBalance > 0): ?>
                                                    <span class="balance-main amount-highlight">
                                                        <?php echo formatCurrency($remainingBalance); ?>
                                                    </span>
                                                    <?php if ($totalPaid > 0): ?>
                                                        <span class="balance-detail" style="color: #38a169;">
                                                            Paid: <?php echo formatCurrency($totalPaid); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="balance-main amount-paid">
                                                        <i class="fas fa-check-circle"></i>
                                                        <span class="icon-check" style="display: none;"></span>
                                                        Fully Paid
                                                    </span>
                                                    <span class="balance-detail" style="color: #38a169;">
                                                        Total: <?php echo formatCurrency($totalPaid); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="delivery-stats">
                                                <?php if ($totalBills > 0): ?>
                                                    <div style="display: flex; gap: 4px; margin-bottom: 4px;">
                                                        <?php if ($servedBills > 0): ?>
                                                            <span class="delivery-badge served">
                                                                <i class="fas fa-check"></i>
                                                                <span class="icon-check" style="display: none;"></span>
                                                                <?php echo $servedBills; ?>
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if ($pendingDelivery > 0): ?>
                                                            <span class="delivery-badge pending">
                                                                <i class="fas fa-clock"></i>
                                                                <span class="icon-times" style="display: none;"></span>
                                                                <?php echo $pendingDelivery; ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="delivery-rate">
                                                        <?php echo number_format($deliveryRate, 1); ?>% delivered
                                                    </div>
                                                <?php else: ?>
                                                    <span style="color: #718096; font-size: 12px;">No bills</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $statusClass; ?>">
                                                <?php echo $statusText; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 5px;">
                                                <a href="?view=<?php echo $result['type']; ?>:<?php echo $result['id']; ?><?php echo $debugMode ? '&debug=1' : ''; ?>" 
                                                   class="action-btn" 
                                                   onclick="showAccountDetails(event, '<?php echo $result['type']; ?>', <?php echo $result['id']; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                    <span class="icon-eye" style="display: none;"></span>
                                                    View
                                                </a>
                                                <?php if ($remainingBalance > 0): ?>
                                                    <a href="record.php?account=<?php echo $result['type']; ?>:<?php echo $result['id']; ?>" 
                                                       class="action-btn payment">
                                                        <i class="fas fa-cash-register"></i>
                                                        <span class="icon-money" style="display: none;"></span>
                                                        Pay
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="no-results">
                        <div class="no-results-icon">
                            <i class="fas fa-search"></i>
                            <span class="icon-search" style="display: none;"></span>
                        </div>
                        <h3>No accounts found</h3>
                        <p>No accounts match your search criteria. Try using different search terms or check your spelling.</p>
                        <?php if ($debugMode): ?>
                            <p style="margin-top: 15px; font-size: 14px; color: #718096;">
                                Debug information is shown above to help troubleshoot the issue.
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Account Details Modal -->
    <div class="modal" id="accountModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle">
                    <i class="fas fa-building"></i>
                    <span class="icon-building" style="display: none;"></span>
                    Account Details & Delivery Management
                </h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body" id="modalBody">
                <!-- Content will be loaded dynamically -->
            </div>
        </div>
    </div>

    <script>
        // Check if Font Awesome loaded, if not show emoji icons
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                const testIcon = document.querySelector('.fas.fa-search');
                if (!testIcon || getComputedStyle(testIcon, ':before').content === 'none') {
                    document.querySelectorAll('.fas, .far').forEach(function(icon) {
                        icon.style.display = 'none';
                    });
                    document.querySelectorAll('[class*="icon-"]').forEach(function(emoji) {
                        emoji.style.display = 'inline';
                    });
                }
            }, 100);

            // Auto-focus search field
            const searchField = document.getElementById('search_term');
            if (searchField) {
                searchField.focus();
            }

            // Show modal if URL has view parameter
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('view')) {
                const viewParam = urlParams.get('view');
                const [type, id] = viewParam.split(':');
                if (type && id) {
                    showAccountDetails(null, type, parseInt(id));
                }
            }
        });

        // Show account details modal
        function showAccountDetails(event, type, id) {
            // Don't prevent default - let the page reload with the view parameter
            // This way PHP can fetch the account details properly
            if (event) {
                // Add debug parameter if it exists in current URL
                const currentUrl = new URL(window.location);
                const debugParam = currentUrl.searchParams.get('debug');
                
                let targetUrl = `?view=${type}:${id}`;
                if (debugParam) {
                    targetUrl += `&debug=${debugParam}`;
                }
                
                // Navigate to the URL with view parameter
                window.location.href = targetUrl;
                return;
            }

            // This code runs when the page loads with a view parameter
            const modal = document.getElementById('accountModal');
            const modalTitle = document.getElementById('modalTitle');
            const modalBody = document.getElementById('modalBody');

            // Update title icon
            const titleIcon = modalTitle.querySelector('i');
            const titleEmojiIcon = modalTitle.querySelector('span');
            if (titleIcon) {
                titleIcon.className = type === 'business' ? 'fas fa-building' : 'fas fa-home';
            }
            if (titleEmojiIcon) {
                titleEmojiIcon.className = type === 'business' ? 'icon-building' : 'icon-home';
            }

            // Show loading state
            modalBody.innerHTML = '<div style="text-align: center; padding: 40px;"><i class="fas fa-spinner fa-spin" style="font-size: 24px; color: #718096;"></i><p style="margin-top: 15px; color: #718096;">Loading account details...</p></div>';
            
            modal.classList.add('show');

            // Check if we have account details from PHP
            <?php if ($accountDetails && !empty($accountDetails)): ?>
                // Account details found
                setTimeout(() => {
                    const accountData = <?php echo json_encode($accountDetails); ?>;
                    const billsData = <?php echo json_encode($accountBills); ?>;
                    displayAccountDetails(accountData, billsData);
                }, 300);
            <?php else: ?>
                // No account details or error occurred
                setTimeout(() => {
                    modalBody.innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #e53e3e;">
                            <i class="fas fa-exclamation-triangle" style="font-size: 24px;"></i>
                            <p style="margin-top: 15px; font-weight: 600;">Account not found</p>
                            <p style="margin-top: 10px; color: #718096; font-size: 14px;">
                                The ${type} account with ID ${id} could not be found or may have been deleted.
                                <br><br>
                                <strong>Debug Information:</strong><br>
                                <?php if ($debugMode): ?>
                                    Check the debug information above for detailed analysis.
                                <?php else: ?>
                                    • Add ?debug=1 to the URL for detailed debugging<br>
                                    • Verify the account number is correct<br>
                                    • Check if the account is active<br>
                                    • Contact administrator if the problem persists
                                <?php endif; ?>
                            </p>
                            <button onclick="closeModal()" style="margin-top: 20px; padding: 10px 20px; background: #e53e3e; color: white; border: none; border-radius: 5px; cursor: pointer;">
                                Close
                            </button>
                        </div>
                    `;
                }, 300);
            <?php endif; ?>
        }

        // Display account details in modal
        function displayAccountDetails(data, bills) {
            const modalBody = document.getElementById('modalBody');
            
            // Validate data
            if (!data || typeof data !== 'object') {
                modalBody.innerHTML = '<div style="text-align: center; padding: 40px; color: #e53e3e;"><i class="fas fa-exclamation-triangle" style="font-size: 24px;"></i><p style="margin-top: 15px;">Invalid account data received.</p></div>';
                return;
            }
            
            const remainingBalance = data.remaining_balance || 0;
            const totalPaid = data.total_paid || 0;
            const amountPayable = data.amount_payable || 0;
            const paymentProgress = data.payment_progress || 0;
            
            // Delivery statistics
            const totalBills = data.total_bills || 0;
            const servedBills = data.served_bills || 0;
            const pendingDelivery = data.pending_delivery || 0;
            const attemptedDelivery = data.attempted_delivery || 0;
            const returnedBills = data.returned_bills || 0;
            const deliveryRate = data.delivery_rate || 0;
            
            let detailsHtml = `
                <!-- Outstanding Balance Highlight -->
                <div class="balance-highlight ${remainingBalance <= 0 ? 'paid' : ''} ${remainingBalance > 0 ? 'pulse' : ''}">
                    <h4>
                        <i class="fas fa-balance-scale"></i>
                        <span class="icon-balance" style="display: none;"></span>
                        ${remainingBalance <= 0 ? 'Account Fully Paid' : 'Outstanding Balance'}
                    </h4>
                    <div class="balance-amount">
                        ₵ ${remainingBalance.toLocaleString('en-US', {minimumFractionDigits: 2})}
                    </div>
                    <div class="balance-subtitle">
                        ${remainingBalance > 0 ? 'This amount needs to be paid to clear the account' : '✅ All bills have been settled'}
                    </div>
                </div>
                
                <!-- Delivery Statistics -->
                <div class="delivery-stats-section">
                    <div class="delivery-stats-header">
                        <h4 style="margin: 0; color: #2d3748; display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-truck"></i>
                            <span class="icon-truck" style="display: none;"></span>
                            Delivery Performance
                        </h4>
                        <span style="font-weight: 600; color: #38a169;">${deliveryRate.toFixed(1)}% Delivery Rate</span>
                    </div>
                    <div class="delivery-stats-grid">
                        <div class="delivery-stat">
                            <div class="delivery-stat-value">${totalBills}</div>
                            <div class="delivery-stat-label">Total Bills</div>
                        </div>
                        <div class="delivery-stat">
                            <div class="delivery-stat-value" style="color: #38a169;">${servedBills}</div>
                            <div class="delivery-stat-label">Served</div>
                        </div>
                        <div class="delivery-stat">
                            <div class="delivery-stat-value" style="color: #e53e3e;">${pendingDelivery}</div>
                            <div class="delivery-stat-label">Pending</div>
                        </div>
                        <div class="delivery-stat">
                            <div class="delivery-stat-value" style="color: #f59e0b;">${attemptedDelivery}</div>
                            <div class="delivery-stat-label">Attempted</div>
                        </div>
                        <div class="delivery-stat">
                            <div class="delivery-stat-value" style="color: #dc2626;">${returnedBills}</div>
                            <div class="delivery-stat-label">Returned</div>
                        </div>
                    </div>
                    <div class="delivery-rate-bar">
                        <div class="delivery-rate-fill" style="width: ${Math.min(deliveryRate, 100)}%;"></div>
                    </div>
                </div>
                
                <!-- Payment Progress -->
                <div class="payment-progress">
                    <div class="progress-header">
                        <h4 style="margin: 0; color: #2d3748; display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-chart-line"></i>
                            <span class="icon-info" style="display: none;"></span>
                            Payment Summary
                        </h4>
                        <span style="font-weight: 600; color: #4299e1;">${paymentProgress.toFixed(1)}% Complete</span>
                    </div>
                    <div class="progress-bar-container">
                        <div class="progress-bar" style="width: ${Math.min(paymentProgress, 100)}%;"></div>
                    </div>
                    <div class="progress-stats">
                        <div class="progress-stat">
                            <div class="progress-stat-value">₵ ${amountPayable.toLocaleString('en-US', {minimumFractionDigits: 2})}</div>
                            <div class="progress-stat-label">Total Payable</div>
                        </div>
                        <div class="progress-stat">
                            <div class="progress-stat-value">₵ ${totalPaid.toLocaleString('en-US', {minimumFractionDigits: 2})}</div>
                            <div class="progress-stat-label">Total Paid</div>
                        </div>
                        <div class="progress-stat">
                            <div class="progress-stat-value">₵ ${remainingBalance.toLocaleString('en-US', {minimumFractionDigits: 2})}</div>
                            <div class="progress-stat-label">Remaining</div>
                        </div>
                        <div class="progress-stat">
                            <div class="progress-stat-value">${data.successful_payments || 0}</div>
                            <div class="progress-stat-label">Payments Made</div>
                        </div>
                    </div>
                </div>
                
                <!-- Account Information -->
                <div class="account-info-grid">
                    <div class="info-item">
                        <span class="info-label">Account Number</span>
                        <span class="info-value">${data.account_number || data.property_number || 'N/A'}</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Owner Name</span>
                        <span class="info-value">${data.owner_name || 'N/A'}</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Phone</span>
                        <span class="info-value">${data.telephone || 'N/A'}</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Zone</span>
                        <span class="info-value">${data.zone_name || 'Unknown'}</span>
                    </div>
            `;

            if (data.type === 'business') {
                detailsHtml += `
                    <div class="info-item">
                        <span class="info-label">Business Name</span>
                        <span class="info-value">${data.business_name || 'N/A'}</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Business Type</span>
                        <span class="info-value">${data.business_type || 'N/A'}</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Category</span>
                        <span class="info-value">${data.category || 'N/A'}</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Sub Zone</span>
                        <span class="info-value">${data.sub_zone_name || 'N/A'}</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Status</span>
                        <span class="info-value">${data.status || 'N/A'}</span>
                    </div>
                `;
            } else {
                detailsHtml += `
                    <div class="info-item">
                        <span class="info-label">Structure</span>
                        <span class="info-value">${data.structure || 'N/A'}</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Property Use</span>
                        <span class="info-value">${data.property_use || 'N/A'}</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Number of Rooms</span>
                        <span class="info-value">${data.number_of_rooms || 'N/A'}</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Ownership Type</span>
                        <span class="info-value">${data.ownership_type || 'N/A'}</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Property Type</span>
                        <span class="info-value">${data.property_type || 'N/A'}</span>
                    </div>
                `;
            }

            detailsHtml += `
                    <div class="info-item">
                        <span class="info-label">Location</span>
                        <span class="info-value">${data.exact_location || data.location || 'N/A'}</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Total Bills</span>
                        <span class="info-value">${data.total_bills || 0}</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Total Transactions</span>
                        <span class="info-value">${data.total_payments || 0}</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Successful Payments</span>
                        <span class="info-value">${data.successful_payments || 0}</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Created</span>
                        <span class="info-value">${data.created_at ? new Date(data.created_at).toLocaleDateString() : 'N/A'}</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Last Updated</span>
                        <span class="info-value">${data.updated_at ? new Date(data.updated_at).toLocaleDateString() : 'N/A'}</span>
                    </div>
                </div>
            `;
            
            // Add recent bills with delivery management if available
            if (bills && bills.length > 0) {
                detailsHtml += `
                    <div style="margin-top: 30px;">
                        <h4 style="color: #2d3748; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-file-invoice"></i>
                            Recent Bills & Delivery Status
                        </h4>
                        <table class="bills-table">
                            <thead>
                                <tr>
                                    <th>Bill Number</th>
                                    <th>Year</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Delivery Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                `;
                
                bills.forEach(bill => {
                    const statusClass = bill.status === 'Paid' ? 'badge-success' : 
                                       bill.status === 'Partially Paid' ? 'badge-warning' : 
                                       bill.status === 'Overdue' ? 'badge-danger' : 'badge-info';
                    
                    const servedStatusClass = bill.served_status ? bill.served_status.toLowerCase().replace(' ', '-') : 'not-served';
                    const statusIcons = {
                        'Served': '<i class="fas fa-check"></i>',
                        'Not Served': '<i class="fas fa-times"></i>',
                        'Attempted': '<i class="fas fa-exclamation"></i>',
                        'Returned': '<i class="fas fa-undo"></i>'
                    };
                    const statusIcon = statusIcons[bill.served_status] || statusIcons['Not Served'];
                    
                    detailsHtml += `
                        <tr>
                            <td>${bill.bill_number}</td>
                            <td>${bill.billing_year}</td>
                            <td>₵ ${parseFloat(bill.amount_payable || 0).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                            <td><span class="table-badge ${statusClass}">${bill.status}</span></td>
                            <td>
                                <div class="serving-badge ${servedStatusClass}" id="serving-badge-${bill.bill_id}">
                                    ${statusIcon} ${bill.served_status || 'Not Served'}
                                </div>
                                ${bill.served_at && bill.served_status !== 'Not Served' ? 
                                    `<small style="display: block; color: #64748b; margin-top: 2px;">${new Date(bill.served_at).toLocaleDateString()}</small>` : ''}
                                ${bill.served_by_first_name ? 
                                    `<small style="display: block; color: #64748b;">by ${bill.served_by_first_name} ${bill.served_by_last_name}</small>` : ''}
                            </td>
                            <td>
                                <div class="serving-actions">
                                    <button class="action-btn btn-xs btn-success" 
                                            onclick="quickServe(${bill.bill_id})"
                                            ${bill.served_status === 'Served' ? 'disabled' : ''}
                                            title="Mark as served">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    
                                    <div class="serving-dropdown">
                                        <button class="action-btn btn-xs btn-secondary" 
                                                onclick="toggleServingDropdown(${bill.bill_id})"
                                                title="Update delivery status">
                                            <i class="fas fa-ellipsis-v"></i>
                                        </button>
                                        
                                        <div class="serving-dropdown-content" id="serving-dropdown-${bill.bill_id}">
                                            <form class="serving-form" onsubmit="updateServingStatus(event, ${bill.bill_id})">
                                                <label style="font-size: 12px; font-weight: 600;">Delivery Status:</label>
                                                <select name="served_status" required>
                                                    <option value="Not Served" ${bill.served_status === 'Not Served' ? 'selected' : ''}>Not Served</option>
                                                    <option value="Served" ${bill.served_status === 'Served' ? 'selected' : ''}>Served</option>
                                                    <option value="Attempted" ${bill.served_status === 'Attempted' ? 'selected' : ''}>Attempted</option>
                                                    <option value="Returned" ${bill.served_status === 'Returned' ? 'selected' : ''}>Returned</option>
                                                </select>
                                                
                                                <label style="font-size: 12px; font-weight: 600;">Notes:</label>
                                                <textarea name="delivery_notes" placeholder="Optional delivery notes...">${bill.delivery_notes || ''}</textarea>
                                                
                                                <div class="serving-form-buttons">
                                                    <button type="button" class="action-btn btn-xs btn-secondary" 
                                                            onclick="toggleServingDropdown(${bill.bill_id})">
                                                        Cancel
                                                    </button>
                                                    <button type="submit" class="action-btn btn-xs btn-primary">
                                                        Update
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    `;
                });
                
                detailsHtml += `
                            </tbody>
                        </table>
                    </div>
                `;
            }
            
            detailsHtml += `
                <!-- Action Buttons -->
                <div style="display: flex; gap: 15px; justify-content: center; margin-top: 30px; padding-top: 20px; border-top: 2px solid #e2e8f0;">
                    ${remainingBalance > 0 ? `
                        <a href="record.php?account=${data.type}:${data.business_id || data.property_id}" 
                           style="background: #38a169; color: white; padding: 12px 25px; border-radius: 8px; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; transition: all 0.3s;"
                           onmouseover="this.style.background='#2f855a'; this.style.transform='translateY(-2px)'"
                           onmouseout="this.style.background='#38a169'; this.style.transform='translateY(0)'">
                            <i class="fas fa-cash-register"></i>
                            <span class="icon-money" style="display: none;"></span>
                            Record Payment
                        </a>
                    ` : `
                        <div style="background: #c6f6d5; color: #276749; padding: 12px 25px; border-radius: 8px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px;">
                            <i class="fas fa-check-circle"></i>
                            <span class="icon-check" style="display: none;"></span>
                            Account Fully Paid
                        </div>
                    `}
                    <button onclick="closeModal()" style="background: #94a3b8; color: white; padding: 12px 25px; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; transition: all 0.3s; display: inline-flex; align-items: center; gap: 8px;"
                            onmouseover="this.style.background='#64748b'; this.style.transform='translateY(-2px)'"
                            onmouseout="this.style.background='#94a3b8'; this.style.transform='translateY(0)'">
                        <i class="fas fa-times"></i>
                        Close
                    </button>
                </div>
            `;

            modalBody.innerHTML = detailsHtml;
        }

        // Quick serve function
        function quickServe(billId) {
            const confirmed = confirm('Mark this bill as served?');
            if (!confirmed) return;
            
            const button = event.target.closest('button');
            const originalHtml = button.innerHTML;
            button.innerHTML = '<div class="loading-spinner"></div>';
            button.disabled = true;
            
            updateServingStatusAjax(billId, 'Served', '', button, originalHtml);
        }

        // Toggle serving dropdown
        function toggleServingDropdown(billId) {
            const dropdown = document.getElementById('serving-dropdown-' + billId).closest('.serving-dropdown');
            const isActive = dropdown.classList.contains('active');
            
            // Close all other dropdowns
            document.querySelectorAll('.serving-dropdown.active').forEach(d => {
                if (d !== dropdown) d.classList.remove('active');
            });
            
            // Toggle current dropdown
            dropdown.classList.toggle('active');
        }

        // Update serving status form submission
        function updateServingStatus(event, billId) {
            event.preventDefault();
            
            const form = event.target;
            const formData = new FormData(form);
            const servedStatus = formData.get('served_status');
            const deliveryNotes = formData.get('delivery_notes');
            
            // Show loading state
            const submitButton = form.querySelector('button[type="submit"]');
            const originalHtml = submitButton.innerHTML;
            submitButton.innerHTML = '<div class="loading-spinner"></div>';
            submitButton.disabled = true;
            
            updateServingStatusAjax(billId, servedStatus, deliveryNotes, submitButton, originalHtml);
        }

        // AJAX function to update serving status
        function updateServingStatusAjax(billId, servedStatus, deliveryNotes, buttonElement, originalHtml) {
            const formData = new FormData();
            formData.append('action', 'update_serving_status');
            formData.append('bill_id', billId);
            formData.append('served_status', servedStatus);
            formData.append('delivery_notes', deliveryNotes);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update the badge
                    updateServingBadge(billId, data.status, data.served_at, data.served_by);
                    
                    // Show success message
                    showNotification('Serving status updated successfully!', 'success');
                    
                    // Close dropdown if open
                    const dropdown = document.getElementById('serving-dropdown-' + billId).closest('.serving-dropdown');
                    if (dropdown) {
                        dropdown.classList.remove('active');
                    }
                    
                    // Update delivery statistics in modal if visible
                    updateDeliveryStats();
                    
                } else {
                    showNotification('Error: ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('An error occurred while updating serving status.', 'error');
            })
            .finally(() => {
                // Restore button state
                if (buttonElement) {
                    buttonElement.innerHTML = originalHtml;
                    buttonElement.disabled = servedStatus === 'Served';
                }
            });
        }

        // Update serving badge in the table
        function updateServingBadge(billId, status, servedAt, servedBy) {
            const badge = document.getElementById('serving-badge-' + billId);
            if (!badge) return;
            
            const parentTd = badge.closest('td');
            
            // Update badge class and content
            badge.className = 'serving-badge ' + status.toLowerCase().replace(' ', '-');
            
            const statusIcons = {
                'Served': '<i class="fas fa-check"></i>',
                'Not Served': '<i class="fas fa-times"></i>',
                'Attempted': '<i class="fas fa-exclamation"></i>',
                'Returned': '<i class="fas fa-undo"></i>'
            };
            
            badge.innerHTML = (statusIcons[status] || '') + ' ' + status;
            
            // Remove existing timestamp and user info
            const existingSmall = parentTd.querySelectorAll('small');
            existingSmall.forEach(el => el.remove());
            
            // Add new timestamp and user info if served
            if (status !== 'Not Served' && servedAt) {
                const timeElement = document.createElement('small');
                timeElement.style.cssText = 'display: block; color: #64748b; margin-top: 2px;';
                timeElement.textContent = servedAt;
                parentTd.appendChild(timeElement);
                
                if (servedBy) {
                    const userElement = document.createElement('small');
                    userElement.style.cssText = 'display: block; color: #64748b;';
                    userElement.textContent = 'by ' + servedBy;
                    parentTd.appendChild(userElement);
                }
            }
        }

        // Update delivery statistics
        function updateDeliveryStats() {
            // Recalculate stats from the modal table if visible
            const modal = document.getElementById('accountModal');
            if (!modal.classList.contains('show')) return;
            
            const badges = modal.querySelectorAll('.serving-badge');
            let served = 0, notServed = 0, attempted = 0, returned = 0;
            
            badges.forEach(badge => {
                if (badge.classList.contains('served')) {
                    served++;
                } else if (badge.classList.contains('not-served')) {
                    notServed++;
                } else if (badge.classList.contains('attempted')) {
                    attempted++;
                } else if (badge.classList.contains('returned')) {
                    returned++;
                }
            });
            
            const total = served + notServed + attempted + returned;
            const deliveryRate = total > 0 ? (served / total) * 100 : 0;
            
            // Update delivery statistics in modal
            const deliveryStatsSection = modal.querySelector('.delivery-stats-section');
            if (deliveryStatsSection) {
                const statValues = deliveryStatsSection.querySelectorAll('.delivery-stat-value');
                if (statValues.length >= 5) {
                    statValues[0].textContent = total;
                    statValues[1].textContent = served;
                    statValues[2].textContent = notServed;
                    statValues[3].textContent = attempted;
                    statValues[4].textContent = returned;
                }
                
                const rateBar = deliveryStatsSection.querySelector('.delivery-rate-fill');
                if (rateBar) {
                    rateBar.style.width = Math.min(deliveryRate, 100) + '%';
                }
                
                const rateText = deliveryStatsSection.querySelector('.delivery-stats-header span');
                if (rateText) {
                    rateText.textContent = deliveryRate.toFixed(1) + '% Delivery Rate';
                }
            }
        }

        // Close modal
        function closeModal() {
            const modal = document.getElementById('accountModal');
            modal.classList.remove('show');
            
            // Clear URL parameter
            const url = new URL(window.location);
            url.searchParams.delete('view');
            window.history.replaceState({}, document.title, url.toString());
        }

        // Close modal when clicking outside
        document.getElementById('accountModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            // Close serving dropdowns when clicking outside
            const activeDropdowns = document.querySelectorAll('.serving-dropdown.active');
            activeDropdowns.forEach(dropdown => {
                if (!dropdown.contains(event.target)) {
                    dropdown.classList.remove('active');
                }
            });
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Escape to close modal
            if (e.key === 'Escape') {
                closeModal();
            }
            
            // Ctrl + F to focus search
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                document.getElementById('search_term').focus();
            }
        });

        // Debug info toggle functionality
        function toggleDebugInfo() {
            const debugDiv = document.getElementById('debugInfo');
            const toggleBtn = document.querySelector('.debug-toggle');
            
            if (debugDiv && toggleBtn) {
                if (debugDiv.style.display === 'none') {
                    debugDiv.style.display = 'block';
                    toggleBtn.textContent = '🔧 Hide Debug Info';
                } else {
                    debugDiv.style.display = 'none';
                    toggleBtn.textContent = '🔧 Show Debug Info';
                }
            }
        }

        // Auto-hide debug info after 10 seconds unless user interacts with it
        setTimeout(() => {
            const debugDiv = document.getElementById('debugInfo');
            const toggleBtn = document.querySelector('.debug-toggle');
            if (debugDiv && toggleBtn && !debugDiv.matches(':hover')) {
                debugDiv.style.display = 'none';
                toggleBtn.textContent = '🔧 Show Debug Info';
            }
        }, 10000);

        // Notification system
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed; top: 100px; right: 20px; z-index: 10001;
                background: ${type === 'success' ? '#d1fae5' : type === 'error' ? '#fee2e2' : type === 'warning' ? '#fef3c7' : '#dbeafe'};
                color: ${type === 'success' ? '#065f46' : type === 'error' ? '#991b1b' : type === 'warning' ? '#92400e' : '#1e40af'};
                border: 1px solid ${type === 'success' ? '#9ae6b4' : type === 'error' ? '#f87171' : type === 'warning' ? '#fbbf24' : '#93c5fd'};
                border-radius: 10px; padding: 15px 20px; max-width: 300px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.1); font-weight: 500;
                animation: slideInRight 0.3s ease, slideOutRight 0.3s ease 2.7s forwards;
            `;
            
            notification.textContent = message;
            document.body.appendChild(notification);
            
            // Add animations if not already present
            if (!document.getElementById('notificationAnimations')) {
                const style = document.createElement('style');
                style.id = 'notificationAnimations';
                style.textContent = `
                    @keyframes slideInRight { 
                        from { transform: translateX(100%); opacity: 0; } 
                        to { transform: translateX(0); opacity: 1; } 
                    }
                    @keyframes slideOutRight { 
                        from { transform: translateX(0); opacity: 1; } 
                        to { transform: translateX(100%); opacity: 0; } 
                    }
                `;
                document.head.appendChild(style);
            }
            
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 3000);
        }

        // Session timeout check
        let lastActivity = <?php echo $_SESSION['LAST_ACTIVITY']; ?>;
        const SESSION_TIMEOUT = 1800; // 30 minutes in seconds

        function checkSessionTimeout() {
            const currentTime = Math.floor(Date.now() / 1000);
            if (currentTime - lastActivity > SESSION_TIMEOUT) {
                showNotification('Session expired. Redirecting to login...', 'error');
                setTimeout(() => {
                    window.location.href = '../../index.php';
                }, 2000);
            }
        }

        // Check session every minute
        setInterval(checkSessionTimeout, 60000);

        // Update last activity on user interaction
        document.addEventListener('click', () => {
            lastActivity = Math.floor(Date.now() / 1000);
        });

        // Mobile responsiveness
        window.addEventListener('resize', function() {
            // Adjust dropdown positioning for mobile
            if (window.innerWidth <= 768) {
                document.querySelectorAll('.serving-dropdown-content').forEach(dropdown => {
                    dropdown.style.left = '0';
                    dropdown.style.right = 'auto';
                    dropdown.style.minWidth = '250px';
                });
            } else {
                document.querySelectorAll('.serving-dropdown-content').forEach(dropdown => {
                    dropdown.style.left = 'auto';
                    dropdown.style.right = '0';
                    dropdown.style.minWidth = '200px';
                });
            }
        });

        console.log('✅ Enhanced search accounts page with delivery management initialized successfully');
    </script>
</body>
</html>