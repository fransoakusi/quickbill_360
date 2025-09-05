<?php
/**
 * Data Collector - View Business Profile with Offline Support
 * businesses/view.php
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

// Check if user is data collector
$currentUser = getCurrentUser();
if (!isDataCollector() && !isAdmin()) {
    setFlashMessage('error', 'Access denied. Data Collector privileges required.');
    header('Location: ../../auth/login.php');
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

$userDisplayName = getUserDisplayName($currentUser);

// Get business ID
$business_id = intval($_GET['id'] ?? 0);

if ($business_id <= 0) {
    setFlashMessage('error', 'Invalid business ID.');
    header('Location: index.php');
    exit();
}

// Initialize variables
$business = null;
$bills = [];
$payments = [];
$dataFromCache = false;

try {
    $db = new Database();
    
    // Get business details with zone information
    $business = $db->fetchRow("
        SELECT 
            b.*,
            z.zone_name,
            sz.sub_zone_name,
            u.first_name as creator_first_name,
            u.last_name as creator_last_name,
            CASE 
                WHEN b.amount_payable > 0 THEN 'Defaulter' 
                ELSE 'Up to Date' 
            END as payment_status
        FROM businesses b
        LEFT JOIN zones z ON b.zone_id = z.zone_id
        LEFT JOIN sub_zones sz ON b.sub_zone_id = sz.sub_zone_id
        LEFT JOIN users u ON b.created_by = u.user_id
        WHERE b.business_id = ?
    ", [$business_id]);
    
    if (!$business) {
        // Handle AJAX request for non-existent business
        if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Business not found'
            ]);
            exit();
        }
        
        setFlashMessage('error', 'Business not found.');
        header('Location: index.php');
        exit();
    }
    
    // Get business bills
    $bills = $db->fetchAll("
        SELECT 
            bill_id,
            bill_number,
            billing_year,
            current_bill,
            amount_payable,
            status,
            generated_at,
            due_date
        FROM bills 
        WHERE bill_type = 'Business' AND reference_id = ?
        ORDER BY billing_year DESC, generated_at DESC
    ", [$business_id]);
    
    // Get payment history
    $payments = $db->fetchAll("
        SELECT 
            p.payment_id,
            p.payment_reference,
            p.amount_paid,
            p.payment_method,
            p.payment_status,
            p.payment_date,
            b.bill_number,
            b.billing_year
        FROM payments p
        JOIN bills b ON p.bill_id = b.bill_id
        WHERE b.bill_type = 'Business' AND b.reference_id = ?
        ORDER BY p.payment_date DESC
        LIMIT 10
    ", [$business_id]);
    
    // Handle AJAX request for fresh data
    if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'data' => [
                'business' => $business,
                'bills' => $bills,
                'payments' => $payments
            ]
        ]);
        exit();
    }
    
} catch (Exception $e) {
    // Handle AJAX request with database error
    if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
        exit();
    }
    
    // If database fails, we'll try to load from cache in JavaScript
    $business = null;
    error_log('Database error in view.php: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Business - <?php echo $business ? htmlspecialchars($business['business_name']) : 'Loading...'; ?> - <?php echo APP_NAME; ?></title>
    
    <!-- Multiple Icon Sources -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    
    <!-- Bootstrap for backup -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Google Maps API -->
    <script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyDg1CWNtJ8BHeclYP7VfltZZLIcY3TVHaI"></script>
    
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
        .icon-building::before { content: "🏢"; }
        .icon-home::before { content: "🏠"; }
        .icon-map::before { content: "🗺️"; }
        .icon-menu::before { content: "☰"; }
        .icon-logout::before { content: "🚪"; }
        .icon-plus::before { content: "➕"; }
        .icon-users::before { content: "👥"; }
        .icon-cog::before { content: "⚙️"; }
        .icon-history::before { content: "📜"; }
        .icon-question::before { content: "❓"; }
        .icon-user::before { content: "👤"; }
        .icon-location::before { content: "📍"; }
        .icon-edit::before { content: "✏️"; }
        .icon-arrow-left::before { content: "⬅️"; }
        .icon-info-circle::before { content: "ℹ️"; }
        .icon-map-marker::before { content: "📍"; }
        .icon-file-invoice::before { content: "📄"; }
        .icon-money-bill::before { content: "💵"; }
        .icon-credit-card::before { content: "💳"; }
        .icon-user-plus::before { content: "👤➕"; }
        .icon-wifi::before { content: "📶"; }
        .icon-wifi-slash::before { content: "📵"; }
        .icon-sync::before { content: "🔄"; }
        .icon-cloud::before { content: "☁️"; }
        .icon-exclamation-triangle::before { content: "⚠️"; }
        .icon-refresh::before { content: "🔃"; }
        
        /* Top Navigation */
        .top-nav {
            background: linear-gradient(135deg, #38a169 0%, #2f855a 100%);
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
        
        /* Network Status Indicator */
        .network-status {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .network-status.online {
            background: rgba(72, 187, 120, 0.2);
            color: #2f855a;
            border: 1px solid rgba(72, 187, 120, 0.3);
        }
        
        .network-status.offline {
            background: rgba(245, 101, 101, 0.2);
            color: #c53030;
            border: 1px solid rgba(245, 101, 101, 0.3);
        }
        
        .network-status.syncing {
            background: rgba(66, 153, 225, 0.2);
            color: #2b6cb0;
            border: 1px solid rgba(66, 153, 225, 0.3);
        }
        
        .network-icon {
            font-size: 14px;
        }
        
        .sync-spinner {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
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
            background: linear-gradient(135deg, #38a169 0%, #2f855a 100%);
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
            color: #38a169;
            transform: translateX(5px);
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
            border-left-color: #38a169;
        }
        
        .nav-link.active {
            background: rgba(56, 161, 105, 0.3);
            color: white;
            border-left-color: #38a169;
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
        
        /* Offline Status Banner */
        .offline-banner {
            background: linear-gradient(135deg, #f56565 0%, #e53e3e 100%);
            color: white;
            padding: 15px 20px;
            margin-bottom: 20px;
            border-radius: 10px;
            display: none;
            align-items: center;
            gap: 15px;
        }
        
        .offline-banner.show {
            display: flex;
        }
        
        .offline-banner .icon {
            font-size: 20px;
        }
        
        .offline-info {
            flex: 1;
        }
        
        .offline-title {
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .offline-message {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .cache-info {
            background: rgba(255,255,255,0.2);
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .cache-info:hover {
            background: rgba(255,255,255,0.3);
        }
        
        /* Loading States */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.9);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 10000;
        }
        
        .loading-overlay.show {
            display: flex;
        }
        
        .loading-spinner {
            text-align: center;
            color: #38a169;
        }
        
        .loading-spinner i {
            font-size: 48px;
            margin-bottom: 15px;
            animation: spin 1s linear infinite;
        }
        
        .loading-text {
            font-size: 18px;
            font-weight: 600;
        }
        
        .loading-subtext {
            font-size: 14px;
            color: #718096;
            margin-top: 5px;
        }
        
        /* Profile Header */
        .profile-header {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .profile-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .title-left {
            flex: 1;
        }
        
        .business-name {
            font-size: 28px;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 8px;
        }
        
        .business-subtitle {
            color: #718096;
            font-size: 16px;
        }
        
        .profile-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: #38a169;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2f855a;
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: #718096;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #4a5568;
        }
        
        .btn-warning {
            background: #ed8936;
            color: white;
        }
        
        .btn-warning:hover {
            background: #dd6b20;
        }
        
        .btn-info {
            background: #4299e1;
            color: white;
        }
        
        .btn-info:hover {
            background: #3182ce;
        }
        
        /* Status Badges */
        .status-badges {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap;
        }
        
        .badge {
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .badge-success {
            background: #c6f6d5;
            color: #22543d;
        }
        
        .badge-warning {
            background: #faf0e6;
            color: #c05621;
        }
        
        .badge-danger {
            background: #fed7d7;
            color: #742a2a;
        }
        
        .badge-info {
            background: #bee3f8;
            color: #2a4365;
        }
        
        .badge-offline {
            background: #e2e8f0;
            color: #4a5568;
        }
        
        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 25px;
            margin-bottom: 25px;
        }
        
        /* Cards */
        .card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .card-header {
            background: #f7fafc;
            padding: 20px;
            border-bottom: 1px solid #e2e8f0;
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
        
        /* Info Rows */
        .info-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 15px;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
        }
        
        .info-label {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 5px;
            font-size: 14px;
        }
        
        .info-value {
            color: #4a5568;
            font-size: 14px;
        }
        
        .info-value.large {
            font-size: 18px;
            font-weight: bold;
            color: #2d3748;
        }
        
        .info-value.currency {
            color: #38a169;
            font-weight: bold;
        }
        
        /* Map Container */
        .map-container {
            height: 300px;
            border-radius: 8px;
            overflow: hidden;
            margin-top: 15px;
        }
        
        /* Table Styles */
        .table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }
        
        .table th,
        .table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .table th {
            background: #f7fafc;
            font-weight: 600;
            color: #2d3748;
            font-size: 14px;
        }
        
        .table tr:hover {
            background: #f7fafc;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #718096;
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        .empty-state h4 {
            font-size: 18px;
            margin-bottom: 8px;
            color: #2d3748;
        }
        
        .empty-state p {
            font-size: 14px;
        }
        
        /* Sync Status Messages */
        .sync-message {
            position: fixed;
            top: 100px;
            right: 20px;
            max-width: 350px;
            padding: 15px 20px;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            z-index: 10000;
            font-weight: 600;
            transform: translateX(400px);
            transition: transform 0.3s ease-out;
        }
        
        .sync-message.show {
            transform: translateX(0);
        }
        
        .sync-message.success {
            background: #48bb78;
            color: white;
        }
        
        .sync-message.error {
            background: #f56565;
            color: white;
        }
        
        .sync-message.info {
            background: #4299e1;
            color: white;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                height: 100%;
                z-index: 999;
                transform: translateX(-100%);
                width: 280px;
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
                padding: 20px;
            }
            
            .content-grid {
                grid-template-columns: 1fr;
            }
            
            .info-row {
                grid-template-columns: 1fr;
            }
            
            .profile-title {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .profile-actions {
                flex-direction: column;
                width: 100%;
            }
            
            .container {
                flex-direction: column;
            }
            
            .network-status {
                display: none;
            }
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fadeIn 0.6s ease-out;
        }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner">
            <i class="fas fa-spinner"></i>
            <span class="icon-sync" style="display: none;"></span>
            <div class="loading-text">Loading Business Data</div>
            <div class="loading-subtext">Please wait...</div>
        </div>
    </div>

    <!-- Top Navigation -->
    <div class="top-nav">
        <div class="nav-left">
            <button class="toggle-btn" onclick="toggleSidebar()" id="toggleBtn">
                <i class="fas fa-bars"></i>
                <span class="icon-menu" style="display: none;"></span>
            </button>
            
            <a href="../index.php" class="brand">
                <i class="fas fa-clipboard-list"></i>
                <span class="icon-dashboard" style="display: none;"></span>
                Data Collector
            </a>
        </div>
        
        <div class="user-section">
            <!-- Network Status Indicator -->
            <div class="network-status" id="networkStatus">
                <div class="network-icon" id="networkIcon">
                    <i class="fas fa-wifi"></i>
                    <span class="icon-wifi" style="display: none;"></span>
                </div>
                <div class="network-text" id="networkText">Online</div>
            </div>
            
            <div class="user-profile" onclick="toggleUserDropdown()" id="userProfile">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($currentUser['first_name'] ?? 'U', 0, 1)); ?>
                </div>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($userDisplayName); ?></div>
                    <div class="user-role">Data Collector</div>
                </div>
                <i class="fas fa-chevron-down dropdown-arrow"></i>
                
                <!-- User Dropdown -->
                <div class="user-dropdown" id="userDropdown">
                    <div class="dropdown-header">
                        <div class="dropdown-avatar">
                            <?php echo strtoupper(substr($currentUser['first_name'] ?? 'U', 0, 1)); ?>
                        </div>
                        <div class="dropdown-name"><?php echo htmlspecialchars($userDisplayName); ?></div>
                        <div class="dropdown-role">Data Collector</div>
                    </div>
                    <div class="dropdown-menu">
                        <a href="#" class="dropdown-item" onclick="alert('Profile management coming soon!')">
                            <i class="fas fa-user"></i>
                            <span class="icon-user" style="display: none;"></span>
                            My Profile
                        </a>
                        <a href="#" class="dropdown-item" onclick="showCacheStatus()">
                            <i class="fas fa-sync-alt"></i>
                            <span class="icon-sync" style="display: none;"></span>
                            Cache Status
                        </a>
                        <a href="#" class="dropdown-item" onclick="alert('Help documentation coming soon!')">
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
        <div class="sidebar hidden" id="sidebar">
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
                
                <!-- Data Collection -->
                <div class="nav-section">
                    <div class="nav-title">Data Collection</div>
                    <div class="nav-item">
                        <a href="index.php" class="nav-link active">
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
                </div>
                
                <!-- Maps & Locations -->
                <div class="nav-section">
                    <div class="nav-title">Maps & Locations</div>
                    <div class="nav-item">
                        <a href="../map/businesses.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-map-marked-alt"></i>
                                <span class="icon-map" style="display: none;"></span>
                            </span>
                            Business Locations
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../map/properties.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-map-marker-alt"></i>
                                <span class="icon-location" style="display: none;"></span>
                            </span>
                            Property Locations
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Offline Status Banner -->
            <div class="offline-banner" id="offlineBanner">
                <div class="icon">
                    <i class="fas fa-wifi-slash"></i>
                    <span class="icon-wifi-slash" style="display: none;"></span>
                </div>
                <div class="offline-info">
                    <div class="offline-title">Working Offline</div>
                    <div class="offline-message">Displaying cached data. Some information may be outdated.</div>
                </div>
                <div class="cache-info" id="cacheInfo" onclick="showCacheStatus()">
                    <span id="cacheText">Cached Data</span>
                </div>
            </div>
            
            <!-- Profile Header -->
            <div class="profile-header fade-in" id="profileHeader" style="display: none;">
                <div class="profile-title">
                    <div class="title-left">
                        <h1 class="business-name" id="businessName">Loading...</h1>
                        <p class="business-subtitle" id="businessSubtitle">Loading business details...</p>
                        
                        <div class="status-badges" id="statusBadges">
                            <!-- Badges will be populated by JavaScript -->
                        </div>
                    </div>
                    
                    <div class="profile-actions">
                        <a href="#" class="btn btn-primary" id="editBtn" style="display: none;">
                            <i class="fas fa-edit"></i>
                            <span class="icon-edit" style="display: none;"></span>
                            Edit Business
                        </a>
                        <a href="#" class="btn btn-info" onclick="refreshBusinessData()">
                            <i class="fas fa-sync-alt"></i>
                            <span class="icon-refresh" style="display: none;"></span>
                            Refresh Data
                        </a>
                        <a href="index.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i>
                            <span class="icon-arrow-left" style="display: none;"></span>
                            Back to List
                        </a>
                    </div>
                </div>
            </div>

            <!-- Content Grid -->
            <div class="content-grid" id="contentGrid" style="display: none;">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Basic Information -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-info-circle"></i>
                                <span class="icon-info-circle" style="display: none;"></span>
                                Basic Information
                            </h3>
                        </div>
                        <div class="card-body" id="basicInfo">
                            <!-- Content will be populated by JavaScript -->
                        </div>
                    </div>
                    
                    <!-- Location Information -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-map-marker-alt"></i>
                                <span class="icon-map-marker" style="display: none;"></span>
                                Location Information
                            </h3>
                        </div>
                        <div class="card-body" id="locationInfo">
                            <!-- Content will be populated by JavaScript -->
                        </div>
                    </div>
                    
                    <!-- Billing History -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-file-invoice"></i>
                                <span class="icon-file-invoice" style="display: none;"></span>
                                Bills & Payments
                            </h3>
                        </div>
                        <div class="card-body" id="billsInfo">
                            <!-- Content will be populated by JavaScript -->
                        </div>
                    </div>
                </div>
                
                <!-- Right Column -->
                <div class="right-column">
                    <!-- Financial Summary -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-money-bill-wave"></i>
                                <span class="icon-money-bill" style="display: none;"></span>
                                Financial Summary
                            </h3>
                        </div>
                        <div class="card-body" id="financialSummary">
                            <!-- Content will be populated by JavaScript -->
                        </div>
                    </div>
                    
                    <!-- Recent Payments -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-credit-card"></i>
                                <span class="icon-credit-card" style="display: none;"></span>
                                Recent Payments
                            </h3>
                        </div>
                        <div class="card-body" id="paymentsInfo">
                            <!-- Content will be populated by JavaScript -->
                        </div>
                    </div>
                    
                    <!-- Registration Info -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-user-plus"></i>
                                <span class="icon-user-plus" style="display: none;"></span>
                                Registration Info
                            </h3>
                        </div>
                        <div class="card-body" id="registrationInfo">
                            <!-- Content will be populated by JavaScript -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // === ENHANCED OFFLINE BUSINESS VIEW SYSTEM ===
        
        // Global variables
        let isOnline = navigator.onLine;
        let currentBusinessData = null;
        let businessId = <?php echo $business_id; ?>;
        let dataFromCache = false;
        let db;
        
        // IndexedDB setup for business data caching
        const dbName = 'BusinessViewDB';
        const dbVersion = 2;
        
        // Server-side data (if available)
        const serverData = <?php 
            if ($business) {
                echo json_encode([
                    'business' => $business,
                    'bills' => $bills,
                    'payments' => $payments
                ]);
            } else {
                echo 'null';
            }
        ?>;
        
        // Debug server data
        console.log('Server data:', serverData);
        console.log('Business ID from URL:', businessId);
        <?php if ($business): ?>
        console.log('PHP: Business found with ID:', <?php echo $business['business_id']; ?>);
        <?php else: ?>
        console.log('PHP: No business data available');
        <?php endif; ?>
        
        // Initialize IndexedDB
        function initDB() {
            return new Promise((resolve, reject) => {
                console.log('Initializing business view IndexedDB...');
                
                if (db) {
                    db.close();
                    db = null;
                }
                
                const request = indexedDB.open(dbName, dbVersion);
                
                request.onerror = (event) => {
                    console.error('IndexedDB error:', event.target.error);
                    reject(new Error('Failed to open IndexedDB: ' + event.target.error));
                };
                
                request.onsuccess = (event) => {
                    db = event.target.result;
                    console.log('Business view IndexedDB opened successfully');
                    
                    db.onerror = (event) => {
                        console.error('IndexedDB runtime error:', event.target.error);
                    };
                    
                    resolve(db);
                };
                
                request.onupgradeneeded = (event) => {
                    console.log('Upgrading business view IndexedDB schema...');
                    db = event.target.result;
                    
                    try {
                        // Delete existing stores
                        Array.from(db.objectStoreNames).forEach(storeName => {
                            db.deleteObjectStore(storeName);
                            console.log('Deleted old store:', storeName);
                        });
                        
                        // Create fresh object store
                        const store = db.createObjectStore('businessData', { 
                            keyPath: 'business_id'
                        });
                        
                        // Create indexes
                        store.createIndex('account_number', 'account_number', { unique: false });
                        store.createIndex('cached_at', 'cached_at', { unique: false });
                        store.createIndex('business_name', 'business_name', { unique: false });
                        
                        console.log('Business view IndexedDB schema created successfully');
                    } catch (error) {
                        console.error('Error creating IndexedDB schema:', error);
                        reject(error);
                    }
                };
            });
        }
        
        // Cache business data
        async function cacheBusinessData(businessData) {
            try {
                if (!db) {
                    throw new Error('Database not initialized');
                }
                
                console.log('Caching business data:', businessData);
                
                // Validate the data structure
                if (!businessData || !businessData.business) {
                    throw new Error('Invalid business data structure - missing business object');
                }
                
                if (!businessData.business.business_id) {
                    throw new Error('Invalid business data - missing business_id');
                }
                
                const dataToCache = {
                    business_id: parseInt(businessData.business.business_id),
                    business: businessData.business,
                    bills: businessData.bills || [],
                    payments: businessData.payments || [],
                    cached_at: new Date().toISOString(),
                    version: 2
                };
                
                console.log('Data prepared for caching:', dataToCache);
                
                return new Promise((resolve, reject) => {
                    try {
                        const transaction = db.transaction(['businessData'], 'readwrite');
                        const store = transaction.objectStore('businessData');
                        
                        transaction.oncomplete = () => {
                            console.log('Business data cached successfully:', dataToCache.business_id);
                            resolve();
                        };
                        
                        transaction.onerror = (event) => {
                            console.error('Transaction failed:', event.target.error);
                            reject(new Error('IndexedDB transaction failed: ' + event.target.error.message));
                        };
                        
                        transaction.onabort = (event) => {
                            console.error('Transaction aborted:', event.target.error);
                            reject(new Error('IndexedDB transaction aborted: ' + (event.target.error ? event.target.error.message : 'Unknown reason')));
                        };
                        
                        const putRequest = store.put(dataToCache);
                        
                        putRequest.onsuccess = () => {
                            console.log('Put request successful');
                        };
                        
                        putRequest.onerror = (event) => {
                            console.error('Put request failed:', event.target.error);
                            reject(new Error('IndexedDB put failed: ' + event.target.error.message));
                        };
                        
                    } catch (syncError) {
                        console.error('Synchronous error in caching:', syncError);
                        reject(new Error('Caching setup error: ' + syncError.message));
                    }
                });
                
            } catch (error) {
                console.error('Error in cacheBusinessData:', error);
                throw new Error('Cache operation failed: ' + error.message);
            }
        }
        
        // Get cached business data
        async function getCachedBusinessData(businessId) {
            try {
                if (!db) {
                    throw new Error('Database not initialized');
                }
                
                return new Promise((resolve, reject) => {
                    const transaction = db.transaction(['businessData'], 'readonly');
                    const store = transaction.objectStore('businessData');
                    
                    const getRequest = store.get(businessId);
                    
                    getRequest.onsuccess = () => {
                        const data = getRequest.result;
                        console.log('Retrieved cached data for business:', businessId, data ? 'found' : 'not found');
                        resolve(data);
                    };
                    
                    getRequest.onerror = (event) => {
                        console.error('Failed to get cached business data:', event.target.error);
                        reject(new Error('Failed to get cached data: ' + event.target.error));
                    };
                });
                
            } catch (error) {
                console.error('Error in getCachedBusinessData:', error);
                return null;
            }
        }
        
        // Format currency
        function formatCurrency(amount) {
            return '₵ ' + parseFloat(amount || 0).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }
        
        // Format date
        function formatDate(dateString) {
            if (!dateString) return 'Not available';
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
        }
        
        // Format datetime
        function formatDateTime(dateString) {
            if (!dateString) return 'Not available';
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            });
        }
        
        // Populate business data in UI
        function populateBusinessData(data) {
            if (!data || !data.business) {
                showError('No business data available');
                return;
            }
            
            const business = data.business;
            const bills = data.bills || [];
            const payments = data.payments || [];
            
            currentBusinessData = data;
            
            // Update page title
            document.title = `View Business - ${business.business_name} - ${document.title.split(' - ').slice(-1)[0]}`;
            
            // Update header
            document.getElementById('businessName').textContent = business.business_name;
            document.getElementById('businessSubtitle').innerHTML = `
                Account: <strong>${business.account_number}</strong> 
                | Owner: <strong>${business.owner_name}</strong>
            `;
            
            // Update edit button
            const editBtn = document.getElementById('editBtn');
            editBtn.href = `edit.php?id=${business.business_id}`;
            editBtn.style.display = isOnline ? 'inline-flex' : 'none';
            
            // Update status badges
            const statusBadges = document.getElementById('statusBadges');
            const paymentStatus = parseFloat(business.amount_payable) > 0 ? 'Defaulter' : 'Up to Date';
            
            let cacheIndicator = '';
            if (dataFromCache) {
                const cachedTime = data.cached_at ? new Date(data.cached_at).toLocaleString() : 'Unknown';
                cacheIndicator = `<span class="badge badge-offline">
                    <i class="fas fa-cloud-download-alt"></i>
                    Cached: ${formatDate(data.cached_at)}
                </span>`;
            }
            
            statusBadges.innerHTML = `
                <span class="badge ${business.status === 'Active' ? 'badge-success' : 'badge-warning'}">
                    <i class="fas fa-circle"></i>
                    ${business.status}
                </span>
                <span class="badge ${paymentStatus === 'Up to Date' ? 'badge-success' : 'badge-danger'}">
                    <i class="fas fa-money-bill"></i>
                    ${paymentStatus}
                </span>
                <span class="badge badge-info">
                    <i class="fas fa-building"></i>
                    ${business.business_type}
                </span>
                ${cacheIndicator}
            `;
            
            // Populate basic information
            document.getElementById('basicInfo').innerHTML = `
                <div class="info-row">
                    <div class="info-item">
                        <div class="info-label">Business Name</div>
                        <div class="info-value large">${business.business_name}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Account Number</div>
                        <div class="info-value large">${business.account_number}</div>
                    </div>
                </div>
                
                <div class="info-row">
                    <div class="info-item">
                        <div class="info-label">Owner Name</div>
                        <div class="info-value">${business.owner_name}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Telephone</div>
                        <div class="info-value">${business.telephone || 'Not provided'}</div>
                    </div>
                </div>
                
                <div class="info-row">
                    <div class="info-item">
                        <div class="info-label">Business Type</div>
                        <div class="info-value">${business.business_type}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Category</div>
                        <div class="info-value">${business.category}</div>
                    </div>
                </div>
                
                <div class="info-row">
                    <div class="info-item">
                        <div class="info-label">Status</div>
                        <div class="info-value">
                            <span class="badge ${business.status === 'Active' ? 'badge-success' : 'badge-warning'}">
                                ${business.status}
                            </span>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Batch</div>
                        <div class="info-value">${business.batch || 'Not assigned'}</div>
                    </div>
                </div>
            `;
            
            // Populate location information
            let mapContainer = '';
            if (business.latitude && business.longitude) {
                mapContainer = '<div class="map-container" id="map"></div>';
            }
            
            document.getElementById('locationInfo').innerHTML = `
                <div class="info-row">
                    <div class="info-item">
                        <div class="info-label">Zone</div>
                        <div class="info-value">${business.zone_name || 'Not assigned'}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Sub-Zone</div>
                        <div class="info-value">${business.sub_zone_name || 'Not assigned'}</div>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Exact Location</div>
                    <div class="info-value">${business.exact_location.replace(/\n/g, '<br>')}</div>
                </div>
                
                <div class="info-row">
                    <div class="info-item">
                        <div class="info-label">Latitude</div>
                        <div class="info-value">${business.latitude || 'Not available'}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Longitude</div>
                        <div class="info-value">${business.longitude || 'Not available'}</div>
                    </div>
                </div>
                
                ${mapContainer}
            `;
            
            // Populate bills information
            if (bills.length > 0) {
                let billsTable = `
                    <div style="overflow-x: auto;">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Year</th>
                                    <th>Bill Number</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Generated</th>
                                </tr>
                            </thead>
                            <tbody>
                `;
                
                bills.forEach(bill => {
                    const statusClass = bill.status === 'Paid' ? 'badge-success' : 
                                      (bill.status === 'Partially Paid' ? 'badge-warning' : 'badge-danger');
                    
                    billsTable += `
                        <tr>
                            <td>${bill.billing_year}</td>
                            <td>${bill.bill_number}</td>
                            <td>${formatCurrency(bill.amount_payable)}</td>
                            <td><span class="badge ${statusClass}">${bill.status}</span></td>
                            <td>${formatDate(bill.generated_at)}</td>
                        </tr>
                    `;
                });
                
                billsTable += '</tbody></table></div>';
                document.getElementById('billsInfo').innerHTML = billsTable;
            } else {
                document.getElementById('billsInfo').innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-file-invoice"></i>
                        <h4>No Bills Found</h4>
                        <p>No bills have been generated for this business yet.</p>
                    </div>
                `;
            }
            
            // Populate financial summary
            document.getElementById('financialSummary').innerHTML = `
                <div class="info-item" style="margin-bottom: 15px;">
                    <div class="info-label">Old Bill</div>
                    <div class="info-value currency">${formatCurrency(business.old_bill)}</div>
                </div>
                
                <div class="info-item" style="margin-bottom: 15px;">
                    <div class="info-label">Previous Payments</div>
                    <div class="info-value currency">${formatCurrency(business.previous_payments)}</div>
                </div>
                
                <div class="info-item" style="margin-bottom: 15px;">
                    <div class="info-label">Arrears</div>
                    <div class="info-value currency">${formatCurrency(business.arrears)}</div>
                </div>
                
                <div class="info-item" style="margin-bottom: 15px;">
                    <div class="info-label">Current Bill</div>
                    <div class="info-value currency">${formatCurrency(business.current_bill)}</div>
                </div>
                
                <hr style="margin: 20px 0; border: 1px solid #e2e8f0;">
                
                <div class="info-item">
                    <div class="info-label">Total Amount Payable</div>
                    <div class="info-value large currency" style="font-size: 24px;">
                        ${formatCurrency(business.amount_payable)}
                    </div>
                </div>
            `;
            
            // Populate payments information
            if (payments.length > 0) {
                let paymentsHtml = '';
                payments.forEach(payment => {
                    const statusClass = payment.payment_status === 'Successful' ? 'badge-success' : 'badge-danger';
                    paymentsHtml += `
                        <div style="border-bottom: 1px solid #e2e8f0; padding: 15px 0;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                                <strong>${formatCurrency(payment.amount_paid)}</strong>
                                <span class="badge ${statusClass}">${payment.payment_status}</span>
                            </div>
                            <div style="font-size: 12px; color: #718096;">
                                ${payment.payment_method} - ${formatDate(payment.payment_date)}
                            </div>
                            <div style="font-size: 12px; color: #718096;">
                                Ref: ${payment.payment_reference}
                            </div>
                        </div>
                    `;
                });
                document.getElementById('paymentsInfo').innerHTML = paymentsHtml;
            } else {
                document.getElementById('paymentsInfo').innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-credit-card"></i>
                        <h4>No Payments</h4>
                        <p>No payments recorded yet.</p>
                    </div>
                `;
            }
            
            // Populate registration info
            const creatorName = business.creator_first_name ? 
                `${business.creator_first_name} ${business.creator_last_name}` : 'System';
            
            document.getElementById('registrationInfo').innerHTML = `
                <div class="info-item" style="margin-bottom: 15px;">
                    <div class="info-label">Registered By</div>
                    <div class="info-value">${creatorName}</div>
                </div>
                
                <div class="info-item" style="margin-bottom: 15px;">
                    <div class="info-label">Registration Date</div>
                    <div class="info-value">${formatDateTime(business.created_at)}</div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Last Updated</div>
                    <div class="info-value">${formatDateTime(business.updated_at)}</div>
                </div>
            `;
            
            // Initialize map if coordinates are available and Google Maps is loaded
            if (business.latitude && business.longitude && typeof google !== 'undefined' && google.maps) {
                setTimeout(() => {
                    initMap(business);
                }, 500);
            }
            
            // Show content
            document.getElementById('profileHeader').style.display = 'block';
            document.getElementById('contentGrid').style.display = 'grid';
            document.getElementById('loadingOverlay').classList.remove('show');
        }
        
        // Initialize map
        function initMap(business) {
            try {
                const businessLocation = {
                    lat: parseFloat(business.latitude),
                    lng: parseFloat(business.longitude)
                };
                
                const mapElement = document.getElementById('map');
                if (!mapElement) return;
                
                const map = new google.maps.Map(mapElement, {
                    zoom: 16,
                    center: businessLocation,
                    mapTypeId: 'satellite'
                });
                
                const marker = new google.maps.Marker({
                    position: businessLocation,
                    map: map,
                    title: business.business_name,
                    icon: {
                        url: 'https://maps.google.com/mapfiles/ms/icons/red-dot.png'
                    }
                });
                
                const infoWindow = new google.maps.InfoWindow({
                    content: `
                        <div style="padding: 10px;">
                            <h6 style="margin-bottom: 5px; color: #2d3748;">
                                ${business.business_name}
                            </h6>
                            <p style="margin-bottom: 5px; font-size: 12px; color: #718096;">
                                Owner: ${business.owner_name}
                            </p>
                            <p style="margin-bottom: 0; font-size: 12px; color: #718096;">
                                Account: ${business.account_number}
                            </p>
                        </div>
                    `
                });
                
                marker.addListener('click', function() {
                    infoWindow.open(map, marker);
                });
                
                console.log('Map initialized successfully');
            } catch (error) {
                console.error('Error initializing map:', error);
            }
        }
        
        // Load business data (online or from cache)
        async function loadBusinessData() {
            try {
                document.getElementById('loadingOverlay').classList.add('show');
                
                console.log('Loading business data for ID:', businessId);
                console.log('Server data available:', !!serverData);
                console.log('Online status:', isOnline);
                
                // If we have server data and we're online, use it and cache it
                if (serverData && serverData.business && isOnline) {
                    console.log('Using server data:', serverData);
                    try {
                        await cacheBusinessData(serverData);
                        console.log('Data cached successfully');
                    } catch (cacheError) {
                        console.warn('Failed to cache data:', cacheError);
                        // Continue anyway, we can still show the data
                    }
                    dataFromCache = false;
                    populateBusinessData(serverData);
                    return;
                }
                
                // If server data is null but we're online, try to fetch from server
                if (!serverData && isOnline) {
                    console.log('No server data, attempting to fetch fresh data...');
                    try {
                        await refreshBusinessData();
                        return;
                    } catch (fetchError) {
                        console.warn('Failed to fetch fresh data:', fetchError);
                        // Fall through to try cache
                    }
                }
                
                // If offline or no server data, try to load from cache
                console.log('Attempting to load from cache...');
                const cachedData = await getCachedBusinessData(businessId);
                
                if (cachedData) {
                    console.log('Using cached data:', cachedData);
                    dataFromCache = true;
                    populateBusinessData(cachedData);
                    return;
                }
                
                // No data available
                let errorMessage = 'No business data available';
                if (!isOnline) {
                    errorMessage += ' offline. Please connect to the internet to load this business.';
                } else if (!serverData) {
                    errorMessage += '. The business may not exist or there was a server error.';
                } else {
                    errorMessage += '. Please try refreshing the page.';
                }
                
                throw new Error(errorMessage);
                
            } catch (error) {
                console.error('Error loading business data:', error);
                showError(error.message);
            }
        }
        
        // Show error message
        function showError(message) {
            document.getElementById('loadingOverlay').classList.remove('show');
            
            const errorHtml = `
                <div class="profile-header fade-in" style="text-align: center; padding: 60px 30px;">
                    <div style="color: #e53e3e; margin-bottom: 20px;">
                        <i class="fas fa-exclamation-triangle" style="font-size: 48px; margin-bottom: 15px;"></i>
                        <h3>Unable to Load Business Data</h3>
                        <p style="color: #718096; margin-bottom: 30px;">${message}</p>
                        <div style="background: #f7fafc; padding: 15px; border-radius: 8px; margin: 20px 0; text-align: left;">
                            <strong>Debug Information:</strong><br>
                            • Business ID: ${businessId}<br>
                            • Network Status: ${isOnline ? 'Online' : 'Offline'}<br>
                            • Server Data Available: ${serverData ? 'Yes' : 'No'}<br>
                            • URL: ${window.location.href}
                        </div>
                    </div>
                    <div style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap;">
                        <button onclick="refreshBusinessData()" class="btn btn-primary" ${!isOnline ? 'disabled' : ''}>
                            <i class="fas fa-sync-alt"></i>
                            ${isOnline ? 'Try Again' : 'Offline - Cannot Retry'}
                        </button>
                        <button onclick="showCacheStatus()" class="btn btn-info">
                            <i class="fas fa-info-circle"></i>
                            Check Cache
                        </button>
                        <a href="index.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i>
                            Back to List
                        </a>
                    </div>
                </div>
            `;
            
            document.querySelector('.main-content').innerHTML = errorHtml;
        }
        
        // Network status detection and UI updates
        function updateNetworkStatus() {
            isOnline = navigator.onLine;
            const networkStatus = document.getElementById('networkStatus');
            const networkIcon = document.getElementById('networkIcon');
            const networkText = document.getElementById('networkText');
            const offlineBanner = document.getElementById('offlineBanner');
            
            if (isOnline) {
                networkStatus.className = 'network-status online';
                networkIcon.innerHTML = '<i class="fas fa-wifi"></i><span class="icon-wifi" style="display: none;"></span>';
                networkText.textContent = 'Online';
                offlineBanner.classList.remove('show');
                
                // Show edit button if business is loaded
                const editBtn = document.getElementById('editBtn');
                if (editBtn && currentBusinessData) {
                    editBtn.style.display = 'inline-flex';
                }
                
                // Refresh data if we were using cached data
                if (dataFromCache && currentBusinessData) {
                    setTimeout(() => {
                        refreshBusinessData();
                    }, 1000);
                }
            } else {
                networkStatus.className = 'network-status offline';
                networkIcon.innerHTML = '<i class="fas fa-wifi-slash"></i><span class="icon-wifi-slash" style="display: none;"></span>';
                networkText.textContent = 'Offline';
                offlineBanner.classList.add('show');
                
                // Hide edit button
                const editBtn = document.getElementById('editBtn');
                if (editBtn) {
                    editBtn.style.display = 'none';
                }
                
                // Update cache info
                updateCacheInfo();
            }
        }
        
        // Update cache info
        function updateCacheInfo() {
            const cacheInfo = document.getElementById('cacheInfo');
            const cacheText = document.getElementById('cacheText');
            
            if (currentBusinessData && currentBusinessData.cached_at) {
                const cachedDate = new Date(currentBusinessData.cached_at);
                const now = new Date();
                const diffHours = Math.round((now - cachedDate) / (1000 * 60 * 60));
                
                if (diffHours < 1) {
                    cacheText.textContent = 'Recently Cached';
                } else if (diffHours < 24) {
                    cacheText.textContent = `Cached ${diffHours}h ago`;
                } else {
                    const diffDays = Math.round(diffHours / 24);
                    cacheText.textContent = `Cached ${diffDays}d ago`;
                }
            } else {
                cacheText.textContent = 'Cached Data';
            }
        }
        
        // Refresh business data
        async function refreshBusinessData() {
            if (!isOnline) {
                showSyncMessage('error', 'Cannot refresh data while offline');
                return;
            }
            
            try {
                updateSyncStatus('syncing', 'Refreshing data...');
                console.log('Starting refresh for business ID:', businessId);
                
                // Fetch fresh data from server
                const fetchUrl = `view.php?id=${businessId}&ajax=1`;
                console.log('Fetching from URL:', fetchUrl);
                
                const response = await fetch(fetchUrl, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                
                console.log('Response status:', response.status);
                console.log('Response headers:', response.headers);
                
                if (!response.ok) {
                    const errorText = await response.text();
                    console.error('HTTP error response:', errorText);
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                
                // Get response text first to debug
                const responseText = await response.text();
                console.log('Raw response:', responseText);
                
                // Try to parse JSON
                let freshData;
                try {
                    freshData = JSON.parse(responseText);
                    console.log('Parsed JSON data:', freshData);
                } catch (parseError) {
                    console.error('JSON parse error:', parseError);
                    console.error('Response was:', responseText);
                    throw new Error(`Invalid JSON response: ${parseError.message}`);
                }
                
                if (freshData.success && freshData.data) {
                    console.log('Attempting to cache fresh data...');
                    try {
                        await cacheBusinessData(freshData.data);
                        console.log('Data cached successfully');
                    } catch (cacheError) {
                        console.error('Cache error:', cacheError);
                        throw new Error(`Failed to cache data: ${cacheError.message}`);
                    }
                    
                    console.log('Populating UI with fresh data...');
                    dataFromCache = false;
                    populateBusinessData(freshData.data);
                    showSyncMessage('success', 'Business data refreshed successfully');
                } else {
                    const errorMsg = freshData.message || 'Unknown error from server';
                    console.error('Server returned error:', errorMsg);
                    throw new Error(errorMsg);
                }
                
            } catch (error) {
                console.error('Error refreshing data:', error);
                console.error('Error stack:', error.stack);
                showSyncMessage('error', 'Failed to refresh data: ' + error.message);
            } finally {
                updateSyncStatus('online', 'Online');
            }
        }
        
        // Update sync status
        function updateSyncStatus(status, message) {
            const networkStatus = document.getElementById('networkStatus');
            const networkIcon = document.getElementById('networkIcon');
            const networkText = document.getElementById('networkText');
            
            switch (status) {
                case 'syncing':
                    networkStatus.className = 'network-status syncing';
                    networkIcon.innerHTML = '<i class="fas fa-sync-alt sync-spinner"></i><span class="icon-sync" style="display: none;"></span>';
                    networkText.textContent = 'Syncing...';
                    break;
                case 'online':
                    networkStatus.className = 'network-status online';
                    networkIcon.innerHTML = '<i class="fas fa-wifi"></i><span class="icon-wifi" style="display: none;"></span>';
                    networkText.textContent = 'Online';
                    break;
            }
        }
        
        // Show sync messages
        function showSyncMessage(type, message) {
            const messageDiv = document.createElement('div');
            messageDiv.className = `sync-message ${type}`;
            messageDiv.innerHTML = `
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                    <div>${message}</div>
                </div>
            `;
            
            document.body.appendChild(messageDiv);
            
            setTimeout(() => {
                messageDiv.classList.add('show');
            }, 100);
            
            setTimeout(() => {
                messageDiv.classList.remove('show');
                setTimeout(() => {
                    if (messageDiv.parentNode) {
                        messageDiv.parentNode.removeChild(messageDiv);
                    }
                }, 300);
            }, 5000);
        }
        
        // Show cache status
        async function showCacheStatus() {
            try {
                const cachedData = await getCachedBusinessData(businessId);
                
                if (cachedData) {
                    const cachedTime = new Date(cachedData.cached_at).toLocaleString();
                    const message = `Cache Status:\n\nBusiness: ${cachedData.business.business_name}\nCached: ${cachedTime}\nNetwork: ${isOnline ? 'Online' : 'Offline'}`;
                    
                    alert(message);
                } else {
                    alert('Cache Status:\n\nNo cached data found for this business.\nNetwork: ' + (isOnline ? 'Online' : 'Offline'));
                }
                
                if (isOnline && confirm('Would you like to refresh the data now?')) {
                    refreshBusinessData();
                }
            } catch (error) {
                alert('Error checking cache status: ' + error.message);
            }
        }
        
        // Initialize system
        document.addEventListener('DOMContentLoaded', async function() {
            try {
                let retryCount = 0;
                const maxRetries = 3;
                
                while (retryCount < maxRetries) {
                    try {
                        await initDB();
                        console.log('IndexedDB initialized successfully');
                        break;
                    } catch (error) {
                        retryCount++;
                        console.warn(`IndexedDB init attempt ${retryCount} failed:`, error);
                        
                        if (retryCount >= maxRetries) {
                            console.error('Failed to initialize IndexedDB after', maxRetries, 'attempts');
                            showSyncMessage('error', 'Failed to initialize offline storage');
                            // Continue anyway, just won't have offline capabilities
                            break;
                        }
                        
                        await new Promise(resolve => setTimeout(resolve, 1000 * retryCount));
                    }
                }
                
                updateNetworkStatus();
                await loadBusinessData();
                
                window.addEventListener('online', updateNetworkStatus);
                window.addEventListener('offline', updateNetworkStatus);
                
                console.log('Business view system initialized successfully');
                
            } catch (error) {
                console.error('Failed to initialize business view system:', error);
                showError('Failed to initialize: ' + error.message);
            }
            
            // Font Awesome fallback check
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
        
        // === END ENHANCED OFFLINE BUSINESS VIEW SYSTEM ===
        
        // Sidebar toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const isMobile = window.innerWidth <= 768;
            
            if (isMobile) {
                sidebar.classList.toggle('show');
                sidebar.classList.toggle('hidden');
            } else {
                sidebar.classList.toggle('hidden');
            }
            
            const isHidden = sidebar.classList.contains('hidden');
            localStorage.setItem('sidebarHidden', isHidden);
        }

        // Restore sidebar state
        document.addEventListener('DOMContentLoaded', function() {
            const sidebarHidden = localStorage.getItem('sidebarHidden');
            const sidebar = document.getElementById('sidebar');
            const isMobile = window.innerWidth <= 768;
            
            if (isMobile) {
                sidebar.classList.add('hidden');
                sidebar.classList.remove('show');
            } else if (sidebarHidden === 'true') {
                sidebar.classList.add('hidden');
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

        // Close sidebar when clicking outside in mobile view
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const toggleBtn = document.getElementById('toggleBtn');
            const isMobile = window.innerWidth <= 768;
            
            if (isMobile && !sidebar.contains(event.target) && !toggleBtn.contains(event.target)) {
                sidebar.classList.remove('show');
                sidebar.classList.add('hidden');
                localStorage.setItem('sidebarHidden', true);
            }
        });
    </script>
</body>
</html>