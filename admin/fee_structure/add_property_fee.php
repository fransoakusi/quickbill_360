 <?php
/**
 * Fee Structure Management - Add Property Fee
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
if (!hasPermission('fee_structure.create')) {
    setFlashMessage('error', 'Access denied. You do not have permission to add property fees.');
    header('Location: property_fees.php');
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

$pageTitle = 'Add Property Fee';
$currentUser = getCurrentUser();
$userDisplayName = getUserDisplayName($currentUser);

// Initialize variables
$errors = [];
$formData = [
    'structure' => '',
    'property_use' => '',
    'fee_per_room' => '',
    'is_active' => 1
];

try {
    $db = new Database();
    
    // Get existing property structures for reference
    $existingStructures = $db->fetchAll("
        SELECT DISTINCT structure, 
               COUNT(*) as use_count,
               GROUP_CONCAT(property_use ORDER BY property_use SEPARATOR ', ') as uses
        FROM property_fee_structure 
        GROUP BY structure 
        ORDER BY structure ASC
    ");
    
    // Process form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Validate and sanitize input
        $formData['structure'] = sanitizeInput($_POST['structure'] ?? '');
        $formData['property_use'] = sanitizeInput($_POST['property_use'] ?? '');
        $formData['fee_per_room'] = sanitizeInput($_POST['fee_per_room'] ?? '');
        $formData['is_active'] = isset($_POST['is_active']) ? 1 : 0;
        
        // Validation
        if (empty($formData['structure'])) {
            $errors[] = 'Property structure is required.';
        } elseif (strlen($formData['structure']) < 2) {
            $errors[] = 'Property structure must be at least 2 characters long.';
        } elseif (strlen($formData['structure']) > 100) {
            $errors[] = 'Property structure cannot exceed 100 characters.';
        }
        
        if (empty($formData['property_use'])) {
            $errors[] = 'Property use is required.';
        } elseif (!in_array($formData['property_use'], ['Commercial', 'Residential'])) {
            $errors[] = 'Property use must be either Commercial or Residential.';
        }
        
        if (empty($formData['fee_per_room'])) {
            $errors[] = 'Fee per room is required.';
        } elseif (!is_numeric($formData['fee_per_room'])) {
            $errors[] = 'Fee per room must be a valid number.';
        } elseif (floatval($formData['fee_per_room']) < 0) {
            $errors[] = 'Fee per room cannot be negative.';
        } elseif (floatval($formData['fee_per_room']) > 999999.99) {
            $errors[] = 'Fee per room cannot exceed 999,999.99.';
        }
        
        // Check if structure and property use combination already exists
        if (empty($errors)) {
            $existingFee = $db->fetchRow("
                SELECT fee_id FROM property_fee_structure 
                WHERE structure = ? AND property_use = ?
            ", [$formData['structure'], $formData['property_use']]);
            
            if ($existingFee) {
                $errors[] = 'A fee structure for this property structure and use combination already exists.';
            }
        }
        
        // If no errors, save the property fee
        if (empty($errors)) {
            try {
                $db->beginTransaction();
                
                // Insert property fee
                $query = "INSERT INTO property_fee_structure 
                          (structure, property_use, fee_per_room, is_active, created_by, created_at, updated_at) 
                          VALUES (?, ?, ?, ?, ?, NOW(), NOW())";
                
                $params = [
                    $formData['structure'],
                    $formData['property_use'],
                    floatval($formData['fee_per_room']),
                    $formData['is_active'],
                    $currentUser['user_id']
                ];
                
                // Execute insert
                $db->execute($query, $params);
                
                // Log the action
                $statusText = $formData['is_active'] ? 'active' : 'inactive';
                writeLog("Property fee created: {$formData['structure']} - {$formData['property_use']} (GHS {$formData['fee_per_room']}/room) as {$statusText} by user {$currentUser['username']}", 'INFO');
                
                $db->commit();
                
                setFlashMessage('success', 'Property fee structure created successfully!');
                header('Location: property_fees.php');
                exit();
                
            } catch (Exception $e) {
                $db->rollback();
                writeLog("Error creating property fee: " . $e->getMessage(), 'ERROR');
                $errors[] = 'An error occurred while creating the property fee. Please try again.';
            }
        }
    }
    
} catch (Exception $e) {
    writeLog("Property fee add page error: " . $e->getMessage(), 'ERROR');
    $errors[] = 'An error occurred while loading the page. Please try again.';
}
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
        .icon-money::before { content: "💰"; }
        .icon-info::before { content: "ℹ️"; }
        .icon-structure::before { content: "🏗️"; }
        .icon-use::before { content: "📋"; }
        
        /* Same navigation and layout styles as previous pages */
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
        
        /* User Dropdown - Same as previous pages */
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
        
        /* Sidebar - Same as previous pages */
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
        }
        
        .page-title {
            font-size: 28px;
            font-weight: bold;
            color: #2d3748;
            margin: 0;
        }
        
        .back-btn {
            background: #64748b;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }
        
        .back-btn:hover {
            background: #475569;
            transform: translateY(-2px);
            color: white;
        }
        
        /* Form Styles */
        .form-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .form-section {
            margin-bottom: 40px;
        }
        
        .form-section:last-child {
            margin-bottom: 0;
        }
        
        .section-title {
            font-size: 20px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-icon {
            width: 35px;
            height: 35px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-row.single {
            grid-template-columns: 1fr;
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
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .required {
            color: #e53e3e;
        }
        
        .form-control {
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
            background: white;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #10b981;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
        }
        
        .form-control:invalid {
            border-color: #e53e3e;
        }
        
        .form-control.error {
            border-color: #e53e3e;
            background: #fef2f2;
        }
        
        .form-help {
            font-size: 12px;
            color: #64748b;
            margin-top: 5px;
        }
        
        .currency-input {
            position: relative;
        }
        
        .currency-symbol {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-weight: 600;
            pointer-events: none;
        }
        
        .currency-input .form-control {
            padding-left: 50px;
        }
        
        .per-room-text {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-size: 12px;
            pointer-events: none;
        }
        
        .currency-input .form-control {
            padding-right: 80px;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 15px;
        }
        
        .checkbox-input {
            width: 18px;
            height: 18px;
            accent-color: #10b981;
        }
        
        .checkbox-label {
            font-size: 14px;
            color: #2d3748;
            cursor: pointer;
        }
        
        /* Existing Structures Reference */
        .reference-card {
            background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
            border: 1px solid #bbf7d0;
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .reference-title {
            font-weight: 600;
            color: #064e3b;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .structure-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
        }
        
        .structure-item {
            background: white;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #bbf7d0;
        }
        
        .structure-name {
            font-weight: 600;
            color: #064e3b;
            font-size: 14px;
            margin-bottom: 8px;
        }
        
        .structure-uses {
            font-size: 12px;
            color: #065f46;
            line-height: 1.4;
        }
        
        .use-count {
            font-size: 12px;
            color: #10b981;
            font-weight: 600;
            background: rgba(16, 185, 129, 0.1);
            padding: 2px 6px;
            border-radius: 4px;
            margin-left: 5px;
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
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.3);
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
        
        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            padding-top: 20px;
            border-top: 2px solid #e2e8f0;
            margin-top: 30px;
        }
        
        /* Tips Card */
        .tips-card {
            background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
            border: 1px solid #bbf7d0;
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .tips-title {
            font-weight: 600;
            color: #064e3b;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .tips-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .tips-list li {
            padding: 8px 0;
            border-bottom: 1px solid #bbf7d0;
            color: #065f46;
            font-size: 14px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }
        
        .tips-list li:last-child {
            border-bottom: none;
        }
        
        .tips-icon {
            color: #10b981;
            margin-top: 2px;
            flex-shrink: 0;
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
            
            .form-row {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .header-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .structure-list {
                grid-template-columns: 1fr;
            }
        }
        
        /* Animations */
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
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
                <button style="
                    background: rgba(255,255,255,0.2);
                    border: none;
                    color: white;
                    font-size: 18px;
                    padding: 10px;
                    border-radius: 50%;
                    cursor: pointer;
                    transition: all 0.3s;
                " onmouseover="this.style.background='rgba(255,255,255,0.3)'" 
                   onmouseout="this.style.background='rgba(255,255,255,0.2)'"
                   onclick="showComingSoon('Notifications')">
                    <i class="fas fa-bell"></i>
                    <span class="icon-bell" style="display: none;"></span>
                </button>
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
                        <a href="#" class="dropdown-item" onclick="showComingSoon('User Profile')">
                            <i class="fas fa-user"></i>
                            <span class="icon-user" style="display: none;"></span>
                            My Profile
                        </a>
                        <a href="#" class="dropdown-item" onclick="showComingSoon('Account Settings')">
                            <i class="fas fa-cog"></i>
                            <span class="icon-cog" style="display: none;"></span>
                            Account Settings
                        </a>
                        <a href="#" class="dropdown-item" onclick="showComingSoon('Activity Log')">
                            <i class="fas fa-history"></i>
                            <span class="icon-chart" style="display: none;"></span>
                            Activity Log
                        </a>
                        <a href="#" class="dropdown-item" onclick="showComingSoon('Help & Support')">
                            <i class="fas fa-question-circle"></i>
                            <span class="icon-bell" style="display: none;"></span>
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
                        <a href="#" class="nav-link" onclick="showComingSoon('Properties')">
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
                        <a href="#" class="nav-link" onclick="showComingSoon('Billing')">
                            <span class="nav-icon">
                                <i class="fas fa-file-invoice"></i>
                                <span class="icon-invoice" style="display: none;"></span>
                            </span>
                            Billing
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="#" class="nav-link" onclick="showComingSoon('Payments')">
                            <span class="nav-icon">
                                <i class="fas fa-credit-card"></i>
                                <span class="icon-credit" style="display: none;"></span>
                            </span>
                            Payments
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="property_fees.php" class="nav-link active">
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
                        <a href="#" class="nav-link" onclick="showComingSoon('Reports')">
                            <span class="nav-icon">
                                <i class="fas fa-chart-bar"></i>
                                <span class="icon-chart" style="display: none;"></span>
                            </span>
                            Reports
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="#" class="nav-link" onclick="showComingSoon('Notifications')">
                            <span class="nav-icon">
                                <i class="fas fa-bell"></i>
                                <span class="icon-bell" style="display: none;"></span>
                            </span>
                            Notifications
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="#" class="nav-link" onclick="showComingSoon('Settings')">
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
                    <a href="property_fees.php">Property Fees</a>
                    <span>/</span>
                    <span class="breadcrumb-current">Add Property Fee</span>
                </div>
            </div>

            <!-- Flash Messages -->
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <strong>Please fix the following errors:</strong>
                        <ul style="margin: 10px 0 0 0; padding-left: 20px;">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Page Header -->
            <div class="page-header">
                <div class="header-content">
                    <div>
                        <h1 class="page-title">
                            <i class="fas fa-plus-circle"></i>
                            Add Property Fee Structure
                        </h1>
                        <p style="color: #64748b; margin: 5px 0 0 0;">Create a new billing rate for a property structure and use</p>
                    </div>
                    <a href="property_fees.php" class="back-btn">
                        <i class="fas fa-arrow-left"></i>
                        Back to Property Fees
                    </a>
                </div>
            </div>

            <!-- Property Fee Creation Form -->
            <form method="POST" action="" id="propertyFeeForm">
                <!-- Property Fee Information -->
                <div class="form-card">
                    <div class="form-section">
                        <h3 class="section-title">
                            <div class="section-icon">
                                <i class="fas fa-home"></i>
                                <span class="icon-home" style="display: none;"></span>
                            </div>
                            Fee Structure Details
                        </h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-hammer"></i>
                                    <span class="icon-structure" style="display: none;"></span>
                                    Property Structure <span class="required">*</span>
                                </label>
                                <input type="text" name="structure" id="structure" class="form-control" 
                                       value="<?php echo htmlspecialchars($formData['structure']); ?>" 
                                       placeholder="Enter property structure (e.g., Concrete Block, Mud Block, Modern Building)" 
                                       maxlength="100" required list="existingStructures">
                                <datalist id="existingStructures">
                                    <?php foreach ($existingStructures as $structure): ?>
                                        <option value="<?php echo htmlspecialchars($structure['structure']); ?>">
                                    <?php endforeach; ?>
                                </datalist>
                                <div class="form-help">The building material or construction type (e.g., Concrete Block, Mud Block, Modern Building)</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-clipboard-list"></i>
                                    <span class="icon-use" style="display: none;"></span>
                                    Property Use <span class="required">*</span>
                                </label>
                                <select name="property_use" id="propertyUse" class="form-control" required>
                                    <option value="">Select property use</option>
                                    <option value="Residential" <?php echo $formData['property_use'] === 'Residential' ? 'selected' : ''; ?>>Residential</option>
                                    <option value="Commercial" <?php echo $formData['property_use'] === 'Commercial' ? 'selected' : ''; ?>>Commercial</option>
                                </select>
                                <div class="form-help">The primary use of the property (Residential or Commercial)</div>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-money-bill-wave"></i>
                                    <span class="icon-money" style="display: none;"></span>
                                    Fee Per Room <span class="required">*</span>
                                </label>
                                <div class="currency-input">
                                    <span class="currency-symbol">GHS</span>
                                    <input type="number" name="fee_per_room" id="feePerRoom" class="form-control" 
                                           value="<?php echo htmlspecialchars($formData['fee_per_room']); ?>" 
                                           placeholder="0.00" 
                                           min="0" max="999999.99" step="0.01" required>
                                    <span class="per-room-text">per room</span>
                                </div>
                                <div class="form-help">Annual billing amount per room for this property structure and use</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-toggle-on"></i>
                                    Status
                                </label>
                                <div class="checkbox-group">
                                    <input type="checkbox" name="is_active" id="isActive" class="checkbox-input" 
                                           <?php echo $formData['is_active'] ? 'checked' : ''; ?>>
                                    <label for="isActive" class="checkbox-label">
                                        Set as active (properties can be assigned this fee structure)
                                    </label>
                                </div>
                                <div class="form-help">Active fee structures are available for new property registrations</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Existing Property Structures Reference -->
                <?php if (!empty($existingStructures)): ?>
                    <div class="form-card">
                        <div class="reference-card">
                            <div class="reference-title">
                                <i class="fas fa-list"></i>
                                Existing Property Structures
                            </div>
                            <div class="structure-list">
                                <?php foreach ($existingStructures as $structure): ?>
                                    <div class="structure-item">
                                        <div class="structure-name">
                                            <?php echo htmlspecialchars($structure['structure']); ?>
                                            <span class="use-count"><?php echo $structure['use_count']; ?> use<?php echo $structure['use_count'] != 1 ? 's' : ''; ?></span>
                                        </div>
                                        <div class="structure-uses">
                                            <?php echo htmlspecialchars($structure['uses']); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Property Fee Tips -->
                <div class="form-card">
                    <div class="tips-card">
                        <div class="tips-title">
                            <i class="fas fa-lightbulb"></i>
                            Property Fee Structure Tips
                        </div>
                        <ul class="tips-list">
                            <li>
                                <span class="tips-icon">
                                    <i class="fas fa-check-circle"></i>
                                </span>
                                <span>Use standard structure names (e.g., "Concrete Block" not "Concrete") for consistency</span>
                            </li>
                            <li>
                                <span class="tips-icon">
                                    <i class="fas fa-check-circle"></i>
                                </span>
                                <span>Property use must be either "Residential" or "Commercial" as per system design</span>
                            </li>
                            <li>
                                <span class="tips-icon">
                                    <i class="fas fa-check-circle"></i>
                                </span>
                                <span>Fee per room determines the total property bill (fee × number of rooms)</span>
                            </li>
                            <li>
                                <span class="tips-icon">
                                    <i class="fas fa-check-circle"></i>
                                </span>
                                <span>Each structure and property use combination must be unique</span>
                            </li>
                            <li>
                                <span class="tips-icon">
                                    <i class="fas fa-check-circle"></i>
                                </span>
                                <span>Commercial properties typically have higher fees than residential properties</span>
                            </li>
                            <li>
                                <span class="tips-icon">
                                    <i class="fas fa-check-circle"></i>
                                </span>
                                <span>Inactive fee structures won't appear in property registration forms</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="form-card">
                    <div class="form-actions">
                        <a href="property_fees.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i>
                            Cancel
                        </a>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save"></i>
                            <span class="icon-save" style="display: none;"></span>
                            Create Fee Structure
                        </button>
                    </div>
                </div>
            </form>
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
            
            // Initialize form
            initializeForm();
        });

        // Initialize form functionality
        function initializeForm() {
            // Set up form validation
            setupFormValidation();
            
            // Set up currency formatting
            setupCurrencyFormatting();
        }

        // Form validation
        function setupFormValidation() {
            const form = document.getElementById('propertyFeeForm');
            
            form.addEventListener('submit', function(e) {
                const structure = document.getElementById('structure').value.trim();
                const propertyUse = document.getElementById('propertyUse').value.trim();
                const feePerRoom = document.getElementById('feePerRoom').value.trim();
                
                let isValid = true;
                const errors = [];
                
                // Validate structure
                if (!structure) {
                    errors.push('Property structure is required.');
                    isValid = false;
                } else if (structure.length < 2) {
                    errors.push('Property structure must be at least 2 characters long.');
                    isValid = false;
                }
                
                // Validate property use
                if (!propertyUse) {
                    errors.push('Property use is required.');
                    isValid = false;
                } else if (!['Commercial', 'Residential'].includes(propertyUse)) {
                    errors.push('Property use must be either Commercial or Residential.');
                    isValid = false;
                }
                
                // Validate fee per room
                if (!feePerRoom) {
                    errors.push('Fee per room is required.');
                    isValid = false;
                } else if (isNaN(feePerRoom) || parseFloat(feePerRoom) < 0) {
                    errors.push('Fee per room must be a valid positive number.');
                    isValid = false;
                } else if (parseFloat(feePerRoom) > 999999.99) {
                    errors.push('Fee per room cannot exceed 999,999.99.');
                    isValid = false;
                }
                
                if (!isValid) {
                    e.preventDefault();
                    showValidationErrors(errors);
                    return false;
                }
            });
        }

        // Currency formatting
        function setupCurrencyFormatting() {
            const feePerRoom = document.getElementById('feePerRoom');
            
            feePerRoom.addEventListener('input', function() {
                let value = this.value;
                
                // Remove any non-numeric characters except decimal point
                value = value.replace(/[^0-9.]/g, '');
                
                // Ensure only one decimal point
                const parts = value.split('.');
                if (parts.length > 2) {
                    value = parts[0] + '.' + parts.slice(1).join('');
                }
                
                // Limit to 2 decimal places
                if (parts[1] && parts[1].length > 2) {
                    value = parts[0] + '.' + parts[1].substring(0, 2);
                }
                
                this.value = value;
            });
            
            feePerRoom.addEventListener('blur', function() {
                if (this.value && !isNaN(this.value)) {
                    this.value = parseFloat(this.value).toFixed(2);
                }
            });
        }

        // Show validation errors
        function showValidationErrors(errors) {
            const errorHtml = errors.map(error => `<li>${error}</li>`).join('');
            const alertHtml = `
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <strong>Please fix the following errors:</strong>
                        <ul style="margin: 10px 0 0 0; padding-left: 20px;">
                            ${errorHtml}
                        </ul>
                    </div>
                </div>
            `;
            
            // Remove existing alerts
            const existingAlerts = document.querySelectorAll('.alert');
            existingAlerts.forEach(alert => alert.remove());
            
            // Insert new alert at the top of main content
            const mainContent = document.querySelector('.main-content');
            const breadcrumb = document.querySelector('.breadcrumb-nav');
            breadcrumb.insertAdjacentHTML('afterend', alertHtml);
            
            // Scroll to top to show errors
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

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

        // Coming soon modal
        function showComingSoon(feature) {
            const backdrop = document.createElement('div');
            backdrop.style.cssText = `
                position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(0,0,0,0.6); backdrop-filter: blur(8px); z-index: 10000;
                display: flex; align-items: center; justify-content: center;
                animation: fadeIn 0.3s ease; cursor: pointer;
            `;
            
            const modal = document.createElement('div');
            modal.style.cssText = `
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white; padding: 50px 40px; border-radius: 25px; text-align: center;
                box-shadow: 0 25px 80px rgba(0,0,0,0.4); max-width: 450px; width: 90%;
                animation: modalSlideIn 0.4s ease; cursor: default; position: relative; overflow: hidden;
            `;
            
            modal.innerHTML = `
                <div style="position: absolute; top: -50%; right: -50%; width: 200%; height: 200%;
                    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
                    animation: rotate 20s linear infinite; pointer-events: none;"></div>
                
                <div style="position: relative; z-index: 2;">
                    <div style="width: 80px; height: 80px; background: rgba(255,255,255,0.2);
                        border-radius: 50%; display: flex; align-items: center; justify-content: center;
                        margin: 0 auto 25px; animation: bounce 2s ease-in-out infinite;">
                        <i class="fas fa-rocket" style="font-size: 2.5rem; color: white;"></i>
                        <span style="font-size: 2.5rem; display: none;">🚀</span>
                    </div>
                    
                    <h3 style="margin: 0 0 15px 0; font-weight: 700; font-size: 1.8rem;
                        text-shadow: 0 2px 4px rgba(0,0,0,0.3);">${feature}</h3>
                    
                    <p style="margin: 0 0 30px 0; opacity: 0.9; font-size: 1.1rem; line-height: 1.6;">
                        This amazing feature is coming soon! 🎉<br>We're working hard to bring you the best experience.</p>
                    
                    <button onclick="closeModal()" style="background: rgba(255,255,255,0.2);
                        border: 2px solid rgba(255,255,255,0.3); color: white; padding: 12px 30px;
                        border-radius: 25px; cursor: pointer; font-weight: 600; font-size: 1rem;
                        transition: all 0.3s ease; backdrop-filter: blur(10px);">
                        Awesome! Let's Go 🚀
                    </button>
                    
                    <div style="margin-top: 20px; font-size: 0.9rem; opacity: 0.7;">
                        Click anywhere outside to close</div>
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
                    @keyframes bounce { 0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
                        40% { transform: translateY(-10px); } 60% { transform: translateY(-5px); } }
                    @keyframes rotate { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
                    @keyframes fadeOut { from { opacity: 1; } to { opacity: 0; } }
                    @keyframes modalSlideOut { from { transform: translateY(0) scale(1); opacity: 1; }
                        to { transform: translateY(-30px) scale(0.9); opacity: 0; } }
                `;
                document.head.appendChild(style);
            }
            
            window.closeModal = function() {
                backdrop.style.animation = 'fadeOut 0.3s ease forwards';
                modal.style.animation = 'modalSlideOut 0.3s ease forwards';
                setTimeout(() => backdrop.remove(), 300);
            };
            
            backdrop.addEventListener('click', function(e) {
                if (e.target === backdrop) closeModal();
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
