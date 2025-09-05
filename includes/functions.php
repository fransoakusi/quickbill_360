<?php
/**
 * Common Utility Functions for QUICKBILL 305
 * Contains helper functions used throughout the application
 */

// Prevent direct access
if (!defined('QUICKBILL_305')) {
    die('Direct access not permitted');
}

/**
 * Sanitize input data
 */
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Validate email address
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate phone number (Ghana format)
 */
function isValidPhone($phone) {
    // Remove spaces and special characters
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    
    // Check Ghana phone number patterns
    $patterns = [
        '/^\+233[0-9]{9}$/',  // +233xxxxxxxxx
        '/^233[0-9]{9}$/',    // 233xxxxxxxxx
        '/^0[0-9]{9}$/'       // 0xxxxxxxxx
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $phone)) {
            return true;
        }
    }
    
    return false;
}

/**
 * Format phone number to standard format
 */
function formatPhone($phone) {
    // Remove all non-numeric characters except +
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    
    // Convert to international format
    if (substr($phone, 0, 1) === '0') {
        $phone = '+233' . substr($phone, 1);
    } elseif (substr($phone, 0, 3) === '233') {
        $phone = '+' . $phone;
    }
    
    return $phone;
}

/**
 * Generate unique ID
 */
function generateUniqueId($prefix = '', $length = 8) {
    $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $randomString = '';
    
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, strlen($characters) - 1)];
    }
    
    return $prefix . $randomString;
}

/**
 * Format currency amount
 */
function formatCurrency($amount, $showSymbol = true) {
    $symbol = getConfig('currency_symbol', '₵');
    $formatted = number_format($amount, 2);
    
    return $showSymbol ? $symbol . ' ' . $formatted : $formatted;
}

/**
 * Format date
 */
function formatDate($date, $format = null) {
    if (!$date || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
        return 'Never';
    }
    
    if (!$format) {
        $format = defined('DATE_FORMAT') ? DATE_FORMAT : 'M j, Y g:i A';
    }
    
    try {
        if (is_string($date)) {
            $date = new DateTime($date);
        }
        return $date->format($format);
    } catch (Exception $e) {
        return 'Invalid Date';
    }
}

/**
 * Format datetime
 */
function formatDateTime($datetime, $format = null) {
    if (!$datetime || $datetime === '0000-00-00 00:00:00') {
        return 'Never';
    }
    
    if (!$format) {
        $format = defined('DATETIME_FORMAT') ? DATETIME_FORMAT : 'M j, Y g:i A';
    }
    
    try {
        if (is_string($datetime)) {
            $datetime = new DateTime($datetime);
        }
        return $datetime->format($format);
    } catch (Exception $e) {
        return 'Invalid Date';
    }
}

/**
 * Calculate time ago
 */
function timeAgo($datetime) {
    if (!$datetime || $datetime === '0000-00-00 00:00:00') {
        return 'Never';
    }
    
    try {
        $now = new DateTime();
        $ago = new DateTime($datetime);
        $diff = $now->diff($ago);
        
        $weeks = floor($diff->d / 7);
        $diff->d -= $weeks * 7;
        
        $string = [
            'y' => 'year',
            'm' => 'month',
            'w' => 'week',
            'd' => 'day',
            'h' => 'hour',
            'i' => 'minute',
            's' => 'second',
        ];
        
        $result = [];
        foreach ($string as $k => $v) {
            if ($k === 'w' && $weeks) {
                $result[] = $weeks . ' ' . $v . ($weeks > 1 ? 's' : '');
            } elseif ($k !== 'w' && $diff->$k) {
                $result[] = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
            }
        }
        
        if (!empty($result)) {
            $result = array_slice($result, 0, 1);
            return implode(', ', $result) . ' ago';
        }
        
        return 'Just now';
        
    } catch (Exception $e) {
        return 'Unknown';
    }
}

/**
 * Redirect to a URL
 */
function redirect($url, $permanent = false) {
    if (headers_sent()) {
        echo "<script>window.location.href='$url';</script>";
    } else {
        if ($permanent) {
            header('HTTP/1.1 301 Moved Permanently');
        }
        header("Location: $url");
    }
    exit();
}

