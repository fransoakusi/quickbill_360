<?php
/**
 * API Settings Management - QuickBill 305
 * Configure Google Maps, Twilio SMS, and Paystack APIs
 */

// Define application constant
define('QUICKBILL_305', true);

// Include configuration files
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/flash_messages.php';

// Start session
session_start();

// Include auth and security
require_once '../../includes/auth.php';
require_once '../../includes/security.php';

// Initialize auth and security
initAuth();
initSecurity();

// Check if user is logged in and is admin
if (!isLoggedIn()) {
    header('Location: ../../auth/login.php');
    exit();
}

if (!isAdmin()) {
    setFlashMessage('error', 'Access denied. Admin privileges required.');
    header('Location: ../../auth/login.php');
    exit();
}

$currentUser = getCurrentUser();
$userDisplayName = getUserDisplayName($currentUser);

// API configuration keys
$apiKeys = [
    'google_maps_api_key' => [
        'name' => 'Google Maps API Key',
        'description' => 'Required for location services and map display',
        'default' => 'AIzaSyDg1CWNtJ8BHeclYP7VfltZZLIcY3TVHaI',
        'type' => 'text',
        'group' => 'google'
    ],
    'twilio_sid' => [
        'name' => 'Twilio Account SID',
        'description' => 'Twilio Account SID for SMS notifications',
        'default' => '831JD7BHZAHE9M7EWNW1FCUB',
        'type' => 'text',
        'group' => 'twilio'
    ],
    'twilio_token' => [
        'name' => 'Twilio Auth Token',
        'description' => 'Twilio authentication token',
        'default' => 'ZQHijuboaimCs7Ali3X9aRzizbjztN8a',
        'type' => 'password',
        'group' => 'twilio'
    ],
    'twilio_phone' => [
        'name' => 'Twilio Phone Number',
        'description' => 'Twilio phone number for sending SMS',
        'default' => '',
        'type' => 'text',
        'group' => 'twilio'
    ],
    'paystack_secret_key' => [
        'name' => 'Paystack Secret Key',
        'description' => 'Paystack secret key for payment processing',
        'default' => 'sk_test_b6d5e56246149f160bf5e572f715714dcb375e72',
        'type' => 'password',
        'group' => 'paystack'
    ],
    'paystack_public_key' => [
        'name' => 'Paystack Public Key',
        'description' => 'Paystack public key for frontend integration',
        'default' => 'pk_test_6a0be5c8c08f05e97fd19eb697df4c37876b49f8',
        'type' => 'text',
        'group' => 'paystack'
    ],
    'paystack_webhook_secret' => [
        'name' => 'Paystack Webhook Secret',
        'description' => 'Secret for verifying Paystack webhook calls',
        'default' => '',
        'type' => 'password',
        'group' => 'paystack'
    ]
];

