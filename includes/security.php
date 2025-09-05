<?php
/**
 * Security Functions for QUICKBILL 305
 * Handles CSRF protection, XSS prevention, input validation, and other security measures
 */

// Prevent direct access
if (!defined('QUICKBILL_305')) {
    die('Direct access not permitted');
}

/**
 * Set security headers
 */
function setSecurityHeaders() {
    // Prevent XSS attacks
    header('X-XSS-Protection: 1; mode=block');
    
    // Prevent MIME type sniffing
    header('X-Content-Type-Options: nosniff');
    
    // Prevent clickjacking
    header('X-Frame-Options: DENY');
    
    // Content Security Policy (adjust as needed)
    $csp = "default-src 'self'; " .
           "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://maps.googleapis.com https://js.paystack.co; " .
           "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
           "font-src 'self' https://fonts.gstatic.com; " .
           "img-src 'self' data: https:; " .
           "connect-src 'self' https:; " .
           "frame-src https://js.paystack.co;";
    
    header("Content-Security-Policy: $csp");
    
    // Strict Transport Security (enable in production with HTTPS)
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    
    // Referrer Policy
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    // Remove server information
    header_remove('X-Powered-By');
}

/**
 * Generate CSRF token for forms
 */
function csrfToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Generate CSRF hidden input field
 */
function csrfField() {
    $token = csrfToken();
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . htmlspecialchars($token) . '">';
}

/**
 * Verify CSRF token
 */
function verifyCsrfToken($token = null) {
    if ($token === null) {
        $token = $_POST[CSRF_TOKEN_NAME] ?? $_GET[CSRF_TOKEN_NAME] ?? '';
    }
    
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Require CSRF token for POST requests
 */
function requireCsrfToken() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verifyCsrfToken()) {
            if (isAjaxRequest()) {
                sendJsonResponse(['error' => 'Invalid CSRF token'], 403);
            } else {
                setFlashMessage('error', 'Security validation failed. Please try again.');
                header('Location: ' . $_SERVER['HTTP_REFERER'] ?? BASE_URL);
                exit();
            }
        }
    }
}

/**
 * Validate password strength
 */
function validatePasswordStrength($password) {
    $errors = [];
    
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors[] = 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters long';
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain at least one uppercase letter';
    }
    
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must contain at least one lowercase letter';
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must contain at least one number';
    }
    
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'Password must contain at least one special character';
    }
    
    return $errors;
}

/**
 * Sanitize input to prevent XSS
 */
function sanitizeXss($input) {
    if (is_array($input)) {
        return array_map('sanitizeXss', $input);
    }
    
    return htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Clean input for database (additional layer)
 */
function cleanInput($input) {
    if (is_array($input)) {
        return array_map('cleanInput', $input);
    }
    
    // Remove null bytes
    $input = str_replace(chr(0), '', $input);
    
    // Trim whitespace
    $input = trim($input);
    
    // Remove potential HTML/script tags
    $input = strip_tags($input);
    
    return $input;
}

/**
 * Rate limiting
 */
function checkRateLimit($action, $limit = 60, $window = 3600) {
    $ip = getClientIP();
    $key = 'rate_limit_' . $action . '_' . md5($ip);
    
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = [
            'count' => 0,
            'reset_time' => time() + $window
        ];
    }
    
    $data = $_SESSION[$key];
    
    // Reset if window expired
    if (time() > $data['reset_time']) {
        $_SESSION[$key] = [
            'count' => 1,
            'reset_time' => time() + $window
        ];
        return true;
    }
    
    // Check limit
    if ($data['count'] >= $limit) {
        return false;
    }
    
    // Increment counter
    $_SESSION[$key]['count']++;
    return true;
}

/**
 * Initialize security measures
 * Call this function after session_start()
 */
function initSecurity() {
    // Set security headers
    setSecurityHeaders();
    
    // Check rate limiting for sensitive actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!checkRateLimit('post_request', 100, 3600)) { // 100 POST requests per hour
            if (isAjaxRequest()) {
                sendJsonResponse(['error' => 'Too many requests. Please try again later.'], 429);
            } else {
                setFlashMessage('error', 'Too many requests. Please try again later.');
                header('Location: ' . $_SERVER['HTTP_REFERER'] ?? BASE_URL);
                exit();
            }
        }
    }
}

// Note: Call initSecurity() manually after session_start() in your pages
?>