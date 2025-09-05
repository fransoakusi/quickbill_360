<?php
/**
 * Authentication Functions for QUICKBILL 305
 * Handles user authentication, authorization, and session management
 * Enhanced with comprehensive permission system
 */

// Prevent direct access
if (!defined('QUICKBILL_305')) {
    die('Direct access not permitted');
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Get current user ID
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current user data
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    try {
        $db = new Database();
        $sql = "SELECT u.*, ur.role_name 
                FROM users u 
                JOIN user_roles ur ON u.role_id = ur.role_id 
                WHERE u.user_id = ? AND u.is_active = 1";
        
        return $db->fetchRow($sql, [getCurrentUserId()]);
    } catch (Exception $e) {
        writeLog("Error getting current user: " . $e->getMessage(), 'ERROR');
        return null;
    }
}

/**
 * Get current user role
 */
function getCurrentUserRole() {
    $user = getCurrentUser();
    return $user ? $user['role_name'] : null;
}

/**
 * Login user
 */
function loginUser($username, $password, $rememberMe = false) {
    try {
        $db = new Database();
        
        // Check login attempts
        if (isAccountLocked($username)) {
            return [
                'success' => false,
                'message' => 'Account temporarily locked due to multiple failed login attempts. Please try again later.'
            ];
        }
        
        // Get user by username or email
        $sql = "SELECT u.*, ur.role_name 
                FROM users u 
                JOIN user_roles ur ON u.role_id = ur.role_id 
                WHERE (u.username = ? OR u.email = ?) AND u.is_active = 1";
        
        $user = $db->fetchRow($sql, [$username, $username]);
        
        if (!$user) {
            recordFailedLogin($username);
            return [
                'success' => false,
                'message' => 'Invalid username or password'
            ];
        }
        
        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            recordFailedLogin($username);
            return [
                'success' => false,
                'message' => 'Invalid username or password'
            ];
        }
        
        // Clear failed login attempts
        clearFailedLogins($username);
        
        // Check if first login
        $firstLogin = $user['first_login'] == 1;
        
        // Create session
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role_name'];
        $_SESSION['first_name'] = $user['first_name'];
        $_SESSION['last_name'] = $user['last_name'];
        $_SESSION['first_login'] = $firstLogin;
        $_SESSION['login_time'] = time();
        
        // Update last login
        $updateSql = "UPDATE users SET last_login = NOW() WHERE user_id = ?";
        $db->execute($updateSql, [$user['user_id']]);
        
        // Log activity using the function from functions.php
        if (function_exists('logUserAction')) {
            logUserAction('USER_LOGIN', 'users', $user['user_id']);
        } else {
            logActivity('User Login', [
                'user_id' => $user['user_id'],
                'username' => $user['username'],
                'ip_address' => getClientIP()
            ]);
        }
        
        return [
            'success' => true,
            'first_login' => $firstLogin,
            'user' => $user
        ];
        
    } catch (Exception $e) {
        writeLog("Login error: " . $e->getMessage(), 'ERROR');
        return [
            'success' => false,
            'message' => 'An error occurred during login. Please try again.'
        ];
    }
}

/**
 * Logout user
 */
function logout() {
    $userId = getCurrentUserId();
    
    // Log activity
    if ($userId) {
        if (function_exists('logUserAction')) {
            logUserAction('USER_LOGOUT', 'users', $userId);
        } else {
            logActivity('User Logout', [
                'user_id' => $userId,
                'session_duration' => time() - ($_SESSION['login_time'] ?? time())
            ]);
        }
    }
    
    // Destroy session
    session_destroy();
    session_start();
}

/**
 * Change password
 */