// Get current API settings
try {
    $db = new Database();
    $currentSettings = [];
    
    foreach (array_keys($apiKeys) as $key) {
        $result = $db->fetchRow("SELECT setting_value FROM system_settings WHERE setting_key = ?", [$key]);
        $currentSettings[$key] = $result['setting_value'] ?? $apiKeys[$key]['default'];
    }
    
} catch (Exception $e) {
    error_log("API Settings Error: " . $e->getMessage());
    $currentSettings = [];
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'update_google_maps':
                    $apiKey = trim($_POST['google_maps_api_key']);
                    if (!empty($apiKey)) {
                        $exists = $db->fetchRow("SELECT setting_id FROM system_settings WHERE setting_key = 'google_maps_api_key'");
                        if ($exists) {
                            $db->execute("UPDATE system_settings SET setting_value = ?, updated_by = ?, updated_at = NOW() WHERE setting_key = 'google_maps_api_key'", 
                                        [$apiKey, $currentUser['user_id']]);
                        } else {
                            $db->execute("INSERT INTO system_settings (setting_key, setting_value, setting_type, description, updated_by) VALUES (?, ?, 'text', 'Google Maps API Key', ?)", 
                                        ['google_maps_api_key', $apiKey, $currentUser['user_id']]);
                        }
                        setFlashMessage('success', 'Google Maps API settings updated successfully!');
                    }
                    break;
                    
                case 'update_twilio':
                    $sid = trim($_POST['twilio_sid']);
                    $token = trim($_POST['twilio_token']);
                    $phone = trim($_POST['twilio_phone']);
                    
                    if (!empty($sid) && !empty($token)) {
                        foreach (['twilio_sid' => $sid, 'twilio_token' => $token, 'twilio_phone' => $phone] as $key => $value) {
                            $exists = $db->fetchRow("SELECT setting_id FROM system_settings WHERE setting_key = ?", [$key]);
                            if ($exists) {
                                $db->execute("UPDATE system_settings SET setting_value = ?, updated_by = ?, updated_at = NOW() WHERE setting_key = ?", 
                                            [$value, $currentUser['user_id'], $key]);
                            } else {
                                $db->execute("INSERT INTO system_settings (setting_key, setting_value, setting_type, description, updated_by) VALUES (?, ?, 'text', ?, ?)", 
                                            [$key, $value, ucwords(str_replace('_', ' ', $key)), $currentUser['user_id']]);
                            }
                        }
                        setFlashMessage('success', 'Twilio SMS settings updated successfully!');
                    }
                    break;
                    
                case 'update_paystack':
                    $secretKey = trim($_POST['paystack_secret_key']);
                    $publicKey = trim($_POST['paystack_public_key']);
                    $webhookSecret = trim($_POST['paystack_webhook_secret']);
                    
                    if (!empty($secretKey) && !empty($publicKey)) {
                        foreach (['paystack_secret_key' => $secretKey, 'paystack_public_key' => $publicKey, 'paystack_webhook_secret' => $webhookSecret] as $key => $value) {
                            $exists = $db->fetchRow("SELECT setting_id FROM system_settings WHERE setting_key = ?", [$key]);
                            if ($exists) {
                                $db->execute("UPDATE system_settings SET setting_value = ?, updated_by = ?, updated_at = NOW() WHERE setting_key = ?", 
                                            [$value, $currentUser['user_id'], $key]);
                            } else {
                                $db->execute("INSERT INTO system_settings (setting_key, setting_value, setting_type, description, updated_by) VALUES (?, ?, 'text', ?, ?)", 
                                            [$key, $value, ucwords(str_replace('_', ' ', $key)), $currentUser['user_id']]);
                            }
                        }
                        setFlashMessage('success', 'Paystack payment settings updated successfully!');
                    }
                    break;
                    
                case 'test_google_maps':
                    $apiKey = trim($_POST['test_google_maps_key']);
                    if (!empty($apiKey)) {
                        $testUrl = "https://maps.googleapis.com/maps/api/geocode/json?address=Accra,Ghana&key=" . $apiKey;
                        $response = file_get_contents($testUrl);
                        $data = json_decode($response, true);
                        
                        if ($data && $data['status'] === 'OK') {
                            setFlashMessage('success', 'Google Maps API test successful!');
                        } else {
                            setFlashMessage('error', 'Google Maps API test failed: ' . ($data['status'] ?? 'Unknown error'));
                        }
                    }
                    break;
            }
        }
        
        header('Location: api_settings.php');
        exit();
        
    } catch (Exception $e) {
        error_log("API Settings Update Error: " . $e->getMessage());
        setFlashMessage('error', 'Failed to update API settings. Please try again.');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Settings - <?php echo htmlspecialchars(APP_NAME); ?></title>
    
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
        .icon-money::before { content: "💰"; }
        .icon-plus::before { content: "➕"; }
        .icon-server::before { content: "🖥️"; }
        .icon-database::before { content: "💾"; }
        .icon-shield::before { content: "🛡️"; }
        .icon-user-plus::before { content: "👤➕"; }
        .icon-history::before { content: "📜"; }
        .icon-question::before { content: "❓"; }
        .icon-plug::before { content: "🔌"; }
        .icon-key::before { content: "🔑"; }
        .icon-phone::before { content: "📱"; }
        
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
        
        /* Welcome Section */
        .welcome-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .welcome-title {
            font-size: 28px;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 8px;
        }
        
        .welcome-subtitle {
            color: #718096;
            font-size: 16px;
        }
        
        /* Cards */
        .card {
            background: white;
            border-radius: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }
        
        .card-header {
            padding: 20px 25px;
            background: #f7fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .card-body {
            padding: 25px;
        }
        
        .api-status {
            margin-left: auto;
        }
        
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .status-configured {
            background: #c6f6d5;
            color: #22543d;
        }
        
        .status-not-configured {
            background: #fed7d7;
            color: #742a2a;
        }
        
        .api-description {
            color: #718096;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #4299e1;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-label {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .form-control {
            border-radius: 8px;
            border: 2px solid #e2e8f0;
            padding: 0.75rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        
        .btn {
            border-radius: 8px;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-test {
            background: #4299e1;
            color: white;
            border: none;
        }
        
        .btn-test:hover {
            background: #3182ce;
            color: white;
        }
        
        .api-icon {
            font-size: 1.25rem;
        }
        
        .api-icon.google { color: #4285f4; }
        .api-icon.twilio { color: #f22f46; }
        .api-icon.paystack { color: #00c3f7; }
        
        .input-group {
            margin-bottom: 1rem;
        }
        
        .input-group .form-control {
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
        }
        
        .input-group .btn {
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
        }
        
        .api-docs {
            background: #e6fffa;
            border: 1px solid #81e6d9;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
        }
        
        .api-docs h6 {
            color: #234e52;
            margin-bottom: 0.5rem;
        }
        
        .api-docs ul {
            margin-bottom: 0;
            color: #2d3748;
        }
        
        .api-docs a {
            color: #2b6cb0;
            text-decoration: none;
        }
        
        .api-docs a:hover {
            text-decoration: underline;
        }
        
        .alert {
            border-radius: 10px;
            border: none;
            padding: 1rem 1.5rem;
        }
        
        .password-field {
            position: relative;
        }
        
        .password-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: none;
            color: #718096;
            cursor: pointer;
        }
        
        .password-toggle:hover {
            color: #667eea;
        }
        
        /* Animations */
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        
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
            
            .card-body {
                padding: 1rem;
            }
            
            .btn-group {
                width: 100%;
            }
            
            .btn-group .btn {
                width: 50%;
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
                <?php echo htmlspecialchars(APP_NAME); ?>
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
                    display: inline-block;
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
                    <?php echo htmlspecialchars(strtoupper(substr($currentUser['first_name'], 0, 1))); ?>
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
                            <?php echo htmlspecialchars(strtoupper(substr($currentUser['first_name'], 0, 1))); ?>
                        </div>
                        <div class="dropdown-name"><?php echo htmlspecialchars($userDisplayName); ?></div>
                        <div class="dropdown-role"><?php echo htmlspecialchars(getCurrentUserRole()); ?></div>
                    </div>
                    <div class="dropdown-menu">
                        <a href="../users/view.php?id=<?php echo htmlspecialchars($currentUser['id']); ?>" class="dropdown-item">
                            <i class="fas fa-user"></i>
                            <span class="icon-users" style="display: none;"></span>
                            My Profile
                        </a>
                        <a href="index.php" class="dropdown-item">
                            <i class="fas fa-cog"></i>
                            <span class="icon-cog" style="display: none;"></span>
                            Account Settings
                        </a>
                        <a href="../logs/user_activity.php" class="dropdown-item">
                            <i class="fas fa-history"></i>
                            <span class="icon-history" style="display: none;"></span>
                            Activity Log
                        </a>
                        <a href="#" class="dropdown-item" onclick="alert('Help documentation coming soon!')">
                            <i class="fas fa-question-circle"></i>
                            <span class="icon-question" style="display: none;"></span>
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
                        <a href="index.php" class="nav-link active">
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
            <!-- Welcome Section -->
            <div class="welcome-card">
                <h1 class="welcome-title">API Configuration 🔌</h1>
                <p class="welcome-subtitle">Configure Google Maps, SMS, and payment gateway integrations for your system.</p>
            </div>

            <!-- Flash Messages -->
            <?php
            $flash = getFlashMessages();
            if (!empty($flash) && is_array($flash) && isset($flash['type'], $flash['message'])):
            ?>
                <div class="alert alert-<?php echo htmlspecialchars($flash['type'] === 'error' ? 'danger' : $flash['type']); ?> alert-dismissible fade show">
                    <i class="fas fa-<?php echo htmlspecialchars($flash['type'] === 'error' ? 'exclamation-triangle' : ($flash['type'] === 'success' ? 'check-circle' : 'info-circle')); ?> me-2"></i>
                    <?php echo htmlspecialchars($flash['message'] ?? ''); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Google Maps API Settings -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">
                        <i class="fas fa-map-marked-alt api-icon google"></i>
                        <span class="icon-map" style="display: none;"></span>
                        Google Maps API
                    </h5>
                    <div class="api-status">
                        <span class="status-badge <?php echo !empty($currentSettings['google_maps_api_key']) ? 'status-configured' : 'status-not-configured'; ?>">
                            <?php echo !empty($currentSettings['google_maps_api_key']) ? 'Configured' : 'Not Configured'; ?>
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="api-description">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Google Maps API</strong> is required for location services, geocoding business and property addresses, and displaying interactive maps throughout the system.
                    </div>
                    
                    <form method="POST">
                        <input type="hidden" name="action" value="update_google_maps">
                        
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-key"></i>
                                <span class="icon-key" style="display: none;"></span>
                                API Key
                            </label>
                            <input type="text" class="form-control" name="google_maps_api_key" 
                                   value="<?php echo htmlspecialchars($currentSettings['google_maps_api_key'] ?? ''); ?>" 
                                   placeholder="Enter your Google Maps API key">
                        </div>
                        
                        <div class="btn-group" role="group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Save Settings
                            </button>
                        </div>
                    </form>
                    
                    <!-- Test API Form -->
                    <form method="POST" class="mt-3">
                        <input type="hidden" name="action" value="test_google_maps">
                        <div class="input-group">
                            <input type="text" class="form-control" name="test_google_maps_key" 
                                   value="<?php echo htmlspecialchars($currentSettings['google_maps_api_key'] ?? ''); ?>" 
                                   placeholder="API key to test">
                            <button type="submit" class="btn btn-test">
                                <i class="fas fa-vial me-2"></i>Test API
                            </button>
                        </div>
                    </form>
                    
                    <div class="api-docs">
                        <h6><i class="fas fa-book me-2"></i>Documentation & Setup</h6>
                        <ul>
                            <li>Get your API key from <a href="https://console.cloud.google.com/" target="_blank">Google Cloud Console</a></li>
                            <li>Enable: Maps JavaScript API, Geocoding API, Places API</li>
                            <li>Restrict your key to your domain for security</li>
                            <li>Set up billing (required for Google Maps APIs)</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Twilio SMS API Settings -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">
                        <i class="fas fa-sms api-icon twilio"></i>
                        <span class="icon-phone" style="display: none;"></span>
                        Twilio SMS API
                    </h5>
                    <div class="api-status">
                        <span class="status-badge <?php echo !empty($currentSettings['twilio_sid']) && !empty($currentSettings['twilio_token']) ? 'status-configured' : 'status-not-configured'; ?>">
                            <?php echo !empty($currentSettings['twilio_sid']) && !empty($currentSettings['twilio_token']) ? 'Configured' : 'Not Configured'; ?>
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="api-description">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Twilio SMS API</strong> enables the system to send SMS notifications for account creation, payment confirmations, bill reminders, and system alerts.
                    </div>
                    
                    <form method="POST">
                        <input type="hidden" name="action" value="update_twilio">
                        
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-id-card"></i>
                                Account SID
                            </label>
                            <input type="text" class="form-control" name="twilio_sid" 
                                   value="<?php echo htmlspecialchars($currentSettings['twilio_sid'] ?? ''); ?>" 
                                   placeholder="Enter your Twilio Account SID">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-lock"></i>
                                Auth Token
                            </label>
                            <div class="password-field">
                                <input type="password" class="form-control" name="twilio_token" id="twilio_token"
                                       value="<?php echo htmlspecialchars($currentSettings['twilio_token'] ?? ''); ?>" 
                                       placeholder="Enter your Twilio Auth Token">
                                <button type="button" class="password-toggle" onclick="togglePassword('twilio_token')">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-phone"></i>
                                <span class="icon-phone" style="display: none;"></span>
                                Twilio Phone Number
                            </label>
                            <input type="text" class="form-control" name="twilio_phone" 
                                   value="<?php echo htmlspecialchars($currentSettings['twilio_phone'] ?? ''); ?>" 
                                   placeholder="+1234567890">
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save Twilio Settings
                        </button>
                    </form>
                    
                    <div class="api-docs">
                        <h6><i class="fas fa-book me-2"></i>Documentation & Setup</h6>
                        <ul>
                            <li>Sign up at <a href="https://www.twilio.com/" target="_blank">Twilio Console</a></li>
                            <li>Purchase a phone number for SMS sending</li>
                            <li>Copy your Account SID and Auth Token from dashboard</li>
                            <li>Verify your account to remove trial limitations</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Paystack Payment API Settings -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">
                        <i class="fas fa-credit-card api-icon paystack"></i>
                        <span class="icon-credit" style="display: none;"></span>
                        Paystack Payment API
                    </h5>
                    <div class="api-status">
                        <span class="status-badge <?php echo !empty($currentSettings['paystack_secret_key']) && !empty($currentSettings['paystack_public_key']) ? 'status-configured' : 'status-not-configured'; ?>">
                            <?php echo !empty($currentSettings['paystack_secret_key']) && !empty($currentSettings['paystack_public_key']) ? 'Configured' : 'Not Configured'; ?>
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="api-description">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Paystack API</strong> handles online payments through the public portal, supporting mobile money (MTN, Telecel, AirtelTigo) and card payments.
                    </div>
                    
                    <form method="POST">
                        <input type="hidden" name="action" value="update_paystack">
                        
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-key"></i>
                                <span class="icon-key" style="display: none;"></span>
                                Secret Key
                            </label>
                            <div class="password-field">
                                <input type="password" class="form-control" name="paystack_secret_key" id="paystack_secret"
                                       value="<?php echo htmlspecialchars($currentSettings['paystack_secret_key'] ?? ''); ?>" 
                                       placeholder="sk_test_...">
                                <button type="button" class="password-toggle" onclick="togglePassword('paystack_secret')">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-globe"></i>
                                Public Key
                            </label>
                            <input type="text" class="form-control" name="paystack_public_key" 
                                   value="<?php echo htmlspecialchars($currentSettings['paystack_public_key'] ?? ''); ?>" 
                                   placeholder="pk_test_...">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-shield-alt"></i>
                                Webhook Secret (Optional)
                            </label>
                            <div class="password-field">
                                <input type="password" class="form-control" name="paystack_webhook_secret" id="paystack_webhook"
                                       value="<?php echo htmlspecialchars($currentSettings['paystack_webhook_secret'] ?? ''); ?>" 
                                       placeholder="Webhook secret for payment verification">
                                <button type="button" class="password-toggle" onclick="togglePassword('paystack_webhook')">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save Paystack Settings
                        </button>
                    </form>
                    
                    <div class="api-docs">
                        <h6><i class="fas fa-book me-2"></i>Documentation & Setup</h6>
                        <ul>
                            <li>Create account at <a href="https://paystack.com/" target="_blank">Paystack</a></li>
                            <li>Get API keys from your Paystack Dashboard</li>
                            <li>Use test keys for development, live keys for production</li>
                            <li>Set up webhooks for automatic payment verification</li>
                            <li>Webhook URL: <code><?php echo htmlspecialchars(BASE_URL ?? 'http://localhost/quickbill_305'); ?>/api/payments/webhook.php</code></li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- API Status Summary -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">
                        <i class="fas fa-chart-line"></i>
                        <span class="icon-chart" style="display: none;"></span>
                        API Integration Status
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-md-4">
                            <div class="p-3">
                                <i class="fas fa-map-marked-alt display-6 mb-3 text-primary"></i>
                                <h6>Google Maps</h6>
                                <span class="status-badge <?php echo !empty($currentSettings['google_maps_api_key']) ? 'status-configured' : 'status-not-configured'; ?>">
                                    <?php echo !empty($currentSettings['google_maps_api_key']) ? 'Ready' : 'Needs Setup'; ?>
                                </span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3">
                                <i class="fas fa-sms display-6 mb-3 text-danger"></i>
                                <h6>SMS Notifications</h6>
                                <span class="status-badge <?php echo !empty($currentSettings['twilio_sid']) && !empty($currentSettings['twilio_token']) ? 'status-configured' : 'status-not-configured'; ?>">
                                    <?php echo !empty($currentSettings['twilio_sid']) && !empty($currentSettings['twilio_token']) ? 'Ready' : 'Needs Setup'; ?>
                                </span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3">
                                <i class="fas fa-credit-card display-6 mb-3 text-info"></i>
                                <h6>Online Payments</h6>
                                <span class="status-badge <?php echo !empty($currentSettings['paystack_secret_key']) && !empty($currentSettings['paystack_public_key']) ? 'status-configured' : 'status-not-configured'; ?>">
                                    <?php echo !empty($currentSettings['paystack_secret_key']) && !empty($currentSettings['paystack_public_key']) ? 'Ready' : 'Needs Setup'; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-4 text-center">
                        <a href="index.php" class="btn btn-outline-primary">
                            <i class="fas fa-arrow-left me-2"></i>Back to Settings
                        </a>
                        <a href="../index.php" class="btn btn-primary ms-2">
                            <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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
            const mainContent = document.getElementById('mainContent');
            
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

        // Password toggle functionality
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const button = field.nextElementSibling;
            const icon = button.querySelector('i');
            
            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                field.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
        
        // Form enhancement
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('form').forEach(form => {
                form.addEventListener('submit', function(e) {
                    const submitBtn = form.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        const originalText = submitBtn.innerHTML;
                        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';
                        submitBtn.disabled = true;
                        
                        setTimeout(() => {
                            submitBtn.innerHTML = originalText;
                            submitBtn.disabled = false;
                        }, 3000);
                    }
                });
            });
            
            setTimeout(() => {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(alert => {
                    if (alert.querySelector('.btn-close')) {
                        alert.querySelector('.btn-close').click();
                    }
                });
            }, 5000);
        });
        
        // Copy webhook URL to clipboard
        document.addEventListener('click', function(e) {
            if (e.target.tagName === 'CODE') {
                const text = e.target.textContent;
                navigator.clipboard.writeText(text).then(() => {
                    const originalText = e.target.textContent;
                    e.target.textContent = 'Copied!';
                    e.target.style.background = '#48bb78';
                    e.target.style.color = 'white';
                    
                    setTimeout(() => {
                        e.target.textContent = originalText;
                        e.target.style.background = '';
                        e.target.style.color = '';
                    }, 2000);
                });
            }
        });

        // Mobile sidebar toggle
        function toggleMobileSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('show');
        }

        // Handle mobile responsiveness
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth <= 768) {
                sidebar.classList.remove('hidden');
            }
        });

        // Add smooth hover effects
        document.addEventListener('DOMContentLoaded', function() {
            const statCards = document.querySelectorAll('.card');
            statCards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-8px) scale(1.02)';
                });
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                });
            });

            const badge = document.querySelector('.notification-badge');
            if (badge) {
                setInterval(() => {
                    badge.style.animation = 'none';
                    setTimeout(() => {
                        badge.style.animation = 'pulse 2s infinite';
                    }, 100);
                }, 5000);
            }
        });
    </script>
</body>
</html>