<?php
/**
 * View Notification Details (AJAX Endpoint)
 * QUICKBILL 305 - Notification Details Modal Content
 */

// Define application constant
define('QUICKBILL_305', true);

// Include required files
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/auth.php';

// Start session and check auth
session_start();
initAuth();

if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo '<div class="alert alert-danger">Access denied</div>';
    exit();
}

$notificationId = (int)($_GET['id'] ?? 0);

if ($notificationId <= 0) {
    echo '<div class="alert alert-danger">Invalid notification ID</div>';
    exit();
}

try {
    $db = new Database();
    
    // Get notification details with related information
    $query = "
        SELECT n.*, 
               u.first_name as sender_first_name, 
               u.last_name as sender_last_name,
               u.email as sender_email,
               CASE 
                   WHEN n.recipient_type = 'Business' THEN b.business_name
                   WHEN n.recipient_type = 'Property' THEN p.owner_name
                   WHEN n.recipient_type = 'User' THEN CONCAT(ur.first_name, ' ', ur.last_name)
                   ELSE 'Unknown'
               END as recipient_name,
               CASE 
                   WHEN n.recipient_type = 'Business' THEN b.account_number
                   WHEN n.recipient_type = 'Property' THEN p.property_number
                   WHEN n.recipient_type = 'User' THEN ur.username
                   ELSE NULL
               END as recipient_identifier,
               CASE 
                   WHEN n.recipient_type = 'Business' THEN b.telephone
                   WHEN n.recipient_type = 'Property' THEN p.telephone
                   WHEN n.recipient_type = 'User' THEN ur.phone
                   ELSE NULL
               END as recipient_phone
        FROM notifications n
        LEFT JOIN users u ON n.sent_by = u.user_id
        LEFT JOIN businesses b ON n.recipient_type = 'Business' AND n.recipient_id = b.business_id
        LEFT JOIN properties p ON n.recipient_type = 'Property' AND n.recipient_id = p.property_id
        LEFT JOIN users ur ON n.recipient_type = 'User' AND n.recipient_id = ur.user_id
        WHERE n.notification_id = ?
    ";
    
    $notification = $db->fetchRow($query, [$notificationId]);
    
    if (!$notification) {
        echo '<div class="alert alert-danger">Notification not found</div>';
        exit();
    }
    
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error loading notification: ' . htmlspecialchars($e->getMessage()) . '</div>';
    exit();
}

// Helper function to get status color
function getStatusColor($status) {
    switch($status) {
        case 'Pending': return 'warning';
        case 'Sent': return 'success';
        case 'Failed': return 'danger';
        case 'Read': return 'info';
        default: return 'secondary';
    }
}

// Helper function to get type color
function getTypeColor($type) {
    switch($type) {
        case 'SMS': return 'primary';
        case 'Email': return 'info';
        case 'System': return 'secondary';
        default: return 'secondary';
    }
}
?>

<div class="notification-details">
    <!-- Header Information -->
    <div class="row mb-4">
        <div class="col-md-8">
            <h5 class="mb-2"><?php echo htmlspecialchars($notification['subject'] ?: 'No Subject'); ?></h5>
            <div class="d-flex gap-2 mb-2">
                <span class="badge bg-<?php echo getTypeColor($notification['notification_type']); ?>">
                    <?php echo $notification['notification_type']; ?>
                </span>
                <span class="badge bg-<?php echo getStatusColor($notification['status']); ?>">
                    <?php echo $notification['status']; ?>
                </span>
                <span class="badge bg-light text-dark">
                    <?php echo ucfirst($notification['recipient_type']); ?>
                </span>
            </div>
        </div>
        <div class="col-md-4 text-end">
            <small class="text-muted">
                Notification ID: #<?php echo $notification['notification_id']; ?>
            </small>
        </div>
    </div>

    <!-- Timing Information -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card bg-light">
                <div class="card-body">
                    <h6 class="card-title mb-2">
                        <i class="fas fa-clock text-primary"></i> Timing
                    </h6>
                    <div class="small">
                        <div class="mb-1">
                            <strong>Created:</strong> 
                            <?php echo date('F j, Y \a\t g:i A', strtotime($notification['created_at'])); ?>
                        </div>
                        
                        <?php if ($notification['sent_at']): ?>
                            <div class="mb-1">
                                <strong>Sent:</strong> 
                                <?php echo date('F j, Y \a\t g:i A', strtotime($notification['sent_at'])); ?>
                            </div>
                            <div>
                                <strong>Delivery Time:</strong> 
                                <?php 
                                $created = new DateTime($notification['created_at']);
                                $sent = new DateTime($notification['sent_at']);
                                $interval = $created->diff($sent);
                                if ($interval->days > 0) {
                                    echo $interval->days . ' days, ';
                                }
                                if ($interval->h > 0) {
                                    echo $interval->h . ' hours, ';
                                }
                                echo $interval->i . ' minutes';
                                ?>
                            </div>
                        <?php else: ?>
                            <div class="text-muted">Not yet sent</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="card bg-light">
                <div class="card-body">
                    <h6 class="card-title mb-2">
                        <i class="fas fa-user text-primary"></i> Sender
                    </h6>
                    <div class="small">
                        <?php if ($notification['sender_first_name']): ?>
                            <div class="mb-1">
                                <strong><?php echo htmlspecialchars($notification['sender_first_name'] . ' ' . $notification['sender_last_name']); ?></strong>
                            </div>
                            <div class="text-muted">
                                <?php echo htmlspecialchars($notification['sender_email']); ?>
                            </div>
                        <?php else: ?>
                            <div class="text-muted">System Generated</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Recipient Information -->
    <div class="card mb-4">
        <div class="card-header">
            <h6 class="mb-0">
                <i class="fas fa-user-circle text-primary"></i> Recipient Information
            </h6>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-2">
                        <strong>Name:</strong> 
                        <?php echo htmlspecialchars($notification['recipient_name']); ?>
                    </div>
                    <div class="mb-2">
                        <strong>Type:</strong> 
                        <?php echo ucfirst($notification['recipient_type']); ?>
                    </div>
                    <div class="mb-2">
                        <strong>ID:</strong> 
                        <?php echo $notification['recipient_id']; ?>
                    </div>
                </div>
                <div class="col-md-6">
                    <?php if ($notification['recipient_identifier']): ?>
                        <div class="mb-2">
                            <strong>
                                <?php echo $notification['recipient_type'] === 'User' ? 'Username:' : 'Account Number:'; ?>
                            </strong> 
                            <?php echo htmlspecialchars($notification['recipient_identifier']); ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($notification['recipient_phone']): ?>
                        <div class="mb-2">
                            <strong>Phone:</strong> 
                            <?php echo htmlspecialchars($notification['recipient_phone']); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Message Content -->
    <div class="card mb-4">
        <div class="card-header">
            <h6 class="mb-0">
                <i class="fas fa-envelope text-primary"></i> Message Content
            </h6>
        </div>
        <div class="card-body">
            <?php if ($notification['subject']): ?>
                <div class="mb-3">
                    <strong>Subject:</strong>
                    <div class="mt-1 p-2 bg-light rounded">
                        <?php echo htmlspecialchars($notification['subject']); ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <div>
                <strong>Message:</strong>
                <div class="mt-1 p-3 bg-light rounded" style="white-space: pre-wrap; font-family: inherit;">
