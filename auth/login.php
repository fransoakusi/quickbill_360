<?php
/**
 * Login Page for QUICKBILL 305
 * Handles user authentication and redirects to appropriate dashboards
 * Enhanced with System Restriction Checks
 */

// Define application constant
define('QUICKBILL_305', true);

// Include required files first (before starting session)
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Start session after configuration
session_start();

// Include auth and security after session is started
require_once '../includes/auth.php';
require_once '../includes/security.php';

// Initialize auth and security
initAuth();
initSecurity();

// Add restriction check function
function checkSystemRestriction() {
    try {
        $db = new Database();
        
        // Get current restriction status
        $restrictionCheck = $db->fetchRow("
            SELECT sr.*, ss.setting_value as system_restricted,
                   DATEDIFF(sr.restriction_end_date, CURDATE()) as days_remaining
            FROM system_restrictions sr
            LEFT JOIN system_settings ss ON ss.setting_key = 'system_restricted'
            WHERE sr.is_active = 1
            ORDER BY sr.created_at DESC
            LIMIT 1
        ");
        
        // Default return values for when no restrictions exist
        if (!$restrictionCheck) {
            return [
                'restricted' => false,
                'days_remaining' => 0,
                'warning_days' => 0,
                'end_date' => '',
                'end_date_time' => '',
                'in_warning_period' => false,
                'overdue' => false
            ];
        }
        
        $isSystemRestricted = ($restrictionCheck['system_restricted'] === 'true');
        $daysRemaining = intval($restrictionCheck['days_remaining']);
        $warningDays = intval($restrictionCheck['warning_days']);
        $endDate = date('F j, Y', strtotime($restrictionCheck['restriction_end_date']));
        $endDateTime = date('F j, Y \a\t g:i A', strtotime($restrictionCheck['restriction_end_date'] . ' 23:59:59'));
        
        return [
            'restricted' => $isSystemRestricted,
            'days_remaining' => $daysRemaining,
            'warning_days' => $warningDays,
            'end_date' => $endDate,
            'end_date_time' => $endDateTime,
            'in_warning_period' => ($daysRemaining <= $warningDays && $daysRemaining > 0),
            'overdue' => $daysRemaining <= 0
        ];
        
    } catch (Exception $e) {
        writeLog("Login restriction check error: " . $e->getMessage(), 'ERROR');
        // Return default values on error
        return [
            'restricted' => false,
            'days_remaining' => 0,
            'warning_days' => 0,
            'end_date' => '',
            'end_date_time' => '',
            'in_warning_period' => false,
            'overdue' => false
        ];
    }
}

// Get restriction status
$restrictionStatus = checkSystemRestriction();

// Redirect if already logged in
if (isLoggedIn()) {
    $userRole = getCurrentUserRole();
    
    switch ($userRole) {
        case 'Super Admin':
        case 'Admin':
            header('Location: ../admin/index.php');
            break;
        case 'Officer':
            header('Location: ../officer/index.php');
            break;
        case 'Revenue Officer':
            header('Location: ../revenue_officer/index.php');
            break;
        case 'Data Collector':
            header('Location: ../data_collector/index.php');
            break;
        default:
            logout();
            break;
    }
    exit();
}

$error = '';
$success = '';

// Enhanced login form submission with restriction checks
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!verifyCsrfToken()) {
        $error = 'Security validation failed. Please try again.';
    } else {
        $username = sanitizeInput($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember_me = isset($_POST['remember_me']);
        
        if (empty($username) || empty($password)) {
            $error = 'Please enter both username and password.';
        } else {
            // RESTRICTION CHECK: Before attempting login
            $proceedWithLogin = false;
            
            // First check if user is Super Admin - Super Admin ALWAYS bypasses restrictions
            $isSuperAdminUser = false;
            try {
                $db = new Database();
                $userCheck = $db->fetchRow("
                    SELECT u.user_id, ur.role_name 
                    FROM users u 
                    JOIN user_roles ur ON u.role_id = ur.role_id 
                    WHERE (u.username = ? OR u.email = ?) AND u.is_active = 1
                ", [$username, $username]);
                
                if ($userCheck && $userCheck['role_name'] === 'Super Admin') {
                    $isSuperAdminUser = true;
                }
            } catch (Exception $e) {
                writeLog("User role check error: " . $e->getMessage(), 'ERROR');
            }
            
            // Super Admin ALWAYS bypasses restrictions
            if ($isSuperAdminUser) {
                $proceedWithLogin = true;
            } 
            // For non-Super Admin users, check if system is restricted
            elseif (!empty($restrictionStatus['restricted']) && $restrictionStatus['restricted']) {
                $error = 'System is currently restricted until ' . htmlspecialchars($restrictionStatus['end_date'] ?? 'Unknown Date') . '. Only Super Admin can access the system.';
                
                // Log the blocked login attempt
                if (function_exists('logActivity')) {
                    logActivity('BLOCKED_LOGIN_ATTEMPT_DURING_RESTRICTION', [
                        'username' => $username,
                        'ip_address' => getClientIP(),
                        'restriction_end_date' => $restrictionStatus['end_date'] ?? 'Unknown'
                    ]);
                }
            } else {
                // System not restricted, proceed normally for all users
                $proceedWithLogin = true;
            }
            
            // Proceed with login if allowed
            if ($proceedWithLogin && empty($error)) {
                $loginResult = loginUser($username, $password, $remember_me);
                
                if ($loginResult['success']) {
                    // Check if it's first login
                    if ($loginResult['first_login']) {
                        header('Location: first_login.php');
                    } else {
                        // Redirect based on role
                        $userRole = $loginResult['user']['role_name'];
                        
                        switch ($userRole) {
                            case 'Super Admin':
                            case 'Admin':
                                header('Location: ../admin/index.php');
                                break;
                            case 'Officer':
                                header('Location: ../officer/index.php');
                                break;
                            case 'Revenue Officer':
                                header('Location: ../revenue_officer/index.php');
                                break;
                            case 'Data Collector':
                                header('Location: ../data_collector/index.php');
                                break;
                            default:
                                logout();
                                $error = 'Invalid user role. Please contact administrator.';
                                break;
                        }
                    }
                    exit();
                } else {
                    $error = $loginResult['message'];
                }
            }
        }
    }
}

// Check for URL parameters
if (isset($_GET['error'])) {
    switch ($_GET['error']) {
        case 'invalid_role':
            $error = 'Invalid user role. Please contact administrator.';
            break;
        case 'session_expired':
            $error = 'Your session has expired. Please login again.';
            break;
        case 'access_denied':
            $error = 'Access denied. Please login to continue.';
            break;
    }
}

if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'password_changed':
            $success = 'Password changed successfully. Please login with your new password.';
            break;
        case 'logout':
            $success = 'You have been logged out successfully.';
            break;
    }
}