function changePassword($userId, $currentPassword, $newPassword) {
    try {
        $db = new Database();
        
        // Get current password hash
        $sql = "SELECT password_hash FROM users WHERE user_id = ?";
        $user = $db->fetchRow($sql, [$userId]);
        
        if (!$user) {
            return [
                'success' => false,
                'message' => 'User not found'
            ];
        }
        
        // Verify current password (skip for first login)
        if (!empty($currentPassword) && !password_verify($currentPassword, $user['password_hash'])) {
            return [
                'success' => false,
                'message' => 'Current password is incorrect'
            ];
        }
        
        // Validate new password
        if (strlen($newPassword) < 6) {
            return [
                'success' => false,
                'message' => 'Password must be at least 6 characters long'
            ];
        }
        
        // Hash new password
        $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        
        // Update password and first_login flag
        $updateSql = "UPDATE users SET password_hash = ?, first_login = 0, updated_at = NOW() WHERE user_id = ?";
        $result = $db->execute($updateSql, [$newPasswordHash, $userId]);
        
        if ($result) {
            // Update session
            $_SESSION['first_login'] = false;
            
            // Log activity
            if (function_exists('logUserAction')) {
                logUserAction('PASSWORD_CHANGED', 'users', $userId);
            } else {
                logActivity('Password Changed', ['user_id' => $userId]);
            }
            
            return [
                'success' => true,
                'message' => 'Password changed successfully'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Failed to update password'
            ];
        }
        
    } catch (Exception $e) {
        writeLog("Password change error: " . $e->getMessage(), 'ERROR');
        return [
            'success' => false,
            'message' => 'An error occurred while changing password'
        ];
    }
}

/**
 * Enhanced permission checking with detailed role-based permissions
 */
function hasPermission($permission) {
    if (!isLoggedIn()) {
        return false;
    }
    
    $user = getCurrentUser();
    $role = $user['role_name'] ?? '';
    
    // Super Admin has all permissions except restrictions.view
    if ($role === 'Super Admin') {
        return $permission !== 'restrictions.view';
    }
    
    // Admin has all permissions except restrictions.view (same as Super Admin)
    if ($role === 'Admin') {
        return $permission !== 'restrictions.view';
    }
    
    // Define role-based permissions for other roles
    $permissions = [
        'Officer' => [
            'businesses.view', 'businesses.create', 'businesses.edit',
            'properties.view', 'properties.create', 'properties.edit',
            'payments.view', 'payments.create',
            'billing.view', 'billing.generate',
            'reports.view'
        ],
        
        'Revenue Officer' => [
            'businesses.view', 'properties.view',
            'payments.view', 'payments.create',
            'reports.view'
        ],
        
        'Data Collector' => [
            'businesses.view', 'businesses.create', 'businesses.edit',
            'properties.view', 'properties.create', 'properties.edit'
        ]
    ];
    
    return isset($permissions[$role]) && in_array($permission, $permissions[$role]);
}

/**
 * Require specific permission or redirect
 */
function requirePermission($permission, $redirectUrl = null) {
    if (!hasPermission($permission)) {
        setFlashMessage('error', 'Access denied. You do not have permission to perform this action.');
        $redirectUrl = $redirectUrl ?: '../index.php';
        header("Location: $redirectUrl");
        exit();
    }
}

/**
 * Require login - FIXED VERSION
 */
function requireLogin() {
    if (!isLoggedIn()) {
        if (isAjaxRequest()) {
            sendJsonResponse(['error' => 'Authentication required'], 401);
        } else {
            // Use the fixed BASE_URL from config.php
            $loginUrl = BASE_URL . '/auth/login.php';
            
            // Fallback if BASE_URL is not defined
            if (!defined('BASE_URL')) {
                $loginUrl = '/quickbill_305/auth/login.php';
            }
            
            // Add current page as return URL
            $currentUrl = $_SERVER['REQUEST_URI'] ?? '';
            if (!empty($currentUrl)) {
                $loginUrl .= '?return=' . urlencode($currentUrl);
            }
            
            header('Location: ' . $loginUrl);
            exit();
        }
    }
}

/**
 * Check if request is AJAX
 */
function isAjaxRequest() {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * Record failed login attempt
 */
function recordFailedLogin($identifier) {
    $ip = getClientIP();
    $key = 'failed_login_' . md5($identifier . $ip);
    
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = [
            'attempts' => 0,
            'last_attempt' => time()
        ];
    }
    
    $_SESSION[$key]['attempts']++;
    $_SESSION[$key]['last_attempt'] = time();
}

/**
 * Clear failed login attempts
 */
function clearFailedLogins($identifier) {
    $ip = getClientIP();
    $key = 'failed_login_' . md5($identifier . $ip);
    
    if (isset($_SESSION[$key])) {
        unset($_SESSION[$key]);
    }
}

