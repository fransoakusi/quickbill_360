<?php
/**
 * Token Registration API - Fixed for your Database class
 * QUICKBILL 305 - Register FCM tokens for push notifications
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Define application constant
define('QUICKBILL_305', true);

// Include required files
require_once '../config/config.php';
require_once '../config/database.php';

// Initialize database
$db = new Database();

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
$requiredFields = ['user_id', 'device_token', 'platform'];
foreach ($requiredFields as $field) {
    if (empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['error' => "Missing required field: {$field}"]);
        exit;
    }
}

$userId = (int)$input['user_id'];
$deviceToken = trim($input['device_token']);
$platform = strtoupper(trim($input['platform']));

// Validate platform
if (!in_array($platform, ['ANDROID', 'IOS'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid platform. Must be ANDROID or IOS']);
    exit;
}

// Validate user exists
try {
    $user = $db->fetchRow("SELECT user_id FROM users WHERE user_id = ? AND is_active = 1", [$userId]);
    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found or inactive']);
        exit;
    }
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
    exit;
}

// Register token
try {
    // First, deactivate old tokens for this user
    $stmt = $db->execute("UPDATE device_tokens SET is_active = 0 WHERE user_id = ? AND device_token != ?", [$userId, $deviceToken]);
    
    // Insert or update the current token
    $stmt = $db->execute("
        INSERT INTO device_tokens (user_id, device_token, platform, is_active, last_used) 
        VALUES (?, ?, ?, 1, NOW()) 
        ON DUPLICATE KEY UPDATE 
        is_active = 1, last_used = NOW(), platform = VALUES(platform)
    ", [$userId, $deviceToken, $platform]);
    
    if ($stmt !== false) {
        echo json_encode([
            'success' => true,
            'message' => 'Device token registered successfully',
            'user_id' => $userId,
            'platform' => $platform
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to register device token']);
    }
    
} catch (Exception $e) {
    error_log("Token registration error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
?>