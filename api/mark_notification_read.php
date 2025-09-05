/**
 * api/mark_notification_read.php - Fixed for your Database class
 */
<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

define('QUICKBILL_305', true);
require_once '../config/config.php';
require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$notificationId = (int)($input['notification_id'] ?? 0);

if ($notificationId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid notification ID']);
    exit;
}

$db = new Database();

try {
    $stmt = $db->execute("UPDATE notifications SET status = 'Read' WHERE notification_id = ?", [$notificationId]);

    if ($stmt !== false) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Update failed']);
    }

} catch (Exception $e) {
    error_log("Mark read error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}