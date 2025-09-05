<?php
/**
 * Data Collector - View Property Profile
 * properties/view.php
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
    // Session expired (30 minutes)
    session_unset();
    session_destroy();
    setFlashMessage('error', 'Your session has expired. Please log in again.');
    header('Location: ../../index.php');
    exit();
}

$userDisplayName = getUserDisplayName($currentUser);

// Get property ID
$property_id = intval($_GET['id'] ?? 0);

if ($property_id <= 0) {
    setFlashMessage('error', 'Invalid property ID.');
    header('Location: index.php');
    exit();
}

try {
    $db = new Database();
    
    // Get property details with zone information
    $property = $db->fetchRow("
        SELECT 
            p.*,
            z.zone_name,
            u.first_name as creator_first_name,
            u.last_name as creator_last_name,
            CASE 
                WHEN p.amount_payable > 0 THEN 'Defaulter' 
                ELSE 'Up to Date' 
            END as payment_status
        FROM properties p
        LEFT JOIN zones z ON p.zone_id = z.zone_id
        LEFT JOIN users u ON p.created_by = u.user_id
        WHERE p.property_id = ?
    ", [$property_id]);
    
    if (!$property) {
        setFlashMessage('error', 'Property not found.');
        header('Location: index.php');
        exit();
    }
    
    // Get property bills
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
        WHERE bill_type = 'Property' AND reference_id = ?
        ORDER BY billing_year DESC, generated_at DESC
    ", [$property_id]);
    
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
        WHERE b.bill_type = 'Property' AND b.reference_id = ?
        ORDER BY p.payment_date DESC
        LIMIT 10
    ", [$property_id]);
    
    // Get fee structure info for this property
    $fee_info = $db->fetchRow("
        SELECT fee_per_room 
        FROM property_fee_structure 
        WHERE structure = ? AND property_use = ? AND is_active = 1
    ", [$property['structure'], $property['property_use']]);
    
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
    <title>View Property - <?php echo htmlspecialchars($property['owner_name']); ?> - <?php echo APP_NAME; ?></title>
    
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
        .icon-eye::before { content: "👁️"; }
        .icon-arrow-left::before { content: "⬅️"; }
        .icon-info-circle::before { content: "ℹ️"; }
        .icon-map-marker-alt::before { content: "📍"; }
        .icon-file-invoice::before { content: "📄"; }
        .icon-money-bill-wave::before { content: "💸"; }
        .icon-credit-card::before { content: "💳"; }
        .icon-user-plus::before { content: "👤➕"; }
        .icon-calculator::before { content: "🧮"; }
        
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
        
        .property-name {
            font-size: 28px;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 8px;
        }
        
        .property-subtitle {
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
        
        .badge-teal {
            background: #b2f5ea;
            color: #234e52;
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
        
        /* Bill Calculator Display */
        .bill-breakdown {
            background: #e6fffa;
            border: 2px solid #38b2ac;
            border-radius: 10px;
            padding: 15px;
            margin-top: 15px;
        }
        
        .breakdown-title {
            font-weight: bold;
            color: #234e52;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .breakdown-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
            padding: 6px 0;
            border-bottom: 1px solid rgba(56, 178, 172, 0.2);
        }
        
        .breakdown-row:last-child {
            border-bottom: none;
            font-weight: bold;
            background: rgba(56, 178, 172, 0.1);
            margin: 8px -10px -10px -10px;
            padding: 12px 10px;
            border-radius: 0 0 6px 6px;
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
                <i class="fas fa-clipboard-list"></i>
                <span class="icon-dashboard" style="display: none;"></span>
                Data Collector
            </a>
        </div>
        
        <div class="user-section">
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
                        <a href="../businesses/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-building"></i>
                                <span class="icon-building" style="display: none;"></span>
                            </span>
                            Businesses
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="index.php" class="nav-link active">
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
            <!-- Profile Header -->
            <div class="profile-header fade-in">
                <div class="profile-title">
                    <div class="title-left">
                        <h1 class="property-name"><?php echo htmlspecialchars($property['owner_name']); ?>'s Property</h1>
                        <p class="property-subtitle">
                            Property: <strong><?php echo htmlspecialchars($property['property_number']); ?></strong> 
                            | Structure: <strong><?php echo htmlspecialchars($property['structure']); ?></strong>
                            | Rooms: <strong><?php echo $property['number_of_rooms']; ?></strong>
                        </p>
                        
                        <div class="status-badges">
                            <span class="badge <?php echo $property['payment_status'] == 'Up to Date' ? 'badge-success' : 'badge-danger'; ?>">
                                <i class="fas fa-money-bill"></i>
                                <span class="icon-money-bill-wave" style="display: none;"></span>
                                <?php echo $property['payment_status']; ?>
                            </span>
                            <span class="badge <?php echo $property['property_use'] == 'Residential' ? 'badge-info' : 'badge-teal'; ?>">
                                <i class="fas fa-home"></i>
                                <span class="icon-home" style="display: none;"></span>
                                <?php echo $property['property_use']; ?>
                            </span>
                            <span class="badge badge-warning">
                                <i class="fas fa-building"></i>
                                <span class="icon-building" style="display: none;"></span>
                                <?php echo htmlspecialchars($property['structure']); ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="profile-actions">
                        <a href="edit.php?id=<?php echo $property['property_id']; ?>" class="btn btn-primary">
                            <i class="fas fa-edit"></i>
                            <span class="icon-edit" style="display: none;"></span>
                            Edit Property
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
            <div class="content-grid">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Basic Information -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-info-circle"></i>
                                <span class="icon-info-circle" style="display: none;"></span>
                                Property Information
                            </h3>
                        </div>
                        <div class="card-body">
                            <div class="info-row">
                                <div class="info-item">
                                    <div class="info-label">Property Number</div>
                                    <div class="info-value large"><?php echo htmlspecialchars($property['property_number']); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Owner Name</div>
                                    <div class="info-value large"><?php echo htmlspecialchars($property['owner_name']); ?></div>
                                </div>
                            </div>
                            
                            <div class="info-row">
                                <div class="info-item">
                                    <div class="info-label">Telephone</div>
                                    <div class="info-value">
                                        <?php echo $property['telephone'] ? htmlspecialchars($property['telephone']) : 'Not provided'; ?>
                                    </div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Gender</div>
                                    <div class="info-value">
                                        <?php echo $property['gender'] ? htmlspecialchars($property['gender']) : 'Not specified'; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="info-row">
                                <div class="info-item">
                                    <div class="info-label">Structure</div>
                                    <div class="info-value"><?php echo htmlspecialchars($property['structure']); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Property Use</div>
                                    <div class="info-value">
                                        <span class="badge <?php echo $property['property_use'] == 'Residential' ? 'badge-info' : 'badge-teal'; ?>">
                                            <?php echo $property['property_use']; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="info-row">
                                <div class="info-item">
                                    <div class="info-label">Ownership Type</div>
                                    <div class="info-value"><?php echo htmlspecialchars($property['ownership_type']); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Property Type</div>
                                    <div class="info-value"><?php echo htmlspecialchars($property['property_type']); ?></div>
                                </div>
                            </div>
                            
                            <div class="info-row">
                                <div class="info-item">
                                    <div class="info-label">Number of Rooms</div>
                                    <div class="info-value large"><?php echo $property['number_of_rooms']; ?> rooms</div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Batch</div>
                                    <div class="info-value">
                                        <?php echo $property['batch'] ? htmlspecialchars($property['batch']) : 'Not assigned'; ?>
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
                                <span class="icon-map-marker-alt" style="display: none;"></span>
                                Location Information
                            </h3>
                        </div>
                        <div class="card-body">
                            <div class="info-row">
                                <div class="info-item">
                                    <div class="info-label">Zone</div>
                                    <div class="info-value"><?php echo htmlspecialchars($property['zone_name'] ?? 'Not assigned'); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Coordinates</div>
                                    <div class="info-value"><?php echo $property['latitude']; ?>, <?php echo $property['longitude']; ?></div>
                                </div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label">Location</div>
                                <div class="info-value"><?php echo nl2br(htmlspecialchars($property['location'])); ?></div>
                            </div>
                            
                            <?php if ($property['latitude'] && $property['longitude']): ?>
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
                                Bills & Payments
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
                                                <th>Amount</th>
                                                <th>Status</th>
                                                <th>Generated</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($bills as $bill): ?>
                                                <tr>
                                                    <td><?php echo $bill['billing_year']; ?></td>
                                                    <td><?php echo htmlspecialchars($bill['bill_number']); ?></td>
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
                                    <p>No bills have been generated for this property yet.</p>
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
                                <span class="icon-money-bill-wave" style="display: none;"></span>
                                Financial Summary
                            </h3>
                        </div>
                        <div class="card-body">
                            <div class="info-item" style="margin-bottom: 15px;">
                                <div class="info-label">Old Bill</div>
                                <div class="info-value currency">₵ <?php echo number_format($property['old_bill'], 2); ?></div>
                            </div>
                            
                            <div class="info-item" style="margin-bottom: 15px;">
                                <div class="info-label">Previous Payments</div>
                                <div class="info-value currency">₵ <?php echo number_format($property['previous_payments'], 2); ?></div>
                            </div>
                            
                            <div class="info-item" style="margin-bottom: 15px;">
                                <div class="info-label">Arrears</div>
                                <div class="info-value currency">₵ <?php echo number_format($property['arrears'], 2); ?></div>
                            </div>
                            
                            <div class="info-item" style="margin-bottom: 15px;">
                                <div class="info-label">Current Bill</div>
                                <div class="info-value currency">₵ <?php echo number_format($property['current_bill'], 2); ?></div>
                            </div>
                            
                            <hr style="margin: 20px 0; border: 1px solid #e2e8f0;">
                            
                            <div class="info-item">
                                <div class="info-label">Total Amount Payable</div>
                                <div class="info-value large currency" style="font-size: 24px;">
                                    ₵ <?php echo number_format($property['amount_payable'], 2); ?>
                                </div>
                            </div>
                            
                            <!-- Bill Calculation Breakdown -->
                            <?php if ($fee_info): ?>
                                <div class="bill-breakdown">
                                    <div class="breakdown-title">
                                        <i class="fas fa-calculator"></i>
                                        <span class="icon-calculator" style="display: none;"></span>
                                        Bill Calculation
                                    </div>
                                    <div class="breakdown-row">
                                        <span>Fee per room:</span>
                                        <span>₵ <?php echo number_format($fee_info['fee_per_room'], 2); ?></span>
                                    </div>
                                    <div class="breakdown-row">
                                        <span>Number of rooms:</span>
                                        <span><?php echo $property['number_of_rooms']; ?></span>
                                    </div>
                                    <div class="breakdown-row">
                                        <span>Current Bill:</span>
                                        <span>₵ <?php echo number_format($property['current_bill'], 2); ?></span>
                                    </div>
                                </div>
                            <?php endif; ?>
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
                                <?php foreach ($payments as $payment): ?>
                                    <div style="border-bottom: 1px solid #e2e8f0; padding: 15px 0;">
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                                            <strong>₵ <?php echo number_format($payment['amount_paid'], 2); ?></strong>
                                            <span class="badge <?php echo $payment['payment_status'] == 'Successful' ? 'badge-success' : 'badge-danger'; ?>">
                                                <?php echo $payment['payment_status']; ?>
                                            </span>
                                        </div>
                                        <div style="font-size: 12px; color: #718096;">
                                            <?php echo htmlspecialchars($payment['payment_method']); ?> - 
                                            <?php echo date('M j, Y', strtotime($payment['payment_date'])); ?>
                                        </div>
                                        <div style="font-size: 12px; color: #718096;">
                                            Ref: <?php echo htmlspecialchars($payment['payment_reference']); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-credit-card"></i>
                                    <span class="icon-credit-card" style="display: none;"></span>
                                    <h4>No Payments</h4>
                                    <p>No payments recorded yet.</p>
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
                                    if ($property['creator_first_name']) {
                                        echo htmlspecialchars($property['creator_first_name'] . ' ' . $property['creator_last_name']);
                                    } else {
                                        echo 'System';
                                    }
                                    ?>
                                </div>
                            </div>
                            
                            <div class="info-item" style="margin-bottom: 15px;">
                                <div class="info-label">Registration Date</div>
                                <div class="info-value"><?php echo date('M j, Y g:i A', strtotime($property['created_at'])); ?></div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label">Last Updated</div>
                                <div class="info-value"><?php echo date('M j, Y g:i A', strtotime($property['updated_at'])); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
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

        // Initialize map if coordinates are available
        <?php if ($property['latitude'] && $property['longitude']): ?>
        function initMap() {
            const propertyLocation = {
                lat: <?php echo $property['latitude']; ?>,
                lng: <?php echo $property['longitude']; ?>
            };
            
            const map = new google.maps.Map(document.getElementById('map'), {
                zoom: 16,
                center: propertyLocation,
                mapTypeId: 'satellite'
            });
            
            const marker = new google.maps.Marker({
                position: propertyLocation,
                map: map,
                title: '<?php echo addslashes($property['owner_name'] . "'s Property"); ?>',
                icon: {
                    url: 'https://maps.google.com/mapfiles/ms/icons/blue-dot.png'
                }
            });
            
            const infoWindow = new google.maps.InfoWindow({
                content: `
                    <div style="padding: 10px;">
                        <h6 style="margin-bottom: 5px; color: #2d3748;">
                            <?php echo addslashes($property['owner_name']); ?>'s Property
                        </h6>
                        <p style="margin-bottom: 5px; font-size: 12px; color: #718096;">
                            Property: <?php echo addslashes($property['property_number']); ?>
                        </p>
                        <p style="margin-bottom: 5px; font-size: 12px; color: #718096;">
                            <?php echo addslashes($property['structure']); ?> - <?php echo $property['number_of_rooms']; ?> rooms
                        </p>
                        <p style="margin-bottom: 0; font-size: 12px; color: #718096;">
                            <?php echo addslashes($property['property_use']); ?>
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
    </script>
</body>
</html>