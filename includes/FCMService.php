<?php
/**
 * Firebase Cloud Messaging Service - Fixed for your Database class
 * QUICKBILL 305 - Push Notification Service
 */

class FCMService {
    private $serverKey;
    private $db;
    
    public function __construct($database) {
        $this->serverKey = FCM_SERVER_KEY;
        $this->db = $database;
    }
    
    /**
     * Send push notification to a specific device token
     */
    public function sendToToken($token, $title, $body, $data = [], $priority = 'high') {
        $url = 'https://fcm.googleapis.com/fcm/send';
        
        $message = [
            'to' => $token,
            'notification' => [
                'title' => $title,
                'body' => $body,
                'sound' => 'default',
                'badge' => 1
            ],
            'data' => array_merge($data, [
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                'timestamp' => time()
            ]),
            'priority' => $priority,
            'content_available' => true
        ];
        
        return $this->sendRequest($url, $message);
    }
    
    /**
     * Send push notification to multiple tokens
     */
    public function sendToMultipleTokens($tokens, $title, $body, $data = []) {
        $url = 'https://fcm.googleapis.com/fcm/send';
        $results = [];
        
        // FCM allows max 1000 tokens per request, chunk if needed
        $chunks = array_chunk($tokens, 500);
        
        foreach ($chunks as $tokenChunk) {
            $message = [
                'registration_ids' => $tokenChunk,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                    'sound' => 'default',
                    'badge' => 1
                ],
                'data' => array_merge($data, [
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                    'timestamp' => time()
                ]),
                'priority' => 'high',
                'content_available' => true
            ];
            
            $result = $this->sendRequest($url, $message);
            $results[] = $result;
        }
        
        return $results;
    }
    
    /**
     * Send notification to user (all their devices)
     */
    public function sendToUser($userId, $title, $body, $data = []) {
        $tokens = $this->getUserTokens($userId);
        
        if (empty($tokens)) {
            return ['success' => false, 'error' => 'No device tokens found for user'];
        }
        
        return $this->sendToMultipleTokens($tokens, $title, $body, $data);
    }
    
    /**
     * Send notification based on recipient type and ID
     */
    public function sendToRecipient($recipientType, $recipientId, $title, $body, $data = []) {
        switch ($recipientType) {
            case 'User':
                return $this->sendToUser($recipientId, $title, $body, $data);
                
            case 'Business':
                // Find users associated with this business
                $userIds = $this->getBusinessUserIds($recipientId);
                $results = [];
                foreach ($userIds as $userId) {
                    $results[] = $this->sendToUser($userId, $title, $body, $data);
                }
                return $results;
                
            case 'Property':
                // Find users associated with this property
                $userIds = $this->getPropertyUserIds($recipientId);
                $results = [];
                foreach ($userIds as $userId) {
                    $results[] = $this->sendToUser($userId, $title, $body, $data);
                }
                return $results;
                
            default:
                return ['success' => false, 'error' => 'Invalid recipient type'];
        }
    }
    
    /**
     * Register device token for a user
     */
    public function registerToken($userId, $token, $platform = 'Android') {
        try {
            // First, deactivate old tokens for this user
            $stmt = $this->db->execute("UPDATE device_tokens SET is_active = 0 WHERE user_id = ? AND device_token != ?", [$userId, $token]);
            
            // Insert or update the current token
            $stmt = $this->db->execute("INSERT INTO device_tokens (user_id, device_token, platform, is_active, last_used) 
                    VALUES (?, ?, ?, 1, NOW()) 
                    ON DUPLICATE KEY UPDATE 
                    is_active = 1, last_used = NOW(), platform = VALUES(platform)", [$userId, $token, $platform]);
            
            return $stmt !== false;
        } catch (Exception $e) {
            error_log("FCM Token Registration Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get active tokens for a user
     */
    private function getUserTokens($userId) {
        try {
            $tokens = $this->db->fetchAll("SELECT device_token FROM device_tokens 
                    WHERE user_id = ? AND is_active = 1 
                    ORDER BY last_used DESC", [$userId]);
            
            if ($tokens && is_array($tokens)) {
                return array_column($tokens, 'device_token');
            }
            return [];
        } catch (Exception $e) {
            error_log("Error getting user tokens: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get user IDs associated with a business
     */
    private function getBusinessUserIds($businessId) {
        try {
            // For now, return the user who created the business
            // You can expand this to include assigned users, etc.
            $result = $this->db->fetchRow("SELECT created_by FROM businesses WHERE business_id = ?", [$businessId]);
            
            return ($result && isset($result['created_by'])) ? [$result['created_by']] : [];
        } catch (Exception $e) {
            error_log("Error getting business user IDs: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get user IDs associated with a property
     */
    private function getPropertyUserIds($propertyId) {
        try {
            // For now, return the user who created the property
            // You can expand this to include assigned users, etc.
            $result = $this->db->fetchRow("SELECT created_by FROM properties WHERE property_id = ?", [$propertyId]);
            
            return ($result && isset($result['created_by'])) ? [$result['created_by']] : [];
        } catch (Exception $e) {
            error_log("Error getting property user IDs: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Send HTTP request to FCM
     */
    private function sendRequest($url, $message) {
        $headers = [
            'Authorization: key=' . $this->serverKey,
            'Content-Type: application/json'
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_POSTFIELDS => json_encode($message),
            CURLOPT_TIMEOUT => 30
        ]);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            error_log("FCM cURL Error: " . $error);
            return ['success' => false, 'error' => $error];
        }
        
        $response = json_decode($result, true);
        
        if ($httpCode === 200) {
            return [
                'success' => true,
                'response' => $response,
                'success_count' => $response['success'] ?? 0,
                'failure_count' => $response['failure'] ?? 0
            ];
        } else {
            error_log("FCM HTTP Error: " . $httpCode . " - " . $result);
            return ['success' => false, 'error' => 'HTTP ' . $httpCode, 'response' => $response];
        }
    }
    
    /**
     * Clean up old/invalid tokens
     */
    public function cleanupTokens() {
        try {
            // Remove tokens older than 30 days that haven't been used
            $stmt = $this->db->execute("DELETE FROM device_tokens 
                    WHERE last_used < DATE_SUB(NOW(), INTERVAL 30 DAY) 
                    AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
            
            return $stmt !== false;
        } catch (Exception $e) {
            error_log("Error cleaning up tokens: " . $e->getMessage());
            return false;
        }
    }
}
?>