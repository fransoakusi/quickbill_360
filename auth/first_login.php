<?php
/**
 * First Login Password Change for QUICKBILL 305
 * Forces users to change password on first login
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

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: login.php?error=access_denied');
    exit();
}

// Check if this is actually a first login
if (!$_SESSION['first_login']) {
    // User has already changed password, redirect to dashboard
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
            header('Location: login.php?error=invalid_role');
            break;
    }
    exit();
}

$error = '';
$success = '';

// Handle password change form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!verifyCsrfToken()) {
        $error = 'Security validation failed. Please try again.';
    } else {
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (empty($new_password) || empty($confirm_password)) {
            $error = 'Please fill in all password fields.';
        } elseif ($new_password !== $confirm_password) {
            $error = 'Passwords do not match.';
        } else {
            // Validate password strength
            $passwordErrors = validatePasswordStrength($new_password);
            
            if (!empty($passwordErrors)) {
                $error = implode('<br>', $passwordErrors);
            } else {
                // Change password (no current password required for first login)
                $changeResult = changePassword(getCurrentUserId(), '', $new_password);
                
                if ($changeResult['success']) {
                    $success = 'Password changed successfully. Redirecting to dashboard...';
                    
                    // Set auto-redirect
                    $userRole = getCurrentUserRole();
                    $redirectUrl = '../admin/index.php'; // Default
                    
                    switch ($userRole) {
                        case 'Super Admin':
                        case 'Admin':
                            $redirectUrl = '../admin/index.php';
                            break;
                        case 'Officer':
                            $redirectUrl = '../officer/index.php';
                            break;
                        case 'Revenue Officer':
                            $redirectUrl = '../revenue_officer/index.php';
                            break;
                        case 'Data Collector':
                            $redirectUrl = '../data_collector/index.php';
                            break;
                    }
                    
                    // Use JavaScript to redirect after 3 seconds
                    echo "<script>
                        setTimeout(function() {
                            window.location.href = '$redirectUrl';
                        }, 3000);
                    </script>";
                } else {
                    $error = $changeResult['message'];
                }
            }
        }
    }
}

$currentUser = getCurrentUser();
$userDisplayName = getUserDisplayName($currentUser);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - <?php echo APP_NAME; ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome Icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <!-- Custom CSS -->
    <style>
        :root {
            --primary-color: #2563eb;
            --secondary-color: #64748b;
            --success-color: #059669;
            --danger-color: #dc2626;
            --warning-color: #d97706;
            --info-color: #0891b2;
            --light-color: #f8fafc;
            --dark-color: #1e293b;
        }
        
        body {
            background: linear-gradient(135deg, var(--warning-color) 0%, var(--primary-color) 100%);
            min-height: 100vh;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        
        .password-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .password-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            overflow: hidden;
            max-width: 500px;
            width: 100%;
        }
        
        .password-header {
            background: var(--warning-color);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        
        .password-header h1 {
            font-size: 1.5rem;
            font-weight: 600;
            margin: 0;
        }
        
        .password-header p {
            margin: 0.5rem 0 0 0;
            opacity: 0.9;
            font-size: 0.9rem;
        }
        
        .password-body {
            padding: 2rem;
        }
        
        .welcome-message {
            background: #f0f9ff;
            border: 1px solid #0891b2;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            color: #0c4a6e;
        }
        
        .security-requirements {
            background: #fefce8;
            border: 1px solid var(--warning-color);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .security-requirements h6 {
            color: #92400e;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }
        
        .security-requirements ul {
            margin: 0;
            padding-left: 1.2rem;
            font-size: 0.9rem;
        }
        
        .security-requirements li {
            color: #78350f;
            margin-bottom: 0.25rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-label {
            font-weight: 500;
            color: var(--dark-color);
            margin-bottom: 0.5rem;
        }
        
        .form-control {
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 0.75rem;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(37, 99, 235, 0.25);
        }
        
        .input-group-text {
            border: 2px solid #e2e8f0;
            border-right: none;
            background-color: #f8fafc;
            color: var(--secondary-color);
        }
        
        .input-group .form-control {
            border-left: none;
        }
        
        .password-strength {
            margin-top: 0.5rem;
        }
        
        .strength-bar {
            height: 4px;
            background: #e2e8f0;
            border-radius: 2px;
            overflow: hidden;
        }
        
        .strength-fill {
            height: 100%;
            width: 0%;
            transition: all 0.3s ease;
            border-radius: 2px;
        }
        
        .strength-weak { background: var(--danger-color); }
        .strength-medium { background: var(--warning-color); }
        .strength-strong { background: var(--success-color); }
        
        .strength-text {
            font-size: 0.8rem;
            margin-top: 0.25rem;
            font-weight: 500;
        }
        
        .btn-change-password {
            background: var(--primary-color);
            border: none;
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
            width: 100%;
            transition: background-color 0.15s ease-in-out;
        }
        
        .btn-change-password:hover {
            background: #1d4ed8;
            color: white;
        }
        
        .btn-change-password:disabled {
            background: var(--secondary-color);
            cursor: not-allowed;
        }
        
        .alert {
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
        
        .loading {
            display: none;
        }
        
        .loading.show {
            display: inline-block;
        }
        
        @media (max-width: 576px) {
            .password-container {
                padding: 10px;
            }
            
            .password-header,
            .password-body {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="password-container">
        <div class="password-card">
            <div class="password-header">
                <i class="fas fa-shield-alt fa-2x mb-3"></i>
                <h1>Security Setup Required</h1>
                <p>First time login - Please change your password</p>
            </div>
            
            <div class="password-body">
                <div class="welcome-message">
                    <i class="fas fa-user-circle me-2"></i>
                    Welcome, <strong><?php echo htmlspecialchars($userDisplayName); ?></strong>!<br>
                    <small>Role: <?php echo htmlspecialchars(getCurrentUserRole()); ?></small>
                </div>
                
                <div class="security-requirements">
                    <h6><i class="fas fa-lock me-2"></i>Password Requirements</h6>
                    <ul>
                        <li>At least <?php echo PASSWORD_MIN_LENGTH; ?> characters long</li>
                        <li>At least one uppercase letter (A-Z)</li>
                        <li>At least one lowercase letter (a-z)</li>
                        <li>At least one number (0-9)</li>
                        <li>At least one special character (!@#$%^&*)</li>
                    </ul>
                </div>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success" role="alert">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="" id="passwordForm">
                    <?php echo csrfField(); ?>
                    
                    <div class="form-group">
                        <label for="new_password" class="form-label">New Password</label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="fas fa-key"></i>
                            </span>
                            <input type="password" 
                                   class="form-control" 
                                   id="new_password" 
                                   name="new_password" 
                                   placeholder="Enter your new password"
                                   required>
                            <button type="button" 
                                    class="btn btn-outline-secondary" 
                                    id="toggleNewPassword">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="password-strength">
                            <div class="strength-bar">
                                <div class="strength-fill" id="strengthBar"></div>
                            </div>
                            <div class="strength-text" id="strengthText"></div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="fas fa-key"></i>
                            </span>
                            <input type="password" 
                                   class="form-control" 
                                   id="confirm_password" 
                                   name="confirm_password" 
                                   placeholder="Confirm your new password"
                                   required>
                        </div>
                        <div class="invalid-feedback" id="passwordMatchError"></div>
                    </div>
                    
                    <button type="submit" class="btn btn-change-password" id="changePasswordBtn">
                        <span class="loading" id="loadingSpinner">
                            <i class="fas fa-spinner fa-spin me-2"></i>
                        </span>
                        <span id="buttonText">Change Password & Continue</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Password visibility toggle
            const toggleNewPassword = document.getElementById('toggleNewPassword');
            const newPasswordInput = document.getElementById('new_password');
            
            toggleNewPassword.addEventListener('click', function() {
                const type = newPasswordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                newPasswordInput.setAttribute('type', type);
                
                const icon = this.querySelector('i');
                icon.classList.toggle('fa-eye');
                icon.classList.toggle('fa-eye-slash');
            });
            
            // Password strength checker
            const strengthBar = document.getElementById('strengthBar');
            const strengthText = document.getElementById('strengthText');
            
            function checkPasswordStrength(password) {
                let score = 0;
                let feedback = [];
                
                if (password.length >= <?php echo PASSWORD_MIN_LENGTH; ?>) score++;
                else feedback.push('At least <?php echo PASSWORD_MIN_LENGTH; ?> characters');
                
                if (/[A-Z]/.test(password)) score++;
                else feedback.push('Uppercase letter');
                
                if (/[a-z]/.test(password)) score++;
                else feedback.push('Lowercase letter');
                
                if (/[0-9]/.test(password)) score++;
                else feedback.push('Number');
                
                if (/[^A-Za-z0-9]/.test(password)) score++;
                else feedback.push('Special character');
                
                let strength = 'weak';
                let width = (score / 5) * 100;
                
                if (score >= 4) {
                    strength = 'strong';
                    strengthText.textContent = 'Strong password';
                    strengthText.style.color = '#059669';
                } else if (score >= 3) {
                    strength = 'medium';
                    strengthText.textContent = 'Medium strength';
                    strengthText.style.color = '#d97706';
                } else {
                    strength = 'weak';
                    strengthText.textContent = 'Weak password - Missing: ' + feedback.join(', ');
                    strengthText.style.color = '#dc2626';
                }
                
                strengthBar.className = 'strength-fill strength-' + strength;
                strengthBar.style.width = width + '%';
                
                return score >= 5;
            }
            
            newPasswordInput.addEventListener('input', function() {
                checkPasswordStrength(this.value);
            });
            
            // Password match validation
            const confirmPasswordInput = document.getElementById('confirm_password');
            const errorElement = document.getElementById('passwordMatchError');
            
            function validatePasswordMatch() {
                const newPassword = newPasswordInput.value;
                const confirmPassword = confirmPasswordInput.value;
                
                if (confirmPassword && newPassword !== confirmPassword) {
                    confirmPasswordInput.classList.add('is-invalid');
                    errorElement.textContent = 'Passwords do not match';
                    errorElement.style.display = 'block';
                } else {
                    confirmPasswordInput.classList.remove('is-invalid');
                    errorElement.style.display = 'none';
                }
            }
            
            confirmPasswordInput.addEventListener('input', validatePasswordMatch);
            
            // Form submission
            const passwordForm = document.getElementById('passwordForm');
            const changePasswordBtn = document.getElementById('changePasswordBtn');
            const loadingSpinner = document.getElementById('loadingSpinner');
            const buttonText = document.getElementById('buttonText');
            
            passwordForm.addEventListener('submit', function(e) {
                const newPassword = newPasswordInput.value;
                const confirmPassword = confirmPasswordInput.value;
                
                if (!checkPasswordStrength(newPassword)) {
                    e.preventDefault();
                    alert('Please ensure your password meets all requirements.');
                    return;
                }
                
                if (newPassword !== confirmPassword) {
                    e.preventDefault();
                    alert('Passwords do not match.');
                    return;
                }
                
                changePasswordBtn.disabled = true;
                loadingSpinner.classList.add('show');
                buttonText.textContent = 'Changing Password...';
            });
            
            // Auto-focus on new password field
            newPasswordInput.focus();
        });
    </script>
</body>
</html>