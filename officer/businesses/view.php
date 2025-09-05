<?php
/**
 * Officer - View Business Profile
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

// Check if user is officer or admin
$currentUser = getCurrentUser();
if (!isOfficer() && !isAdmin()) {
    setFlashMessage('error', 'Access denied. Officer privileges required.');
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


$userDisplayName = getUserDisplayName($currentUser);

// Get business ID
$business_id = intval($_GET['id'] ?? 0);

if ($business_id <= 0) {
    setFlashMessage('error', 'Invalid business ID.');
    header('Location: index.php');
    exit();
}

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
        setFlashMessage('error', 'Business not found.');
        header('Location: index.php');
        exit();
    }
    
    // Calculate remaining balance (outstanding amount after all payments)
    $totalPaymentsQuery = "SELECT COALESCE(SUM(p.amount_paid), 0) as total_paid
                          FROM payments p 
                          INNER JOIN bills b ON p.bill_id = b.bill_id 
                          WHERE b.bill_type = 'Business' AND b.reference_id = ? 
                          AND p.payment_status = 'Successful'";
    $totalPaymentsResult = $db->fetchRow($totalPaymentsQuery, [$business_id]);
    $totalPaid = $totalPaymentsResult['total_paid'] ?? 0;
    
    // Calculate remaining balance: amount payable minus total successful payments
    $remainingBalance = max(0, $business['amount_payable'] - $totalPaid);
    
    // Get business bills
    $bills = $db->fetchAll("
        SELECT 
            bill_id,
            bill_number,
            billing_year,
            old_bill,
            previous_payments,
            arrears,
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
            p.payment_channel,
            p.payment_status,
            p.payment_date,
            p.notes,
            b.bill_number,
            b.billing_year,
            u.first_name as processed_by_first_name,
            u.last_name as processed_by_last_name
        FROM payments p
        JOIN bills b ON p.bill_id = b.bill_id
        LEFT JOIN users u ON p.processed_by = u.user_id
        WHERE b.bill_type = 'Business' AND b.reference_id = ?
        ORDER BY p.payment_date DESC
        LIMIT 15
    ", [$business_id]);
    
    // Calculate payment summary
    $paymentSummary = $db->fetchRow("
        SELECT 
            COALESCE(SUM(CASE WHEN p.payment_status = 'Successful' THEN p.amount_paid ELSE 0 END), 0) as total_paid,
            COUNT(CASE WHEN p.payment_status = 'Successful' THEN 1 END) as successful_payments,
            COUNT(*) as total_transactions
        FROM payments p
        JOIN bills b ON p.bill_id = b.bill_id
        WHERE b.bill_type = 'Business' AND b.reference_id = ?
    ", [$business_id]);
    
} catch (Exception $e) {
    setFlashMessage('error', 'Database error: ' . $e->getMessage());
    header('Location: index.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Business - <?php echo htmlspecialchars($business['business_name']); ?> - <?php echo APP_NAME; ?></title>
    
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
        .icon-dashboard::before { content: "⚡"; }
        .icon-building::before { content: "🏢"; }
        .icon-home::before { content: "🏠"; }
        .icon-money::before { content: "💰"; }
        .icon-receipt::before { content: "🧾"; }
        .icon-map::before { content: "🗺️"; }
        .icon-menu::before { content: "☰"; }
        .icon-logout::before { content: "🚪"; }
        .icon-plus::before { content: "➕"; }
        .icon-search::before { content: "🔍"; }
        .icon-user::before { content: "👤"; }
        .icon-edit::before { content: "✏️"; }
        .icon-arrow-left::before { content: "⬅️"; }
        .icon-info-circle::before { content: "ℹ️"; }
        .icon-map-marker::before { content: "📍"; }
        .icon-file-invoice::before { content: "📄"; }
        .icon-money-bill::before { content: "💵"; }
        .icon-credit-card::before { content: "💳"; }
        .icon-user-plus::before { content: "👤➕"; }
        .icon-question::before { content: "❓"; }
        .icon-print::before { content: "🖨️"; }
        .icon-cash::before { content: "💵"; }
        .icon-balance::before { content: "⚖️"; }
        
        /* Top Navigation */
        .top-nav {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
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
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
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
            color: #4299e1;
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
            border-left-color: #4299e1;
        }
        
        .nav-link.active {
            background: rgba(66, 153, 225, 0.3);
            color: white;
            border-left-color: #4299e1;
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
            background: #4299e1;
            color: white;
        }
        
        .btn-primary:hover {
            background: #3182ce;
            transform: translateY(-2px);
            color: white;
        }
        
        .btn-secondary {
            background: #718096;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #4a5568;
            color: white;
        }
        
        .btn-success {
            background: #38a169;
            color: white;
        }
        
        .btn-success:hover {
            background: #2f855a;
            color: white;
        }
        
        .btn-warning {
            background: #ed8936;
            color: white;
        }
        
        .btn-warning:hover {
            background: #dd6b20;
            color: white;
        }
        
        .btn-info {
            background: #4299e1;
            color: white;
        }
        
        .btn-info:hover {
            background: #3182ce;
            color: white;
        }
        
        .btn-danger {
            background: #e53e3e;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c53030;
            color: white;
        }
        
        /* Status Badges */
        .status-badges {
            display: flex;
            gap: 10px;
            margin-top: 15px;
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
        
        .info-value.balance {
            color: #7c2d12;
            font-size: 20px;
            font-weight: bold;
        }
        
        .info-value.balance.zero {
            color: #059669;
        }
        
        /* Balance Highlight */
        .balance-highlight {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: 2px solid #f59e0b;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
            text-align: center;
        }
        
        .balance-highlight.paid {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            border-color: #10b981;
        }
        
        .balance-highlight h4 {
            margin: 0 0 10px 0;
            font-size: 18px;
            color: #92400e;
        }
        
        .balance-highlight.paid h4 {
            color: #065f46;
        }
        
        .balance-amount {
            font-size: 32px;
            font-weight: bold;
            color: #92400e;
            margin: 10px 0;
        }
        
        .balance-highlight.paid .balance-amount {
            color: #065f46;
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
        
        /* Action Buttons */
        .action-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border: 2px dashed #e2e8f0;
        }
        
        .action-title {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 15px;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
        }
        
        .stat-card.primary {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
        }
        
        .stat-card.success {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
        }
        
        .stat-card.warning {
            background: linear-gradient(135deg, #ed8936 0%, #dd6b20 100%);
            color: white;
        }
        
        .stat-card.danger {
            background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%);
            color: white;
        }
        
        .stat-icon {
            font-size: 24px;
            margin-bottom: 10px;
            opacity: 0.8;
        }
        
        .stat-value {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 12px;
            opacity: 0.9;
            font-weight: 500;
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
            
            .action-buttons {
                flex-direction: column;
            }
            
            .container {
                flex-direction: column;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
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
    <!-- Top Navigation -->
    <div class="top-nav">
        <div class="nav-left">
            <button class="toggle-btn" onclick="toggleSidebar()" id="toggleBtn">
                <i class="fas fa-bars"></i>
                <span class="icon-menu" style="display: none;"></span>
            </button>
            
            <a href="../index.php" class="brand">
                <i class="fas fa-user-tie"></i>
                <span class="icon-user" style="display: none;"></span>
                Officer Portal
            </a>
        </div>
        
        <div class="user-section">
            <div class="user-profile" onclick="toggleUserDropdown()" id="userProfile">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($currentUser['first_name'] ?? 'U', 0, 1)); ?>
                </div>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($userDisplayName); ?></div>
                    <div class="user-role">Officer</div>
                </div>
                <i class="fas fa-chevron-down dropdown-arrow"></i>
                
                <!-- User Dropdown -->
                <div class="user-dropdown" id="userDropdown">
                    <div class="dropdown-header">
                        <div class="dropdown-avatar">
                            <?php echo strtoupper(substr($currentUser['first_name'] ?? 'U', 0, 1)); ?>
                        </div>
                        <div class="dropdown-name"><?php echo htmlspecialchars($userDisplayName); ?></div>
                        <div class="dropdown-role">Officer</div>
                    </div>
                    <div class="dropdown-menu">
                        <a href="#" class="dropdown-item" onclick="alert('Profile management coming soon!')">
                            <i class="fas fa-user"></i>
                            <span class="icon-user" style="display: none;"></span>
                            My Profile
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
                
                <!-- Registration -->
                <div class="nav-section">
                    <div class="nav-title">Registration</div>
                    <div class="nav-item">
                        <a href="add.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-plus-circle"></i>
                                <span class="icon-plus" style="display: none;"></span>
                            </span>
                            Register Business
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../properties/add.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-plus-circle"></i>
                                <span class="icon-plus" style="display: none;"></span>
                            </span>
                            Register Property
                        </a>
                    </div>
                </div>
                
                <!-- Management -->
                <div class="nav-section">
                    <div class="nav-title">Management</div>
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
                
                <!-- Payments & Bills -->
                <div class="nav-section">
                    <div class="nav-title">Payments & Bills</div>
                    <div class="nav-item">
                        <a href="../payments/record.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-cash-register"></i>
                                <span class="icon-cash" style="display: none;"></span>
                            </span>
                            Record Payment
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../payments/search.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-search"></i>
                                <span class="icon-search" style="display: none;"></span>
                            </span>
                            Search Accounts
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
                            Business Map
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../map/properties.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-map-marker-alt"></i>
                                <span class="icon-map" style="display: none;"></span>
                            </span>
                            Property Map
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Profile Header -->
            <div class="profile-header fade-in">
                <div class="profile-title">
                    <div class="title-left">
                        <h1 class="business-name"><?php echo htmlspecialchars($business['business_name']); ?></h1>
                        <p class="business-subtitle">
                            Account: <strong><?php echo htmlspecialchars($business['account_number']); ?></strong> 
                            | Owner: <strong><?php echo htmlspecialchars($business['owner_name']); ?></strong>
                        </p>
                        
                        <div class="status-badges">
                            <span class="badge <?php echo $business['status'] == 'Active' ? 'badge-success' : 'badge-warning'; ?>">
                                <i class="fas fa-circle"></i>
                                <span class="icon-circle" style="display: none;"></span>
                                <?php echo $business['status']; ?>
                            </span>
                            <span class="badge <?php echo $remainingBalance <= 0 ? 'badge-success' : 'badge-danger'; ?>">
                                <i class="fas fa-balance-scale"></i>
                                <span class="icon-balance" style="display: none;"></span>
                                <?php echo $remainingBalance <= 0 ? 'Fully Paid' : 'Outstanding Balance'; ?>
                            </span>
                            <span class="badge badge-info">
                                <i class="fas fa-building"></i>
                                <span class="icon-building" style="display: none;"></span>
                                <?php echo htmlspecialchars($business['business_type']); ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="profile-actions">
                        <a href="edit.php?id=<?php echo $business['business_id']; ?>" class="btn btn-warning">
                            <i class="fas fa-edit"></i>
                            <span class="icon-edit" style="display: none;"></span>
                            Edit Business
                        </a>
                        
                        <a href="index.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i>
                            <span class="icon-arrow-left" style="display: none;"></span>
                            Back to List
                        </a>
                    </div>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="stat-icon">
                        <i class="fas fa-file-invoice"></i>
                    </div>
                    <div class="stat-value"><?php echo count($bills); ?></div>
                    <div class="stat-label">Total Bills</div>
                </div>

                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-value">₵ <?php echo number_format($paymentSummary['total_paid'] ?? 0, 2); ?></div>
                    <div class="stat-label">Total Paid</div>
                </div>

                <div class="stat-card <?php echo $remainingBalance > 0 ? 'danger' : 'success'; ?>">
                    <div class="stat-icon">
                        <i class="fas fa-balance-scale"></i>
                        <span class="icon-balance" style="display: none;"></span>
                    </div>
                    <div class="stat-value">₵ <?php echo number_format($remainingBalance, 2); ?></div>
                    <div class="stat-label">Remaining Balance</div>
                </div>

                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-credit-card"></i>
                    </div>
                    <div class="stat-value"><?php echo $paymentSummary['successful_payments'] ?? 0; ?></div>
                    <div class="stat-label">Successful Payments</div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="action-section">
                <div class="action-title">
                    <i class="fas fa-bolt"></i>
                    Quick Actions
                </div>
                <div class="action-buttons">
                    <a href="../payments/record.php?account=<?php echo urlencode($business['account_number']); ?>" class="btn btn-success">
                        <i class="fas fa-cash-register"></i>
                        <span class="icon-cash" style="display: none;"></span>
                        Record Payment
                    </a>
                    <a href="../billing/print.php?business_id=<?php echo $business['business_id']; ?>" class="btn btn-primary">
                        <i class="fas fa-print"></i>
                        <span class="icon-print" style="display: none;"></span>
                        Print Bill
                    </a>
                    <button onclick="showBalanceDetails()" class="btn btn-warning">
                        <i class="fas fa-balance-scale"></i>
                        <span class="icon-balance" style="display: none;"></span>
                        Balance Details
                    </button>
                </div>
            </div>

            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Basic Information -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-info-circle"></i>
                                <span class="icon-info-circle" style="display: none;"></span>
                                Business Information
                            </h3>
                        </div>
                        <div class="card-body">
                            <div class="info-row">
                                <div class="info-item">
                                    <div class="info-label">Business Name</div>
                                    <div class="info-value large"><?php echo htmlspecialchars($business['business_name']); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Account Number</div>
                                    <div class="info-value large"><?php echo htmlspecialchars($business['account_number']); ?></div>
                                </div>
                            </div>
                            
                            <div class="info-row">
                                <div class="info-item">
                                    <div class="info-label">Owner Name</div>
                                    <div class="info-value"><?php echo htmlspecialchars($business['owner_name']); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Telephone</div>
                                    <div class="info-value">
                                        <?php echo $business['telephone'] ? htmlspecialchars($business['telephone']) : 'Not provided'; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="info-row">
                                <div class="info-item">
                                    <div class="info-label">Business Type</div>
                                    <div class="info-value"><?php echo htmlspecialchars($business['business_type']); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Category</div>
                                    <div class="info-value"><?php echo htmlspecialchars($business['category']); ?></div>
                                </div>
                            </div>
                            
                            <div class="info-row">
                                <div class="info-item">
                                    <div class="info-label">Status</div>
                                    <div class="info-value">
                                        <span class="badge <?php echo $business['status'] == 'Active' ? 'badge-success' : 'badge-warning'; ?>">
                                            <?php echo $business['status']; ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Batch</div>
                                    <div class="info-value">
                                        <?php echo $business['batch'] ? htmlspecialchars($business['batch']) : 'Not assigned'; ?>
                                    </div>
                                </div>
                            </div>
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
                        <div class="card-body">
                            <div class="info-row">
                                <div class="info-item">
                                    <div class="info-label">Zone</div>
                                    <div class="info-value"><?php echo htmlspecialchars($business['zone_name'] ?? 'Not assigned'); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Sub-Zone</div>
                                    <div class="info-value"><?php echo htmlspecialchars($business['sub_zone_name'] ?? 'Not assigned'); ?></div>
                                </div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label">Exact Location</div>
                                <div class="info-value"><?php echo nl2br(htmlspecialchars($business['exact_location'])); ?></div>
                            </div>
                            
                            <div class="info-row">
                                <div class="info-item">
                                    <div class="info-label">Latitude</div>
                                    <div class="info-value"><?php echo $business['latitude']; ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Longitude</div>
                                    <div class="info-value"><?php echo $business['longitude']; ?></div>
                                </div>
                            </div>
                            
                            <?php if ($business['latitude'] && $business['longitude']): ?>
                                <div class="map-container" id="map"></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Bills & Payments -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-file-invoice"></i>
                                <span class="icon-file-invoice" style="display: none;"></span>
                                Bills History
                            </h3>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($bills)): ?>
                                <div style="overflow-x: auto;">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Year</th>
                                                <th>Bill Number</th>
                                                <th>Current Bill</th>
                                                <th>Amount Payable</th>
                                                <th>Status</th>
                                                <th>Generated</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($bills as $bill): ?>
                                                <tr>
                                                    <td><?php echo $bill['billing_year']; ?></td>
                                                    <td><?php echo htmlspecialchars($bill['bill_number']); ?></td>
                                                    <td>₵ <?php echo number_format($bill['current_bill'], 2); ?></td>
                                                    <td>₵ <?php echo number_format($bill['amount_payable'], 2); ?></td>
                                                    <td>
                                                        <span class="badge <?php 
                                                            echo $bill['status'] == 'Paid' ? 'badge-success' : 
                                                                ($bill['status'] == 'Partially Paid' ? 'badge-warning' : 'badge-danger'); 
                                                        ?>">
                                                            <?php echo $bill['status']; ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo date('M j, Y', strtotime($bill['generated_at'])); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-file-invoice"></i>
                                    <span class="icon-file-invoice" style="display: none;"></span>
                                    <h4>No Bills Found</h4>
                                    <p>No bills have been generated for this business yet.</p>
                                    <a href="../billing/generate.php?business_id=<?php echo $business['business_id']; ?>" class="btn btn-primary">
                                        <i class="fas fa-plus"></i>
                                        Generate First Bill
                                    </a>
                                </div>
                            <?php endif; ?>
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
                        <div class="card-body">
                            <div class="info-item" style="margin-bottom: 15px;">
                                <div class="info-label">Old Bill</div>
                                <div class="info-value currency">₵ <?php echo number_format($business['old_bill'], 2); ?></div>
                            </div>
                            
                            <div class="info-item" style="margin-bottom: 15px;">
                                <div class="info-label">Previous Payments</div>
                                <div class="info-value currency">₵ <?php echo number_format($business['previous_payments'], 2); ?></div>
                            </div>
                            
                            <div class="info-item" style="margin-bottom: 15px;">
                                <div class="info-label">Arrears</div>
                                <div class="info-value currency">₵ <?php echo number_format($business['arrears'], 2); ?></div>
                            </div>
                            
                            <div class="info-item" style="margin-bottom: 15px;">
                                <div class="info-label">Current Bill</div>
                                <div class="info-value currency">₵ <?php echo number_format($business['current_bill'], 2); ?></div>
                            </div>
                            
                            <hr style="margin: 20px 0; border: 1px solid #e2e8f0;">
                            
                            <div class="info-item">
                                <div class="info-label">Total Amount Payable</div>
                                <div class="info-value large currency" style="font-size: 24px;">
                                    ₵ <?php echo number_format($business['amount_payable'], 2); ?>
                                </div>
                            </div>
                            
                            <!-- Remaining Balance Highlight -->
                            <div class="balance-highlight <?php echo $remainingBalance <= 0 ? 'paid' : ''; ?>">
                                <h4>
                                    <i class="fas fa-balance-scale"></i>
                                    <span class="icon-balance" style="display: none;"></span>
                                    <?php echo $remainingBalance <= 0 ? 'Account Fully Paid' : 'Outstanding Balance'; ?>
                                </h4>
                                <div class="balance-amount">
                                    ₵ <?php echo number_format($remainingBalance, 2); ?>
                                </div>
                                <?php if ($remainingBalance > 0): ?>
                                    <p style="margin: 10px 0 0 0; font-size: 14px; color: #92400e;">
                                        This amount needs to be paid
                                    </p>
                                <?php else: ?>
                                    <p style="margin: 10px 0 0 0; font-size: 14px; color: #065f46;">
                                        ✅ All bills have been settled
                                    </p>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($remainingBalance > 0): ?>
                                <a href="../payments/record.php?account=<?php echo urlencode($business['account_number']); ?>" class="btn btn-success" style="width: 100%; justify-content: center; margin-top: 15px;">
                                    <i class="fas fa-cash-register"></i>
                                    <span class="icon-cash" style="display: none;"></span>
                                    Record Payment
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Payment Summary -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-chart-line"></i>
                                Payment Summary
                            </h3>
                        </div>
                        <div class="card-body">
                            <div class="info-item" style="margin-bottom: 15px;">
                                <div class="info-label">Total Paid</div>
                                <div class="info-value currency">₵ <?php echo number_format($paymentSummary['total_paid'] ?? 0, 2); ?></div>
                            </div>
                            
                            <div class="info-item" style="margin-bottom: 15px;">
                                <div class="info-label">Remaining Balance</div>
                                <div class="info-value balance <?php echo $remainingBalance <= 0 ? 'zero' : ''; ?>">
                                    ₵ <?php echo number_format($remainingBalance, 2); ?>
                                </div>
                            </div>
                            
                            <div class="info-item" style="margin-bottom: 15px;">
                                <div class="info-label">Successful Payments</div>
                                <div class="info-value"><?php echo number_format($paymentSummary['successful_payments'] ?? 0); ?></div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label">Total Transactions</div>
                                <div class="info-value"><?php echo number_format($paymentSummary['total_transactions'] ?? 0); ?></div>
                            </div>
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
                        <div class="card-body">
                            <?php if (!empty($payments)): ?>
                                <?php foreach (array_slice($payments, 0, 5) as $payment): ?>
                                    <div style="border-bottom: 1px solid #e2e8f0; padding: 15px 0;">
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                                            <strong>₵ <?php echo number_format($payment['amount_paid'], 2); ?></strong>
                                            <span class="badge <?php echo $payment['payment_status'] == 'Successful' ? 'badge-success' : 'badge-danger'; ?>">
                                                <?php echo $payment['payment_status']; ?>
                                            </span>
                                        </div>
                                        <div style="font-size: 12px; color: #718096;">
                                            <?php echo htmlspecialchars($payment['payment_method']); ?>
                                            <?php if (!empty($payment['payment_channel'])): ?>
                                                (<?php echo htmlspecialchars($payment['payment_channel']); ?>)
                                            <?php endif; ?>
                                            - <?php echo date('M j, Y', strtotime($payment['payment_date'])); ?>
                                        </div>
                                        <div style="font-size: 12px; color: #718096;">
                                            Ref: <?php echo htmlspecialchars($payment['payment_reference']); ?>
                                        </div>
                                        <?php if (!empty($payment['processed_by_first_name'])): ?>
                                            <div style="font-size: 12px; color: #718096;">
                                                By: <?php echo htmlspecialchars($payment['processed_by_first_name'] . ' ' . $payment['processed_by_last_name']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                                
                                <?php if (count($payments) > 5): ?>
                                    <div style="text-align: center; margin-top: 15px;">
                                        <a href="../payments/history.php?account=<?php echo urlencode($business['account_number']); ?>" class="btn btn-info">
                                            View All Payments
                                        </a>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-credit-card"></i>
                                    <span class="icon-credit-card" style="display: none;"></span>
                                    <h4>No Payments</h4>
                                    <p>No payments recorded yet.</p>
                                    <a href="../payments/record.php?account=<?php echo urlencode($business['account_number']); ?>" class="btn btn-success">
                                        <i class="fas fa-plus"></i>
                                        Record First Payment
                                    </a>
                                </div>
                            <?php endif; ?>
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
                        <div class="card-body">
                            <div class="info-item" style="margin-bottom: 15px;">
                                <div class="info-label">Registered By</div>
                                <div class="info-value">
                                    <?php 
                                    if ($business['creator_first_name']) {
                                        echo htmlspecialchars($business['creator_first_name'] . ' ' . $business['creator_last_name']);
                                    } else {
                                        echo 'System';
                                    }
                                    ?>
                                </div>
                            </div>
                            
                            <div class="info-item" style="margin-bottom: 15px;">
                                <div class="info-label">Registration Date</div>
                                <div class="info-value"><?php echo date('M j, Y g:i A', strtotime($business['created_at'])); ?></div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label">Last Updated</div>
                                <div class="info-value"><?php echo date('M j, Y g:i A', strtotime($business['updated_at'])); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Global variables
        const remainingBalance = <?php echo $remainingBalance; ?>;
        const businessName = <?php echo json_encode($business['business_name']); ?>;
        const accountNumber = <?php echo json_encode($business['account_number']); ?>;
        
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
            
            // Display balance notification
            displayBalanceNotification();
        });

        function displayBalanceNotification() {
            // Show balance status notification on page load
            if (remainingBalance > 0) {
                setTimeout(() => {
                    showNotification(`⚠️ Outstanding balance: ₵ ${remainingBalance.toLocaleString('en-US', {minimumFractionDigits: 2})}`, 'warning');
                }, 2000);
            } else {
                setTimeout(() => {
                    showNotification('✅ Account fully paid - No outstanding balance', 'success');
                }, 2000);
            }
        }

        // Show detailed balance information
        function showBalanceDetails() {
            const balanceModal = document.createElement('div');
            balanceModal.style.cssText = `
                position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(0,0,0,0.6); backdrop-filter: blur(8px); z-index: 10000;
                display: flex; align-items: center; justify-content: center;
                animation: fadeIn 0.3s ease; cursor: pointer;
            `;
            
            const balanceContent = document.createElement('div');
            balanceContent.style.cssText = `
                background: white; padding: 30px; border-radius: 15px; max-width: 600px; width: 90%;
                box-shadow: 0 25px 80px rgba(0,0,0,0.4); text-align: center;
                animation: modalSlideIn 0.4s ease; cursor: default;
            `;
            
            const amountPayable = <?php echo $business['amount_payable']; ?>;
            const totalPaid = <?php echo $paymentSummary['total_paid'] ?? 0; ?>;
            const paymentProgress = amountPayable > 0 ? (totalPaid / amountPayable) * 100 : 100;
            
            balanceContent.innerHTML = `
                <h3 style="margin: 0 0 20px 0; color: #2d3748; display: flex; align-items: center; gap: 10px; justify-content: center;">
                    <i class="fas fa-balance-scale" style="color: #4299e1;"></i>
                    ⚖️ Balance Analysis
                </h3>
                <div style="background: #f8fafc; padding: 25px; border-radius: 12px; margin: 20px 0;">
                    <h4 style="margin: 0 0 15px 0; color: #4299e1; font-size: 18px;">${businessName}</h4>
                    <p style="margin: 0 0 20px 0; color: #64748b;">Account: ${accountNumber}</p>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                        <div style="text-align: left;">
                            <strong style="color: #2d3748;">Total Amount Payable:</strong>
                            <div style="font-size: 24px; font-weight: bold; color: #4299e1; margin-top: 5px;">
                                ₵ ${amountPayable.toLocaleString('en-US', {minimumFractionDigits: 2})}
                            </div>
                        </div>
                        <div style="text-align: left;">
                            <strong style="color: #2d3748;">Total Paid:</strong>
                            <div style="font-size: 24px; font-weight: bold; color: #059669; margin-top: 5px;">
                                ₵ ${totalPaid.toLocaleString('en-US', {minimumFractionDigits: 2})}
                            </div>
                        </div>
                    </div>
                    <div style="background: white; padding: 15px; border-radius: 8px; margin: 15px 0;">
                        <strong style="color: #2d3748;">Payment Progress:</strong>
                        <div style="background: #e2e8f0; height: 12px; border-radius: 6px; margin: 10px 0; overflow: hidden;">
                            <div style="background: linear-gradient(90deg, #10b981, #059669); height: 100%; width: ${Math.min(paymentProgress, 100)}%; transition: width 1s ease;"></div>
                        </div>
                        <div style="font-size: 14px; color: #64748b;">${paymentProgress.toFixed(1)}% Completed</div>
                    </div>
                    <div style="border: 3px solid ${remainingBalance > 0 ? '#f59e0b' : '#10b981'}; 
                                background: ${remainingBalance > 0 ? '#fef3c7' : '#d1fae5'}; 
                                padding: 20px; border-radius: 12px; margin-top: 20px;">
                        <h4 style="margin: 0 0 10px 0; color: ${remainingBalance > 0 ? '#92400e' : '#065f46'};">
                            ${remainingBalance > 0 ? '⚠️ Outstanding Balance' : '✅ Account Status'}
                        </h4>
                        <div style="font-size: 36px; font-weight: bold; color: ${remainingBalance > 0 ? '#92400e' : '#065f46'}; margin: 15px 0;">
                            ₵ ${remainingBalance.toLocaleString('en-US', {minimumFractionDigits: 2})}
                        </div>
                        <p style="margin: 10px 0 0 0; color: ${remainingBalance > 0 ? '#92400e' : '#065f46'};">
                            ${remainingBalance > 0 ? 'This amount needs to be paid to clear the account' : 'All bills have been fully settled'}
                        </p>
                    </div>
                </div>
                <div style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap; margin-top: 25px;">
                    ${remainingBalance > 0 ? `
                        <a href="../payments/record.php?account=${encodeURIComponent(accountNumber)}" style="
                            background: #10b981; color: white; padding: 12px 20px; border: none; border-radius: 8px;
                            text-decoration: none; font-weight: 600; transition: all 0.3s; display: inline-flex;
                            align-items: center; gap: 8px; font-size: 14px;">
                            <i class="fas fa-cash-register"></i> 💳 Record Payment
                        </a>
                        <a href="../billing/print.php?business_id=<?php echo $business['business_id']; ?>" style="
                            background: #4299e1; color: white; padding: 12px 20px; border: none; border-radius: 8px;
                            text-decoration: none; font-weight: 600; transition: all 0.3s; display: inline-flex;
                            align-items: center; gap: 8px; font-size: 14px;">
                            <i class="fas fa-print"></i> 🖨️ Print Bill
                        </a>
                    ` : `
                        <a href="../billing/print.php?business_id=<?php echo $business['business_id']; ?>" style="
                            background: #4299e1; color: white; padding: 12px 20px; border: none; border-radius: 8px;
                            text-decoration: none; font-weight: 600; transition: all 0.3s; display: inline-flex;
                            align-items: center; gap: 8px; font-size: 14px;">
                            <i class="fas fa-print"></i> 🖨️ Print Bill
                        </a>
                    `}
                    <button onclick="printBusinessDetails()" style="
                        background: #64748b; color: white; padding: 12px 20px; border: none; border-radius: 8px;
                        cursor: pointer; font-weight: 600; transition: all 0.3s; display: inline-flex;
                        align-items: center; gap: 8px; font-size: 14px;">
                        <i class="fas fa-print"></i> 🖨️ Print Details
                    </button>
                    <button onclick="this.closest('.balance-modal').remove()" style="
                        background: #94a3b8; color: white; padding: 12px 20px; border: none; border-radius: 8px;
                        cursor: pointer; font-weight: 600; transition: all 0.3s; display: inline-flex;
                        align-items: center; gap: 8px; font-size: 14px;">
                        <i class="fas fa-times"></i> ❌ Close
                    </button>
                </div>
            `;
            
            balanceModal.className = 'balance-modal';
            balanceModal.appendChild(balanceContent);
            document.body.appendChild(balanceModal);
            
            // Close modal when clicking backdrop
            balanceModal.addEventListener('click', function(e) {
                if (e.target === balanceModal) {
                    balanceModal.remove();
                }
            });
        }

        // Print functionality
        function printBusinessDetails() {
            const printWindow = window.open('', '_blank');
            const businessData = {
                name: businessName,
                account: accountNumber,
                owner: <?php echo json_encode($business['owner_name']); ?>,
                type: <?php echo json_encode($business['business_type']); ?>,
                category: <?php echo json_encode($business['category']); ?>,
                location: <?php echo json_encode($business['exact_location']); ?>,
                amountPayable: <?php echo $business['amount_payable']; ?>,
                remainingBalance: remainingBalance
            };
            
            printWindow.document.write(`
                <html>
                <head>
                    <title>Business Details - ${businessData.name}</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        .header { text-align: center; border-bottom: 2px solid #4299e1; padding-bottom: 20px; margin-bottom: 30px; }
                        .detail-row { display: flex; margin-bottom: 10px; }
                        .label { font-weight: bold; width: 150px; }
                        .value { flex: 1; }
                        .amount { font-size: 24px; color: #dc2626; font-weight: bold; }
                        .balance { font-size: 28px; color: ${businessData.remainingBalance > 0 ? '#dc2626' : '#059669'}; font-weight: bold; }
                        .balance-section { border: 2px solid ${businessData.remainingBalance > 0 ? '#fbbf24' : '#10b981'}; 
                                         background: ${businessData.remainingBalance > 0 ? '#fef3c7' : '#d1fae5'}; 
                                         padding: 20px; border-radius: 10px; margin: 20px 0; text-align: center; }
                        @media print { body { margin: 0; } }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <h1>🏢 Business Profile - Officer Portal</h1>
                        <h2>${businessData.name}</h2>
                        <p>Account Number: ${businessData.account}</p>
                    </div>
                    <div class="details">
                        <div class="detail-row">
                            <div class="label">Business Name:</div>
                            <div class="value">${businessData.name}</div>
                        </div>
                        <div class="detail-row">
                            <div class="label">Account Number:</div>
                            <div class="value">${businessData.account}</div>
                        </div>
                        <div class="detail-row">
                            <div class="label">Owner Name:</div>
                            <div class="value">${businessData.owner}</div>
                        </div>
                        <div class="detail-row">
                            <div class="label">Business Type:</div>
                            <div class="value">${businessData.type}</div>
                        </div>
                        <div class="detail-row">
                            <div class="label">Category:</div>
                            <div class="value">${businessData.category}</div>
                        </div>
                        <div class="detail-row">
                            <div class="label">Location:</div>
                            <div class="value">${businessData.location || 'Not provided'}</div>
                        </div>
                        <div class="detail-row" style="margin-top: 30px; padding-top: 20px; border-top: 2px solid #e2e8f0;">
                            <div class="label">Amount Payable:</div>
                            <div class="value amount">₵ ${businessData.amountPayable.toLocaleString('en-US', {minimumFractionDigits: 2})}</div>
                        </div>
                    </div>
                    <div class="balance-section">
                        <h3>${businessData.remainingBalance > 0 ? '⚠️ Outstanding Balance' : '✅ Account Status'}</h3>
                        <div class="balance">₵ ${businessData.remainingBalance.toLocaleString('en-US', {minimumFractionDigits: 2})}</div>
                        <p>${businessData.remainingBalance > 0 ? 'This amount needs to be paid to clear the account' : 'All bills have been fully settled'}</p>
                    </div>
                    <div style="margin-top: 50px; text-align: center; color: #64748b; font-size: 14px;">
                        Generated by Officer Portal on ${new Date().toLocaleDateString('en-US', {
                            year: 'numeric',
                            month: 'long',
                            day: 'numeric',
                            hour: '2-digit',
                            minute: '2-digit'
                        })}
                    </div>
                </body>
                </html>
            `);
            
            printWindow.document.close();
            setTimeout(() => {
                printWindow.print();
                printWindow.close();
            }, 500);
        }

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
                    @keyframes modalSlideIn {
                        from { transform: scale(0.8) translateY(-20px); opacity: 0; }
                        to { transform: scale(1) translateY(0); opacity: 1; }
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

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + P for print
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                printBusinessDetails();
            }
            
            // Ctrl/Cmd + B for balance info
            if ((e.ctrlKey || e.metaKey) && e.key === 'b') {
                e.preventDefault();
                showBalanceDetails();
            }
            
            // Escape to close modals
            if (e.key === 'Escape') {
                const modals = document.querySelectorAll('.balance-modal, .user-dropdown.show');
                modals.forEach(modal => {
                    if (modal.classList && modal.classList.contains('balance-modal')) {
                        modal.remove();
                    } else if (modal.classList && modal.classList.contains('show')) {
                        modal.classList.remove('show');
                    }
                });
            }
        });

        // Balance monitoring and alerts
        function checkBalanceAlerts() {
            const alerts = [];
            
            if (remainingBalance > 0) {
                if (remainingBalance > 1000) {
                    alerts.push({
                        type: 'danger',
                        message: `🚨 High outstanding balance: ₵ ${remainingBalance.toLocaleString('en-US', {minimumFractionDigits: 2})}`
                    });
                } else if (remainingBalance > 500) {
                    alerts.push({
                        type: 'warning', 
                        message: `⚠️ Moderate outstanding balance: ₵ ${remainingBalance.toLocaleString('en-US', {minimumFractionDigits: 2})}`
                    });
                } else {
                    alerts.push({
                        type: 'info',
                        message: `ℹ️ Low outstanding balance: ₵ ${remainingBalance.toLocaleString('en-US', {minimumFractionDigits: 2})}`
                    });
                }
            }
            
            return alerts;
        }

        // Display balance alerts on page load
        setTimeout(() => {
            const alerts = checkBalanceAlerts();
            alerts.forEach((alert, index) => {
                setTimeout(() => {
                    showNotification(alert.message, alert.type);
                }, index * 1000);
            });
        }, 3000);

        // Initialize map if coordinates are available
        <?php if ($business['latitude'] && $business['longitude']): ?>
        function initMap() {
            const businessLocation = {
                lat: <?php echo $business['latitude']; ?>,
                lng: <?php echo $business['longitude']; ?>
            };
            
            const map = new google.maps.Map(document.getElementById('map'), {
                zoom: 16,
                center: businessLocation,
                mapTypeId: 'satellite'
            });
            
            const marker = new google.maps.Marker({
                position: businessLocation,
                map: map,
                title: '<?php echo addslashes($business['business_name']); ?>',
                icon: {
                    url: 'https://maps.google.com/mapfiles/ms/icons/red-dot.png'
                }
            });
            
            const infoWindow = new google.maps.InfoWindow({
                content: `
                    <div style="padding: 10px;">
                        <h6 style="margin-bottom: 5px; color: #2d3748;">
                            <?php echo addslashes($business['business_name']); ?>
                        </h6>
                        <p style="margin-bottom: 5px; font-size: 12px; color: #718096;">
                            Owner: <?php echo addslashes($business['owner_name']); ?>
                        </p>
                        <p style="margin-bottom: 5px; font-size: 12px; color: #718096;">
                            Account: <?php echo addslashes($business['account_number']); ?>
                        </p>
                        <p style="margin-bottom: 0; font-size: 12px; font-weight: bold; color: ${remainingBalance > 0 ? '#dc2626' : '#059669'};">
                            Balance: ₵ ${remainingBalance.toLocaleString('en-US', {minimumFractionDigits: 2})}
                        </p>
                    </div>
                `
            });
            
            marker.addListener('click', function() {
                infoWindow.open(map, marker);
            });
        }
        
        // Initialize map when page loads
        document.addEventListener('DOMContentLoaded', function() {
            initMap();
        });
        <?php endif; ?>

        console.log('✅ Officer business profile page initialized successfully');
        console.log(`💰 Remaining Balance: ₵ ${remainingBalance.toLocaleString('en-US', {minimumFractionDigits: 2})}`);
    </script>
</body>
</html>