/**
 * Check if account is locked
 */
function isAccountLocked($identifier) {
    $ip = getClientIP();
    $key = 'failed_login_' . md5($identifier . $ip);
    
    if (!isset($_SESSION[$key])) {
        return false;
    }
    
    $data = $_SESSION[$key];
    
    // Check if lockout period has expired (5 minutes default)
    $lockoutTime = defined('LOGIN_LOCKOUT_TIME') ? LOGIN_LOCKOUT_TIME : 300;
    if (time() - $data['last_attempt'] > $lockoutTime) {
        unset($_SESSION[$key]);
        return false;
    }
    
    $attemptsLimit = defined('LOGIN_ATTEMPTS_LIMIT') ? LOGIN_ATTEMPTS_LIMIT : 5;
    return $data['attempts'] >= $attemptsLimit;
}

/**
 * Generate remember me token
 */
function generateRememberMeToken($userId) {
    $token = bin2hex(random_bytes(32));
    $expiry = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60)); // 30 days
    
    try {
        // For now, we'll store in session
        $_SESSION['remember_tokens'][$userId] = [
            'token' => $token,
            'expires' => $expiry
        ];
        
        return $token;
        
    } catch (Exception $e) {
        writeLog("Remember me token error: " . $e->getMessage(), 'ERROR');
        return null;
    }
}

/**
 * Clear remember me token
 */
function clearRememberMeToken($userId) {
    if (isset($_SESSION['remember_tokens'][$userId])) {
        unset($_SESSION['remember_tokens'][$userId]);
    }
}

/**
 * Check remember me token
 */
