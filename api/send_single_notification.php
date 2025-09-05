
/**
 * api/send_single_notification.php - Fixed for your Database class
 */
<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

define('QUICKBILL_305', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/FCMService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$requiredFields = ['recipient_type', 'recipient_id', 'title', 'message', 'sent_by'];
foreach ($requiredFields as $field) {
    if (empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['error' => "Missing required field: {$field}"]);
        exit;
    }
}

$db = new Database();
$fcmService = new FCMService($db);

try {
    // Store notification in database
    $stmt = $db->execute("INSERT INTO notifications (recipient_type, recipient_id, notification_type, subject, message, status, sent_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)", [
        $input['recipient_type'],
        $input['recipient_id'],
        'Push',
        $input['title'],
        $input['message'],
        'Pending',
        $input['sent_by'],
        date('Y-m-d H:i:s')
    ]);
    
    if ($stmt === false) {
        throw new Exception('Failed to store notification');
    }
    $notificationId = $db->lastInsertId();

    // Send push notification
    $result = $fcmService->sendToRecipient(
        $input['recipient_type'],
        $input['recipient_id'],
        $input['title'],
        $input['message'],
        $input['data'] ?? []
    );

    // Update status based on result
    if ($result && $result['success']) {
        $db->execute("UPDATE notifications SET status = 'Sent', sent_at = ? WHERE notification_id = ?", 
                    [date('Y-m-d H:i:s'), $notificationId]);
        
        echo json_encode([
            'success' => true,
            'notification_id' => $notificationId,
            'fcm_result' => $result
        ]);
    } else {
        $db->execute("UPDATE notifications SET status = 'Failed' WHERE notification_id = ?", 
                    [$notificationId]);
        
        echo json_encode([
            'success' => false,
            'error' => $result['error'] ?? 'Push notification failed',
            'notification_id' => $notificationId
        ]);
    }

} catch (Exception $e) {
    error_log("Single notification error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}