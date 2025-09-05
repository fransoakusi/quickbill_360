<?php
/**
 * User Management - Edit User
 * QUICKBILL 305 - Admin Panel
 */

// Define application constant
define('QUICKBILL_305', true);

// Include configuration files
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Start session
session_start();

// Include auth and security
require_once '../../includes/auth.php';
require_once '../../includes/security.php';

// Initialize auth and security
initAuth();
initSecurity();

// Check authentication and permissions
requireLogin();
if (!hasPermission('users.edit')) {
    setFlashMessage('error', 'Access denied. You do not have permission to edit users.');
    header('Location: index.php');
    exit();
}

// Check session expiration
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > 5600)) {
    // Session expired (30 minutes)
    session_unset();
    session_destroy();
    setFlashMessage('error', 'Your session has expired. Please log in again.');
    header('Location: ../../index.php');
    exit();
}

$pageTitle = 'Edit User';
$currentUser = getCurrentUser();
$userDisplayName = getUserDisplayName($currentUser);
$userId = intval($_GET['id'] ?? 0);

if ($userId <= 0) {
    setFlashMessage('error', 'Invalid user ID.');
    header('Location: index.php');
    exit();
}

// Get user data
try {
    $db = new Database();
    $user = $db->fetchRow("SELECT u.*, ur.role_name FROM users u JOIN user_roles ur ON u.role_id = ur.role_id WHERE u.user_id = ?", [$userId]);
    
    if (!$user) {
        setFlashMessage('error', 'User not found.');
        header('Location: index.php');
        exit();
    }
    
    // Prevent non-Super Admins from editing Super Admin accounts
    if ($user['role_name'] === 'Super Admin' && !isSuperAdmin()) {
        setFlashMessage('error', 'Access denied. Only Super Admins can edit Super Admin accounts.');
        header('Location: index.php');
        exit();
    }
    
    // Prevent editing own account role if not super admin
    $canEditRole = true;
    $canChangeStatus = true;
    
    if ($userId == getCurrentUserId()) {
        if (!isSuperAdmin()) {
            $canEditRole = false;
        }
        $canChangeStatus = false; // Cannot deactivate own account
    }
    
} catch (Exception $e) {
    writeLog("Error fetching user for edit: " . $e->getMessage(), 'ERROR');
    setFlashMessage('error', 'An error occurred while loading user data.');
    header('Location: index.php');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate CSRF token
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token. Please try again.');
        }
        
        // Sanitize and validate inputs
        $firstName = sanitizeInput($_POST['first_name'] ?? '');
        $lastName = sanitizeInput($_POST['last_name'] ?? '');
        $username = sanitizeInput($_POST['username'] ?? '');
        $email = sanitizeInput($_POST['email'] ?? '');
        $phone = sanitizeInput($_POST['phone'] ?? '');
        $roleId = intval($_POST['role_id'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $changePassword = isset($_POST['change_password']);
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        // Validation
        $errors = [];
        
        if (empty($firstName)) {
            $errors[] = 'First name is required.';
        }
        
        if (empty($lastName)) {
            $errors[] = 'Last name is required.';
        }
        
        if (empty($username)) {
            $errors[] = 'Username is required.';
        } elseif (strlen($username) < 3) {
            $errors[] = 'Username must be at least 3 characters long.';
        }
        
        if (empty($email)) {
            $errors[] = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        
        if ($canEditRole && $roleId <= 0) {
            $errors[] = 'Please select a valid role.';
        }
        
        // Password validation (only if changing password)
        if ($changePassword) {
            if (empty($password)) {
                $errors[] = 'Password is required when changing password.';
            } elseif (strlen($password) < 6) {
                $errors[] = 'Password must be at least 6 characters long.';
            }
            
            if ($password !== $confirmPassword) {
                $errors[] = 'Passwords do not match.';
            }
        }
        
        // Prevent user from deactivating themselves
        if ($userId == getCurrentUserId() && $isActive == 0) {
            $errors[] = 'You cannot deactivate your own account.';
        }
        
        if (empty($errors)) {
            // Check if username already exists (excluding current user)
            $existingUser = $db->fetchRow("SELECT user_id FROM users WHERE username = ? AND user_id != ?", [$username, $userId]);
            if ($existingUser) {
                $errors[] = 'Username already exists. Please choose a different username.';
            }
            
            // Check if email already exists (excluding current user)
            $existingEmail = $db->fetchRow("SELECT user_id FROM users WHERE email = ? AND user_id != ?", [$email, $userId]);
            if ($existingEmail) {
                $errors[] = 'Email already exists. Please use a different email address.';
            }
            
            if (empty($errors)) {
                // Store old values for audit
                $oldValues = [
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'role_id' => $user['role_id'],
                    'first_name' => $user['first_name'],
                    'last_name' => $user['last_name'],
                    'phone' => $user['phone'],
                    'is_active' => $user['is_active']
                ];
                
                // Prepare update query
                $updateFields = [
                    'first_name = ?',
                    'last_name = ?',
                    'username = ?',
                    'email = ?',
                    'phone = ?',
                    'updated_at = NOW()'
                ];
                
                $params = [$firstName, $lastName, $username, $email, $phone];
                
                // Add role if user can edit it
                if ($canEditRole) {
                    $updateFields[] = 'role_id = ?';
                    $params[] = $roleId;
                }
                
                // Add status if user can change it
                if ($canChangeStatus) {
                    $updateFields[] = 'is_active = ?';
                    $params[] = $isActive;
                }
                
                // Add password if changing
                if ($changePassword) {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $updateFields[] = 'password_hash = ?';
                    $updateFields[] = 'first_login = 1'; // Reset first login flag
                    $params[] = $passwordHash;
                }
                
                $params[] = $userId; // For WHERE clause
                
                $query = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE user_id = ?";
                
                if ($db->execute($query, $params)) {
                    // Log the action
                    writeLog("User updated: ID $userId, Username: $username by User ID: " . getCurrentUserId(), 'INFO');
                    
                    // Store new values for audit
                    $newValues = [
                        'username' => $username,
                        'email' => $email,
                        'role_id' => $canEditRole ? $roleId : $user['role_id'],
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'phone' => $phone,
                        'is_active' => $canChangeStatus ? $isActive : $user['is_active']
                    ];
                    
                    // Log audit trail
                    $auditData = [
                        'user_id' => getCurrentUserId(),
                        'action' => 'UPDATE_USER',
                        'table_name' => 'users',
                        'record_id' => $userId,
                        'old_values' => json_encode($oldValues),
                        'new_values' => json_encode($newValues),
                        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
                    ];
                    
                    $auditQuery = "INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                    $db->execute($auditQuery, array_values($auditData));
                    
                    $message = $changePassword ? 
                        "User '$firstName $lastName' has been updated successfully! Password was also changed." :
                        "User '$firstName $lastName' has been updated successfully!";
                    
                    setFlashMessage('success', $message);
                    header('Location: index.php');
                    exit();
                } else {
                    $errors[] = 'Failed to update user. Please try again.';
                }
            }
        }
        
        if (!empty($errors)) {
            foreach ($errors as $error) {
                setFlashMessage('error', $error);
            }
        }
        
    } catch (Exception $e) {
        writeLog("Edit user error: " . $e->getMessage(), 'ERROR');
        setFlashMessage('error', 'An error occurred: ' . $e->getMessage());
    }
}

// Get roles for dropdown
try {
    $roles = $db->fetchAll("SELECT * FROM user_roles ORDER BY role_name");
} catch (Exception $e) {
    writeLog("Error fetching roles: " . $e->getMessage(), 'ERROR');
    $roles = [];
}

// Generate CSRF token
$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - <?php echo APP_NAME; ?></title>
    
    <!-- Multiple Icon Sources -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://use.fontawesome.com/releases/v6.4.0/css/all.css">
    
    <!-- Bootstrap for backup -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
            overflow-x: hidden;
        }
        
        /* Custom Icons (fallback if Font Awesome fails) */
        .icon-dashboard::before { content: "📊"; }
        .icon-users::before { content: "👥"; }
        .icon-building::before { content: "🏢"; }
        .icon-home::before { content: "🏠"; }
        .icon-map::before { content: "🗺️"; }
        .icon-invoice::before { content: "📄"; }
        .icon-credit::before { content: "💳"; }
        .icon-tags::before { content: "🏷️"; }
        .icon-chart::before { content: "📈"; }
        .icon-bell::before { content: "🔔"; }
        .icon-cog::before { content: "⚙️"; }
        .icon-receipt::before { content: "🧾"; }
        .icon-menu::before { content: "☰"; }
        .icon-logout::before { content: "🚪"; }
        .icon-user::before { content: "👤"; }
        .icon-edit::before { content: "✏️"; }
        .icon-key::before { content: "🔑"; }
        .icon-lock::before { content: "🔒"; }
        
        /* Top Navigation */
        .top-nav {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 20px;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .nav-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .toggle-btn {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            font-size: 18px;
            padding: 10px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .toggle-btn:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .brand {
            font-size: 24px;
            font-weight: bold;
            color: white;
            text-decoration: none;
        }
        
        .user-section {
            display: flex;
            align-items: center;
            gap: 15px;
            position: relative;
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            padding: 8px 12px;
            border-radius: 10px;
            transition: all 0.3s;
            position: relative;
        }
        
        .user-profile:hover {
            background: rgba(255,255,255,0.1);
        }
        
        .user-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(255,255,255,0.3) 0%, rgba(255,255,255,0.1) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            border: 2px solid rgba(255,255,255,0.2);
            transition: all 0.3s;
        }
        
        .user-profile:hover .user-avatar {
            transform: scale(1.05);
            border-color: rgba(255,255,255,0.4);
        }
        
        .user-info {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }
        
        .user-name {
            font-weight: 600;
            font-size: 14px;
            color: white;
        }
        
        .user-role {
            font-size: 12px;
            opacity: 0.8;
            color: rgba(255,255,255,0.8);
        }
        
        .dropdown-arrow {
            margin-left: 8px;
            font-size: 12px;
            transition: transform 0.3s;
        }
        
        .user-profile.active .dropdown-arrow {
            transform: rotate(180deg);
        }
        
        /* User Dropdown */
        .user-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            min-width: 220px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            z-index: 1001;
            overflow: hidden;
            margin-top: 10px;
        }
        
        .user-dropdown.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }
        
        .dropdown-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            text-align: center;
        }
        
        .dropdown-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 24px;
            margin: 0 auto 10px;
            border: 3px solid rgba(255,255,255,0.3);
        }
        
        .dropdown-name {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .dropdown-role {
            font-size: 12px;
            opacity: 0.9;
            background: rgba(255,255,255,0.2);
            padding: 4px 12px;
            border-radius: 15px;
            display: inline-block;
        }
        
        .dropdown-menu {
            padding: 0;
        }
        
        .dropdown-item {
            display: flex;
            align-items: center;
            padding: 15px 20px;
            color: #2d3748;
            text-decoration: none;
            transition: all 0.3s;
            border-bottom: 1px solid #f7fafc;
        }
        
        .dropdown-item:hover {
            background: #f7fafc;
            color: #667eea;
            transform: translateX(5px);
        }
        
        .dropdown-item:last-child {
            border-bottom: none;
        }
        
        .dropdown-item i {
            width: 20px;
            margin-right: 12px;
            text-align: center;
        }
        
        .dropdown-item.logout {
            color: #e53e3e;
            border-top: 2px solid #fed7d7;
        }
        
        .dropdown-item.logout:hover {
            background: #fed7d7;
            color: #c53030;
        }
        
        /* Layout */
        .container {
            margin-top: 80px;
            display: flex;
            min-height: calc(100vh - 80px);
        }
        
        /* Sidebar */
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, #2d3748 0%, #1a202c 100%);
            color: white;
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        .sidebar.hidden {
            width: 0;
            min-width: 0;
        }
        
        .sidebar-content {
            width: 280px;
            padding: 20px 0;
        }
        
        .nav-section {
            margin-bottom: 30px;
        }
        
        .nav-title {
            color: #a0aec0;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
            padding: 0 20px;
            margin-bottom: 10px;
            letter-spacing: 1px;
        }
        
        .nav-item {
            margin-bottom: 2px;
        }
        
        .nav-link {
            color: #e2e8f0;
            text-decoration: none;
            padding: 12px 20px;
            display: block;
            transition: all 0.3s;
            border-left: 3px solid transparent;
        }
        
        .nav-link:hover {
            background: rgba(255,255,255,0.1);
            color: white;
            border-left-color: #667eea;
        }
        
        .nav-link.active {
            background: rgba(102, 126, 234, 0.3);
            color: white;
            border-left-color: #667eea;
        }
        
        .nav-icon {
            display: inline-block;
            width: 20px;
            margin-right: 12px;
            text-align: center;
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            padding: 30px;
            background: #f8f9fa;
            transition: all 0.3s ease;
        }
        
        /* Breadcrumb */
        .breadcrumb-nav {
            background: white;
            border-radius: 15px;
            padding: 20px 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
            color: #64748b;
        }
        
        .breadcrumb a {
            color: #667eea;
            text-decoration: none;
        }
        
        .breadcrumb-current {
            color: #2d3748;
            font-weight: 600;
        }
        
        /* Page Header */
        .page-header {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .page-title {
            font-size: 28px;
            font-weight: bold;
            color: #2d3748;
            margin: 0;
        }
        
        .back-btn {
            background: #64748b;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }
        
        .back-btn:hover {
            background: #475569;
            color: white;
            transform: translateY(-1px);
        }
        
        /* User Info Card */
        .user-info-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .user-avatar-section {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .user-avatar-large {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 32px;
        }
        
        .user-basic-info h3 {
            color: #2d3748;
            margin: 0 0 5px 0;
        }
        
        .user-basic-info p {
            color: #64748b;
            margin: 0;
        }
        
        .user-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .stat-item {
            text-align: center;
            padding: 15px;
            background: #f8fafc;
            border-radius: 10px;
        }
        
        .stat-value {
            font-size: 20px;
            font-weight: bold;
            color: #2d3748;
        }
        
        .stat-label {
            font-size: 12px;
            color: #64748b;
            text-transform: uppercase;
            margin-top: 5px;
        }
        
        /* Form Card */
        .form-card {
            background: white;
            border-radius: 15px;
            padding: 40px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin-bottom: 25px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        
        .form-label {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .form-label.required::after {
            content: " *";
            color: #ef4444;
        }
        
        .form-control {
            padding: 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
            background: white;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .form-control:disabled {
            background: #f8fafc;
            color: #64748b;
            cursor: not-allowed;
        }
        
        .form-help {
            font-size: 12px;
            color: #64748b;
            margin-top: 5px;
        }
        
        /* Password Section */
        .password-section {
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
        }
        
        .password-toggle {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .password-fields {
            display: none;
        }
        
        .password-fields.show {
            display: block;
        }
        
        /* Checkbox Group */
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
        }
        
        .checkbox-input {
            width: 20px;
            height: 20px;
            accent-color: #667eea;
        }
        
        .checkbox-label {
            font-weight: 500;
            color: #2d3748;
            cursor: pointer;
        }
        
        /* Warning Box */
        .warning-box {
            background: #fef3c7;
            border: 2px solid #f59e0b;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            color: #92400e;
        }
        
        .warning-box h4 {
            margin: 0 0 8px 0;
            color: #92400e;
        }
        
        /* Form Actions */
        .form-actions {
            display: flex;
            gap: 15px;
            padding-top: 30px;
            border-top: 2px solid #f1f5f9;
            margin-top: 30px;
        }
        
        .btn {
            padding: 15px 30px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            justify-content: center;
            font-size: 14px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }
        
        .btn-secondary {
            background: #64748b;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #475569;
            color: white;
        }
        
        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid;
        }
        
        .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border-left-color: #ef4444;
        }
        
        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border-left-color: #22c55e;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                height: 100%;
                z-index: 999;
                transform: translateX(-100%);
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            .sidebar.hidden {
                transform: translateX(-100%);
                width: 280px;
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .form-row {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .header-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .user-stats {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        /* Animations */
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <div class="top-nav">
        <div class="nav-left">
            <button class="toggle-btn" onclick="toggleSidebar()" id="toggleBtn">
                <i class="fas fa-bars"></i>
                <span class="icon-menu" style="display: none;"></span>
            </button>
            
            <a href="../index.php" class="brand">
                <i class="fas fa-receipt"></i>
                <span class="icon-receipt" style="display: none;"></span>
                <?php echo APP_NAME; ?>
            </a>
        </div>
        
        <div class="user-section">
            <!-- Notification Bell -->
            <div style="position: relative; margin-right: 10px;">
                <a href="../notifications/index.php" style="
                    background: rgba(255,255,255,0.2);
                    border: none;
                    color: white;
                    font-size: 18px;
                    padding: 10px;
                    border-radius: 50%;
                    cursor: pointer;
                    transition: all 0.3s;
                    text-decoration: none;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                " onmouseover="this.style.background='rgba(255,255,255,0.3)'" 
                   onmouseout="this.style.background='rgba(255,255,255,0.2)'">
                    <i class="fas fa-bell"></i>
                    <span class="icon-bell" style="display: none;"></span>
                </a>
                <span class="notification-badge" style="
                    position: absolute;
                    top: -2px;
                    right: -2px;
                    background: #ef4444;
                    color: white;
                    border-radius: 50%;
                    width: 20px;
                    height: 20px;
                    font-size: 11px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-weight: bold;
                    animation: pulse 2s infinite;
                ">3</span>
            </div>
            
            <div class="user-profile" onclick="toggleUserDropdown()" id="userProfile">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($currentUser['first_name'], 0, 1)); ?>
                </div>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($userDisplayName); ?></div>
                    <div class="user-role"><?php echo htmlspecialchars(getCurrentUserRole()); ?></div>
                </div>
                <i class="fas fa-chevron-down dropdown-arrow"></i>
                
                <!-- User Dropdown -->
                <div class="user-dropdown" id="userDropdown">
                    <div class="dropdown-header">
                        <div class="dropdown-avatar">
                            <?php echo strtoupper(substr($currentUser['first_name'], 0, 1)); ?>
                        </div>
                        <div class="dropdown-name"><?php echo htmlspecialchars($userDisplayName); ?></div>
                        <div class="dropdown-role"><?php echo htmlspecialchars(getCurrentUserRole()); ?></div>
                    </div>
                    <div class="dropdown-menu">
                        <a href="view.php?id=<?php echo getCurrentUserId(); ?>" class="dropdown-item">
                            <i class="fas fa-user"></i>
                            <span class="icon-user" style="display: none;"></span>
                            My Profile
                        </a>
                        <a href="../settings/index.php" class="dropdown-item">
                            <i class="fas fa-cog"></i>
                            <span class="icon-cog" style="display: none;"></span>
                            Account Settings
                        </a>
                        <a href="../logs/user_activity.php" class="dropdown-item">
                            <i class="fas fa-history"></i>
                            <span class="icon-chart" style="display: none;"></span>
                            Activity Log
                        </a>
                        <a href="../docs/user_manual.md" class="dropdown-item">
                            <i class="fas fa-question-circle"></i>
                            <span class="icon-bell" style="display: none;"></span>
                            Help & Support
                        </a>
                        <div style="height: 1px; background: #e2e8f0; margin: 10px 0;"></div>
                        <a href="../../auth/logout.php" class="dropdown-item logout">
                            <i class="fas fa-sign-out-alt"></i>
                            <span class="icon-logout" style="display: none;"></span>
                            Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-content">
                <!-- Dashboard -->
                <div class="nav-section">
                    <div class="nav-item">
                        <a href="../index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-tachometer-alt"></i>
                                <span class="icon-dashboard" style="display: none;"></span>
                            </span>
                            Dashboard
                        </a>
                    </div>
                </div>
                
                <!-- Core Management -->
                <div class="nav-section">
                    <div class="nav-title">Core Management</div>
                    <div class="nav-item">
                        <a href="index.php" class="nav-link active">
                            <span class="nav-icon">
                                <i class="fas fa-users"></i>
                                <span class="icon-users" style="display: none;"></span>
                            </span>
                            Users
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../businesses/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-building"></i>
                                <span class="icon-building" style="display: none;"></span>
                            </span>
                            Businesses
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../properties/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-home"></i>
                                <span class="icon-home" style="display: none;"></span>
                            </span>
                            Properties
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../zones/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-map-marked-alt"></i>
                                <span class="icon-map" style="display: none;"></span>
                            </span>
                            Zones & Areas
                        </a>
                    </div>
                </div>
                
                <!-- Billing & Payments -->
                <div class="nav-section">
                    <div class="nav-title">Billing & Payments</div>
                    <div class="nav-item">
                        <a href="../billing/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-file-invoice"></i>
                                <span class="icon-invoice" style="display: none;"></span>
                            </span>
                            Billing
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../payments/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-credit-card"></i>
                                <span class="icon-credit" style="display: none;"></span>
                            </span>
                            Payments
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../fee_structure/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-tags"></i>
                                <span class="icon-tags" style="display: none;"></span>
                            </span>
                            Fee Structure
                        </a>
                    </div>
                </div>
                
                <!-- Reports & System -->
                <div class="nav-section">
                    <div class="nav-title">Reports & System</div>
                    <div class="nav-item">
                        <a href="../reports/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-chart-bar"></i>
                                <span class="icon-chart" style="display: none;"></span>
                            </span>
                            Reports
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../notifications/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-bell"></i>
                                <span class="icon-bell" style="display: none;"></span>
                            </span>
                            Notifications
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../settings/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-cog"></i>
                                <span class="icon-cog" style="display: none;"></span>
                            </span>
                            Settings
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Breadcrumb -->
            <div class="breadcrumb-nav">
                <div class="breadcrumb">
                    <a href="../index.php">Dashboard</a>
                    <span>/</span>
                    <a href="index.php">User Management</a>
                    <span>/</span>
                    <span class="breadcrumb-current">Edit User</span>
                </div>
            </div>

            <!-- Flash Messages -->
            <?php 
            $flashMessages = getFlashMessages();
            if (!empty($flashMessages)): 
            ?>
                <?php foreach ($flashMessages as $message): ?>
                    <div class="alert alert-<?php echo $message['type']; ?>">
                        <?php echo htmlspecialchars($message['message']); ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Page Header -->
            <div class="page-header">
                <div class="header-content">
                    <div>
                        <h1 class="page-title">
                            <i class="fas fa-user-edit"></i>
                            Edit User
                        </h1>
                        <p style="color: #64748b; margin: 5px 0 0 0;">Modify user information and settings</p>
                    </div>
                    <a href="index.php" class="back-btn">
                        <i class="fas fa-arrow-left"></i>
                        Back to Users
                    </a>
                </div>
            </div>

            <!-- User Info Card -->
            <div class="user-info-card">
                <div class="user-avatar-section">
                    <div class="user-avatar-large">
                        <?php echo strtoupper(substr($user['first_name'], 0, 1)); ?>
                    </div>
                    <div class="user-basic-info">
                        <h3><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h3>
                        <p><?php echo htmlspecialchars($user['username']); ?> • <?php echo htmlspecialchars($user['role_name']); ?></p>
                    </div>
                </div>
                
                <div class="user-stats">
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?></div>
                        <div class="stat-label">Status</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $user['last_login'] ? timeAgo($user['last_login']) : 'Never'; ?></div>
                        <div class="stat-label">Last Login</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo formatDate($user['created_at']); ?></div>
                        <div class="stat-label">Created</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $user['first_login'] ? 'Required' : 'Complete'; ?></div>
                        <div class="stat-label">Setup Status</div>
                    </div>
                </div>
            </div>

            <!-- Edit User Form -->
            <div class="form-card">
                <?php if ($userId == getCurrentUserId()): ?>
                    <div class="warning-box">
                        <h4><i class="fas fa-exclamation-triangle"></i> Editing Your Own Account</h4>
                        <p>You are editing your own account. Some restrictions apply for security reasons.</p>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="" id="editUserForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    
                    <!-- Personal Information -->
                    <h3 style="color: #2d3748; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 2px solid #f1f5f9;">
                        <i class="fas fa-user"></i> Personal Information
                    </h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required">First Name</label>
                            <input type="text" name="first_name" class="form-control" 
                                   value="<?php echo htmlspecialchars($_POST['first_name'] ?? $user['first_name']); ?>" 
                                   required maxlength="50" placeholder="Enter first name">
                            <div class="form-help">User's first name</div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label required">Last Name</label>
                            <input type="text" name="last_name" class="form-control" 
                                   value="<?php echo htmlspecialchars($_POST['last_name'] ?? $user['last_name']); ?>" 
                                   required maxlength="50" placeholder="Enter last name">
                            <div class="form-help">User's last name</div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" name="phone" class="form-control" 
                                   value="<?php echo htmlspecialchars($_POST['phone'] ?? $user['phone']); ?>" 
                                   maxlength="20" placeholder="e.g., +233xxxxxxxxx">
                            <div class="form-help">Optional contact number</div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label required">Role</label>
                            <select name="role_id" class="form-control" required <?php echo $canEditRole ? '' : 'disabled'; ?>>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo $role['role_id']; ?>" 
                                            <?php echo (($_POST['role_id'] ?? $user['role_id']) == $role['role_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($role['role_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-help">
                                <?php if (!$canEditRole): ?>
                                    <span style="color: #f59e0b;">⚠️ Cannot change your own role</span>
                                <?php else: ?>
                                    User's system role and permissions
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Account Information -->
                    <h3 style="color: #2d3748; margin: 40px 0 25px 0; padding-bottom: 15px; border-bottom: 2px solid #f1f5f9;">
                        <i class="fas fa-key"></i> Account Information
                    </h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required">Username</label>
                            <input type="text" name="username" class="form-control" 
                                   value="<?php echo htmlspecialchars($_POST['username'] ?? $user['username']); ?>" 
                                   required maxlength="50" placeholder="Enter username" 
                                   pattern="[a-zA-Z0-9_]+" title="Only letters, numbers, and underscores allowed">
                            <div class="form-help">Must be unique, 3+ characters, letters/numbers/underscore only</div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label required">Email Address</label>
                            <input type="email" name="email" class="form-control" 
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? $user['email']); ?>" 
                                   required maxlength="100" placeholder="Enter email address">
                            <div class="form-help">Must be a valid and unique email address</div>
                        </div>
                    </div>
                    
                    <!-- Password Section -->
                    <h3 style="color: #2d3748; margin: 40px 0 25px 0; padding-bottom: 15px; border-bottom: 2px solid #f1f5f9;">
                        <i class="fas fa-lock"></i> Password Settings
                    </h3>
                    
                    <div class="password-section">
                        <div class="password-toggle">
                            <input type="checkbox" name="change_password" id="change_password" class="checkbox-input" 
                                   onchange="togglePasswordFields()">
                            <label for="change_password" class="checkbox-label">Change Password</label>
                        </div>
                        <div class="form-help">Check this box if you want to set a new password</div>
                        
                        <div class="password-fields" id="passwordFields">
                            <div class="form-row" style="margin-top: 20px;">
                                <div class="form-group">
                                    <label class="form-label">New Password</label>
                                    <input type="password" name="password" class="form-control" 
                                           minlength="6" placeholder="Enter new password" id="password">
                                    <div class="form-help">Minimum 6 characters, use strong password</div>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Confirm New Password</label>
                                    <input type="password" name="confirm_password" class="form-control" 
                                           minlength="6" placeholder="Confirm new password" 
                                           id="confirmPassword" oninput="checkPasswordMatch()">
                                    <div class="form-help" id="passwordMatch"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Account Settings -->
                    <h3 style="color: #2d3748; margin: 40px 0 25px 0; padding-bottom: 15px; border-bottom: 2px solid #f1f5f9;">
                        <i class="fas fa-cog"></i> Account Settings
                    </h3>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" name="is_active" id="is_active" class="checkbox-input" 
                                   value="1" <?php echo (($_POST['is_active'] ?? $user['is_active']) == '1') ? 'checked' : ''; ?>
                                   <?php echo $canChangeStatus ? '' : 'disabled'; ?>>
                            <label for="is_active" class="checkbox-label">Active Account</label>
                        </div>
                        <div class="form-help">
                            <?php if (!$canChangeStatus): ?>
                                <span style="color: #f59e0b;">⚠️ Cannot deactivate your own account</span>
                            <?php else: ?>
                                Uncheck to deactivate account (user cannot login)
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Form Actions -->
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-save"></i>
                            Update User Account
                        </button>
                        <a href="index.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i>
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Check if Font Awesome loaded, if not show emoji icons
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                // Check if Font Awesome icons are working
                const testIcon = document.querySelector('.fas.fa-bars');
                if (!testIcon || getComputedStyle(testIcon, ':before').content === 'none') {
                    // Font Awesome didn't load, show emoji fallbacks
                    document.querySelectorAll('.fas, .far').forEach(function(icon) {
                        icon.style.display = 'none';
                    });
                    document.querySelectorAll('[class*="icon-"]').forEach(function(emoji) {
                        emoji.style.display = 'inline';
                    });
                }
            }, 100);
        });

        // Sidebar toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('hidden');
            
            // Save state
            const isHidden = sidebar.classList.contains('hidden');
            localStorage.setItem('sidebarHidden', isHidden);
        }

        // Restore sidebar state
        document.addEventListener('DOMContentLoaded', function() {
            const sidebarHidden = localStorage.getItem('sidebarHidden');
            if (sidebarHidden === 'true') {
                document.getElementById('sidebar').classList.add('hidden');
            }
        });

        // User dropdown toggle
        function toggleUserDropdown() {
            const dropdown = document.getElementById('userDropdown');
            const profile = document.getElementById('userProfile');
            
            dropdown.classList.toggle('show');
            profile.classList.toggle('active');
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('userDropdown');
            const profile = document.getElementById('userProfile');
            
            if (!profile.contains(event.target)) {
                dropdown.classList.remove('show');
                profile.classList.remove('active');
            }
        });

        // Toggle password fields
        function togglePasswordFields() {
            const checkbox = document.getElementById('change_password');
            const fields = document.getElementById('passwordFields');
            const passwordInput = document.getElementById('password');
            const confirmInput = document.getElementById('confirmPassword');
            
            if (checkbox.checked) {
                fields.classList.add('show');
                passwordInput.required = true;
                confirmInput.required = true;
            } else {
                fields.classList.remove('show');
                passwordInput.required = false;
                confirmInput.required = false;
                passwordInput.value = '';
                confirmInput.value = '';
                document.getElementById('passwordMatch').textContent = '';
            }
        }

        // Password match checker
        function checkPasswordMatch() {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirmPassword').value;
            const matchDiv = document.getElementById('passwordMatch');
            
            if (confirmPassword.length === 0) {
                matchDiv.textContent = '';
                matchDiv.style.color = '#64748b';
                return;
            }
            
            if (password === confirmPassword) {
                matchDiv.textContent = '✓ Passwords match';
                matchDiv.style.color = '#059669';
            } else {
                matchDiv.textContent = '✗ Passwords do not match';
                matchDiv.style.color = '#ef4444';
            }
        }

        // Form validation
        document.getElementById('editUserForm').addEventListener('submit', function(e) {
            const changePassword = document.getElementById('change_password').checked;
            
            if (changePassword) {
                const password = document.getElementById('password').value;
                const confirmPassword = document.getElementById('confirmPassword').value;
                
                if (password !== confirmPassword) {
                    e.preventDefault();
                    alert('Passwords do not match. Please check and try again.');
                    return false;
                }
                
                if (password.length < 6) {
                    e.preventDefault();
                    alert('Password must be at least 6 characters long.');
                    return false;
                }
            }
            
            // Disable submit button to prevent double submission
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating User...';
        });

        // Mobile responsiveness
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth <= 768) {
                sidebar.classList.remove('hidden');
            }
        });
    </script>
</body>
</html>