function checkRememberMeToken() {
    if (!isset($_COOKIE['remember_me'])) {
        return false;
    }
    
    $token = $_COOKIE['remember_me'];
    
    // Check token validity
    if (isset($_SESSION['remember_tokens'])) {
        foreach ($_SESSION['remember_tokens'] as $userId => $tokenData) {
            if ($tokenData['token'] === $token && strtotime($tokenData['expires']) > time()) {
                // Auto login user
                try {
                    $db = new Database();
                    $sql = "SELECT u.*, ur.role_name 
                            FROM users u 
                            JOIN user_roles ur ON u.role_id = ur.role_id 
                            WHERE u.user_id = ? AND u.is_active = 1";
                    
                    $user = $db->fetchRow($sql, [$userId]);
                    
                    if ($user) {
                        $_SESSION['user_id'] = $user['user_id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['role'] = $user['role_name'];
                        $_SESSION['first_name'] = $user['first_name'];
                        $_SESSION['last_name'] = $user['last_name'];
                        $_SESSION['first_login'] = false;
                        $_SESSION['login_time'] = time();
                        
                        return true;
                    }
                } catch (Exception $e) {
                    writeLog("Remember me login error: " . $e->getMessage(), 'ERROR');
                }
            }
        }
    }
    
    // Invalid token, clear cookie
    setcookie('remember_me', '', time() - 3600, '/', '', false, true);
    return false;
}

/**
 * Get user display name (uses existing function if available)
 */
function getUserDisplayName($user = null) {
    if (!$user) {
        $user = getCurrentUser();
    }
    
    if (!$user) {
        return 'Unknown User';
    }
    
    $firstName = $user['first_name'] ?? '';
    $lastName = $user['last_name'] ?? '';
    
    if ($firstName && $lastName) {
        return trim("$firstName $lastName");
    } elseif ($firstName) {
        return $firstName;
    } elseif ($lastName) {
        return $lastName;
    } else {
        return $user['username'] ?? 'Unknown User';
    }
}

/**
 * Check if user is admin or super admin
 */
function isAdmin() {
    $role = getCurrentUserRole();
    return in_array($role, ['Admin', 'Super Admin']);
}

/**
 * Check if user is super admin
 */
function isSuperAdmin() {
    return getCurrentUserRole() === 'Super Admin';
}

/**
 * Check if current user is Officer
 */
function isOfficer() {
    return getCurrentUserRole() === 'Officer';
}

/**
 * Check if current user is Revenue Officer
 */
function isRevenueOfficer() {
    return getCurrentUserRole() === 'Revenue Officer';
}

/**
 * Check if current user is Data Collector
 */
function isDataCollector() {
    return getCurrentUserRole() === 'Data Collector';
}

/**
 * Check if user can edit another user
 */
function canEditUser($targetUserId) {
    if (!hasPermission('users.edit')) {
        return false;
    }
    
    $currentUserId = getCurrentUserId();
    $currentRole = getCurrentUserRole();
    
    // Super Admin can edit anyone
    if ($currentRole === 'Super Admin') {
        return true;
    }
    
    // Admin can edit non-Super Admin users
    if ($currentRole === 'Admin') {
        try {
            $db = new Database();
            $targetUser = $db->fetchRow("
                SELECT ur.role_name 
                FROM users u 
                JOIN user_roles ur ON u.role_id = ur.role_id 
                WHERE u.user_id = ?", [$targetUserId]);
            
            return $targetUser && $targetUser['role_name'] !== 'Super Admin';
        } catch (Exception $e) {
            return false;
        }
    }
    
    return false;
}

/**
 * Check if user can delete another user
 */
function canDeleteUser($targetUserId) {
    if (!hasPermission('users.delete')) {
        return false;
    }
    
    $currentUserId = getCurrentUserId();
    
    // Cannot delete own account
    if ($currentUserId == $targetUserId) {
        return false;
    }
    
    $currentRole = getCurrentUserRole();
    
    // Super Admin can delete anyone (except themselves)
    if ($currentRole === 'Super Admin') {
        return true;
    }
    
    // Admin can delete non-Super Admin users
    if ($currentRole === 'Admin') {
        try {
            $db = new Database();
            $targetUser = $db->fetchRow("
                SELECT ur.role_name 
                FROM users u 
                JOIN user_roles ur ON u.role_id = ur.role_id 
                WHERE u.user_id = ?", [$targetUserId]);
            
            return $targetUser && $targetUser['role_name'] !== 'Super Admin';
        } catch (Exception $e) {
            return false;
        }
    }
    
    return false;
}

/**
 * Get permissions for current user role
 */
function getCurrentUserPermissions() {
    if (!isLoggedIn()) {
        return [];
    }
    
    $role = getCurrentUserRole();
    
    // Super Admin and Admin have all permissions except restrictions.view
    if ($role === 'Super Admin' || $role === 'Admin') {
        // Return a comprehensive list of all available permissions except restrictions.view
        return [
            'users.view', 'users.create', 'users.edit', 'users.delete',
            'businesses.view', 'businesses.create', 'businesses.edit', 'businesses.delete',
            'properties.view', 'properties.create', 'properties.edit', 'properties.delete',
            'zones.view', 'zones.create', 'zones.edit', 'zones.delete',
            'billing.view', 'billing.create', 'billing.edit', 'billing.generate',
            'payments.view', 'payments.create', 'payments.edit', 'payments.delete',
            'fees.view', 'fees.edit', 'fees.create', 'fees.delete',
            'reports.view', 'reports.generate',
            'notifications.view', 'notifications.send',
            'settings.view', 'settings.edit',
            'backup.create', 'backup.restore',
            'audit.view',
            'map.view'
            // Note: 'restrictions.view' is intentionally excluded
        ];
    }
    
    $permissions = [
        'Officer' => [
            'businesses.view', 'businesses.create', 'businesses.edit',
            'properties.view', 'properties.create', 'properties.edit',
            'payments.view', 'payments.create',
            'billing.view', 'billing.generate',
            'reports.view'
        ],
        'Revenue Officer' => [
            'businesses.view', 'properties.view',
            'payments.view', 'payments.create',
            'reports.view'
        ],
        'Data Collector' => [
            'businesses.view', 'businesses.create', 'businesses.edit',
            'properties.view', 'properties.create', 'properties.edit'
        ]
    ];
    
    return $permissions[$role] ?? [];
}

/**
 * Check if system is in maintenance mode
 */
function isSystemRestricted() {
    try {
        $db = new Database();
        $setting = $db->fetchRow("SELECT setting_value FROM system_settings WHERE setting_key = 'system_restricted'");
        return ($setting['setting_value'] ?? 'false') === 'true';
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Get system restriction info
 */
function getSystemRestrictionInfo() {
    try {
        $db = new Database();
        
        $restrictionInfo = $db->fetchRow("
            SELECT sr.*, ss.setting_value as system_restricted
            FROM system_restrictions sr
            JOIN system_settings ss ON ss.setting_key = 'system_restricted'
            WHERE sr.is_active = 1
            ORDER BY sr.created_at DESC
            LIMIT 1
        ");
        
        return $restrictionInfo;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Check system restrictions for all users
 */
function checkSystemRestrictions() {
    if (isSystemRestricted()) {
        setFlashMessage('error', 'System is currently under maintenance. Please try again later.');
        header('Location: ' . BASE_URL . '/auth/login.php');
        exit();
    }
}

/**
 * Validate user input against role permissions
 */
function validateUserRoleAccess($targetRoleId) {
    if (!isLoggedIn()) {
        return false;
    }
    
    $currentRole = getCurrentUserRole();
    
    // Super Admin can assign any role
    if ($currentRole === 'Super Admin') {
        return true;
    }
    
    // Admin cannot assign Super Admin role
    if ($currentRole === 'Admin') {
        try {
            $db = new Database();
            $targetRole = $db->fetchRow("SELECT role_name FROM user_roles WHERE role_id = ?", [$targetRoleId]);
            return $targetRole && $targetRole['role_name'] !== 'Super Admin';
        } catch (Exception $e) {
            return false;
        }
    }
    
    return false;
}

/**
 * Get user initials for avatar
 */
function getUserInitials($user = null) {
    if (!$user) {
        $user = getCurrentUser();
    }
    
    if (!$user) {
        return '??';
    }
    
    $firstName = $user['first_name'] ?? '';
    $lastName = $user['last_name'] ?? '';
    $username = $user['username'] ?? '';
    
    if ($firstName && $lastName) {
        return strtoupper(substr($firstName, 0, 1) . substr($lastName, 0, 1));
    } elseif ($firstName) {
        return strtoupper(substr($firstName, 0, 2));
    } elseif ($username) {
        return strtoupper(substr($username, 0, 2));
    }
    
    return '??';
}

/**
 * Check if user needs to complete first login setup
 */
function needsFirstLoginSetup() {
    if (!isLoggedIn()) {
        return false;
    }
    
    $user = getCurrentUser();
    return !empty($user['first_login']);
}

/**
 * Redirect to first login setup if needed
 */
function checkFirstLoginSetup() {
    if (needsFirstLoginSetup()) {
        $currentPage = basename($_SERVER['PHP_SELF']);
        if ($currentPage !== 'first_login.php') {
            header('Location: ' . BASE_URL . '/auth/first_login.php');
            exit();
        }
    }
}

/**
 * Get available roles for current user to assign
 */
function getAssignableRoles() {
    $currentRole = getCurrentUserRole();
    
    try {
        $db = new Database();
        
        if ($currentRole === 'Super Admin') {
            // Super Admin can assign any role
            return $db->fetchAll("SELECT * FROM user_roles ORDER BY role_name");
        } elseif ($currentRole === 'Admin') {
            // Admin cannot assign Super Admin role
            return $db->fetchAll("SELECT * FROM user_roles WHERE role_name != 'Super Admin' ORDER BY role_name");
        }
        
        return [];
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Initialize authentication session
 * Call this function after session_start()
 */
function initAuth() {
    // Check remember me if not logged in
    if (!isLoggedIn()) {
        checkRememberMeToken();
    }
    
    // Check session timeout
    if (isLoggedIn() && isset($_SESSION['login_time'])) {
        $sessionLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 3600; // 1 hour default
        if (time() - $_SESSION['login_time'] > $sessionLifetime) {
            logout();
            if (!isAjaxRequest()) {
                setFlashMessage('warning', 'Your session has expired. Please login again.');
                header('Location: ' . BASE_URL . '/index.php');
                exit();
            }
        }
    }
}

// getClientIP() function is already defined in functions.php

/**
 * Send JSON response (if not already defined in functions.php)
 */
if (!function_exists('sendJsonResponse')) {
    function sendJsonResponse($data, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit();
    }
}
?>