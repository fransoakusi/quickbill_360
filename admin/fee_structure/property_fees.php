<?php
/**
 * Fee Structure Management - Business Fees
 * QUICKBILL 305 - Admin Panel
 * Updated with 50 items per page default pagination and proper navigation links
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
if (!hasPermission('fee_structure.view')) {
    setFlashMessage('error', 'Access denied. You do not have permission to view fee structures.');
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

$pageTitle = 'Business Fee Structure';
$currentUser = getCurrentUser();
$userDisplayName = getUserDisplayName($currentUser);

// Initialize variables
$businessFees = [];
$stats = [
    'total_fees' => 0,
    'business_types' => 0,
    'active_fees' => 0,
    'inactive_fees' => 0
];

// Pagination settings - UPDATED: Default to 50 items per page
$defaultItemsPerPage = 50;
$allowedItemsPerPage = [10, 25, 50, 100, 200];
$itemsPerPage = isset($_GET['items_per_page']) && in_array((int)$_GET['items_per_page'], $allowedItemsPerPage) 
    ? (int)$_GET['items_per_page'] 
    : $defaultItemsPerPage;

$currentPage = max(1, intval($_GET['page'] ?? 1));
$offset = ($currentPage - 1) * $itemsPerPage;

// Search and filter parameters
$searchTerm = sanitizeInput($_GET['search'] ?? '');
$filterStatus = sanitizeInput($_GET['status'] ?? '');

// Build pagination URL parameters
$urlParams = [];
if (!empty($searchTerm)) $urlParams['search'] = $searchTerm;
if ($filterStatus !== '') $urlParams['status'] = $filterStatus;
if ($itemsPerPage !== $defaultItemsPerPage) $urlParams['items_per_page'] = $itemsPerPage;
$baseUrl = 'business_fees.php' . (!empty($urlParams) ? '?' . http_build_query($urlParams) : '');

try {
    $db = new Database();
    
    // Build search and filter conditions
    $conditions = [];
    $params = [];
    
    if (!empty($searchTerm)) {
        $conditions[] = "(business_type LIKE ? OR category LIKE ?)";
        $params[] = "%{$searchTerm}%";
        $params[] = "%{$searchTerm}%";
    }
    
    if ($filterStatus !== '') {
        $conditions[] = "is_active = ?";
        $params[] = $filterStatus;
    }
    
    $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
    
    // Get total count for pagination
    $countQuery = "SELECT COUNT(*) as total FROM business_fee_structure {$whereClause}";
    $totalResult = $db->fetchRow($countQuery, $params);
    $totalRecords = $totalResult['total'] ?? 0;
    $totalPages = ceil($totalRecords / $itemsPerPage);
    
    // Ensure current page is valid
    if ($currentPage > $totalPages && $totalPages > 0) {
        $currentPage = $totalPages;
        $offset = ($currentPage - 1) * $itemsPerPage;
    }
    
    // Get business fees with creator information (paginated)
    $paginationParams = array_merge($params, [$offset, $itemsPerPage]);
    $businessFees = $db->fetchAll("
        SELECT 
            bf.*,
            u.username as created_by_username,
            CONCAT(u.first_name, ' ', u.last_name) as created_by_name
        FROM business_fee_structure bf
        LEFT JOIN users u ON bf.created_by = u.user_id
        {$whereClause}
        ORDER BY bf.business_type ASC, bf.category ASC
        LIMIT ?, ?
    ", $paginationParams);
    
    // Calculate statistics
    $allStats = $db->fetchRow("
        SELECT 
            COUNT(*) as total_fees,
            COUNT(DISTINCT business_type) as business_types,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_fees,
            SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_fees
        FROM business_fee_structure
    ");
    
    if ($allStats) {
        $stats = [
            'total_fees' => $allStats['total_fees'],
            'business_types' => $allStats['business_types'],
            'active_fees' => $allStats['active_fees'],
            'inactive_fees' => $allStats['inactive_fees']
        ];
    }
    
    // Handle delete action
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
        $feeId = intval($_GET['id']);
        
        if (hasPermission('fee_structure.delete')) {
            // Check if fee structure is being used by businesses
            $businessCountResult = $db->fetchRow("
                SELECT COUNT(*) as count FROM businesses 
                WHERE business_type = (
                    SELECT business_type FROM business_fee_structure WHERE fee_id = ?
                ) AND category = (
                    SELECT category FROM business_fee_structure WHERE fee_id = ?
                )
            ", [$feeId, $feeId]);
            $businessCount = $businessCountResult['count'] ?? 0;
            
            if ($businessCount > 0) {
                setFlashMessage('error', "Cannot delete fee structure. It is being used by {$businessCount} business(es).");
            } else {
                try {
                    $db->beginTransaction();
                    
                    // Get fee details for logging
                    $feeDetails = $db->fetchRow("
                        SELECT business_type, category, fee_amount 
                        FROM business_fee_structure WHERE fee_id = ?
                    ", [$feeId]);
                    
                    // Delete fee structure
                    $db->execute("DELETE FROM business_fee_structure WHERE fee_id = ?", [$feeId]);
                    
                    // Log the action
                    if ($feeDetails) {
                        writeLog("Business fee deleted: {$feeDetails['business_type']} - {$feeDetails['category']} (GHS {$feeDetails['fee_amount']}) by user {$currentUser['username']}", 'INFO');
                    }
                    
                    $db->commit();
                    setFlashMessage('success', 'Business fee structure deleted successfully!');
                } catch (Exception $e) {
                    $db->rollback();
                    writeLog("Error deleting business fee: " . $e->getMessage(), 'ERROR');
                    setFlashMessage('error', 'An error occurred while deleting the fee structure.');
                }
            }
        } else {
            setFlashMessage('error', 'Access denied. You do not have permission to delete fee structures.');
        }
        
        // Preserve pagination and filters in redirect
        $redirectUrl = $baseUrl . (strpos($baseUrl, '?') !== false ? '&' : '?') . 'page=' . $currentPage;
        header('Location: ' . $redirectUrl);
        exit();
    }
    
    // Handle toggle status action
    if (isset($_GET['action']) && $_GET['action'] === 'toggle' && isset($_GET['id'])) {
        $feeId = intval($_GET['id']);
        
        if (hasPermission('fee_structure.edit')) {
            try {
                $db->beginTransaction();
                
                // Get current status
                $currentFee = $db->fetchRow("
                    SELECT is_active, business_type, category 
                    FROM business_fee_structure WHERE fee_id = ?
                ", [$feeId]);
                
                if ($currentFee) {
                    $newStatus = $currentFee['is_active'] ? 0 : 1;
                    
                    // Update status
                    $db->execute("
                        UPDATE business_fee_structure 
                        SET is_active = ?, updated_at = NOW() 
                        WHERE fee_id = ?
                    ", [$newStatus, $feeId]);
                    
                    // Log the action
                    $statusText = $newStatus ? 'activated' : 'deactivated';
                    writeLog("Business fee {$statusText}: {$currentFee['business_type']} - {$currentFee['category']} by user {$currentUser['username']}", 'INFO');
                    
                    $db->commit();
                    setFlashMessage('success', 'Fee structure status updated successfully!');
                } else {
                    setFlashMessage('error', 'Fee structure not found.');
                }
            } catch (Exception $e) {
                $db->rollback();
                writeLog("Error toggling business fee status: " . $e->getMessage(), 'ERROR');
                setFlashMessage('error', 'An error occurred while updating the fee structure.');
            }
        } else {
            setFlashMessage('error', 'Access denied. You do not have permission to edit fee structures.');
        }
        
        // Preserve pagination and filters in redirect
        $redirectUrl = $baseUrl . (strpos($baseUrl, '?') !== false ? '&' : '?') . 'page=' . $currentPage;
        header('Location: ' . $redirectUrl);
        exit();
    }
    
} catch (Exception $e) {
    writeLog("Business fees page error: " . $e->getMessage(), 'ERROR');
    setFlashMessage('error', 'An error occurred while loading fee structures.');
}

// Calculate pagination info
$startRecord = $totalRecords > 0 ? $offset + 1 : 0;
$endRecord = min($offset + $itemsPerPage, $totalRecords);

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
    
    <!-- Multiple Icon Sources -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://use.fontawesome.com/releases/v6.4.0/css/all.css">
    
    <!-- Bootstrap for backup -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
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
        
        /* Custom Icons (fallback if Font Awesome fails) */
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
        .icon-user::before { content: "👤"; }
        .icon-plus::before { content: "➕"; }
        .icon-save::before { content: "💾"; }
        .icon-edit::before { content: "✏️"; }
        .icon-delete::before { content: "🗑️"; }
        .icon-view::before { content: "👁️"; }
        .icon-search::before { content: "🔍"; }
        .icon-filter::before { content: "🔽"; }
        .icon-money::before { content: "💰"; }
        .icon-active::before { content: "✅"; }
        .icon-inactive::before { content: "❌"; }
        .icon-history::before { content: "📜"; }
        .icon-question::before { content: "❓"; }
        
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
        
        .dropdown-item i {
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
        
        .breadcrumb-current {
            color: #2d3748;
            font-weight: 600;
        }
        
        /* Stats Header */
        .stats-header {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border: 1px solid #bae6fd;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
        }
        
        .stats-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
        }
        
        .stats-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .stats-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .stats-avatar {
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
        
        .stats-details h3 {
            font-size: 20px;
            font-weight: bold;
            color: #2d3748;
            margin: 0 0 5px 0;
        }
        
        .stats-description {
            color: #64748b;
            font-size: 14px;
        }
        
        .stats-grid {
            display: flex;
            gap: 20px;
        }
        
        .stat-item {
            text-align: center;
            padding: 15px 20px;
            background: rgba(255, 255, 255, 0.8);
            border-radius: 10px;
            min-width: 100px;
        }
        
        .stat-number {
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
        
        /* Page Header */
        .page-header {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .page-title {
            font-size: 28px;
            font-weight: bold;
            color: #2d3748;
            margin: 0;
        }
        
        .header-actions {
            display: flex;
            gap: 15px;
        }
        
        /* Search and Filter Bar */
        .search-bar {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .search-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .search-input {
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            width: 300px;
            transition: all 0.3s;
        }
        
        .search-input:focus {
            outline: none;
            border-color: #4299e1;
            box-shadow: 0 0 0 3px rgba(66, 153, 225, 0.1);
        }
        
        .filter-select {
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            background: white;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .filter-select:focus {
            outline: none;
            border-color: #4299e1;
            box-shadow: 0 0 0 3px rgba(66, 153, 225, 0.1);
        }
        
        /* Alert Messages */
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
        
        /* Buttons */
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            justify-content: center;
            font-size: 14px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(66, 153, 225, 0.3);
            color: white;
        }
        
        .btn-secondary {
            background: #64748b;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #475569;
            color: white;
        }
        
        .btn-success {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(72, 187, 120, 0.3);
            color: white;
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #f56565 0%, #e53e3e 100%);
            color: white;
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(245, 101, 101, 0.3);
            color: white;
        }
        
        .btn-sm {
            padding: 8px 15px;
            font-size: 12px;
        }
        
        /* Fees Grid */
        .fees-grid {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .grid-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .grid-title {
            font-size: 20px;
            font-weight: 600;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        /* Pagination Controls */
        .pagination-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 2px solid #e2e8f0;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .pagination-info {
            color: #64748b;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .pagination-summary {
            font-weight: 600;
            color: #2d3748;
            background: #f8fafc;
            padding: 8px 15px;
            border-radius: 8px;
        }
        
        .items-per-page {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .items-per-page select {
            padding: 8px 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 13px;
            background: white;
            cursor: pointer;
            font-weight: 600;
        }
        
        .items-per-page select:focus {
            outline: none;
            border-color: #4299e1;
        }
        
        .pagination-nav {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .pagination-btn {
            padding: 10px 15px;
            border: 2px solid #e2e8f0;
            background: white;
            color: #64748b;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 14px;
        }
        
        .pagination-btn:hover:not(.disabled) {
            background: #4299e1;
            color: white;
            border-color: #4299e1;
            transform: translateY(-1px);
            text-decoration: none;
        }
        
        .pagination-btn.active {
            background: #4299e1;
            color: white;
            border-color: #4299e1;
        }
        
        .pagination-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }
        
        .pagination-numbers {
            display: flex;
            gap: 5px;
        }
        
        .pagination-ellipsis {
            padding: 10px 8px;
            color: #64748b;
            font-weight: 600;
        }
        
        .quick-jump {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-left: 15px;
            background: #f8fafc;
            padding: 8px 15px;
            border-radius: 8px;
        }
        
        .quick-jump input {
            width: 60px;
            padding: 8px;
            border: 2px solid #e2e8f0;
            border-radius: 6px;
            text-align: center;
            font-size: 13px;
            font-weight: 600;
        }
        
        .quick-jump input:focus {
            outline: none;
            border-color: #4299e1;
        }
        
        .quick-jump button {
            padding: 8px 12px;
            background: #4299e1;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .quick-jump button:hover {
            background: #3182ce;
            transform: translateY(-1px);
        }
        
        .fees-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .fees-table th {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            color: #2d3748;
            font-weight: 600;
            padding: 15px;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .fees-table td {
            padding: 20px 15px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }
        
        .fees-table tr:hover {
            background: #f8fafc;
        }
        
        .business-type {
            font-weight: 600;
            color: #2d3748;
            font-size: 16px;
        }
        
        .category {
            color: #64748b;
            font-size: 14px;
            margin-top: 3px;
        }
        
        .fee-amount {
            font-size: 18px;
            font-weight: bold;
            color: #059669;
            font-family: monospace;
        }
        
        .currency {
            font-size: 14px;
            color: #64748b;
            margin-right: 5px;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .status-active {
            background: #d1fae5;
            color: #065f46;
        }
        
        .status-inactive {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .created-info {
            font-size: 12px;
            color: #a0aec0;
        }
        
        .action-btn {
            padding: 8px 12px;
            border: none;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-right: 5px;
        }
        
        .action-btn.edit {
            background: #4299e1;
            color: white;
        }
        
        .action-btn.edit:hover {
            background: #3182ce;
            color: white;
        }
        
        .action-btn.toggle {
            background: #f59e0b;
            color: white;
        }
        
        .action-btn.toggle:hover {
            background: #d97706;
            color: white;
        }
        
        .action-btn.delete {
            background: #f56565;
            color: white;
        }
        
        .action-btn.delete:hover {
            background: #e53e3e;
            color: white;
        }
        
        .action-btn.protected {
            background: #e2e8f0;
            color: #64748b;
            cursor: not-allowed;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #64748b;
        }
        
        .empty-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
        }
        
        .empty-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 10px;
            color: #2d3748;
        }
        
        .empty-text {
            font-size: 16px;
            margin-bottom: 25px;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
            line-height: 1.6;
        }
        
        /* Table Performance Indicator */
        .table-performance {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
            padding: 15px;
            background: linear-gradient(135deg, #e0f7fa 0%, #b2ebf2 100%);
            border-radius: 10px;
            border-left: 4px solid #00acc1;
        }
        
        .performance-icon {
            width: 40px;
            height: 40px;
            background: #00acc1;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
        }
        
        .performance-text {
            flex: 1;
            color: #00695c;
        }
        
        .performance-title {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .performance-description {
            font-size: 14px;
            opacity: 0.8;
        }
        
        /* Responsive Design */
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
            
            .header-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .stats-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 20px;
            }
            
            .stats-grid {
                align-self: stretch;
                justify-content: space-around;
            }
            
            .search-bar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .search-input {
                width: 100%;
            }
            
            .fees-table {
                font-size: 14px;
            }
            
            .fees-table th,
            .fees-table td {
                padding: 10px 8px;
            }
            
            .action-btn {
                margin-bottom: 5px;
            }
            
            .pagination-controls {
                flex-direction: column;
                align-items: center;
                gap: 20px;
            }
            
            .pagination-nav {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .pagination-info {
                text-align: center;
                flex-direction: column;
                gap: 10px;
            }
        }
        
        /* Animations */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .fees-table tr {
            animation: slideIn 0.3s ease forwards;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <div class="top-nav">
        <div class="nav-left">
            <button class="toggle-btn" onclick="toggleSidebar()" id="toggleBtn">
                <i class="fas fa-bars"></i>
                <span class="icon-menu" style="display: none;"></span>
            </button>
            
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
                <span class="notification-badge" style="
                    position: absolute;
                    top: -2px;
                    right: -2px;
                    background: #ef4444;
                    color: white;
                    border-radius: 50%;
                    width: 20px;
                    height: 20px;
                    font-size: 11px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-weight: bold;
                    animation: pulse 2s infinite;
                ">3</span>
            </div>
            
            <div class="user-profile" onclick="toggleUserDropdown()" id="userProfile">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($currentUser['first_name'], 0, 1)); ?>
                </div>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($userDisplayName); ?></div>
                    <div class="user-role"><?php echo htmlspecialchars(getCurrentUserRole()); ?></div>
                </div>
                <i class="fas fa-chevron-down dropdown-arrow"></i>
                
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
                            <i class="fas fa-user"></i>
                            <span class="icon-user" style="display: none;"></span>
                            My Profile
                        </a>
                        <a href="../settings/index.php" class="dropdown-item">
                            <i class="fas fa-cog"></i>
                            <span class="icon-cog" style="display: none;"></span>
                            Account Settings
                        </a>
                        <a href="../logs/user_activity.php" class="dropdown-item">
                            <i class="fas fa-history"></i>
                            <span class="icon-history" style="display: none;"></span>
                            Activity Log
                        </a>
                        <a href="../../docs/user_manual.md" class="dropdown-item">
                            <i class="fas fa-question-circle"></i>
                            <span class="icon-question" style="display: none;"></span>
                            Help & Support
                        </a>
                        <div style="height: 1px; background: #e2e8f0; margin: 10px 0;"></div>
                        <a href="../../auth/logout.php" class="dropdown-item logout">
                            <i class="fas fa-sign-out-alt"></i>
                            <span class="icon-logout" style="display: none;"></span>
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
                            <span class="nav-icon">
                                <i class="fas fa-tachometer-alt"></i>
                                <span class="icon-dashboard" style="display: none;"></span>
                            </span>
                            Dashboard
                        </a>
                    </div>
                </div>
                
                <!-- Core Management -->
                <div class="nav-section">
                    <div class="nav-title">Core Management</div>
                    <div class="nav-item">
                        <a href="../users/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-users"></i>
                                <span class="icon-users" style="display: none;"></span>
                            </span>
                            Users
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../businesses/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-building"></i>
                                <span class="icon-building" style="display: none;"></span>
                            </span>
                            Businesses
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../properties/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-home"></i>
                                <span class="icon-home" style="display: none;"></span>
                            </span>
                            Properties
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../zones/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-map-marked-alt"></i>
                                <span class="icon-map" style="display: none;"></span>
                            </span>
                            Zones & Areas
                        </a>
                    </div>
                </div>
                
                <!-- Billing & Payments -->
                <div class="nav-section">
                    <div class="nav-title">Billing & Payments</div>
                    <div class="nav-item">
                        <a href="../billing/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-file-invoice"></i>
                                <span class="icon-invoice" style="display: none;"></span>
                            </span>
                            Billing
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../payments/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-credit-card"></i>
                                <span class="icon-credit" style="display: none;"></span>
                            </span>
                            Payments
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../fee_structure/index.php" class="nav-link active">
                            <span class="nav-icon">
                                <i class="fas fa-tags"></i>
                                <span class="icon-tags" style="display: none;"></span>
                            </span>
                            Fee Structure
                        </a>
                    </div>
                </div>
                
                <!-- Reports & System -->
                <div class="nav-section">
                    <div class="nav-title">Reports & System</div>
                    <div class="nav-item">
                        <a href="../reports/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-chart-bar"></i>
                                <span class="icon-chart" style="display: none;"></span>
                            </span>
                            Reports
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../notifications/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-bell"></i>
                                <span class="icon-bell" style="display: none;"></span>
                            </span>
                            Notifications
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../settings/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-cog"></i>
                                <span class="icon-cog" style="display: none;"></span>
                            </span>
                            Settings
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Breadcrumb -->
            <div class="breadcrumb-nav">
                <div class="breadcrumb">
                    <a href="../index.php">Dashboard</a>
                    <span>/</span>
                    <a href="../fee_structure/index.php">Fee Structure</a>
                    <span>/</span>
                    <span class="breadcrumb-current">Business Fees</span>
                </div>
            </div>

            <!-- Flash Messages -->
            <?php if ($flashMessage): ?>
                <div class="alert alert-<?php echo $flashMessage['type']; ?>">
                    <i class="fas fa-<?php echo $flashMessage['type'] === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                    <div><?php echo htmlspecialchars($flashMessage['message']); ?></div>
                </div>
            <?php endif; ?>

            <!-- Statistics Header -->
            <div class="stats-header">
                <div class="stats-content">
                    <div class="stats-info">
                        <div class="stats-avatar">
                            <i class="fas fa-tags"></i>
                            <span class="icon-tags" style="display: none;"></span>
                        </div>
                        <div class="stats-details">
                            <h3>Business Fee Structure</h3>
                            <div class="stats-description">Configure billing rates for different business types and categories</div>
                        </div>
                    </div>
                    
                    <div class="stats-grid">
                        <div class="stat-item">
                            <div class="stat-number"><?php echo $stats['total_fees']; ?></div>
                            <div class="stat-label">Total Fees</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number"><?php echo $stats['business_types']; ?></div>
                            <div class="stat-label">Business Types</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number"><?php echo $stats['active_fees']; ?></div>
                            <div class="stat-label">Active</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number"><?php echo $stats['inactive_fees']; ?></div>
                            <div class="stat-label">Inactive</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <div class="header-content">
                    <div>
                        <h1 class="page-title">
                            <i class="fas fa-tags"></i>
                            Business Fee Management
                        </h1>
                        <p style="color: #64748b; margin: 5px 0 0 0;">Manage billing rates for business types and categories</p>
                    </div>
                    <div class="header-actions">
                        <a href="property_fees.php" class="btn btn-secondary">
                            <i class="fas fa-home"></i>
                            Property Fees
                        </a>
                        <?php if (hasPermission('fee_structure.create')): ?>
                            <a href="add_business_fee.php" class="btn btn-success">
                                <i class="fas fa-plus"></i>
                                <span class="icon-plus" style="display: none;"></span>
                                Add Business Fee
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Search Bar -->
                <div class="search-bar">
                    <form method="GET" action="" style="display: flex; gap: 15px; align-items: center; flex: 1;">
                        <!-- Hidden page field to reset to page 1 on new search -->
                        <input type="hidden" name="page" value="1">
                        <div class="search-group">
                            <i class="fas fa-search" style="color: #64748b;"></i>
                            <span class="icon-search" style="display: none;"></span>
                            <input type="text" name="search" class="search-input" 
                                   placeholder="Search business types or categories..." 
                                   value="<?php echo htmlspecialchars($searchTerm); ?>">
                        </div>
                        <div class="search-group">
                            <i class="fas fa-filter" style="color: #64748b;"></i>
                            <span class="icon-filter" style="display: none;"></span>
                            <select name="status" class="filter-select">
                                <option value="">All Status</option>
                                <option value="1" <?php echo $filterStatus === '1' ? 'selected' : ''; ?>>Active</option>
                                <option value="0" <?php echo $filterStatus === '0' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i>
                            Search
                        </button>
                        <?php if (!empty($searchTerm) || $filterStatus !== ''): ?>
                            <a href="business_fees.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i>
                                Clear
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Business Fees Grid -->
            <div class="fees-grid">
                <div class="grid-header">
                    <div class="grid-title">
                        <i class="fas fa-tags"></i>
                        <span class="icon-tags" style="display: none;"></span>
                        Business Fee Structures
                        <?php if (!empty($searchTerm) || $filterStatus !== ''): ?>
                            <span style="color: #64748b; font-weight: normal;">(Filtered Results)</span>
                        <?php endif; ?>
                    </div>
                    <div style="color: #64748b; font-size: 14px;">
                        <?php echo $totalRecords; ?> fee structure<?php echo $totalRecords !== 1 ? 's' : ''; ?> total
                    </div>
                </div>

                <!-- Table Performance Indicator -->
                <?php if ($totalRecords > 0): ?>
                <div class="table-performance">
                    <div class="performance-icon">
                        <i class="fas fa-tachometer-alt"></i>
                    </div>
                    <div class="performance-text">
                        <div class="performance-title">Showing <?php echo $itemsPerPage; ?> items per page</div>
                        <div class="performance-description">
                            Enhanced pagination with <?php echo $itemsPerPage; ?> records per page for better performance.
                            Use the dropdown below to adjust page size.
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (empty($businessFees)): ?>
                    <!-- Empty State -->
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="fas fa-tags"></i>
                            <span class="icon-tags" style="display: none;"></span>
                        </div>
                        <div class="empty-title">
                            <?php if (!empty($searchTerm) || $filterStatus !== ''): ?>
                                No Fee Structures Found
                            <?php else: ?>
                                No Business Fees Yet
                            <?php endif; ?>
                        </div>
                        <div class="empty-text">
                            <?php if (!empty($searchTerm) || $filterStatus !== ''): ?>
                                No fee structures match your search criteria. Try adjusting your search terms or clear the filter to view all fee structures.
                            <?php else: ?>
                                No business fee structures have been configured yet. Create your first business fee structure to start billing businesses based on their type and category.
                            <?php endif; ?>
                        </div>
                        <?php if (hasPermission('fee_structure.create')): ?>
                            <a href="add_business_fee.php" class="btn btn-success">
                                <i class="fas fa-plus"></i>
                                Create First Fee Structure
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <!-- Business Fees Table -->
                    <table class="fees-table">
                        <thead>
                            <tr>
                                <th>Business Type & Category</th>
                                <th>Fee Amount</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($businessFees as $fee): ?>
                                <tr>
                                    <td>
                                        <div class="business-type"><?php echo htmlspecialchars($fee['business_type']); ?></div>
                                        <div class="category"><?php echo htmlspecialchars($fee['category']); ?></div>
                                    </td>
                                    <td>
                                        <div class="fee-amount">
                                            <span class="currency">GHS</span><?php echo number_format($fee['fee_amount'], 2); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $fee['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                            <?php echo $fee['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="created-info">
                                            <?php echo date('M d, Y', strtotime($fee['created_at'])); ?><br>
                                            by <?php echo htmlspecialchars($fee['created_by_name'] ?? $fee['created_by_username'] ?? 'Unknown'); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (hasPermission('fee_structure.edit')): ?>
                                            <a href="edit_business_fee.php?id=<?php echo $fee['fee_id']; ?>" 
                                               class="action-btn edit">
                                                <i class="fas fa-edit"></i>
                                                <span class="icon-edit" style="display: none;"></span>
                                                Edit
                                            </a>
                                            
                                            <a href="javascript:void(0)" 
                                               class="action-btn toggle"
                                               onclick="toggleStatus(<?php echo $fee['fee_id']; ?>, '<?php echo htmlspecialchars($fee['business_type'] . ' - ' . $fee['category'], ENT_QUOTES); ?>', <?php echo $fee['is_active'] ? 'true' : 'false'; ?>)">
                                                <i class="fas fa-<?php echo $fee['is_active'] ? 'pause' : 'play'; ?>"></i>
                                                <?php echo $fee['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php 
                                        // Check if fee is being used by businesses (placeholder logic)
                                        $canDelete = hasPermission('fee_structure.delete'); // Add actual business check later
                                        ?>
                                        
                                        <?php if ($canDelete): ?>
                                            <a href="javascript:void(0)" 
                                               class="action-btn delete"
                                               onclick="confirmDelete(<?php echo $fee['fee_id']; ?>, '<?php echo htmlspecialchars($fee['business_type'] . ' - ' . $fee['category'], ENT_QUOTES); ?>')">
                                                <i class="fas fa-trash"></i>
                                                <span class="icon-delete" style="display: none;"></span>
                                                Delete
                                            </a>
                                        <?php else: ?>
                                            <span class="action-btn protected" 
                                                  title="Cannot delete: In use by businesses">
                                                <i class="fas fa-lock"></i>
                                                Protected
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- Pagination Controls -->
                    <?php if ($totalPages > 1): ?>
                        <div class="pagination-controls">
                            <div class="pagination-info">
                                <div class="pagination-summary">
                                    Showing <?php echo $startRecord; ?>-<?php echo $endRecord; ?> of <?php echo $totalRecords; ?> records
                                </div>
                                <div class="items-per-page">
                                    <label for="itemsPerPage">Items per page:</label>
                                    <select id="itemsPerPage" onchange="changeItemsPerPage(this.value)">
                                        <option value="10" <?php echo $itemsPerPage == 10 ? 'selected' : ''; ?>>10</option>
                                        <option value="25" <?php echo $itemsPerPage == 25 ? 'selected' : ''; ?>>25</option>
                                        <option value="50" <?php echo $itemsPerPage == 50 ? 'selected' : ''; ?>>50</option>
                                        <option value="100" <?php echo $itemsPerPage == 100 ? 'selected' : ''; ?>>100</option>
                                        <option value="200" <?php echo $itemsPerPage == 200 ? 'selected' : ''; ?>>200</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="pagination-nav">
                                <!-- First Page -->
                                <?php if ($currentPage > 1): ?>
                                    <a href="<?php echo $baseUrl . (strpos($baseUrl, '?') !== false ? '&' : '?') . 'page=1'; ?>" 
                                       class="pagination-btn">
                                        <i class="fas fa-angle-double-left"></i>
                                        First
                                    </a>
                                    <a href="<?php echo $baseUrl . (strpos($baseUrl, '?') !== false ? '&' : '?') . 'page=' . ($currentPage - 1); ?>" 
                                       class="pagination-btn">
                                        <i class="fas fa-angle-left"></i>
                                        Previous
                                    </a>
                                <?php else: ?>
                                    <span class="pagination-btn disabled">
                                        <i class="fas fa-angle-double-left"></i>
                                        First
                                    </span>
                                    <span class="pagination-btn disabled">
                                        <i class="fas fa-angle-left"></i>
                                        Previous
                                    </span>
                                <?php endif; ?>
                                
                                <!-- Page Numbers -->
                                <div class="pagination-numbers">
                                    <?php
                                    $startPage = max(1, $currentPage - 2);
                                    $endPage = min($totalPages, $currentPage + 2);
                                    
                                    // Show first page if not in range
                                    if ($startPage > 1): ?>
                                        <a href="<?php echo $baseUrl . (strpos($baseUrl, '?') !== false ? '&' : '?') . 'page=1'; ?>" 
                                           class="pagination-btn">1</a>
                                        <?php if ($startPage > 2): ?>
                                            <span class="pagination-ellipsis">...</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <!-- Page range -->
                                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                        <a href="<?php echo $baseUrl . (strpos($baseUrl, '?') !== false ? '&' : '?') . 'page=' . $i; ?>" 
                                           class="pagination-btn <?php echo $i === $currentPage ? 'active' : ''; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    <?php endfor; ?>
                                    
                                    <!-- Show last page if not in range -->
                                    <?php if ($endPage < $totalPages): ?>
                                        <?php if ($endPage < $totalPages - 1): ?>
                                            <span class="pagination-ellipsis">...</span>
                                        <?php endif; ?>
                                        <a href="<?php echo $baseUrl . (strpos($baseUrl, '?') !== false ? '&' : '?') . 'page=' . $totalPages; ?>" 
                                           class="pagination-btn"><?php echo $totalPages; ?></a>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Next/Last Page -->
                                <?php if ($currentPage < $totalPages): ?>
                                    <a href="<?php echo $baseUrl . (strpos($baseUrl, '?') !== false ? '&' : '?') . 'page=' . ($currentPage + 1); ?>" 
                                       class="pagination-btn">
                                        Next
                                        <i class="fas fa-angle-right"></i>
                                    </a>
                                    <a href="<?php echo $baseUrl . (strpos($baseUrl, '?') !== false ? '&' : '?') . 'page=' . $totalPages; ?>" 
                                       class="pagination-btn">
                                        Last
                                        <i class="fas fa-angle-double-right"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="pagination-btn disabled">
                                        Next
                                        <i class="fas fa-angle-right"></i>
                                    </span>
                                    <span class="pagination-btn disabled">
                                        Last
                                        <i class="fas fa-angle-double-right"></i>
                                    </span>
                                <?php endif; ?>
                                
                                <!-- Quick Jump -->
                                <div class="quick-jump">
                                    <span>Go to page:</span>
                                    <input type="number" id="quickJumpPage" min="1" max="<?php echo $totalPages; ?>" 
                                           placeholder="<?php echo $currentPage; ?>">
                                    <button onclick="quickJumpToPage()">Go</button>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
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
        });

        // Pagination functions with proper URL handling
        function changeItemsPerPage(value) {
            const url = new URL(window.location);
            url.searchParams.set('items_per_page', value);
            url.searchParams.set('page', '1'); // Reset to first page
            window.location.href = url.toString();
        }
        
        function quickJumpToPage() {
            const pageInput = document.getElementById('quickJumpPage');
            const pageNumber = parseInt(pageInput.value);
            const maxPages = parseInt(pageInput.getAttribute('max'));
            
            if (pageNumber && pageNumber >= 1 && pageNumber <= maxPages) {
                const url = new URL(window.location);
                url.searchParams.set('page', pageNumber);
                window.location.href = url.toString();
            } else {
                alert(`Please enter a page number between 1 and ${maxPages}`);
                pageInput.focus();
            }
        }
        
        // Allow Enter key for quick jump
        document.addEventListener('DOMContentLoaded', function() {
            const quickJumpInput = document.getElementById('quickJumpPage');
            if (quickJumpInput) {
                quickJumpInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        quickJumpToPage();
                    }
                });
                
                // Auto-focus quick jump input when typing numbers
                document.addEventListener('keydown', function(e) {
                    if (e.key >= '0' && e.key <= '9' && !e.ctrlKey && !e.altKey && !e.metaKey) {
                        if (document.activeElement.tagName !== 'INPUT' && 
                            document.activeElement.tagName !== 'TEXTAREA') {
                            quickJumpInput.focus();
                            quickJumpInput.value = e.key;
                            e.preventDefault();
                        }
                    }
                });
            }
        });

        // Sidebar toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('hidden');
            
            const isHidden = sidebar.classList.contains('hidden');
            localStorage.setItem('sidebarHidden', isHidden);
        }

        // Restore sidebar state
        document.addEventListener('DOMContentLoaded', function() {
            const sidebarHidden = localStorage.getItem('sidebarHidden');
            if (sidebarHidden === 'true') {
                document.getElementById('sidebar').classList.add('hidden');
            }
        });

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

        // Toggle status confirmation with pagination preservation
        function toggleStatus(feeId, feeName, isActive) {
            const action = isActive ? 'deactivate' : 'activate';
            const actionTitle = isActive ? 'Deactivate' : 'Activate';
            const actionColor = isActive ? '#f59e0b' : '#48bb78';
            
            const backdrop = document.createElement('div');
            backdrop.style.cssText = `
                position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(0,0,0,0.6); backdrop-filter: blur(8px); z-index: 10000;
                display: flex; align-items: center; justify-content: center;
                animation: fadeIn 0.3s ease; cursor: pointer;
            `;
            
            const modal = document.createElement('div');
            modal.style.cssText = `
                background: white; border-radius: 20px; text-align: center;
                box-shadow: 0 25px 80px rgba(0,0,0,0.4); max-width: 450px; width: 90%;
                animation: modalSlideIn 0.4s ease; cursor: default; position: relative; overflow: hidden;
            `;
            
            modal.innerHTML = `
                <div style="background: ${actionColor}; color: white; padding: 30px;">
                    <div style="width: 60px; height: 60px; background: rgba(255,255,255,0.2);
                        border-radius: 50%; display: flex; align-items: center; justify-content: center;
                        margin: 0 auto 15px; font-size: 24px;">
                        <i class="fas fa-${isActive ? 'pause' : 'play'}"></i>
                        <span style="display: none;">${isActive ? '⏸️' : '▶️'}</span>
                    </div>
                    <h3 style="margin: 0; font-size: 1.5rem;">${actionTitle} Fee Structure</h3>
                </div>
                
                <div style="padding: 30px;">
                    <p style="margin: 0 0 20px 0; color: #2d3748; font-size: 1.1rem;">
                        Are you sure you want to ${action} <strong>"${feeName}"</strong>?
                    </p>
                    <p style="margin: 0 0 30px 0; color: #64748b; font-size: 0.9rem;">
                        ${isActive ? 'This will prevent new businesses from using this fee structure.' : 'This will make the fee structure available for new businesses.'}
                    </p>
                    
                    <div style="display: flex; gap: 15px; justify-content: center;">
                        <button onclick="closeToggleModal()" style="background: #64748b; color: white; 
                            border: none; padding: 12px 24px; border-radius: 10px; cursor: pointer; 
                            font-weight: 600; transition: all 0.3s;">
                            Cancel
                        </button>
                        <button onclick="executeToggle(${feeId})" style="background: ${actionColor}; 
                            color: white; border: none; padding: 12px 24px; border-radius: 10px; 
                            cursor: pointer; font-weight: 600; transition: all 0.3s;">
                            ${actionTitle}
                        </button>
                    </div>
                </div>
            `;
            
            backdrop.appendChild(modal);
            document.body.appendChild(backdrop);
            
            if (!document.getElementById('modalAnimations')) {
                const style = document.createElement('style');
                style.id = 'modalAnimations';
                style.textContent = `
                    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
                    @keyframes modalSlideIn { from { transform: translateY(-30px) scale(0.9); opacity: 0; } 
                        to { transform: translateY(0) scale(1); opacity: 1; } }
                    @keyframes fadeOut { from { opacity: 1; } to { opacity: 0; } }
                    @keyframes modalSlideOut { from { transform: translateY(0) scale(1); opacity: 1; }
                        to { transform: translateY(-30px) scale(0.9); opacity: 0; } }
                `;
                document.head.appendChild(style);
            }
            
            window.closeToggleModal = function() {
                backdrop.style.animation = 'fadeOut 0.3s ease forwards';
                modal.style.animation = 'modalSlideOut 0.3s ease forwards';
                setTimeout(() => backdrop.remove(), 300);
            };
            
            window.executeToggle = function(id) {
                // Preserve current page and filters
                const url = new URL(window.location);
                url.searchParams.set('action', 'toggle');
                url.searchParams.set('id', id);
                window.location.href = url.toString();
            };
            
            backdrop.addEventListener('click', function(e) {
                if (e.target === backdrop) closeToggleModal();
            });
        }

        // Delete confirmation with pagination preservation
        function confirmDelete(feeId, feeName) {
            const backdrop = document.createElement('div');
            backdrop.style.cssText = `
                position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(0,0,0,0.6); backdrop-filter: blur(8px); z-index: 10000;
                display: flex; align-items: center; justify-content: center;
                animation: fadeIn 0.3s ease; cursor: pointer;
            `;
            
            const modal = document.createElement('div');
            modal.style.cssText = `
                background: white; border-radius: 20px; text-align: center;
                box-shadow: 0 25px 80px rgba(0,0,0,0.4); max-width: 450px; width: 90%;
                animation: modalSlideIn 0.4s ease; cursor: default; position: relative; overflow: hidden;
            `;
            
            modal.innerHTML = `
                <div style="background: linear-gradient(135deg, #f56565 0%, #e53e3e 100%); color: white; padding: 30px;">
                    <div style="width: 60px; height: 60px; background: rgba(255,255,255,0.2);
                        border-radius: 50%; display: flex; align-items: center; justify-content: center;
                        margin: 0 auto 15px; font-size: 24px;">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span style="display: none;">⚠️</span>
                    </div>
                    <h3 style="margin: 0; font-size: 1.5rem;">Delete Fee Structure</h3>
                </div>
                
                <div style="padding: 30px;">
                    <p style="margin: 0 0 20px 0; color: #2d3748; font-size: 1.1rem;">
                        Are you sure you want to delete <strong>"${feeName}"</strong>?
                    </p>
                    <p style="margin: 0 0 30px 0; color: #64748b; font-size: 0.9rem;">
                        This action cannot be undone. The fee structure will be permanently removed from the system.
                    </p>
                    
                    <div style="display: flex; gap: 15px; justify-content: center;">
                        <button onclick="closeDeleteModal()" style="background: #64748b; color: white; 
                            border: none; padding: 12px 24px; border-radius: 10px; cursor: pointer; 
                            font-weight: 600; transition: all 0.3s;">
                            Cancel
                        </button>
                        <button onclick="deleteFee(${feeId})" style="background: linear-gradient(135deg, #f56565 0%, #e53e3e 100%); 
                            color: white; border: none; padding: 12px 24px; border-radius: 10px; 
                            cursor: pointer; font-weight: 600; transition: all 0.3s;">
                            Delete Fee Structure
                        </button>
                    </div>
                </div>
            `;
            
            backdrop.appendChild(modal);
            document.body.appendChild(backdrop);
            
            window.closeDeleteModal = function() {
                backdrop.style.animation = 'fadeOut 0.3s ease forwards';
                modal.style.animation = 'modalSlideOut 0.3s ease forwards';
                setTimeout(() => backdrop.remove(), 300);
            };
            
            window.deleteFee = function(id) {
                // Preserve current page and filters
                const url = new URL(window.location);
                url.searchParams.set('action', 'delete');
                url.searchParams.set('id', id);
                window.location.href = url.toString();
            };
            
            backdrop.addEventListener('click', function(e) {
                if (e.target === backdrop) closeDeleteModal();
            });
        }

        // Mobile responsiveness
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth <= 768) {
                sidebar.classList.remove('hidden');
            }
        });
    </script>
</body>
</html>