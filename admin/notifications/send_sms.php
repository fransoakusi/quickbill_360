<?php
/**
 * Send SMS Notification - Following Admin Structure
 * QUICKBILL 305 - Send individual SMS notifications
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

$currentUser = getCurrentUser();
$userDisplayName = getUserDisplayName($currentUser);

// Initialize database
$db = new Database();

// Handle form submission
$message = '';
$messageType = '';
$formData = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $recipientType = $_POST['recipient_type'] ?? '';
        $recipientId = (int)($_POST['recipient_id'] ?? 0);
        $customPhone = trim($_POST['custom_phone'] ?? '');
        $messageText = trim($_POST['message'] ?? '');
        
        // Validation
        $errors = [];
        
        if (empty($messageText)) {
            $errors[] = 'Message is required';
        }
        
        if (strlen($messageText) > 1600) {
            $errors[] = 'Message is too long (max 1600 characters)';
        }
        
        $phoneNumber = '';
        $recipientName = '';
        
        if (!empty($customPhone)) {
            // Use custom phone number
            $phoneNumber = preg_replace('/[^0-9+]/', '', $customPhone);
            $recipientName = 'Custom Number';
            $recipientType = 'User';
            $recipientId = 0;
        } else {
            // Use selected recipient
            if (empty($recipientType) || $recipientId <= 0) {
                $errors[] = 'Please select a recipient or enter a phone number';
            } else {
                // Get recipient details
                switch ($recipientType) {
                    case 'Business':
                        $recipient = $db->fetchRow("SELECT business_name as name, telephone as phone FROM businesses WHERE business_id = ?", [$recipientId]);
                        break;
                    case 'Property':
                        $recipient = $db->fetchRow("SELECT owner_name as name, telephone as phone FROM properties WHERE property_id = ?", [$recipientId]);
                        break;
                    case 'User':
                        $recipient = $db->fetchRow("SELECT CONCAT(first_name, ' ', last_name) as name, phone FROM users WHERE user_id = ?", [$recipientId]);
                        break;
                    default:
                        $errors[] = 'Invalid recipient type';
                }
                
                if (isset($recipient)) {
                    if (empty($recipient['phone'])) {
                        $errors[] = 'Recipient has no phone number on file';
                    } else {
                        $phoneNumber = preg_replace('/[^0-9+]/', '', $recipient['phone']);
                        $recipientName = $recipient['name'];
                    }
                } else {
                    $errors[] = 'Recipient not found';
                }
            }
        }
        
        // Validate phone number format
        if (!empty($phoneNumber)) {
            if (!preg_match('/^\+?[1-9]\d{1,14}$/', $phoneNumber)) {
                $errors[] = 'Invalid phone number format';
            }
            
            // Ensure Ghana format for local numbers
            if (!str_starts_with($phoneNumber, '+')) {
                if (str_starts_with($phoneNumber, '0')) {
                    $phoneNumber = '+233' . substr($phoneNumber, 1);
                } elseif (strlen($phoneNumber) === 9) {
                    $phoneNumber = '+233' . $phoneNumber;
                } else {
                    $phoneNumber = '+' . $phoneNumber;
                }
            }
        }
        
        if (empty($errors)) {
            // Save notification to database first
            $sql = "INSERT INTO notifications (recipient_type, recipient_id, notification_type, subject, message, status, sent_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $params = [
                $recipientType,
                $recipientId,
                'SMS',
                'SMS Notification',
                $messageText,
                'Pending',
                $currentUser['user_id'],
                date('Y-m-d H:i:s')
            ];
            $stmt = $db->execute($sql, $params);
            if ($stmt === false) {
                throw new Exception('Failed to insert notification record');
            }
            $notificationId = $db->lastInsertId();
            
            // Simulate SMS sending (replace with actual Twilio integration)
            $smsResult = ['success' => true]; // Simulate success for demo
            
            if ($smsResult['success']) {
                // Update notification status
                $sql = "UPDATE notifications SET status = ?, sent_at = ? WHERE notification_id = ?";
                $params = ['Sent', date('Y-m-d H:i:s'), $notificationId];
                $stmt = $db->execute($sql, $params);
                if ($stmt === false) {
                    throw new Exception('Failed to update notification status to Sent');
                }
                
                $message = "SMS sent successfully to {$recipientName} ({$phoneNumber})";
                $messageType = 'success';
                
                // Clear form data on success
                $formData = [];
            } else {
                // Update notification status to failed
                $sql = "UPDATE notifications SET status = ? WHERE notification_id = ?";
                $params = ['Failed', $notificationId];
                $stmt = $db->execute($sql, $params);
                if ($stmt === false) {
                    throw new Exception('Failed to update notification status to Failed');
                }
                
                $message = "Failed to send SMS: " . ($smsResult['error'] ?? 'Unknown error');
                $messageType = 'error';
            }
        } else {
            $message = implode('<br>', $errors);
            $messageType = 'error';
            
            // Keep form data for correction
            $formData = $_POST;
        }
        
    } catch (Exception $e) {
        $message = 'Error sending SMS: ' . $e->getMessage();
        $messageType = 'error';
        $formData = $_POST;
    }
}

// Get recipients for dropdowns
$businesses = [];
$properties = [];
$users = [];

try {
    $businesses = $db->fetchAll("
        SELECT business_id, business_name, account_number, telephone 
        FROM businesses 
        WHERE status = 'Active' AND telephone IS NOT NULL AND telephone != ''
        ORDER BY business_name 
        LIMIT 50
    ");
    
    $properties = $db->fetchAll("
        SELECT property_id, owner_name, property_number, telephone 
        FROM properties 
        WHERE telephone IS NOT NULL AND telephone != ''
        ORDER BY owner_name 
        LIMIT 50
    ");
    
    $users = $db->fetchAll("
        SELECT user_id, CONCAT(first_name, ' ', last_name) as name, username, phone 
        FROM users 
        WHERE is_active = 1 AND phone IS NOT NULL AND phone != ''
        ORDER BY first_name, last_name 
        LIMIT 50
    ");
} catch (Exception $e) {
    // Continue with empty arrays
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Send SMS - <?php echo APP_NAME; ?></title>
    
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
        .icon-bell::before { content: "🔔"; }
        .icon-sms::before { content: "💬"; }
        .icon-users::before { content: "👥"; }
        .icon-phone::before { content: "📞"; }
        .icon-send::before { content: "📤"; }
        .icon-back::before { content: "⬅️"; }
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
        .icon-plus::before { content: "➕"; }
        .icon-server::before { content: "🖥️"; }
        .icon-database::before { content: "💾"; }
        .icon-shield::before { content: "🛡️"; }
        .icon-user-plus::before { content: "👤➕"; }
        .icon-history::before { content: "📜"; }
        .icon-question::before { content: "❓"; }
        
        /* Same styling as admin dashboard */
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
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            padding: 8px 12px;
            border-radius: 10px;
            transition: all 0.3s;
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
        
        /* Cards */
        .card {
            background: white;
            border-radius: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
            border: none;
        }
        
        .card-header {
            padding: 20px 25px;
            background: #f7fafc;
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
        
        /* Form styles */
        .form-control, .form-select {
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            padding: 12px 15px;
            margin-bottom: 15px;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .form-label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }
        
        /* Action Buttons */
        .action-btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            text-align: center;
            text-decoration: none;
            display: inline-block;
            margin-right: 10px;
            margin-bottom: 10px;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            color: white;
            text-decoration: none;
        }
        
        .action-btn.success { background: #48bb78; }
        .action-btn.info { background: #4299e1; }
        .action-btn.warning { background: #ed8936; }
        .action-btn.secondary { background: #718096; }
        
        /* Alert styles */
        .alert {
            border-radius: 10px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 20px;
        }
        
        .alert.alert-success {
            background: #f0fff4;
            color: #276749;
            border-left: 4px solid #48bb78;
        }
        
        .alert.alert-danger {
            background: #fed7d7;
            color: #c53030;
            border-left: 4px solid #e53e3e;
        }
        
        .alert.alert-warning {
            background: #fef5e7;
            color: #dd6b20;
            border-left: 4px solid #ed8936;
        }
        
        .alert.alert-info {
            background: #ebf8ff;
            color: #3182ce;
            border-left: 4px solid #4299e1;
        }
        
        /* Recipient selection */
        .recipient-card {
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .recipient-card:hover {
            border-color: #667eea;
            background-color: #f7fafc;
        }
        
        .recipient-card.active {
            border-color: #667eea;
            background-color: #ebf4ff;
        }
        
        /* Character counter */
        .char-counter {
            text-align: right;
            margin-top: 8px;
            font-size: 14px;
            color: #718096;
        }
        
        .char-counter.warning {
            color: #ed8936;
        }
        
        .char-counter.danger {
            color: #e53e3e;
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
            
            .container {
                flex-direction: column;
            }
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
                        <a href="../fee_structure/business_fees.php" class="nav-link">
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
                        <a href="index.php" class="nav-link active">
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
        <div class="main-content" id="mainContent">
            <!-- Page Header -->
            <div style="margin-bottom: 30px;">
                <h1 style="font-size: 28px; font-weight: bold; color: #2d3748; margin-bottom: 8px;">
                    💬 Send SMS Notification
                </h1>
                <p style="color: #718096; font-size: 16px;">
                    Send individual SMS notifications to users, businesses, or properties.
                </p>
            </div>

            <!-- Flash Messages -->
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $messageType === 'error' ? 'danger' : $messageType; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <!-- Back Button -->
            <div style="margin-bottom: 20px;">
                <a href="index.php" class="action-btn secondary">
                    <i class="fas fa-arrow-left"></i>
                    <span class="icon-back" style="display: none;"></span>
                    Back to Notifications
                </a>
            </div>

            <form method="POST" id="smsForm">
                <div class="row">
                    <div class="col-lg-8">
                        <!-- Step 1: Select Recipient -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title">📱 Step 1: Select Recipient</h5>
                            </div>
                            <div class="card-body">
                                <!-- Recipient Type Selection -->
                                <div class="row">
                                    <div class="col-md-6">
                                        <label class="form-label">Choose Recipient Method</label>
                                        <div>
                                            <div class="recipient-card" onclick="selectMethod('database')">
                                                <input type="radio" name="recipient_method" value="database" id="method_database" 
                                                       <?php echo empty($formData['custom_phone']) ? 'checked' : ''; ?>>
                                                <label for="method_database" style="margin-left: 10px; cursor: pointer;">
                                                    <strong>Select from Database</strong><br>
                                                    <small style="color: #718096;">Choose from existing businesses, properties, or users</small>
                                                </label>
                                            </div>
                                            
                                            <div class="recipient-card" onclick="selectMethod('custom')">
                                                <input type="radio" name="recipient_method" value="custom" id="method_custom"
                                                       <?php echo !empty($formData['custom_phone']) ? 'checked' : ''; ?>>
                                                <label for="method_custom" style="margin-left: 10px; cursor: pointer;">
                                                    <strong>Custom Phone Number</strong><br>
                                                    <small style="color: #718096;">Enter any phone number manually</small>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <!-- Database Selection -->
                                        <div id="databaseSelection" style="<?php echo !empty($formData['custom_phone']) ? 'display: none;' : ''; ?>">
                                            <label class="form-label">Recipient Type</label>
                                            <select name="recipient_type" id="recipientType" class="form-select">
                                                <option value="">Select Type</option>
                                                <option value="Business" <?php echo ($formData['recipient_type'] ?? '') === 'Business' ? 'selected' : ''; ?>>Businesses</option>
                                                <option value="Property" <?php echo ($formData['recipient_type'] ?? '') === 'Property' ? 'selected' : ''; ?>>Properties</option>
                                                <option value="User" <?php echo ($formData['recipient_type'] ?? '') === 'User' ? 'selected' : ''; ?>>Users</option>
                                            </select>
                                            
                                            <label class="form-label">Select Recipient</label>
                                            <select name="recipient_id" id="recipientSelect" class="form-select">
                                                <option value="">First select a type</option>
                                            </select>
                                        </div>
                                        
                                        <!-- Custom Phone -->
                                        <div id="customPhoneSection" style="<?php echo empty($formData['custom_phone']) ? 'display: none;' : ''; ?>">
                                            <label class="form-label">Phone Number</label>
                                            <input type="tel" name="custom_phone" class="form-control" 
                                                   placeholder="e.g., +233245123456 or 0245123456"
                                                   value="<?php echo htmlspecialchars($formData['custom_phone'] ?? ''); ?>">
                                            <small style="color: #718096;">Enter phone number with country code or Ghana local format</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Step 2: Compose Message -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title">✍️ Step 2: Compose Message</h5>
                            </div>
                            <div class="card-body">
                                <label class="form-label">Message Text *</label>
                                <textarea name="message" id="messageText" class="form-control" rows="6" 
                                          placeholder="Type your SMS message here..." required><?php echo htmlspecialchars($formData['message'] ?? ''); ?></textarea>
                                <div class="char-counter" id="charCounter">
                                    <span id="charCount">0</span> / 160 characters
                                    <div id="smsPartsInfo" style="font-size: 12px; color: #718096;"></div>
                                </div>

                                <div class="alert alert-info" style="margin-top: 15px;">
                                    <strong>📋 SMS Guidelines:</strong>
                                    <ul style="margin: 10px 0 0 20px;">
                                        <li>SMS messages up to 160 characters count as 1 SMS</li>
                                        <li>Longer messages will be split into multiple parts</li>
                                        <li>Keep messages clear and include sender identification</li>
                                        <li>Avoid special characters that may not display properly</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="card">
                            <div class="card-body">
                                <button type="submit" class="action-btn success">
                                    <i class="fas fa-paper-plane"></i>
                                    <span class="icon-send" style="display: none;"></span>
                                    Send SMS
                                </button>
                                <button type="reset" class="action-btn warning">
                                    <i class="fas fa-undo"></i>
                                    Reset Form
                                </button>
                                <a href="index.php" class="action-btn secondary">
                                    <i class="fas fa-times"></i>
                                    Cancel
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Preview Sidebar -->
                    <div class="col-lg-4">
                        <div class="card" style="position: sticky; top: 2rem;">
                            <div class="card-header">
                                <h5 class="card-title">📱 Message Preview</h5>
                            </div>
                            <div class="card-body">
                                <div style="text-align: center; margin-bottom: 20px;">
                                    <div style="
                                        width: 200px;
                                        height: 320px;
                                        border: 8px solid #333;
                                        border-radius: 25px;
                                        background: #000;
                                        margin: 0 auto;
                                        padding: 20px 15px;
                                        position: relative;
                                    ">
                                        <div style="
                                            background: #1a1a1a;
                                            color: #00ff00;
                                            padding: 10px;
                                            border-radius: 10px;
                                            font-size: 11px;
                                            font-family: monospace;
                                            min-height: 100px;
                                            word-wrap: break-word;
                                        " id="phonePreview">
                                            Your message will appear here...
                                        </div>
                                        <div style="text-align: center; margin-top: 10px; color: #666; font-size: 10px;">
                                            SMS Preview
                                        </div>
                                    </div>
                                </div>

                                <div id="previewStats" style="display: none;">
                                    <div class="row" style="text-align: center;">
                                        <div class="col-6">
                                            <div style="font-size: 12px; color: #718096;">Characters</div>
                                            <div style="font-weight: bold;" id="previewCharCount">0</div>
                                        </div>
                                        <div class="col-6">
                                            <div style="font-size: 12px; color: #718096;">SMS Parts</div>
                                            <div style="font-weight: bold;" id="previewSmsCount">1</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
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

        // Character counter
        function updateCharCounter() {
            const message = document.getElementById('messageText').value;
            const length = message.length;
            const counter = document.getElementById('charCounter');
            const charCount = document.getElementById('charCount');
            const smsPartsInfo = document.getElementById('smsPartsInfo');
            
            charCount.textContent = length;
            
            // Update preview
            document.getElementById('phonePreview').textContent = message || 'Your message will appear here...';
            document.getElementById('previewCharCount').textContent = length;
            
            if (length === 0) {
                counter.className = 'char-counter';
                smsPartsInfo.textContent = '';
                document.getElementById('previewStats').style.display = 'none';
                document.getElementById('previewSmsCount').textContent = '1';
            } else {
                document.getElementById('previewStats').style.display = 'block';
                
                if (length <= 160) {
                    counter.className = 'char-counter';
                    smsPartsInfo.textContent = '1 SMS';
                    document.getElementById('previewSmsCount').textContent = '1';
                } else if (length <= 320) {
                    counter.className = 'char-counter warning';
                    smsPartsInfo.textContent = '2 SMS parts';
                    document.getElementById('previewSmsCount').textContent = '2';
                } else {
                    const parts = Math.ceil(length / 153);
                    counter.className = 'char-counter danger';
                    smsPartsInfo.textContent = parts + ' SMS parts';
                    document.getElementById('previewSmsCount').textContent = parts;
                }
            }
        }

        // Initialize character counter
        document.getElementById('messageText').addEventListener('input', updateCharCounter);
        updateCharCounter();

        // Recipient method selection
        function selectMethod(method) {
            const databaseSection = document.getElementById('databaseSelection');
            const customSection = document.getElementById('customPhoneSection');
            
            if (method === 'database') {
                document.getElementById('method_database').checked = true;
                databaseSection.style.display = 'block';
                customSection.style.display = 'none';
                updateRecipientCards();
            } else {
                document.getElementById('method_custom').checked = true;
                databaseSection.style.display = 'none';
                customSection.style.display = 'block';
                updateRecipientCards();
            }
        }

        // Update recipient card styling
        function updateRecipientCards() {
            const cards = document.querySelectorAll('.recipient-card');
            cards.forEach(card => {
                const radio = card.querySelector('input[type="radio"]');
                if (radio && radio.checked) {
                    card.classList.add('active');
                } else {
                    card.classList.remove('active');
                }
            });
        }

        // Recipient type change
        document.getElementById('recipientType').addEventListener('change', function() {
            const type = this.value;
            const select = document.getElementById('recipientSelect');
            
            if (!type) {
                select.innerHTML = '<option value="">First select a type</option>';
                return;
            }
            
            select.innerHTML = '<option value="">Loading...</option>';
            
            // Populate based on type
            let options = '<option value="">Select ' + type.toLowerCase() + '...</option>';
            
            if (type === 'Business') {
                <?php foreach ($businesses as $business): ?>
                options += '<option value="<?php echo $business['business_id']; ?>"><?php echo htmlspecialchars($business['business_name']); ?> (<?php echo htmlspecialchars($business['account_number']); ?>)</option>';
                <?php endforeach; ?>
            } else if (type === 'Property') {
                <?php foreach ($properties as $property): ?>
                options += '<option value="<?php echo $property['property_id']; ?>"><?php echo htmlspecialchars($property['owner_name']); ?> (<?php echo htmlspecialchars($property['property_number']); ?>)</option>';
                <?php endforeach; ?>
            } else if (type === 'User') {
                <?php foreach ($users as $user): ?>
                options += '<option value="<?php echo $user['user_id']; ?>"><?php echo htmlspecialchars($user['name']); ?> (<?php echo htmlspecialchars($user['username']); ?>)</option>';
                <?php endforeach; ?>
            }
            
            select.innerHTML = options;
        });

        // Initialize recipient cards
        updateRecipientCards();

        // Mobile sidebar toggle
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth <= 768) {
                sidebar.classList.remove('hidden');
            }
        });
    </script>
</body>
</html>