/**
 * Set flash message
 */
function setFlashMessage($type, $message) {
    if (!isset($_SESSION['flash_messages'])) {
        $_SESSION['flash_messages'] = [];
    }
    $_SESSION['flash_messages'][] = [
        'type' => $type,
        'message' => $message
    ];
}

/**
 * Get flash messages
 */
function getFlashMessages() {
    if (isset($_SESSION['flash_messages'])) {
        $messages = $_SESSION['flash_messages'];
        unset($_SESSION['flash_messages']);
        return $messages;
    }
    return [];
}

/**
 * Enhanced log user action for audit trail
 */
function logUserAction($action, $tableName = '', $recordId = null, $oldValues = null, $newValues = null) {
    if (!isLoggedIn()) {
        return false;
    }
    
    try {
        $db = new Database();
        
        $auditData = [
            'user_id' => getCurrentUserId(),
            'action' => $action,
            'table_name' => $tableName,
            'record_id' => $recordId,
            'old_values' => $oldValues ? json_encode($oldValues) : null,
            'new_values' => $newValues ? json_encode($newValues) : null,
            'ip_address' => getClientIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ];
        
        $query = "INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        return $db->execute($query, array_values($auditData));
        
    } catch (Exception $e) {
        writeLog("Audit log error: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

/**
 * Log activity (keep existing for compatibility)
 */
function logActivity($action, $details = '', $userId = null) {
    try {
        $db = new Database();
        
        if (!$userId) {
            $userId = getCurrentUserId();
        }
        
        $sql = "INSERT INTO audit_logs (user_id, action, table_name, record_id, new_values, ip_address, user_agent, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $params = [
            $userId,
            $action,
            '', // table_name (can be extracted from details)
            null, // record_id (can be extracted from details)
            json_encode($details),
            getClientIP(),
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ];
        
        $db->execute($sql, $params);
        
    } catch (Exception $e) {
        error_log("Failed to log activity: " . $e->getMessage());
    }
}

/**
 * Write to application log
 */
function writeLog($message, $level = 'INFO', $logFile = null) {
    if (!$logFile) {
        $logFile = defined('LOG_FILE_PATH') ? LOG_FILE_PATH : dirname(__DIR__) . '/storage/logs/app.log';
    }
    
    // Create log directory if it doesn't exist
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$level] $message" . PHP_EOL;
    
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * Send JSON response
 */
function sendJsonResponse($data, $httpCode = 200) {
    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

/**
 * Test system functionality
 */
function runSystemTest() {
    $tests = [];
    
    // Test database connection
    $dbTest = testDatabaseConnection();
    $tests['database'] = $dbTest;
    
    // Test directory permissions
    $directories = [
        defined('UPLOADS_PATH') ? UPLOADS_PATH : dirname(__DIR__) . '/uploads',
        defined('STORAGE_PATH') ? STORAGE_PATH : dirname(__DIR__) . '/storage',
        dirname(__DIR__) . '/storage/logs'
    ];
    
    foreach ($directories as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $tests['directories'][$dir] = is_writable($dir);
    }
    
    // Test PHP extensions
    $extensions = ['pdo', 'pdo_mysql', 'gd', 'curl', 'json'];
    foreach ($extensions as $ext) {
        $tests['extensions'][$ext] = extension_loaded($ext);
    }
    
    return $tests;
}

/**
 * Get client IP address
 */
function getClientIP() {
    $ipKeys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    
    foreach ($ipKeys as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            foreach (array_map('trim', explode(',', $_SERVER[$key])) as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/**
 * Create directory if not exists
 */
function createDirectory($path) {
    if (!is_dir($path)) {
        return mkdir($path, 0755, true);
    }
    return true;
}

/**
 * Validate required fields
 */
function validateRequired($data, $requiredFields) {
    $errors = [];
    
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || empty(trim($data[$field]))) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
        }
    }
    
    return $errors;
}

// =============================================
// MISSING FUNCTIONS THAT CAUSED ERRORS
// =============================================

/**
 * Generate CSRF token
 */
if (!function_exists('generateCSRFToken')) {
    function generateCSRFToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

/**
 * Validate CSRF token
 */
if (!function_exists('validateCSRFToken')) {
    function validateCSRFToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

/**
 * Database helper - Get last insert ID with fallbacks
 */
if (!function_exists('getLastInsertId')) {
    function getLastInsertId($db) {
        // Try different method names that might exist in your Database class
        $methods = ['getLastInsertId', 'lastInsertId', 'getInsertId', 'insert_id', 'lastId'];
        
        foreach ($methods as $method) {
            if (method_exists($db, $method)) {
                return $db->$method();
            }
        }
        
        // Fallback to MySQL function
        try {
            $result = $db->fetchRow("SELECT LAST_INSERT_ID() as id");
            return $result['id'] ?? null;
        } catch (Exception $e) {
            writeLog("Error getting last insert ID: " . $e->getMessage(), 'ERROR');
            return null;
        }
    }
}

/**
 * Database helper - Begin transaction with fallbacks
 */
if (!function_exists('beginTransaction')) {
    function beginTransaction($db) {
        $methods = ['beginTransaction', 'begin', 'startTransaction'];
        
        foreach ($methods as $method) {
            if (method_exists($db, $method)) {
                return $db->$method();
            }
        }
        
        // Fallback to SQL command
        return $db->execute("START TRANSACTION");
    }
}

/**
 * Database helper - Commit transaction with fallbacks  
 */
if (!function_exists('commitTransaction')) {
    function commitTransaction($db) {
        $methods = ['commit', 'commitTransaction'];
        
        foreach ($methods as $method) {
            if (method_exists($db, $method)) {
                return $db->$method();
            }
        }
        
        // Fallback to SQL command
        return $db->execute("COMMIT");
    }
}

/**
 * Database helper - Rollback transaction with fallbacks
 */
if (!function_exists('rollbackTransaction')) {
    function rollbackTransaction($db) {
        $methods = ['rollback', 'rollBack', 'rollbackTransaction'];
        
        foreach ($methods as $method) {
            if (method_exists($db, $method)) {
                return $db->$method();
            }
        }
        
        // Fallback to SQL command
        return $db->execute("ROLLBACK");
    }
}

/**
 * Test database connection
 */
if (!function_exists('testDatabaseConnection')) {
    function testDatabaseConnection() {
        try {
            $db = new Database();
            // Try a simple query
            $result = $db->fetchRow("SELECT 1 as test");
            return $result['test'] == 1;
        } catch (Exception $e) {
            writeLog("Database connection test failed: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
}

/**
 * Get configuration value (if not already defined elsewhere)
 */
if (!function_exists('getConfig')) {
    function getConfig($key, $default = null) {
        // Try to get from database system_settings table
        try {
            $db = new Database();
            $result = $db->fetchRow("SELECT setting_value FROM system_settings WHERE setting_key = ?", [$key]);
            return $result ? $result['setting_value'] : $default;
        } catch (Exception $e) {
            // Fallback to default if database not available
            return $default;
        }
    }
}

// =============================================
// PAYMENT PROCESSING FUNCTIONS - NEWLY ADDED
// =============================================

/**
 * Generate payment reference number
 */
if (!function_exists('generatePaymentReference')) {
    function generatePaymentReference() {
        // Format: PAY + YYYYMMDD + 6 random characters
        $date = date('Ymd');
        $random = strtoupper(substr(str_shuffle('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 6));
        return 'PAY' . $date . $random;
    }
}

/**
 * Generate bill number
 */
if (!function_exists('generateBillNumber')) {
    function generateBillNumber($type) {
        $year = date('Y');
        $prefix = $type === 'Business' ? 'BILL' . $year . 'B' : 'BILL' . $year . 'P';
        $random = strtoupper(substr(str_shuffle('0123456789ABCDEF'), 0, 6));
        return $prefix . $random;
    }
}

/**
 * Validate payment amount
 */
if (!function_exists('validatePaymentAmount')) {
    function validatePaymentAmount($amount, $maxAmount = null) {
        $errors = [];
        
        // Check if amount is numeric
        if (!is_numeric($amount)) {
            $errors[] = 'Payment amount must be a valid number';
            return $errors;
        }
        
        $amount = floatval($amount);
        
        // Check if amount is positive
        if ($amount <= 0) {
            $errors[] = 'Payment amount must be greater than zero';
        }
        
        // Check maximum amount if provided
        if ($maxAmount !== null && $amount > floatval($maxAmount)) {
            $errors[] = 'Payment amount cannot exceed GHS ' . number_format($maxAmount, 2);
        }
        
        return $errors;
    }
}

/**
 * Process payment transaction
 */
if (!function_exists('processPayment')) {
    function processPayment($paymentData, $billData, $accountData) {
        try {
            $db = new Database();
            
            // Start transaction
            if (method_exists($db, 'beginTransaction')) {
                $db->beginTransaction();
            } else {
                $db->execute("START TRANSACTION");
            }
            
            $amountPaid = floatval($paymentData['amount_paid']);
            $paymentReference = generatePaymentReference();
            
            // Insert payment record
            $paymentQuery = "
                INSERT INTO payments (payment_reference, bill_id, amount_paid, payment_method, 
                                    payment_channel, transaction_id, payment_status, payment_date, 
                                    processed_by, notes)
                VALUES (?, ?, ?, ?, ?, ?, 'Successful', NOW(), ?, ?)
            ";
            
            $result = $db->execute($paymentQuery, [
                $paymentReference,
                $billData['bill_id'],
                $amountPaid,
                $paymentData['payment_method'],
                $paymentData['payment_channel'],
                $paymentData['transaction_id'],
                $paymentData['processed_by'],
                $paymentData['notes']
            ]);
            
            if (!$result) {
                throw new Exception("Failed to insert payment record");
            }
            
            // Get payment ID
            $paymentId = null;
            if (method_exists($db, 'lastInsertId')) {
                $paymentId = $db->lastInsertId();
            } else {
                $result = $db->fetchRow("SELECT LAST_INSERT_ID() as id");
                $paymentId = $result['id'] ?? null;
            }
            
            // Update bill status
            $newAmountPayable = floatval($billData['amount_payable']) - $amountPaid;
            $billStatus = $newAmountPayable <= 0 ? 'Paid' : 'Partially Paid';
            
            $billUpdateResult = $db->execute("
                UPDATE bills 
                SET amount_payable = ?, status = ?
                WHERE bill_id = ?
            ", [$newAmountPayable, $billStatus, $billData['bill_id']]);
            
            if (!$billUpdateResult) {
                throw new Exception("Failed to update bill status");
            }
            
            // Update account balance
            if ($paymentData['account_type'] === 'Business') {
                $newAccountPayable = floatval($accountData['amount_payable']) - $amountPaid;
                $newPreviousPayments = floatval($accountData['previous_payments']) + $amountPaid;
                
                $accountUpdateResult = $db->execute("
                    UPDATE businesses 
                    SET amount_payable = ?, previous_payments = ?
                    WHERE business_id = ?
                ", [$newAccountPayable, $newPreviousPayments, $accountData['business_id']]);
                
            } else {
                $newAccountPayable = floatval($accountData['amount_payable']) - $amountPaid;
                $newPreviousPayments = floatval($accountData['previous_payments']) + $amountPaid;
                
                $accountUpdateResult = $db->execute("
                    UPDATE properties 
                    SET amount_payable = ?, previous_payments = ?
                    WHERE property_id = ?
                ", [$newAccountPayable, $newPreviousPayments, $accountData['property_id']]);
            }
            
            if (!$accountUpdateResult) {
                throw new Exception("Failed to update account balance");
            }
            
            // Commit transaction
            if (method_exists($db, 'commit')) {
                $db->commit();
            } else {
                $db->execute("COMMIT");
            }
            
            return [
                'success' => true,
                'payment_id' => $paymentId,
                'payment_reference' => $paymentReference,
                'new_balance' => $newAmountPayable
            ];
            
        } catch (Exception $e) {
            // Rollback transaction
            if (isset($db)) {
                if (method_exists($db, 'rollback')) {
                    $db->rollback();
                } else {
                    $db->execute("ROLLBACK");
                }
            }
            
            writeLog("Payment processing error: " . $e->getMessage(), 'ERROR');
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}

/**
 * Get payment methods
 */
if (!function_exists('getPaymentMethods')) {
    function getPaymentMethods() {
        return [
            'Cash' => [
                'name' => 'Cash',
                'icon' => '💵',
                'requires_transaction_id' => false
            ],
            'Mobile Money' => [
                'name' => 'Mobile Money',
                'icon' => '📱',
                'requires_transaction_id' => true
            ],
            'Bank Transfer' => [
                'name' => 'Bank Transfer',
                'icon' => '🏦',
                'requires_transaction_id' => true
            ],
            'Online' => [
                'name' => 'Online',
                'icon' => '💳',
                'requires_transaction_id' => true
            ]
        ];
    }
}

/**
 * Format payment reference for display
 */
if (!function_exists('formatPaymentReference')) {
    function formatPaymentReference($reference) {
        // Format: PAY-2025-01-15-ABC123 becomes PAY 2025-01-15 ABC123
        if (preg_match('/^PAY(\d{8})([A-Z0-9]+)$/', $reference, $matches)) {
            $date = $matches[1];
            $code = $matches[2];
            $formattedDate = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
            return 'PAY ' . $formattedDate . ' ' . $code;
        }
        
        return $reference;
    }
}

// =============================================
// DEFINE MISSING CONSTANTS WITH DEFAULTS
// =============================================

if (!defined('APP_NAME')) {
    define('APP_NAME', 'QUICKBILL 305');
}

if (!defined('BASE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $path = str_replace(basename($scriptName), '', $scriptName);
    define('BASE_URL', $protocol . $host . rtrim($path, '/'));
}

if (!defined('SESSION_LIFETIME')) {
    define('SESSION_LIFETIME', 3600); // 1 hour
}

if (!defined('LOGIN_ATTEMPTS_LIMIT')) {
    define('LOGIN_ATTEMPTS_LIMIT', 5);
}

if (!defined('LOGIN_LOCKOUT_TIME')) {
    define('LOGIN_LOCKOUT_TIME', 300); // 5 minutes
}

if (!defined('DATE_FORMAT')) {
    define('DATE_FORMAT', 'M j, Y');
}

if (!defined('DATETIME_FORMAT')) {
    define('DATETIME_FORMAT', 'M j, Y g:i A');
}

if (!defined('LOG_FILE_PATH')) {
    define('LOG_FILE_PATH', dirname(__DIR__) . '/storage/logs/app.log');
}

if (!defined('UPLOADS_PATH')) {
    define('UPLOADS_PATH', dirname(__DIR__) . '/uploads');
}

if (!defined('STORAGE_PATH')) {
    define('STORAGE_PATH', dirname(__DIR__) . '/storage');
}

/**
 * Get a system setting value from the database
 * 
 * @param string $key The setting key to retrieve
 * @param mixed $default Default value if setting not found
 * @return mixed The setting value or default if not found
 */
if (!function_exists('getSystemSetting')) {
    function getSystemSetting($key, $default = null) {
        try {
            $db = new Database();
            $setting = $db->fetchRow("SELECT setting_value, setting_type FROM system_settings WHERE setting_key = ?", [$key]);
            
            if (!$setting) {
                return $default;
            }
            
            // Convert value based on type
            switch ($setting['setting_type']) {
                case 'boolean':
                    return $setting['setting_value'] === 'true';
                case 'number':
                    return floatval($setting['setting_value']);
                case 'json':
                    return json_decode($setting['setting_value'], true);
                case 'date':
                case 'text':
                default:
                    return $setting['setting_value'];
            }
        } catch (Exception $e) {
            writeLog("Error getting system setting {$key}: " . $e->getMessage(), 'ERROR');
            return $default;
        }
    }
}
?>