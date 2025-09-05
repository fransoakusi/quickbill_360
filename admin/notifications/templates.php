<?php
/**
 * Notification Templates - Following Admin Structure
 * QUICKBILL 305 - Manage notification templates
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

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: ../../auth/login.php');
    exit();
}

// Check if user is admin
if (!isAdmin()) {
    setFlashMessage('error', 'Access denied. Admin privileges required.');
    header('Location: ../../auth/login.php');
    exit();
}

$currentUser = getCurrentUser();
$userDisplayName = getUserDisplayName($currentUser);

// Initialize database
$db = new Database();

// Create templates table if it doesn't exist
try {
    $db->execute("
        CREATE TABLE IF NOT EXISTS message_templates (
            template_id int(11) NOT NULL AUTO_INCREMENT,
            template_name varchar(100) NOT NULL,
            template_type enum('SMS','Email','System') NOT NULL DEFAULT 'SMS',
            template_content text NOT NULL,
            variables text DEFAULT NULL,
            is_active tinyint(1) DEFAULT 1,
            created_by int(11) DEFAULT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (template_id),
            KEY created_by (created_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
} catch (Exception $e) {
    // Table might already exist
}

// Handle actions
$action = $_GET['action'] ?? '';
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($action) {
        case 'add':
            try {
                $templateName = trim($_POST['template_name'] ?? '');
                $templateType = $_POST['template_type'] ?? 'SMS';
                $templateContent = trim($_POST['template_content'] ?? '');
                $variables = trim($_POST['variables'] ?? '');
                
                // Validation
                $errors = [];
                if (empty($templateName)) $errors[] = 'Template name is required';
                if (empty($templateContent)) $errors[] = 'Template content is required';
                
                // Check for duplicate name
                $existing = $db->fetchRow("SELECT template_id FROM message_templates WHERE template_name = ?", [$templateName]);
                if ($existing) $errors[] = 'Template name already exists';
                
                if (empty($errors)) {
                    $sql = "INSERT INTO message_templates (template_name, template_type, template_content, variables, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)";
                    $params = [
                        $templateName,
                        $templateType,
                        $templateContent,
                        $variables,
                        $currentUser['user_id'],
                        date('Y-m-d H:i:s')
                    ];
                    $stmt = $db->execute($sql, $params);
                    if ($stmt === false) {
                        throw new Exception('Failed to insert template record');
                    }
                    
                    $message = 'Template created successfully';
                    $messageType = 'success';
                } else {
                    $message = implode('<br>', $errors);
                    $messageType = 'error';
                }
            } catch (Exception $e) {
                $message = 'Error creating template: ' . $e->getMessage();
                $messageType = 'error';
            }
            break;
            
        case 'edit':
            try {
                $templateId = (int)($_POST['template_id'] ?? 0);
                $templateName = trim($_POST['template_name'] ?? '');
                $templateType = $_POST['template_type'] ?? 'SMS';
                $templateContent = trim($_POST['template_content'] ?? '');
                $variables = trim($_POST['variables'] ?? '');
                
                // Validation
                $errors = [];
                if ($templateId <= 0) $errors[] = 'Invalid template ID';
                if (empty($templateName)) $errors[] = 'Template name is required';
                if (empty($templateContent)) $errors[] = 'Template content is required';
                
                // Check for duplicate name (excluding current template)
                $existing = $db->fetchRow("SELECT template_id FROM message_templates WHERE template_name = ? AND template_id != ?", [$templateName, $templateId]);
                if ($existing) $errors[] = 'Template name already exists';
                
                if (empty($errors)) {
                    $sql = "UPDATE message_templates SET template_name = ?, template_type = ?, template_content = ?, variables = ?, updated_at = ? WHERE template_id = ?";
                    $params = [
                        $templateName,
                        $templateType,
                        $templateContent,
                        $variables,
                        date('Y-m-d H:i:s'),
                        $templateId
                    ];
                    $stmt = $db->execute($sql, $params);
                    if ($stmt === false) {
                        throw new Exception('Failed to update template record');
                    }
                    
                    $message = 'Template updated successfully';
                    $messageType = 'success';
                } else {
                    $message = implode('<br>', $errors);
                    $messageType = 'error';
                }
            } catch (Exception $e) {
                $message = 'Error updating template: ' . $e->getMessage();
                $messageType = 'error';
            }
            break;
            
        case 'toggle_status':
            try {
                $templateId = (int)($_POST['template_id'] ?? 0);
                $currentStatus = (int)($_POST['current_status'] ?? 0);
                $newStatus = $currentStatus ? 0 : 1;
                
                $sql = "UPDATE message_templates SET is_active = ? WHERE template_id = ?";
                $params = [$newStatus, $templateId];
                $stmt = $db->execute($sql, $params);
                if ($stmt === false) {
                    throw new Exception('Failed to update template status');
                }
                
                $message = 'Template status updated successfully';
                $messageType = 'success';
            } catch (Exception $e) {
                $message = 'Error updating template status: ' . $e->getMessage();
                $messageType = 'error';
            }
            break;
            
        case 'delete':
            try {
                $templateId = (int)($_POST['template_id'] ?? 0);
                
                if ($templateId > 0) {
                    $sql = "DELETE FROM message_templates WHERE template_id = ?";
                    $stmt = $db->execute($sql, [$templateId]);
                    if ($stmt === false) {
                        throw new Exception('Failed to delete template');
                    }
                    $message = 'Template deleted successfully';
                    $messageType = 'success';
                } else {
                    $message = 'Invalid template ID';
                    $messageType = 'error';
                }
            } catch (Exception $e) {
                $message = 'Error deleting template: ' . $e->getMessage();
                $messageType = 'error';
            }
            break;
    }
}

// Get templates
try {
    $templates = $db->fetchAll("
        SELECT t.*, u.first_name, u.last_name 
        FROM message_templates t
        LEFT JOIN users u ON t.created_by = u.user_id
        ORDER BY t.template_type, t.template_name
    ");
} catch (Exception $e) {
    $templates = [];
}

// Get edit template if editing
$editTemplate = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $editId = (int)$_GET['id'];
    try {
        $editTemplate = $db->fetchRow("SELECT * FROM message_templates WHERE template_id = ?", [$editId]);
    } catch (Exception $e) {
        // Template not found
    }
}

// Default templates to create if none exist
$defaultTemplates = [
    [
        'name' => 'Account Created',
        'type' => 'SMS',
        'content' => 'Welcome to QuickBill 305! Your account number is {{account_number}}. Your current bill is GHS {{current_bill}}. Thank you.',
        'variables' => 'account_number, current_bill'
    ],
    [
        'name' => 'Payment Confirmation',
        'type' => 'SMS',
        'content' => 'Payment received! Amount: GHS {{amount}}. Ref: {{payment_ref}}. Balance: GHS {{balance}}. Thank you for your payment.',
        'variables' => 'amount, payment_ref, balance'
    ],
    [
        'name' => 'Bill Reminder',
        'type' => 'SMS',
        'content' => 'Dear {{name}}, your {{year}} bill of GHS {{amount}} is due. Account: {{account_number}}. Please pay to avoid penalties.',
        'variables' => 'name, year, amount, account_number'
    ],
    [
        'name' => 'Defaulter Notice',
        'type' => 'SMS',
        'content' => 'URGENT: Outstanding bill of GHS {{amount}} for account {{account_number}}. Please pay immediately to avoid legal action.',
        'variables' => 'amount, account_number'
    ]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Templates - <?php echo APP_NAME; ?></title>
    
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
        .icon-bell::before { content: "🔔"; }
        .icon-file::before { content: "📄"; }
        .icon-plus::before { content: "➕"; }
        .icon-edit::before { content: "✏️"; }
        .icon-trash::before { content: "🗑️"; }
        .icon-back::before { content: "⬅️"; }
        .icon-save::before { content: "💾"; }
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
        .icon-money::before { content: "💰"; }
        .icon-plus::before { content: "➕"; }
        .icon-server::before { content: "🖥️"; }
        .icon-database::before { content: "💾"; }
        .icon-shield::before { content: "🛡️"; }
        .icon-user-plus::before { content: "👤➕"; }
        .icon-history::before { content: "📜"; }
        .icon-question::before { content: "❓"; }
        
        /* Same styling as admin dashboard */
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
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            padding: 8px 12px;
            border-radius: 10px;
            transition: all 0.3s;
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
            margin-bottom: 20px;
        }
        
        .nav-title {
            color: #a0aec0;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            padding: 10px 20px;
            margin-bottom: 8px;
            letter-spacing: 1.2px;
            border-left: 3px solid transparent;
        }
        
        .nav-item {
            margin-bottom: 2px;
        }
        
        .nav-link {
            color: #e2e8f0;
            text-decoration: none;
            padding: 10px 20px;
            display: block;
            transition: all 0.3s;
            border-left: 3px solid transparent;
            font-size: 14px;
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
        
        .nav-subsection {
            padding-left: 20px;
        }
        
        .nav-sublink {
            color: #cbd5e1;
            text-decoration: none;
            padding: 8px 20px 8px 30px;
            display: block;
            font-size: 13px;
            transition: all 0.3s;
            border-left: 3px solid transparent;
        }
        
        .nav-sublink:hover {
            background: rgba(255,255,255,0.08);
            color: white;
            border-left-color: #7f9cf5;
        }
        
        .nav-sublink.active {
            background: rgba(102, 126, 234, 0.2);
            color: white;
            border-left-color: #7f9cf5;
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            padding: 30px;
            background: #f8f9fa;
            transition: all 0.3s ease;
        }
        
        /* Cards */
        .card {
            background: white;
            border-radius: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
            border: none;
        }
        
        .card-header {
            padding: 20px 25px;
            background: #f7fafc;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .card-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            margin: 0;
        }
        
        .card-body {
            padding: 25px;
        }
        
        /* Form styles */
        .form-control, .form-select {
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            padding: 12px 15px;
            margin-bottom: 15px;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .form-label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }
        
        /* Action Buttons */
        .action-btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            text-align: center;
            text-decoration: none;
            display: inline-block;
            margin-right: 10px;
            margin-bottom: 10px;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            color: white;
            text-decoration: none;
        }
        
        .action-btn.success { background: #48bb78; }
        .action-btn.info { background: #4299e1; }
        .action-btn.warning { background: #ed8936; }
        .action-btn.secondary { background: #718096; }
        .action-btn.danger { background: #e53e3e; }
        .action-btn.small { padding: 8px 12px; font-size: 12px; }
        
        /* Alert styles */
        .alert {
            border-radius: 10px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 20px;
        }
        
        .alert.alert-success {
            background: #f0fff4;
            color: #276749;
            border-left: 4px solid #48bb78;
        }
        
        .alert.alert-danger {
            background: #fed7d7;
            color: #c53030;
            border-left: 4px solid #e53e3e;
        }
        
        .alert.alert-warning {
            background: #fef5e7;
            color: #dd6b20;
            border-left: 4px solid #ed8936;
        }
        
        .alert.alert-info {
            background: #ebf8ff;
            color: #3182ce;
            border-left: 4px solid #4299e1;
        }
        
        /* Template card */
        .template-card {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }
        
        .template-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        
        .template-header {
            background: #f8f9fa;
            padding: 15px 20px;
            border-bottom: 1px solid #e2e8f0;
            border-radius: 10px 10px 0 0;
        }
        
        .template-content {
            padding: 20px;
        }
        
        .template-preview {
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 15px;
            font-family: monospace;
            font-size: 14px;
            white-space: pre-wrap;
            max-height: 150px;
            overflow-y: auto;
            margin-bottom: 15px;
        }
        
        .variable-tag {
            background: #667eea;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            margin: 2px;
            display: inline-block;
        }
        
        .badge {
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge.success { background: #48bb78; color: white; }
        .badge.warning { background: #ed8936; color: white; }
        .badge.danger { background: #e53e3e; color: white; }
        .badge.info { background: #4299e1; color: white; }
        .badge.primary { background: #667eea; color: white; }
        .badge.secondary { background: #718096; color: white; }
        
        /* Modal styles */
        .modal-content {
            border-radius: 15px;
            border: none;
        }
        
        .modal-header {
            background: #f7fafc;
            border-bottom: 1px solid #e2e8f0;
            border-radius: 15px 15px 0 0;
        }
        
        /* Default templates guide */
        .default-templates {
            background: #f7fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
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
            
            .container {
                flex-direction: column;
            }
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
            <div class="user-profile">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($currentUser['first_name'], 0, 1)); ?>
                </div>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($userDisplayName); ?></div>
                    <div class="user-role"><?php echo htmlspecialchars(getCurrentUserRole()); ?></div>
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
                    <div class="nav-title">Overview</div>
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
                        <a href="../users/index.php" class="nav-link">
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
                        <a href="../fee_structure/business_fees.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-tags"></i>
                                <span class="icon-tags" style="display: none;"></span>
                            </span>
                            Fee Structure
                        </a>
                    </div>
                </div>
                
                <!-- Notifications -->
                <div class="nav-section">
                    <div class="nav-title">Notifications</div>
                    <div class="nav-item">
                        <a href="index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-bell"></i>
                                <span class="icon-bell" style="display: none;"></span>
                            </span>
                            Notification Center
                        </a>
                    </div>
                    <div class="nav-subsection">
                        <div class="nav-item">
                            <a href="send_sms.php" class="nav-sublink">
                                <span class="nav-icon">
                                    <i class="fas fa-sms"></i>
                                    <span class="icon-sms" style="display: none;"></span>
                                </span>
                                Send SMS
                            </a>
                        </div>
                        <div class="nav-item">
                            <a href="bulk_notifications.php" class="nav-sublink">
                                <span class="nav-icon">
                                    <i class="fas fa-bullhorn"></i>
                                    <span class="icon-bullhorn" style="display: none;"></span>
                                </span>
                                Bulk Notifications
                            </a>
                        </div>
                        <div class="nav-item">
                            <a href="templates.php" class="nav-sublink active">
                                <span class="nav-icon">
                                    <i class="fas fa-file-alt"></i>
                                    <span class="icon-file" style="display: none;"></span>
                                </span>
                                Templates
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- System -->
                <div class="nav-section">
                    <div class="nav-title">System</div>
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
        <div class="main-content" id="mainContent">
            <!-- Page Header -->
            <div style="margin-bottom: 30px;">
                <h1 style="font-size: 28px; font-weight: bold; color: #2d3748; margin-bottom: 8px;">
                    📄 Notification Templates
                </h1>
                <p style="color: #718096; font-size: 16px;">
                    Create and manage reusable notification templates for SMS, email, and system notifications.
                </p>
            </div>

            <!-- Flash Messages -->
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $messageType === 'error' ? 'danger' : $messageType; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <!-- Action Buttons -->
            <div style="margin-bottom: 20px;">
                <a href="index.php" class="action-btn secondary">
                    <i class="fas fa-arrow-left"></i>
                    <span class="icon-back" style="display: none;"></span>
                    Back to Notifications
                </a>
                <button class="action-btn" onclick="openTemplateModal()">
                    <i class="fas fa-plus"></i>
                    <span class="icon-plus" style="display: none;"></span>
                    Add Template
                </button>
            </div>

            <!-- Default Templates Guide -->
            <?php if (empty($templates)): ?>
                <div class="default-templates">
                    <h5 style="margin-bottom: 15px;">
                        <i class="fas fa-lightbulb" style="color: #ed8936;"></i>
                        Get Started with Templates
                    </h5>
                    <p style="margin-bottom: 15px;">No templates found. Here are some commonly used notification templates you can create:</p>
                    
                    <div class="row">
                        <?php foreach ($defaultTemplates as $template): ?>
                            <div class="col-md-6" style="margin-bottom: 15px;">
                                <div class="card">
                                    <div class="card-body" style="padding: 15px;">
                                        <h6 style="margin-bottom: 10px;">
                                            <?php echo $template['name']; ?>
                                            <span class="badge primary" style="margin-left: 10px;"><?php echo $template['type']; ?></span>
                                        </h6>
                                        <div class="template-preview" style="margin-bottom: 10px; max-height: 80px;">
                                            <?php echo htmlspecialchars($template['content']); ?>
                                        </div>
                                        <button class="action-btn small" 
                                                onclick="useDefaultTemplate('<?php echo htmlspecialchars($template['name']); ?>', '<?php echo $template['type']; ?>', '<?php echo htmlspecialchars($template['content']); ?>', '<?php echo $template['variables']; ?>')">
                                            Use This Template
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Templates List -->
            <?php if (empty($templates) && empty($defaultTemplates)): ?>
                <div style="text-align: center; padding: 50px; color: #718096;">
                    <i class="fas fa-file-alt" style="font-size: 48px; margin-bottom: 20px;"></i>
                    <h5>No templates found</h5>
                    <p>Create your first notification template to get started.</p>
                    <button class="action-btn" onclick="openTemplateModal()">
                        <i class="fas fa-plus"></i>
                        <span class="icon-plus" style="display: none;"></span>
                        Create Template
                    </button>
                </div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($templates as $template): ?>
                        <div class="col-lg-6">
                            <div class="template-card">
                                <div class="template-header">
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <div>
                                            <h6 style="margin-bottom: 5px; font-weight: 600;"><?php echo htmlspecialchars($template['template_name']); ?></h6>
                                            <div style="display: flex; gap: 8px; align-items: center;">
                                                <span class="badge <?php echo $template['template_type'] === 'SMS' ? 'primary' : 
                                                    ($template['template_type'] === 'Email' ? 'info' : 'secondary'); ?>">
                                                    <?php echo $template['template_type']; ?>
                                                </span>
                                                <span class="badge <?php echo $template['is_active'] ? 'success' : 'secondary'; ?>">
                                                    <?php echo $template['is_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div>
                                            <button class="action-btn small info" onclick="editTemplate(<?php echo $template['template_id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                                <span class="icon-edit" style="display: none;"></span>
                                            </button>
                                            <button class="action-btn small danger" onclick="deleteTemplate(<?php echo $template['template_id']; ?>, '<?php echo htmlspecialchars($template['template_name']); ?>')">
                                                <i class="fas fa-trash"></i>
                                                <span class="icon-trash" style="display: none;"></span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="template-content">
                                    <div class="template-preview">
                                        <?php echo htmlspecialchars($template['template_content']); ?>
                                    </div>
                                    
                                    <?php if (!empty($template['variables'])): ?>
                                        <div style="margin-bottom: 15px;">
                                            <strong style="font-size: 12px; color: #718096;">Variables:</strong><br>
                                            <?php 
                                            $variables = explode(',', $template['variables']);
                                            foreach ($variables as $var): 
                                                $var = trim($var);
                                                if (!empty($var)):
                                            ?>
                                                <span class="variable-tag">{{<?php echo htmlspecialchars($var); ?>}}</span>
                                            <?php 
                                                endif;
                                            endforeach; 
                                            ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div style="font-size: 12px; color: #718096;">
                                        Created by: <?php echo htmlspecialchars(($template['first_name'] ?? '') . ' ' . ($template['last_name'] ?? '')); ?>
                                        on <?php echo date('M j, Y', strtotime($template['created_at'])); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Template Modal -->
    <div class="modal fade" id="templateModal" tabindex="-1" style="display: none;">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add New Template</h5>
                    <button type="button" class="btn-close" onclick="closeTemplateModal()"></button>
                </div>
                
                <form method="POST" id="templateForm">
                    <input type="hidden" name="template_id" id="templateId">
                    
                    <div class="modal-body">
                        <div class="row" style="margin-bottom: 15px;">
                            <div class="col-md-8">
                                <label class="form-label">Template Name *</label>
                                <input type="text" class="form-control" id="templateName" name="template_name" 
                                       placeholder="Enter template name" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Type *</label>
                                <select class="form-select" id="templateType" name="template_type" required>
                                    <option value="SMS">SMS</option>
                                    <option value="Email">Email</option>
                                    <option value="System">System</option>
                                </select>
                            </div>
                        </div>

                        <div style="margin-bottom: 15px;">
                            <label class="form-label">Template Content *</label>
                            <textarea class="form-control" id="templateContent" name="template_content" 
                                      placeholder="Enter your template content here..." required rows="6"></textarea>
                            <div style="font-size: 12px; color: #718096; margin-top: 5px;">
                                Use {{variable_name}} for dynamic content. Example: "Hello {{name}}, your bill is {{amount}}"
                            </div>
                        </div>

                        <div style="margin-bottom: 15px;">
                            <label class="form-label">Variables (Optional)</label>
                            <input type="text" class="form-control" id="variables" name="variables" 
                                   placeholder="Comma-separated list of variables">
                            <div style="font-size: 12px; color: #718096; margin-top: 5px;">
                                Example: name, amount, account_number
                            </div>
                        </div>

                        <!-- Variable Helper -->
                        <div class="alert alert-info">
                            <h6><i class="fas fa-info-circle"></i> Common Variables:</h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <strong>General:</strong><br>
                                    <code>{{name}}</code> - Recipient name<br>
                                    <code>{{phone}}</code> - Phone number<br>
                                    <code>{{date}}</code> - Current date
                                </div>
                                <div class="col-md-6">
                                    <strong>Billing:</strong><br>
                                    <code>{{account_number}}</code> - Account number<br>
                                    <code>{{amount}}</code> - Amount<br>
                                    <code>{{balance}}</code> - Outstanding balance
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="action-btn secondary" onclick="closeTemplateModal()">Cancel</button>
                        <button type="submit" class="action-btn success">
                            <i class="fas fa-save"></i>
                            <span class="icon-save" style="display: none;"></span>
                            <span id="submitText">Create Template</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" style="display: none;">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Delete</h5>
                    <button type="button" class="btn-close" onclick="closeDeleteModal()"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete the template "<span id="deleteTemplateName"></span>"?</p>
                    <p style="color: #e53e3e;"><strong>This action cannot be undone.</strong></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="action-btn secondary" onclick="closeDeleteModal()">Cancel</button>
                    <form method="POST" action="?action=delete" id="deleteForm" style="display: inline;">
                        <input type="hidden" name="template_id" id="deleteTemplateId">
                        <button type="submit" class="action-btn danger">Delete Template</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Check if Font Awesome loaded, if not show emoji icons
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                const testIcon = document.querySelector('.fas.fa-bars');
                if (!testIcon || getComputedStyle(testIcon, ':before').content === 'none') {
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

        // Template modal functions
        function openTemplateModal() {
            document.getElementById('templateModal').style.display = 'block';
            document.getElementById('modalTitle').textContent = 'Add New Template';
            document.getElementById('submitText').textContent = 'Create Template';
            document.getElementById('templateForm').action = '?action=add';
            document.getElementById('templateId').value = '';
            clearForm();
        }

        function closeTemplateModal() {
            document.getElementById('templateModal').style.display = 'none';
        }

        function editTemplate(templateId) {
            <?php if ($editTemplate): ?>
            // If we have edit data, populate the form
            document.getElementById('templateModal').style.display = 'block';
            document.getElementById('modalTitle').textContent = 'Edit Template';
            document.getElementById('submitText').textContent = 'Update Template';
            document.getElementById('templateForm').action = '?action=edit';
            document.getElementById('templateId').value = '<?php echo $editTemplate['template_id']; ?>';
            document.getElementById('templateName').value = '<?php echo htmlspecialchars($editTemplate['template_name']); ?>';
            document.getElementById('templateType').value = '<?php echo $editTemplate['template_type']; ?>';
            document.getElementById('templateContent').value = '<?php echo htmlspecialchars($editTemplate['template_content']); ?>';
            document.getElementById('variables').value = '<?php echo htmlspecialchars($editTemplate['variables']); ?>';
            <?php else: ?>
            // Redirect to edit
            window.location.href = '?action=edit&id=' + templateId;
            <?php endif; ?>
        }

        function deleteTemplate(templateId, templateName) {
            document.getElementById('deleteModal').style.display = 'block';
            document.getElementById('deleteTemplateId').value = templateId;
            document.getElementById('deleteTemplateName').textContent = templateName;
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        function useDefaultTemplate(name, type, content, variables) {
            document.getElementById('templateName').value = name;
            document.getElementById('templateType').value = type;
            document.getElementById('templateContent').value = content;
            document.getElementById('variables').value = variables;
            openTemplateModal();
        }

        function clearForm() {
            document.getElementById('templateName').value = '';
            document.getElementById('templateType').value = 'SMS';
            document.getElementById('templateContent').value = '';
            document.getElementById('variables').value = '';
        }

        // Auto-extract variables from template content
        document.getElementById('templateContent').addEventListener('blur', function() {
            const content = this.value;
            const variableInput = document.getElementById('variables');
            
            if (variableInput.value === '') {
                const matches = content.match(/\{\{([^}]+)\}\}/g);
                if (matches) {
                    const variables = matches.map(match => match.replace(/\{\{|\}\}/g, '').trim());
                    const uniqueVariables = [...new Set(variables)];
                    variableInput.value = uniqueVariables.join(', ');
                }
            }
        });

        // Open modal if editing
        <?php if ($editTemplate): ?>
            editTemplate(<?php echo $editTemplate['template_id']; ?>);
        <?php endif; ?>

        // Mobile sidebar toggle
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth <= 768) {
                sidebar.classList.remove('hidden');
            }
        });
    </script>
</body>
</html>