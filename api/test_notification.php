
/**
 * api/test_notification.php - Fixed for your Database class
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
$userId = (int)($input['user_id'] ?? 0);

if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid user ID']);
    exit;
}

$db = new Database();
$fcmService = new FCMService($db);

try {
    // Check if user exists
    $user = $db->fetchRow("SELECT user_id, CONCAT(first_name, ' ', last_name) as name FROM users WHERE user_id = ? AND is_active = 1", [$userId]);
    
    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found or inactive']);
        exit;
    }

    // Send test notification
    $result = $fcmService->sendToUser(
        $userId,
        'Test Notification',
        'This is a test push notification from QuickBill 305.',
        ['type' => 'test', 'timestamp' => time()]
    );

    echo json_encode([
        'success' => true,
        'result' => $result,
        'message' => 'Test notification sent to ' . $user['name'],
        'user_id' => $userId
    ]);

} catch (Exception $e) {
    error_log("Test notification error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
?>