 <?php
/**
 * User Management - Delete User
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
if (!hasPermission('users.delete')) {
    setFlashMessage('error', 'Access denied. You do not have permission to delete users.');
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

$currentUser = getCurrentUser();
$userId = intval($_GET['id'] ?? 0);

if ($userId <= 0) {
    setFlashMessage('error', 'Invalid user ID.');
    header('Location: index.php');
    exit();
}

// Prevent users from deleting themselves
if ($userId == getCurrentUserId()) {
    setFlashMessage('error', 'You cannot delete your own account.');
    header('Location: index.php');
    exit();
}

try {
    $db = new Database();
    
    // Get user information before deletion
    $user = $db->fetchRow("SELECT u.*, ur.role_name FROM users u JOIN user_roles ur ON u.role_id = ur.role_id WHERE u.user_id = ?", [$userId]);
    
    if (!$user) {
        setFlashMessage('error', 'User not found.');
        header('Location: index.php');
        exit();
    }
    
    // Prevent deletion of the last admin user
    if (in_array($user['role_name'], ['Admin', 'Super Admin'])) {
        $adminCount = $db->fetchRow("
            SELECT COUNT(*) as count 
            FROM users u 
            JOIN user_roles ur ON u.role_id = ur.role_id 
            WHERE ur.role_name IN ('Admin', 'Super Admin') 
            AND u.is_active = 1 
            AND u.user_id != ?", [$userId]);
        
        if ($adminCount['count'] <= 0) {
            setFlashMessage('error', 'Cannot delete the last active administrator account.');
            header('Location: index.php');
            exit();
        }
    }
    
    // Handle confirmation
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Validate CSRF token
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            setFlashMessage('error', 'Invalid security token. Please try again.');
            header('Location: index.php');
            exit();
        }
        
        $deleteType = $_POST['delete_type'] ?? 'soft';
        $userName = $user['first_name'] . ' ' . $user['last_name'];
        
        if ($deleteType === 'soft') {
            // Soft delete - deactivate user
            $query = "UPDATE users SET is_active = 0, updated_at = NOW() WHERE user_id = ?";
            
            if ($db->execute($query, [$userId])) {
                // Log the action
                writeLog("User soft deleted: ID $userId, Username: {$user['username']} by User ID: " . getCurrentUserId(), 'INFO');
                
                // Log audit trail
                $auditData = [
                    'user_id' => getCurrentUserId(),
                    'action' => 'SOFT_DELETE_USER',
                    'table_name' => 'users',
                    'record_id' => $userId,
                    'old_values' => json_encode(['is_active' => 1]),
                    'new_values' => json_encode(['is_active' => 0]),
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
                ];
                
                $auditQuery = "INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                $db->execute($auditQuery, array_values($auditData));
                
                setFlashMessage('success', "User '$userName' has been deactivated successfully. The account can be reactivated later if needed.");
            } else {
                setFlashMessage('error', 'Failed to deactivate user. Please try again.');
            }
            
        } else {
            // Hard delete - permanent removal
            // Note: In production, you might want to restrict this to Super Admin only
            
            // Start transaction
            $db->beginTransaction();
            
            try {
                // Delete audit logs for this user
                $db->execute("DELETE FROM audit_logs WHERE user_id = ?", [$userId]);
                
                // Delete the user
                $db->execute("DELETE FROM users WHERE user_id = ?", [$userId]);
                
                // Commit transaction
                $db->commit();
                
                // Log the action
                writeLog("User permanently deleted: ID $userId, Username: {$user['username']} by User ID: " . getCurrentUserId(), 'WARNING');
                
                // Log audit trail (for the current user)
                $auditData = [
                    'user_id' => getCurrentUserId(),
                    'action' => 'HARD_DELETE_USER',
                    'table_name' => 'users',
                    'record_id' => $userId,
                    'old_values' => json_encode([
                        'username' => $user['username'],
                        'email' => $user['email'],
                        'first_name' => $user['first_name'],
                        'last_name' => $user['last_name']
                    ]),
                    'new_values' => json_encode(['deleted' => true]),
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
                ];
                
                $auditQuery = "INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                $db->execute($auditQuery, array_values($auditData));
                
                setFlashMessage('success', "User '$userName' has been permanently deleted from the system.");
                
            } catch (Exception $e) {
                $db->rollback();
                throw $e;
            }
        }
        
        header('Location: index.php');
        exit();
    }
    
} catch (Exception $e) {
    writeLog("Delete user error: " . $e->getMessage(), 'ERROR');
    setFlashMessage('error', 'An error occurred: ' . $e->getMessage());
    header('Location: index.php');
    exit();
}

// Generate CSRF token
$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete User - <?php echo APP_NAME; ?></title>
    
    <!-- Multiple Icon Sources -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    
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
        
        .container {
            margin-top: 80px;
            display: flex;
            min-height: calc(100vh - 80px);
            align-items: center;
            justify-content: center;
            padding: 30px;
        }
        
        /* Delete Confirmation Modal */
        .delete-modal {
            background: white;
            border-radius: 20px;
            padding: 40px;
            max-width: 500px;
            width: 100%;
            box-shadow: 0 20px 80px rgba(0,0,0,0.2);
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .delete-modal::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: linear-gradient(90deg, #ef4444, #dc2626, #b91c1c);
        }
        
        .danger-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 25px;
            border: 4px solid #fef2f2;
        }
        
        .danger-icon i {
            font-size: 32px;
            color: #dc2626;
        }
        
        .delete-title {
            font-size: 24px;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 10px;
        }
        
        .delete-subtitle {
            color: #64748b;
            margin-bottom: 30px;
            font-size: 16px;
        }
        
        /* User Info Card */
        .user-info {
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .user-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 24px;
        }
        
        .user-details h4 {
            color: #2d3748;
            margin: 0 0 5px 0;
        }
        
        .user-details p {
            color: #64748b;
            margin: 0;
            font-size: 14px;
        }
        
        /* Delete Options */
        .delete-options {
            text-align: left;
            margin-bottom: 30px;
        }
        
        .option-group {
            margin-bottom: 20px;
        }
        
        .option-radio {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .option-radio:hover {
            border-color: #667eea;
            background: #f8fafc;
        }
        
        .option-radio.selected {
            border-color: #667eea;
            background: #f0f4ff;
        }
        
        .option-radio input[type="radio"] {
            margin-top: 2px;
            accent-color: #667eea;
        }
        
        .option-content h5 {
            color: #2d3748;
            margin: 0 0 5px 0;
            font-size: 16px;
        }
        
        .option-content p {
            color: #64748b;
            margin: 0;
            font-size: 14px;
            line-height: 1.4;
        }
        
        /* Warning Box */
        .warning-box {
            background: #fef3c7;
            border: 2px solid #f59e0b;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 25px;
            text-align: left;
        }
        
        .warning-box h5 {
            color: #92400e;
            margin: 0 0 8px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .warning-box p {
            color: #92400e;
            margin: 0;
            font-size: 14px;
        }
        
        /* Form Actions */
        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: center;
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
            min-width: 120px;
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(239, 68, 68, 0.3);
        }
        
        .btn-secondary {
            background: #64748b;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #475569;
            color: white;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 20px;
            }
            
            .delete-modal {
                padding: 30px 20px;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .user-info {
                flex-direction: column;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <div class="top-nav">
        <div class="nav-left">
            <button class="toggle-btn" onclick="window.location.href='index.php'">
                <i class="fas fa-arrow-left"></i>
            </button>
            <a href="../index.php" class="brand">
                <i class="fas fa-receipt"></i>
                <?php echo APP_NAME; ?>
            </a>
        </div>
    </div>

    <div class="container">
        <!-- Delete Confirmation Modal -->
        <div class="delete-modal">
            <div class="danger-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            
            <h2 class="delete-title">Delete User Account</h2>
            <p class="delete-subtitle">This action will remove the user from the system</p>
            
            <!-- User Information -->
            <div class="user-info">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($user['first_name'], 0, 1)); ?>
                </div>
                <div class="user-details">
                    <h4><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h4>
                    <p><?php echo htmlspecialchars($user['username']); ?> • <?php echo htmlspecialchars($user['role_name']); ?></p>
                    <p>Email: <?php echo htmlspecialchars($user['email']); ?></p>
                </div>
            </div>
            
            <!-- Delete Form -->
            <form method="POST" action="" id="deleteForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                
                <div class="delete-options">
                    <div class="option-group">
                        <label class="option-radio selected" onclick="selectOption(this, 'soft')">
                            <input type="radio" name="delete_type" value="soft" checked>
                            <div class="option-content">
                                <h5>Deactivate Account (Recommended)</h5>
                                <p>User account will be disabled but data is preserved. Account can be reactivated later if needed.</p>
                            </div>
                        </label>
                    </div>
                    
                    <div class="option-group">
                        <label class="option-radio" onclick="selectOption(this, 'hard')">
                            <input type="radio" name="delete_type" value="hard">
                            <div class="option-content">
                                <h5>Permanent Deletion</h5>
                                <p>Completely remove user and all associated data. This action cannot be undone.</p>
                            </div>
                        </label>
                    </div>
                </div>
                
                <div class="warning-box" id="warningBox">
                    <h5>
                        <i class="fas fa-info-circle"></i>
                        Deactivation Notice
                    </h5>
                    <p>The user account will be deactivated and the user will no longer be able to log in. All data will be preserved and the account can be reactivated by an administrator at any time.</p>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-danger" id="deleteBtn">
                        <i class="fas fa-user-times"></i>
                        Deactivate User
                    </button>
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i>
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>

    <script>
        function selectOption(element, type) {
            // Remove selected class from all options
            document.querySelectorAll('.option-radio').forEach(option => {
                option.classList.remove('selected');
            });
            
            // Add selected class to clicked option
            element.classList.add('selected');
            
            // Update warning box and button text
            const warningBox = document.getElementById('warningBox');
            const deleteBtn = document.getElementById('deleteBtn');
            
            if (type === 'soft') {
                warningBox.innerHTML = `
                    <h5>
                        <i class="fas fa-info-circle"></i>
                        Deactivation Notice
                    </h5>
                    <p>The user account will be deactivated and the user will no longer be able to log in. All data will be preserved and the account can be reactivated by an administrator at any time.</p>
                `;
                warningBox.style.background = '#dbeafe';
                warningBox.style.borderColor = '#3b82f6';
                warningBox.style.color = '#1e40af';
                
                deleteBtn.innerHTML = '<i class="fas fa-user-times"></i> Deactivate User';
                deleteBtn.style.background = 'linear-gradient(135deg, #f59e0b 0%, #d97706 100%)';
            } else {
                warningBox.innerHTML = `
                    <h5>
                        <i class="fas fa-exclamation-triangle"></i>
                        Permanent Deletion Warning
                    </h5>
                    <p><strong>This action cannot be undone!</strong> The user account and all associated data will be permanently removed from the system. Consider deactivation instead if you might need to restore the account later.</p>
                `;
                warningBox.style.background = '#fef2f2';
                warningBox.style.borderColor = '#ef4444';
                warningBox.style.color = '#dc2626';
                
                deleteBtn.innerHTML = '<i class="fas fa-trash"></i> Delete Permanently';
                deleteBtn.style.background = 'linear-gradient(135deg, #ef4444 0%, #dc2626 100%)';
            }
        }

        // Form confirmation
        document.getElementById('deleteForm').addEventListener('submit', function(e) {
            const deleteType = document.querySelector('input[name="delete_type"]:checked').value;
            const userName = '<?php echo addslashes($user['first_name'] . ' ' . $user['last_name']); ?>';
            
            let confirmMessage;
            if (deleteType === 'soft') {
                confirmMessage = `Are you sure you want to deactivate ${userName}?\n\nThe user will no longer be able to log in, but their data will be preserved and the account can be reactivated later.`;
            } else {
                confirmMessage = `⚠️ PERMANENT DELETION WARNING ⚠️\n\nAre you absolutely sure you want to permanently delete ${userName}?\n\nThis action CANNOT be undone! All user data will be permanently removed from the system.\n\nType "DELETE" below to confirm:`;
                
                const confirmation = prompt(confirmMessage);
                if (confirmation !== 'DELETE') {
                    e.preventDefault();
                    return false;
                }
            }
            
            if (deleteType === 'soft' && !confirm(confirmMessage)) {
                e.preventDefault();
                return false;
            }
            
            // Disable form to prevent double submission
            const deleteBtn = document.getElementById('deleteBtn');
            deleteBtn.disabled = true;
            deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        });

        // Add animation on load
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.querySelector('.delete-modal');
            modal.style.opacity = '0';
            modal.style.transform = 'translateY(30px)';
            
            setTimeout(() => {
                modal.style.transition = 'all 0.6s ease';
                modal.style.opacity = '1';
                modal.style.transform = 'translateY(0)';
            }, 100);
        });
    </script>
</body>
</html>
