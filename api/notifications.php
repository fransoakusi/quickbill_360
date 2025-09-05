<?php
/**
 * api/notifications.php - Fixed for your Database class
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

define('QUICKBILL_305', true);
require_once '../config/config.php';
require_once '../config/database.php';

$db = new Database();
$userId = (int)($_GET['user_id'] ?? 0);

if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid user ID']);
    exit;
}

try {
    $notifications = $db->fetchAll("
        SELECT n.*, 
               CASE 
                   WHEN n.recipient_type = 'Business' THEN b.business_name
                   WHEN n.recipient_type = 'Property' THEN p.owner_name
                   ELSE CONCAT(u.first_name, ' ', u.last_name)
               END as recipient_name
        FROM notifications n
        LEFT JOIN businesses b ON n.recipient_type = 'Business' AND n.recipient_id = b.business_id
        LEFT JOIN properties p ON n.recipient_type = 'Property' AND n.recipient_id = p.property_id
        LEFT JOIN users u ON n.recipient_type = 'User' AND n.recipient_id = u.user_id
        WHERE (n.recipient_type = 'User' AND n.recipient_id = ?) 
           OR (n.recipient_type = 'Business' AND b.created_by = ?)
           OR (n.recipient_type = 'Property' AND p.created_by = ?)
        ORDER BY n.created_at DESC
        LIMIT 50
    ", [$userId, $userId, $userId]);

    echo json_encode([
        'success' => true,
        'notifications' => $notifications ?: []
    ]);

} catch (Exception $e) {
    error_log("Notifications API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
?>