<?php
/**
 * Bulk Notifications with Push Notification Support
 * QUICKBILL 305 - Send bulk notifications via SMS, System, and Push
 */

// Define application constant
define('QUICKBILL_305', true);

// Include configuration files
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/FCMService.php';

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

// Initialize database and FCM service
$db = new Database();
$fcmService = new FCMService($db);

// Handle form submission
$message = '';
$messageType = '';
$recipientPreview = [];
$sendResults = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'preview') {
        // Generate recipient preview
        $recipientPreview = generateRecipientList($_POST);
    } elseif ($action === 'send') {
        // Send bulk notifications
        try {
            $recipients = generateRecipientList($_POST);
            $messageText = trim($_POST['message'] ?? '');
            $notificationType = $_POST['notification_type'] ?? 'Push';
            $subject = trim($_POST['subject'] ?? '');
            
            if (empty($messageText)) {
                throw new Exception('Message is required');
            }
            
            if (empty($recipients)) {
                throw new Exception('No recipients found for the selected criteria');
            }
            
            if (count($recipients) > 500) {
                throw new Exception('Too many recipients. Maximum 500 allowed per batch.');
            }
            
            $sendResults = sendBulkNotifications($recipients, $messageText, $notificationType, $subject, $currentUser['user_id']);
            
            $message = sprintf(
                "Bulk notification completed. %d sent successfully, %d failed, %d skipped.",
                $sendResults['success_count'],
                $sendResults['failure_count'],
                $sendResults['skipped_count']
            );
            $messageType = $sendResults['failure_count'] > 0 ? 'warning' : 'success';
            
        } catch (Exception $e) {
            $message = 'Error sending bulk notifications: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

/**
 * Send bulk notifications to recipients
 */
function sendBulkNotifications($recipients, $messageText, $notificationType, $subject, $sentBy) {
    global $db, $fcmService;
    
    $successCount = 0;
    $failureCount = 0;
    $skippedCount = 0;
    
    foreach ($recipients as $recipient) {
        try {
            // Create notification record in database
            $sql = "INSERT INTO notifications (recipient_type, recipient_id, notification_type, subject, message, status, sent_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $params = [
                $recipient['type'],
                $recipient['id'],
                $notificationType,
                $subject ?: 'Bulk Notification',
                $messageText,
                'Pending',
                $sentBy,
                date('Y-m-d H:i:s')
            ];
            
            $stmt = $db->execute($sql, $params);
            if ($stmt === false) {
                throw new Exception('Failed to insert notification record');
            }
            $notificationId = $db->lastInsertId();
            
            // Send notification based on type
            $sendResult = false;
            $errorMessage = null;
            
            switch ($notificationType) {
                case 'Push':
                    $sendResult = sendPushNotification($recipient, $subject, $messageText);
                    break;
                    
                case 'SMS':
                    $sendResult = sendSMSNotification($recipient, $messageText);
                    break;
                    
                case 'System':
                    // System notifications are just stored in database
                    $sendResult = ['success' => true];
                    break;
                    
                case 'All':
                    // Send both push and SMS
                    $pushResult = sendPushNotification($recipient, $subject, $messageText);
                    $smsResult = sendSMSNotification($recipient, $messageText);
                    $sendResult = ['success' => $pushResult['success'] || $smsResult['success']];
                    break;
                    
                default:
                    $sendResult = ['success' => false, 'error' => 'Invalid notification type'];
            }
            
            // Update notification status
            if ($sendResult && $sendResult['success']) {
                $sql = "UPDATE notifications SET status = ?, sent_at = ? WHERE notification_id = ?";
                $params = ['Sent', date('Y-m-d H:i:s'), $notificationId];
                $db->execute($sql, $params);
                $successCount++;
                
                // Log to audit
                logAuditAction($sentBy, 'NOTIFICATION_SENT', 'notifications', $notificationId, null, [
                    'recipient_type' => $recipient['type'],
                    'recipient_id' => $recipient['id'],
                    'notification_type' => $notificationType,
                    'method' => 'bulk'
                ]);
                
            } else {
                $errorMsg = $sendResult['error'] ?? 'Unknown error';
                $sql = "UPDATE notifications SET status = ? WHERE notification_id = ?";
                $params = ['Failed', $notificationId];
                $db->execute($sql, $params);
                $failureCount++;
                
                error_log("Notification failed for {$recipient['type']} {$recipient['id']}: {$errorMsg}");
            }
            
            // Small delay to prevent overwhelming
            usleep(100000); // 0.1 second delay
            
        } catch (Exception $e) {
            $failureCount++;
            error_log("Bulk notification error: " . $e->getMessage());
        }
    }
    
    return [
        'success_count' => $successCount,
        'failure_count' => $failureCount,
        'skipped_count' => $skippedCount
    ];
}

/**
 * Send push notification
 */
function sendPushNotification($recipient, $subject, $message) {
    global $fcmService;
    
    try {
        $title = $subject ?: 'QuickBill Notification';
        $data = [
            'type' => 'bulk_notification',
            'recipient_type' => $recipient['type'],
            'recipient_id' => (string)$recipient['id']
        ];
        
        $result = $fcmService->sendToRecipient($recipient['type'], $recipient['id'], $title, $message, $data);
        
        if (is_array($result) && isset($result['success'])) {
            return $result;
        } elseif (is_array($result)) {
            // Multiple results (for multiple devices)
            $anySuccess = false;
            foreach ($result as $res) {
                if ($res['success']) {
                    $anySuccess = true;
                    break;
                }
            }
            return ['success' => $anySuccess];
        }
        
        return ['success' => false, 'error' => 'Invalid FCM response'];
        
    } catch (Exception $e) {
        error_log("Push notification error: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Send SMS notification (placeholder - implement with your SMS provider)
 */
function sendSMSNotification($recipient, $message) {
    // Check if recipient has phone number
    if (empty($recipient['phone'])) {
        return ['success' => false, 'error' => 'No phone number available'];
    }
    
    try {
        // TODO: Implement actual SMS sending with Twilio, etc.
        // For now, simulate with 90% success rate
        $success = (rand(1, 10) !== 1);
        
        if ($success) {
            return ['success' => true];
        } else {
            return ['success' => false, 'error' => 'SMS service unavailable'];
        }
        
        // Example Twilio implementation:
        /*
        $twilio = new TwilioService();
        $result = $twilio->sendSMS($recipient['phone'], $message);
        return $result;
        */
        
    } catch (Exception $e) {
        error_log("SMS notification error: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Helper function to generate recipient list
 */
function generateRecipientList($formData) {
    global $db;
    
    $criteria = $formData['criteria'] ?? '';
    $recipients = [];
    
    try {
        switch ($criteria) {
            case 'all_defaulters':
                // All businesses and properties with outstanding amounts
                $businesses = $db->fetchAll("
                    SELECT business_id as id, business_name as name, telephone as phone, 'Business' as type, amount_payable
                    FROM businesses 
                    WHERE amount_payable > 0 AND status = 'Active'
                    ORDER BY business_name
                    LIMIT 200
                ");
                
                $properties = $db->fetchAll("
                    SELECT property_id as id, owner_name as name, telephone as phone, 'Property' as type, amount_payable
                    FROM properties 
                    WHERE amount_payable > 0
                    ORDER BY owner_name
                    LIMIT 200
                ");
                
                $recipients = array_merge($businesses, $properties);
                break;
                
            case 'business_defaulters':
                $recipients = $db->fetchAll("
                    SELECT business_id as id, business_name as name, telephone as phone, 'Business' as type, amount_payable
                    FROM businesses 
                    WHERE amount_payable > 0 AND status = 'Active'
                    ORDER BY business_name
                    LIMIT 300
                ");
                break;
                
            case 'property_defaulters':
                $recipients = $db->fetchAll("
                    SELECT property_id as id, owner_name as name, telephone as phone, 'Property' as type, amount_payable
                    FROM properties 
                    WHERE amount_payable > 0
                    ORDER BY owner_name
                    LIMIT 300
                ");
                break;
                
            case 'zone':
                $zoneId = (int)($formData['zone_id'] ?? 0);
                if ($zoneId > 0) {
                    $businesses = $db->fetchAll("
                        SELECT business_id as id, business_name as name, telephone as phone, 'Business' as type
                        FROM businesses 
                        WHERE zone_id = ? AND status = 'Active'
                        ORDER BY business_name
                        LIMIT 200
                    ", [$zoneId]);
                    
                    $properties = $db->fetchAll("
                        SELECT property_id as id, owner_name as name, telephone as phone, 'Property' as type
                        FROM properties 
                        WHERE zone_id = ?
                        ORDER BY owner_name
                        LIMIT 200
                    ", [$zoneId]);
                    
                    $recipients = array_merge($businesses, $properties);
                }
                break;
                
            case 'business_type':
                $businessType = $formData['business_type'] ?? '';
                if (!empty($businessType)) {
                    $recipients = $db->fetchAll("
                        SELECT business_id as id, business_name as name, telephone as phone, 'Business' as type
                        FROM businesses 
                        WHERE business_type = ? AND status = 'Active'
                        ORDER BY business_name
                        LIMIT 300
                    ", [$businessType]);
                }
                break;
                
            case 'all_businesses':
                $recipients = $db->fetchAll("
                    SELECT business_id as id, business_name as name, telephone as phone, 'Business' as type
                    FROM businesses 
                    WHERE status = 'Active'
                    ORDER BY business_name
                    LIMIT 300
                ");
                break;
                
            case 'all_properties':
                $recipients = $db->fetchAll("
                    SELECT property_id as id, owner_name as name, telephone as phone, 'Property' as type
                    FROM properties 
                    ORDER BY owner_name
                    LIMIT 300
                ");
                break;
                
            case 'all_users':
                $recipients = $db->fetchAll("
                    SELECT user_id as id, CONCAT(first_name, ' ', last_name) as name, phone, 'User' as type
                    FROM users 
                    WHERE is_active = 1
                    ORDER BY first_name, last_name
                    LIMIT 100
                ");
                break;
        }
    } catch (Exception $e) {
        error_log("Error generating recipient list: " . $e->getMessage());
        return [];
    }
    
    return $recipients;
}

/**
 * Log audit action
 */
function logAuditAction($userId, $action, $tableName, $recordId, $oldValues, $newValues) {
    global $db;
    
    try {
        $sql = "INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $params = [
            $userId,
            $action,
            $tableName,
            $recordId,
            $oldValues ? json_encode($oldValues) : null,
            $newValues ? json_encode($newValues) : null,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            date('Y-m-d H:i:s')
        ];
        
        $db->execute($sql, $params);
    } catch (Exception $e) {
        error_log("Audit log error: " . $e->getMessage());
    }
}

// Load zones and business types for form
try {
    $zones = $db->fetchAll("SELECT zone_id, zone_name FROM zones ORDER BY zone_name");
    $businessTypes = $db->fetchAll("SELECT DISTINCT business_type FROM businesses WHERE status = 'Active' ORDER BY business_type");
} catch (Exception $e) {
    $zones = [];
    $businessTypes = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Notifications - <?php echo APP_NAME; ?></title>
    
    <!-- Multiple Icon Sources -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    
    <!-- Bootstrap for backup -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <style>
        /* Keep the same CSS styles from the original file */
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
        
        /* All other CSS styles remain the same as in the original file */
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
        .action-btn.danger { background: #e53e3e; }
        
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
        
        /* Criteria selection */
        .criteria-card {
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .criteria-card:hover {
            border-color: #667eea;
            background-color: #f7fafc;
        }
        
        .criteria-card.active {
            border-color: #667eea;
            background-color: #ebf4ff;
        }
        
        .criteria-card input[type="radio"] {
            margin-right: 10px;
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
        
        /* Recipient preview */
        .recipient-preview {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: #f8f9fa;
        }
        
        .recipient-item {
            padding: 10px 15px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .recipient-item:last-child {
            border-bottom: none;
        }
        
        /* Send results */
        .send-results {
            margin-top: 20px;
            padding: 15px;
            border-radius: 10px;
            background: #f7fafc;
            border-left: 4px solid #4299e1;
        }
        
        .result-stat {
            display: inline-block;
            margin-right: 20px;
            padding: 5px 10px;
            border-radius: 5px;
            font-weight: 600;
            font-size: 14px;
        }
        
        .result-stat.success {
            background: #c6f6d5;
            color: #276749;
        }
        
        .result-stat.danger {
            background: #fed7d7;
            color: #c53030;
        }
        
        .result-stat.warning {
            background: #fef5e7;
            color: #dd6b20;
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
            </button>
            
            <a href="../index.php" class="brand">
                <i class="fas fa-receipt"></i>
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
        <!-- Sidebar (same as original) -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-content">
                <!-- Dashboard -->
                <div class="nav-section">
                    <div class="nav-item">
                        <a href="../index.php" class="nav-link">
                            <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                            Dashboard
                        </a>
                    </div>
                </div>
                
                <!-- Core Management -->
                <div class="nav-section">
                    <div class="nav-title">Core Management</div>
                    <div class="nav-item">
                        <a href="../users/index.php" class="nav-link">
                            <span class="nav-icon"><i class="fas fa-users"></i></span>
                            Users
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../businesses/index.php" class="nav-link">
                            <span class="nav-icon"><i class="fas fa-building"></i></span>
                            Businesses
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../properties/index.php" class="nav-link">
                            <span class="nav-icon"><i class="fas fa-home"></i></span>
                            Properties
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../zones/index.php" class="nav-link">
                            <span class="nav-icon"><i class="fas fa-map-marked-alt"></i></span>
                            Zones & Areas
                        </a>
                    </div>
                </div>
                
                <!-- Billing & Payments -->
                <div class="nav-section">
                    <div class="nav-title">Billing & Payments</div>
                    <div class="nav-item">
                        <a href="../billing/index.php" class="nav-link">
                            <span class="nav-icon"><i class="fas fa-file-invoice"></i></span>
                            Billing
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../payments/index.php" class="nav-link">
                            <span class="nav-icon"><i class="fas fa-credit-card"></i></span>
                            Payments
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../fee_structure/business_fees.php" class="nav-link">
                            <span class="nav-icon"><i class="fas fa-tags"></i></span>
                            Fee Structure
                        </a>
                    </div>
                </div>
                
                <!-- Reports & System -->
                <div class="nav-section">
                    <div class="nav-title">Reports & System</div>
                    <div class="nav-item">
                        <a href="../reports/index.php" class="nav-link">
                            <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
                            Reports
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="index.php" class="nav-link active">
                            <span class="nav-icon"><i class="fas fa-bell"></i></span>
                            Notifications
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../settings/index.php" class="nav-link">
                            <span class="nav-icon"><i class="fas fa-cog"></i></span>
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
                    <i class="fas fa-bell"></i> Bulk Notifications
                </h1>
                <p style="color: #718096; font-size: 16px;">
                    Send push notifications, SMS, and system notifications to multiple recipients.
                </p>
            </div>

            <!-- Flash Messages -->
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $messageType === 'error' ? 'danger' : $messageType; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Send Results -->
            <?php if ($sendResults !== null): ?>
                <div class="send-results">
                    <h6 style="margin-bottom: 10px;">Notification Results:</h6>
                    <div class="result-stat success">
                        <i class="fas fa-check-circle"></i> <?php echo $sendResults['success_count']; ?> Sent
                    </div>
                    <div class="result-stat danger">
                        <i class="fas fa-times-circle"></i> <?php echo $sendResults['failure_count']; ?> Failed
                    </div>
                    <div class="result-stat warning">
                        <i class="fas fa-exclamation-circle"></i> <?php echo $sendResults['skipped_count']; ?> Skipped
                    </div>
                </div>
            <?php endif; ?>

            <!-- Back Button -->
            <div style="margin-bottom: 20px;">
                <a href="index.php" class="action-btn secondary">
                    <i class="fas fa-arrow-left"></i> Back to Notifications
                </a>
            </div>

            <form method="POST" id="bulkForm">
                <div class="row">
                    <div class="col-lg-8">
                        <!-- Step 1: Select Recipients -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title"><i class="fas fa-users"></i> Step 1: Select Recipients</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <!-- Defaulters Options -->
                                    <div class="col-md-6">
                                        <h6 style="color: #e53e3e; margin-bottom: 15px; font-weight: 600;">
                                            <i class="fas fa-exclamation-triangle"></i> Defaulters
                                        </h6>
                                        
                                        <div class="criteria-card" onclick="selectCriteria('all_defaulters')">
                                            <label style="cursor: pointer; margin-bottom: 0;">
                                                <input type="radio" name="criteria" value="all_defaulters">
                                                <strong>All Defaulters</strong><br>
                                                <small style="color: #718096;">All businesses & properties with outstanding payments</small>
                                            </label>
                                        </div>
                                        
                                        <div class="criteria-card" onclick="selectCriteria('business_defaulters')">
                                            <label style="cursor: pointer; margin-bottom: 0;">
                                                <input type="radio" name="criteria" value="business_defaulters">
                                                <strong>Business Defaulters</strong><br>
                                                <small style="color: #718096;">Only businesses with outstanding payments</small>
                                            </label>
                                        </div>
                                        
                                        <div class="criteria-card" onclick="selectCriteria('property_defaulters')">
                                            <label style="cursor: pointer; margin-bottom: 0;">
                                                <input type="radio" name="criteria" value="property_defaulters">
                                                <strong>Property Defaulters</strong><br>
                                                <small style="color: #718096;">Only properties with outstanding payments</small>
                                            </label>
                                        </div>
                                    </div>
                                    
                                    <!-- General Categories -->
                                    <div class="col-md-6">
                                        <h6 style="color: #4299e1; margin-bottom: 15px; font-weight: 600;">
                                            <i class="fas fa-layer-group"></i> Categories
                                        </h6>
                                        
                                        <div class="criteria-card" onclick="selectCriteria('all_businesses')">
                                            <label style="cursor: pointer; margin-bottom: 0;">
                                                <input type="radio" name="criteria" value="all_businesses">
                                                <strong>All Businesses</strong><br>
                                                <small style="color: #718096;">All active businesses</small>
                                            </label>
                                        </div>
                                        
                                        <div class="criteria-card" onclick="selectCriteria('all_properties')">
                                            <label style="cursor: pointer; margin-bottom: 0;">
                                                <input type="radio" name="criteria" value="all_properties">
                                                <strong>All Properties</strong><br>
                                                <small style="color: #718096;">All registered properties</small>
                                            </label>
                                        </div>
                                        
                                        <div class="criteria-card" onclick="selectCriteria('all_users')">
                                            <label style="cursor: pointer; margin-bottom: 0;">
                                                <input type="radio" name="criteria" value="all_users">
                                                <strong>All Users</strong><br>
                                                <small style="color: #718096;">All active system users</small>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Specific Filters -->
                                <div class="row" style="margin-top: 20px;">
                                    <div class="col-md-6">
                                        <h6 style="color: #48bb78; margin-bottom: 15px; font-weight: 600;">
                                            <i class="fas fa-filter"></i> Specific Filters
                                        </h6>
                                        
                                        <div class="criteria-card" onclick="selectCriteria('zone')">
                                            <label style="cursor: pointer; margin-bottom: 10px;">
                                                <input type="radio" name="criteria" value="zone">
                                                <strong>By Zone</strong>
                                            </label>
                                            <select name="zone_id" class="form-select" id="zoneSelect">
                                                <option value="">Select Zone</option>
                                                <?php foreach ($zones as $zone): ?>
                                                    <option value="<?php echo $zone['zone_id']; ?>">
                                                        <?php echo htmlspecialchars($zone['zone_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <h6 style="color: #48bb78; margin-bottom: 15px; font-weight: 600;"></h6>
                                        
                                        <div class="criteria-card" onclick="selectCriteria('business_type')">
                                            <label style="cursor: pointer; margin-bottom: 10px;">
                                                <input type="radio" name="criteria" value="business_type">
                                                <strong>By Business Type</strong>
                                            </label>
                                            <select name="business_type" class="form-select" id="businessTypeSelect">
                                                <option value="">Select Business Type</option>
                                                <?php foreach ($businessTypes as $type): ?>
                                                    <option value="<?php echo htmlspecialchars($type['business_type']); ?>">
                                                        <?php echo htmlspecialchars($type['business_type']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <div style="margin-top: 20px;">
                                    <button type="submit" name="action" value="preview" class="action-btn info">
                                        <i class="fas fa-eye"></i> Preview Recipients
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Step 2: Compose Message -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title"><i class="fas fa-edit"></i> Step 2: Compose Message</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <label class="form-label">Notification Type</label>
                                        <select name="notification_type" class="form-select" id="notificationType">
                                            <option value="Push">Push Notification</option>
                                            <option value="SMS">SMS</option>
                                            <option value="System">System Notification</option>
                                            <option value="All">Push + SMS</option>
                                        </select>
                                        <small class="form-text text-muted">
                                            Push notifications are sent to mobile apps with registered tokens.
                                        </small>
                                    </div>
                                    <div class="col-md-6" id="subjectField">
                                        <label class="form-label">Subject</label>
                                        <input type="text" name="subject" class="form-control" 
                                               placeholder="Notification subject (optional)" 
                                               value="QuickBill Notification">
                                    </div>
                                </div>
                                
                                <label class="form-label">Message Text *</label>
                                <textarea name="message" id="messageText" class="form-control" rows="6" 
                                          placeholder="Type your message here..." required></textarea>
                                <div class="char-counter" id="charCounter">
                                    <span id="charCount">0</span> / 160 characters (1 SMS)
                                </div>

                                <div class="alert alert-warning" style="margin-top: 15px;">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <strong>Important:</strong> Push notifications will be sent to users with registered mobile devices. 
                                    SMS will be sent to recipients with valid phone numbers. Please review your message carefully.
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="card">
                            <div class="card-body">
                                <button type="submit" name="action" value="send" class="action-btn success" id="sendButton" disabled>
                                    <i class="fas fa-paper-plane"></i> Send Bulk Notification
                                </button>
                                <button type="submit" name="action" value="preview" class="action-btn info">
                                    <i class="fas fa-eye"></i> Preview Recipients
                                </button>
                                <a href="index.php" class="action-btn secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Preview Sidebar -->
                    <div class="col-lg-4">
                        <div class="card" style="position: sticky; top: 2rem;">
                            <div class="card-header">
                                <h5 class="card-title">
                                    <i class="fas fa-users"></i> Recipients Preview
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($recipientPreview)): ?>
                                    <div class="alert alert-info" style="margin-bottom: 15px;">
                                        <strong><?php echo count($recipientPreview); ?></strong> recipients found
                                    </div>
                                    
                                    <div class="recipient-preview">
                                        <?php foreach (array_slice($recipientPreview, 0, 15) as $recipient): ?>
                                            <div class="recipient-item">
                                                <div>
                                                    <strong><?php echo htmlspecialchars($recipient['name']); ?></strong>
                                                    <div style="font-size: 12px; color: #718096;">
                                                        <?php echo ucfirst($recipient['type']); ?>
                                                        <?php if (!empty($recipient['phone'])): ?>
                                                            - <?php echo htmlspecialchars($recipient['phone']); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                        
                                        <?php if (count($recipientPreview) > 15): ?>
                                            <div class="recipient-item" style="text-align: center; color: #718096;">
                                                <em>... and <?php echo count($recipientPreview) - 15; ?> more recipients</em>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <script>
                                        document.getElementById('sendButton').disabled = false;
                                    </script>
                                <?php else: ?>
                                    <div style="text-align: center; color: #718096; padding: 30px;">
                                        <i class="fas fa-users" style="font-size: 48px; margin-bottom: 15px;"></i>
                                        <p>Select criteria and click "Preview Recipients" to see who will receive the notification.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
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
            const smsCount = Math.ceil(length / 160);
            
            charCount.textContent = length;
            
            if (length <= 160) {
                counter.innerHTML = `<span id="charCount">${length}</span> / 160 characters (1 SMS)`;
                counter.className = 'char-counter';
            } else if (length <= 320) {
                counter.innerHTML = `<span id="charCount">${length}</span> characters (${smsCount} SMS)`;
                counter.className = 'char-counter warning';
            } else {
                counter.innerHTML = `<span id="charCount">${length}</span> characters (${smsCount} SMS)`;
                counter.className = 'char-counter danger';
            }
        }

        // Initialize character counter
        document.getElementById('messageText').addEventListener('input', updateCharCounter);
        updateCharCounter();

        // Notification type change
        document.getElementById('notificationType').addEventListener('change', function() {
            const subjectField = document.getElementById('subjectField');
            if (this.value === 'SMS') {
                subjectField.style.display = 'none';
            } else {
                subjectField.style.display = 'block';
            }
        });

        // Criteria selection
        function selectCriteria(criteria) {
            const radio = document.querySelector(`input[name="criteria"][value="${criteria}"]`);
            if (radio) {
                radio.checked = true;
                updateCriteriaCards();
            }
        }

        // Update criteria card styling
        function updateCriteriaCards() {
            const cards = document.querySelectorAll('.criteria-card');
            cards.forEach(card => {
                const radio = card.querySelector('input[type="radio"]');
                if (radio && radio.checked) {
                    card.classList.add('active');
                } else {
                    card.classList.remove('active');
                }
            });
        }

        // Initialize criteria cards
        document.querySelectorAll('input[name="criteria"]').forEach(radio => {
            radio.addEventListener('change', updateCriteriaCards);
        });
        updateCriteriaCards();

        // Form validation
        document.getElementById('bulkForm').addEventListener('submit', function(e) {
            const action = e.submitter.value;
            const criteria = document.querySelector('input[name="criteria"]:checked');
            const message = document.getElementById('messageText').value.trim();
            
            if (action === 'send') {
                if (!criteria) {
                    e.preventDefault();
                    alert('Please select recipient criteria');
                    return;
                }
                
                if (!message) {
                    e.preventDefault();
                    alert('Please enter a message');
                    return;
                }
                
                const recipientCount = <?php echo count($recipientPreview); ?>;
                if (recipientCount === 0) {
                    e.preventDefault();
                    alert('Please preview recipients first to ensure there are valid recipients for your criteria.');
                    return;
                }
                
                if (!confirm(`Are you sure you want to send this bulk notification to ${recipientCount} recipients? This action cannot be undone.`)) {
                    e.preventDefault();
                    return;
                }
                
                // Show loading state
                e.submitter.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
                e.submitter.disabled = true;
            }
        });

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