<?php echo htmlspecialchars($notification['message']); ?>
                </div>
            </div>
            
            <!-- Character count for SMS -->
            <?php if ($notification['notification_type'] === 'SMS'): ?>
                <div class="mt-2 small text-muted">
                    <i class="fas fa-info-circle"></i>
                    Message length: <?php echo strlen($notification['message']); ?> characters
                    <?php if (strlen($notification['message']) > 160): ?>
                        <span class="text-warning">
                            (Multi-part SMS: <?php echo ceil(strlen($notification['message']) / 160); ?> parts)
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Status Details -->
    <?php if ($notification['status'] === 'Failed' || $notification['status'] === 'Sent'): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="mb-0">
                    <i class="fas fa-info-circle text-primary"></i> Status Details
                </h6>
            </div>
            <div class="card-body">
                <?php if ($notification['status'] === 'Failed'): ?>
                    <div class="alert alert-danger mb-0">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Delivery Failed</strong>
                        <p class="mb-0 mt-2">
                            This notification could not be delivered. Common reasons include:
                        </p>
                        <ul class="mb-0 mt-2">
                            <li>Invalid phone number (for SMS)</li>
                            <li>Network connectivity issues</li>
                            <li>Recipient phone is switched off</li>
                            <li>SMS service temporary unavailable</li>
                        </ul>
                    </div>
                <?php else: ?>
                    <div class="alert alert-success mb-0">
                        <i class="fas fa-check-circle"></i>
                        <strong>Successfully Delivered</strong>
                        <p class="mb-0 mt-2">
                            This notification was successfully delivered to the recipient.
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Actions -->
    <div class="card">
        <div class="card-header">
            <h6 class="mb-0">
                <i class="fas fa-cogs text-primary"></i> Actions
            </h6>
        </div>
        <div class="card-body">
            <div class="d-flex gap-2 flex-wrap">
                <?php if ($notification['status'] !== 'Read'): ?>
                    <form method="POST" action="index.php?action=mark_read" style="display: inline;">
                        <input type="hidden" name="notification_id" value="<?php echo $notification['notification_id']; ?>">
                        <button type="submit" class="btn btn-success btn-sm">
                            <i class="fas fa-check"></i> Mark as Read
                        </button>
                    </form>
                <?php endif; ?>
                
                <?php if ($notification['status'] === 'Failed' && $notification['notification_type'] === 'SMS'): ?>
                    <a href="send_sms.php?resend=<?php echo $notification['notification_id']; ?>" class="btn btn-warning btn-sm">
                        <i class="fas fa-redo"></i> Resend SMS
                    </a>
                <?php endif; ?>
                
                <?php if ($notification['notification_type'] === 'SMS' && $notification['recipient_phone']): ?>
                    <a href="send_sms.php?to=<?php echo urlencode($notification['recipient_phone']); ?>&type=<?php echo $notification['recipient_type']; ?>&id=<?php echo $notification['recipient_id']; ?>" 
                       class="btn btn-primary btn-sm">
                        <i class="fas fa-sms"></i> Send New SMS
                    </a>
                <?php endif; ?>
                
                <button type="button" class="btn btn-secondary btn-sm" onclick="window.print()">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    .notification-details .card {
        border: 1px solid #ddd !important;
        box-shadow: none !important;
        margin-bottom: 1rem !important;
    }
    
    .notification-details .btn {
        display: none !important;
    }
    
    .notification-details .card:last-child {
        display: none !important;
    }
}
</style>