// Determine if form should be disabled (never disable for potential Super Admin login)
$formDisabled = false; // Always allow login attempts - restriction check happens server-side
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo APP_NAME; ?></title>
    
    <!-- Font Awesome Icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        :root {
            --primary-purple: #667eea;
            --secondary-purple: #764ba2;
            --accent-blue: #4299e1;
            --success-green: #48bb78;
            --warning-orange: #ed8936;
            --danger-red: #e53e3e;
            --dark-text: #2d3748;
            --light-gray: #f8f9fa;
            --medium-gray: #718096;
            --white: #ffffff;
            --shadow-light: 0 4px 20px rgba(0, 0, 0, 0.08);
            --shadow-medium: 0 8px 40px rgba(0, 0, 0, 0.12);
            --shadow-heavy: 0 16px 60px rgba(0, 0, 0, 0.15);
            --border-radius: 20px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        /* Custom Icons (fallback) */
        .icon-receipt::before { content: "🧾"; }
        .icon-user::before { content: "👤"; }
        .icon-lock::before { content: "🔒"; }
        .icon-eye::before { content: "👁️"; }
        .icon-check::before { content: "✓"; }
        .icon-warning::before { content: "⚠️"; }
        .icon-spinner::before { content: "⏳"; }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, var(--primary-purple) 0%, var(--secondary-purple) 30%, var(--accent-blue) 70%, var(--success-green) 100%);
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
        }
        
        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 1000"><defs><radialGradient id="grad1" cx="20%" cy="20%"><stop offset="0%" stop-color="rgba(255,255,255,0.1)"/><stop offset="100%" stop-color="rgba(255,255,255,0)"/></radialGradient><radialGradient id="grad2" cx="80%" cy="80%"><stop offset="0%" stop-color="rgba(255,255,255,0.15)"/><stop offset="100%" stop-color="rgba(255,255,255,0)"/></radialGradient></defs><circle cx="200" cy="200" r="150" fill="url(%23grad1)"/><circle cx="800" cy="800" r="200" fill="url(%23grad2)"/></svg>');
            opacity: 0.4;
            animation: float 25s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            33% { transform: translateY(-15px) rotate(2deg); }
            66% { transform: translateY(10px) rotate(-1deg); }
        }
        
        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 15px;
            position: relative;
            z-index: 2;
        }
        
        .login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 24px;
            box-shadow: var(--shadow-heavy);
            border: 1px solid rgba(255, 255, 255, 0.2);
            overflow: hidden;
            max-width: 440px;
            width: 100%;
            animation: slideInUp 0.8s ease-out;
            position: relative;
        }
        
        /* Enhanced login card styling when restricted */
        .login-card.restricted {
            border: 2px solid rgba(229, 62, 62, 0.3);
            box-shadow: var(--shadow-heavy), 0 0 30px rgba(229, 62, 62, 0.2);
        }
        
        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .login-header {
            background: linear-gradient(135deg, var(--primary-purple), var(--secondary-purple));
            color: var(--white);
            padding: 1.2rem 2rem 1rem 2rem; /* Further reduced from 1.8rem to 1.2rem top, 1rem bottom */
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .login-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
            pointer-events: none;
        }
        
        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .login-logo {
            width: 50px; /* Further reduced from 60px */
            height: 50px; /* Further reduced from 60px */
            background: rgba(255, 255, 255, 0.2);
            border-radius: 14px; /* Reduced from 16px */
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.7rem; /* Reduced from 1rem */
            font-size: 1.75rem; /* Reduced from 2rem */
            animation: pulse 2s infinite;
            position: relative;
            z-index: 2;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        .login-header h1 {
            font-size: 1.75rem; /* Reduced from 2rem */
            font-weight: 800;
            margin: 0 0 0.4rem 0; /* Reduced from 0.5rem */
            letter-spacing: -0.02em;
            position: relative;
            z-index: 2;
        }
        
        .login-header p {
            margin: 0;
            opacity: 0.9;
            font-size: 0.9rem; /* Reduced from 1rem */
            font-weight: 500;
            position: relative;
            z-index: 2;
        }
        
        .login-body {
            padding: 2rem; /* Reduced from 2.5rem */
            background: var(--white);
        }
        
        /* Restriction Notice Styles */
        .restriction-notice {
            background: linear-gradient(135deg, rgba(229, 62, 62, 0.95), rgba(197, 48, 48, 0.95));
            color: white;
            padding: 18px; /* Reduced from 20px */
            border-radius: 15px;
            margin-bottom: 20px; /* Reduced from 25px */
            text-align: center;
            animation: fadeInDown 0.5s ease-out;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            position: relative;
            overflow: hidden;
        }
        
        .restriction-notice::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
            pointer-events: none;
        }
        
        .restriction-notice.warning {
            background: linear-gradient(135deg, rgba(237, 137, 54, 0.95), rgba(221, 107, 32, 0.95));
        }
        
        .restriction-notice.info {
            background: linear-gradient(135deg, rgba(66, 153, 225, 0.95), rgba(49, 130, 206, 0.95));
        }
        
        .restriction-notice-icon {
            font-size: 28px; /* Reduced from 32px */
            margin-bottom: 8px; /* Reduced from 10px */
            display: block;
        }
        
        .restriction-notice-title {
            font-size: 16px; /* Reduced from 18px */
            font-weight: bold;
            margin-bottom: 6px; /* Reduced from 8px */
            position: relative;
            z-index: 2;
        }
        
        .restriction-notice-message {
            font-size: 13px; /* Reduced from 14px */
            opacity: 0.9;
            position: relative;
            z-index: 2;
            line-height: 1.4;
        }
        
        .restriction-countdown {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 10px;
            padding: 8px; /* Reduced from 10px */
            margin-top: 8px; /* Reduced from 10px */
            font-weight: bold;
            position: relative;
            z-index: 2;
            font-size: 13px;
        }
        
        .form-group {
            margin-bottom: 1.25rem; /* Reduced from 1.5rem */
        }
        
        .form-label {
            font-weight: 600;
            color: var(--dark-text);
            margin-bottom: 0.6rem; /* Reduced from 0.75rem */
            display: block;
            font-size: 0.9rem; /* Reduced from 0.95rem */
        }
        
        .input-group {
            position: relative;
            display: flex;
            align-items: stretch;
            width: 100%;
        }
        
        .input-group-text {
            background: var(--light-gray);
            border: 2px solid #e2e8f0;
            border-right: none;
            color: var(--medium-gray);
            padding: 0.7rem 0.9rem; /* Slightly reduced from 0.75rem 1rem */
            border-radius: 12px 0 0 12px;
            display: flex;
            align-items: center;
            font-size: 1rem; /* Reduced from 1.1rem */
            transition: var(--transition);
        }
        
        .form-control {
            border: 2px solid #e2e8f0;
            border-left: none;
            border-radius: 0 12px 12px 0;
            padding: 0.7rem 1rem; /* Slightly reduced from 0.75rem */
            font-size: 0.95rem; /* Reduced from 1rem */
            transition: var(--transition);
            background: var(--white);
            color: var(--dark-text);
            flex: 1;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-purple);
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        
        .form-control:focus + .input-group-text,
        .input-group:focus-within .input-group-text {
            border-color: var(--primary-purple);
        }
        
        .form-control:disabled {
            background-color: #f5f5f5;
            opacity: 0.7;
            cursor: not-allowed;
        }
        
        .toggle-password {
            background: var(--light-gray);
            border: 2px solid #e2e8f0;
            border-left: none;
            color: var(--medium-gray);
            padding: 0.7rem; /* Slightly reduced from 0.75rem */
            border-radius: 0 12px 12px 0;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
        }
        
        .toggle-password:hover {
            background: #e2e8f0;
            color: var(--primary-purple);
        }
        
        .toggle-password:disabled {
            cursor: not-allowed;
            opacity: 0.5;
        }
        
        .form-control.password-input {
            border-radius: 0;
            border-left: none;
            border-right: none;
        }
        
        .btn-login {
            background: linear-gradient(135deg, var(--primary-purple), var(--secondary-purple));
            border: none;
            color: var(--white);
            padding: 0.9rem 2rem; /* Slightly reduced from 1rem */
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.95rem; /* Reduced from 1rem */
            width: 100%;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            cursor: pointer;
        }
        
        .btn-login::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.6s;
        }
        
        .btn-login:hover::before {
            left: 100%;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .btn-login:disabled {
            background: #9ca3af;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .btn-login:disabled:hover {
            transform: none;
            box-shadow: none;
        }
        
        .btn-login:disabled::before {
            display: none;
        }
        
        .alert {
            border-radius: 12px;
            margin-bottom: 1.25rem; /* Reduced from 1.5rem */
            padding: 0.9rem 1.1rem; /* Slightly reduced from 1rem 1.25rem */
            border: none;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: fadeInDown 0.5s ease-out;
            font-size: 0.9rem;
        }
        
        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .alert-danger {
            background: rgba(229, 62, 62, 0.1);
            color: var(--danger-red);
            border-left: 4px solid var(--danger-red);
        }
        
        .alert-success {
            background: rgba(72, 187, 120, 0.1);
            color: var(--success-green);
            border-left: 4px solid var(--success-green);
        }
        
        .form-check {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.25rem; /* Reduced from 1.5rem */
        }
        
        .form-check-input {
            width: 18px;
            height: 18px;
            border: 2px solid #e2e8f0;
            border-radius: 4px;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .form-check-input:checked {
            background: var(--primary-purple);
            border-color: var(--primary-purple);
        }
        
        .form-check-label {
            font-size: 0.9rem; /* Reduced from 0.95rem */
            color: var(--medium-gray);
            cursor: pointer;
            user-select: none;
        }
        
        .system-info {
            background: var(--light-gray);
            border-radius: 12px;
            padding: 1rem; /* Reduced from 1.25rem */
            margin-top: 1.5rem; /* Reduced from 2rem */
            font-size: 0.85rem; /* Reduced from 0.9rem */
            color: var(--medium-gray);
            border: 1px solid #e2e8f0;
        }
        
        .system-info-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.4rem; /* Reduced from 0.5rem */
        }
        
        .system-info-item:last-child {
            margin-bottom: 0;
        }
        
        .system-info-label {
            font-weight: 600;
            color: var(--dark-text);
        }
        
        .loading {
            display: none;
        }
        
        .loading.show {
            display: inline-flex;
            align-items: center;
        }
        
        .spinner {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .back-to-home {
            position: absolute;
            top: 1.5rem; /* Reduced from 2rem */
            left: 1.5rem; /* Reduced from 2rem */
            background: rgba(255, 255, 255, 0.2);
            color: var(--white);
            padding: 0.6rem 1.2rem; /* Slightly reduced from 0.75rem 1.5rem */
            border-radius: 50px;
            text-decoration: none;
            font-weight: 500;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: var(--transition);
            z-index: 3;
            font-size: 0.9rem;
        }
        
        .back-to-home:hover {
            background: rgba(255, 255, 255, 0.3);
            color: var(--white);
            text-decoration: none;
            transform: translateY(-2px);
        }
        
        /* Responsive Design */
        @media (max-width: 576px) {
            .login-container {
                padding: 10px;
            }
            
            .login-header {
                padding: 1.4rem 1.2rem 1.2rem 1.2rem; /* Further reduced for mobile */
            }
            
            .login-body {
                padding: 1.5rem 1.2rem; /* Reduced from 2rem 1.5rem */
            }
            
            .login-header h1 {
                font-size: 1.4rem; /* Reduced from 1.5rem */
            }
            
            .login-header p {
                font-size: 0.85rem;
            }
            
            .login-logo {
                width: 50px; /* Further reduced for mobile */
                height: 50px;
                font-size: 1.6rem;
                margin-bottom: 0.8rem;
            }
            
            .back-to-home {
                position: relative;
                top: 0;
                left: 0;
                margin-bottom: 0.8rem; /* Reduced from 1rem */
                display: inline-block;
                width: auto;
                font-size: 0.85rem;
                padding: 0.5rem 1rem;
            }
            
            .restriction-notice {
                padding: 12px; /* Reduced from 15px */
                font-size: 12px; /* Reduced from 13px */
            }
            
            .restriction-notice-title {
                font-size: 14px; /* Reduced from 16px */
            }
            
            .restriction-notice-icon {
                font-size: 24px; /* Reduced from 28px */
                margin-bottom: 6px;
            }
            
            .form-group {
                margin-bottom: 1rem; /* Further reduced for mobile */
            }
            
            .system-info {
                margin-top: 1.2rem; /* Reduced from 1.5rem */
                padding: 0.8rem; /* Reduced from 1rem */
                font-size: 0.8rem;
            }
        }
        
        /* Focus states for accessibility */
        .form-control:focus,
        .form-check-input:focus,
        .btn-login:focus,
        .toggle-password:focus {
            outline: 2px solid var(--primary-purple);
            outline-offset: 2px;
        }
        
        /* High contrast mode support */
        @media (prefers-contrast: high) {
            .login-card {
                background: var(--white);
                border: 2px solid var(--dark-text);
            }
            
            .form-control {
                border-color: var(--dark-text);
            }
        }
        
        /* Reduced motion support */
        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>
<body>
    <a href="../index.php" class="back-to-home">
        <i class="fas fa-arrow-left"></i>
        Back to Home
    </a>
    
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="login-logo">
                    <i class="fas fa-receipt"></i>
                    <span class="icon-receipt" style="display: none;"></span>
                </div>
                <h1><?php echo APP_NAME; ?></h1>
                <p>Modern Assembly Revenue Management</p>
            </div>
            
            <div class="login-body">
                <!-- System Restriction Notice -->
                <?php if (!empty($restrictionStatus['restricted']) && $restrictionStatus['restricted']): ?>
                    <div class="restriction-notice">
                        <span class="restriction-notice-icon">🔒</span>
                        <div class="restriction-notice-title">System Currently Restricted</div>
                        <div class="restriction-notice-message">
                            The system is under maintenance restriction until <?php echo htmlspecialchars($restrictionStatus['end_date'] ?? 'Unknown Date'); ?>.
                            <br><strong>Super Admin can still login normally.</strong>
                        </div>
                    </div>
                <?php elseif (!empty($restrictionStatus['in_warning_period']) && $restrictionStatus['in_warning_period']): ?>
                    <div class="restriction-notice warning">
                        <span class="restriction-notice-icon">⚠️</span>
                        <div class="restriction-notice-title">System Restriction Warning</div>
                        <div class="restriction-notice-message">
                            System will be restricted in <?php echo intval($restrictionStatus['days_remaining'] ?? 0); ?> day<?php echo (intval($restrictionStatus['days_remaining'] ?? 0) === 1) ? '' : 's'; ?> on <?php echo htmlspecialchars($restrictionStatus['end_date'] ?? 'Unknown Date'); ?>.
                            <br><small><em>Super Admin will retain full access during restrictions.</em></small>
                        </div>
                        <div class="restriction-countdown">
                            <?php echo intval($restrictionStatus['days_remaining'] ?? 0); ?> day<?php echo (intval($restrictionStatus['days_remaining'] ?? 0) === 1) ? '' : 's'; ?> remaining
                        </div>
                    </div>
                <?php elseif (!empty($restrictionStatus['overdue']) && $restrictionStatus['overdue']): ?>
                    <div class="restriction-notice info">
                        <span class="restriction-notice-icon">🔴</span>
                        <div class="restriction-notice-title">Restriction Overdue</div>
                        <div class="restriction-notice-message">
                            System restriction was scheduled for <?php echo htmlspecialchars($restrictionStatus['end_date'] ?? 'Unknown Date'); ?>.
                            <br><strong>Super Admin can still login normally.</strong>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger" role="alert">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span class="icon-warning" style="display: none;"></span>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success" role="alert">
                        <i class="fas fa-check-circle"></i>
                        <span class="icon-check" style="display: none;"></span>
                        <span><?php echo htmlspecialchars($success); ?></span>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="" id="loginForm">
                    <?php echo csrfField(); ?>
                    
                    <div class="form-group">
                        <label for="username" class="form-label">Username or Email</label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="fas fa-user"></i>
                                <span class="icon-user" style="display: none;"></span>
                            </span>
                            <input type="text" 
                                   class="form-control" 
                                   id="username" 
                                   name="username" 
                                   value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                                   placeholder="Enter your username or email"
                                   required
                                   autocomplete="username">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="password" class="form-label">Password</label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="fas fa-lock"></i>
                                <span class="icon-lock" style="display: none;"></span>
                            </span>
                            <input type="password" 
                                   class="form-control password-input" 
                                   id="password" 
                                   name="password" 
                                   placeholder="Enter your password"
                                   required
                                   autocomplete="current-password">
                            <button type="button" class="toggle-password" id="togglePassword">
                                <i class="fas fa-eye"></i>
                                <span class="icon-eye" style="display: none;"></span>
                            </button>
                        </div>
                    </div>
                    
                    <div class="form-check">
                        <input type="checkbox" 
                               class="form-check-input" 
                               id="remember_me" 
                               name="remember_me"
                               <?php echo isset($_POST['remember_me']) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="remember_me">
                            Remember me for 30 days
                        </label>
                    </div>
                    
                    <button type="submit" class="btn-login" id="loginBtn">
                        <span class="loading" id="loadingSpinner">
                            <i class="fas fa-spinner spinner"></i>
                            <span class="icon-spinner" style="display: none;"></span>
                            &nbsp;
                        </span>
                        <span id="loginText">Sign In to Dashboard</span>
                    </button>
                </form>
                
                <div class="system-info">
                    <div class="system-info-item">
                        <span class="system-info-label">System Version:</span>
                        <span><?php echo APP_VERSION; ?></span>
                    </div>
                    <div class="system-info-item">
                        <span class="system-info-label">Environment:</span>
                        <span><?php echo ucfirst(ENVIRONMENT); ?></span>
                    </div>
                    <div class="system-info-item">
                        <span class="system-info-label">Status:</span>
                        <span style="color: <?php echo (!empty($restrictionStatus['restricted']) && $restrictionStatus['restricted']) ? 'var(--warning-orange)' : 'var(--success-green)'; ?>; font-weight: 600;">
                            <?php echo (!empty($restrictionStatus['restricted']) && $restrictionStatus['restricted']) ? 'Restricted (Super Admin Access)' : 'Online'; ?>
                        </span>
                    </div>
                    <?php if ((!empty($restrictionStatus['restricted']) && $restrictionStatus['restricted']) || (!empty($restrictionStatus['in_warning_period']) && $restrictionStatus['in_warning_period'])): ?>
                    <div class="system-info-item">
                        <span class="system-info-label">Restriction End:</span>
                        <span style="color: var(--warning-orange); font-weight: 600;">
                            <?php echo htmlspecialchars($restrictionStatus['end_date'] ?? 'Unknown Date'); ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Restriction status for JavaScript
        const restrictionStatus = <?php echo json_encode($restrictionStatus); ?>;
        
        // Check if Font Awesome loaded, if not show emoji icons
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                const testIcon = document.querySelector('.fas.fa-user');
                if (!testIcon || getComputedStyle(testIcon, ':before').content === 'none') {
                    document.querySelectorAll('.fas, .far').forEach(function(icon) {
                        icon.style.display = 'none';
                    });
                    document.querySelectorAll('[class*="icon-"]').forEach(function(emoji) {
                        emoji.style.display = 'inline';
                    });
                }
            }, 100);

            // Toggle password visibility
            const togglePassword = document.getElementById('togglePassword');
            const passwordInput = document.getElementById('password');
            
            togglePassword.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                
                const icon = this.querySelector('i');
                if (icon) {
                    icon.classList.toggle('fa-eye');
                    icon.classList.toggle('fa-eye-slash');
                }
            });
            
            // Form submission with loading state
            const loginForm = document.getElementById('loginForm');
            const loginBtn = document.getElementById('loginBtn');
            const loadingSpinner = document.getElementById('loadingSpinner');
            const loginText = document.getElementById('loginText');
            
            loginForm.addEventListener('submit', function(e) {
                // Show loading state
                loginBtn.disabled = true;
                loadingSpinner.classList.add('show');
                loginText.textContent = 'Signing In...';
                
                // Add visual feedback
                loginBtn.style.transform = 'translateY(0)';
            });
            
            // Handle restricted form
            if (restrictionStatus.restricted) {
                console.log('System is currently restricted');
                
                // Show additional info on form click for restricted users
                loginForm.addEventListener('click', function(e) {
                    if (loginBtn.disabled) {
                        e.preventDefault();
                        alert('System is currently restricted. Only Super Admin can access the system.\n\nRestriction ends on: ' + restrictionStatus.end_date);
                    }
                });
            }
            
            // Auto-focus on username field
            const usernameField = document.getElementById('username');
            if (usernameField) {
                usernameField.focus();
            }
            
            // Enhanced form validation
            const formControls = document.querySelectorAll('.form-control');
            formControls.forEach(control => {
                control.addEventListener('blur', function() {
                    if (this.value.trim() === '' && this.hasAttribute('required')) {
                        this.style.borderColor = 'var(--danger-red)';
                    } else {
                        this.style.borderColor = '#e2e8f0';
                    }
                });
                
                control.addEventListener('input', function() {
                    if (this.style.borderColor === 'var(--danger-red)' && this.value.trim() !== '') {
                        this.style.borderColor = 'var(--success-green)';
                    }
                });
            });
            
            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Alt + H to go back to home
                if (e.altKey && e.key === 'h') {
                    e.preventDefault();
                    window.location.href = '../index.php';
                }
            });
            
            // Add ripple effect to button
            function addRippleEffect(button) {
                button.addEventListener('click', function(e) {
                    const ripple = document.createElement('span');
                    const rect = this.getBoundingClientRect();
                    const size = Math.max(rect.width, rect.height);
                    const x = e.clientX - rect.left - size / 2;
                    const y = e.clientY - rect.top - size / 2;
                    
                    ripple.style.cssText = `
                        width: ${size}px;
                        height: ${size}px;
                        left: ${x}px;
                        top: ${y}px;
                        position: absolute;
                        border-radius: 50%;
                        background: rgba(255,255,255,0.5);
                        transform: scale(0);
                        animation: ripple 0.6s linear;
                        pointer-events: none;
                    `;
                    
                    this.appendChild(ripple);
                    
                    setTimeout(() => {
                        ripple.remove();
                    }, 600);
                });
            }
            
            // Add ripple to login button
            addRippleEffect(loginBtn);
            
            // Add CSS for ripple animation
            const style = document.createElement('style');
            style.textContent = `
                @keyframes ripple {
                    to {
                        transform: scale(4);
                        opacity: 0;
                    }
                }
            `;
            document.head.appendChild(style);

            // Show warning countdown for warning period
            if (restrictionStatus.in_warning_period && !restrictionStatus.restricted) {
                console.log('System will be restricted in ' + restrictionStatus.days_remaining + ' days');
            }

            // Show additional messaging for overdue restrictions
            if (restrictionStatus.overdue && !restrictionStatus.restricted) {
                console.log('System restriction is overdue. Scheduled for: ' + restrictionStatus.end_date);
            }
        });
    </script>
</body>
</html>