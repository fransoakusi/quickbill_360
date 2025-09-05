<?php
/**
 * Billing Management - View Bill Details with Official Format
 * QUICKBILL 305 - Admin Panel
 */

// Define application constant
define('QUICKBILL_305', true);

// Include configuration files
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../vendor/autoload.php';

// Start session
session_start();

// Include auth and security
require_once '../../includes/auth.php';
require_once '../../includes/security.php';

// QR Code libraries
use chillerlan\QRCode\QRCode as ChillerlanQRCode;
use chillerlan\QRCode\QROptions;

// Initialize auth and security
initAuth();
initSecurity();

// Check authentication and permissions
requireLogin();
if (!hasPermission('billing.view')) {
    setFlashMessage('error', 'Access denied. You do not have permission to view bills.');
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

// Get bill ID
$billId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$billId) {
    setFlashMessage('error', 'Bill ID is required.');
    header('Location: index.php');
    exit();
}

$pageTitle = 'Bill Details';
$currentUser = getCurrentUser();
$userDisplayName = getUserDisplayName($currentUser);
$bill = null;
$payerInfo = null;
$payments = [];
$qr_url = null;
$assemblyName = 'Anloga District Assembly'; // Default fallback

try {
    $db = new Database();
    
    // Get assembly name from settings (dynamic institution name)
    $assemblyNameSetting = $db->fetchRow("SELECT setting_value FROM system_settings WHERE setting_key = 'assembly_name'");
    if ($assemblyNameSetting && !empty($assemblyNameSetting['setting_value'])) {
        $assemblyName = $assemblyNameSetting['setting_value'];
    }
    
    // Get bill details
    $bill = $db->fetchRow("
        SELECT b.*, u.first_name as generated_by_name, u.last_name as generated_by_surname
        FROM bills b
        LEFT JOIN users u ON b.generated_by = u.user_id
        WHERE b.bill_id = ?
    ", [$billId]);
    
    if (!$bill) {
        setFlashMessage('error', 'Bill not found.');
        header('Location: index.php');
        exit();
    }
    
    // Get payer information based on bill type
    if ($bill['bill_type'] === 'Business') {
        $payerInfo = $db->fetchRow("
            SELECT b.*, z.zone_name, sz.sub_zone_name, bfs.fee_amount as annual_fee
            FROM businesses b
            LEFT JOIN zones z ON b.zone_id = z.zone_id
            LEFT JOIN sub_zones sz ON b.sub_zone_id = sz.sub_zone_id
            LEFT JOIN business_fee_structure bfs ON b.business_type = bfs.business_type AND b.category = bfs.category
            WHERE b.business_id = ? AND bfs.is_active = 1
        ", [$bill['reference_id']]);
    } else {
        $payerInfo = $db->fetchRow("
            SELECT p.*, z.zone_name, pfs.fee_per_room
            FROM properties p
            LEFT JOIN zones z ON p.zone_id = z.zone_id
            LEFT JOIN property_fee_structure pfs ON p.structure = pfs.structure AND p.property_use = pfs.property_use
            WHERE p.property_id = ? AND pfs.is_active = 1
        ", [$bill['reference_id']]);
    }
    
    if (!$payerInfo) {
        setFlashMessage('error', 'Payer information not found.');
        header('Location: index.php');
        exit();
    }
    
    // Get payment history for this bill
    $payments = $db->fetchAll("
        SELECT p.*, u.first_name as processed_by_name, u.last_name as processed_by_surname
        FROM payments p
        LEFT JOIN users u ON p.processed_by = u.user_id
        WHERE p.bill_id = ?
        ORDER BY p.payment_date DESC
    ", [$billId]);
    
    // Generate QR Code
    $qr_dir = __DIR__ . '/../../assets/qr_codes/';
    if (!is_dir($qr_dir)) {
        mkdir($qr_dir, 0755, true);
    }
    
    // Create complete bill content for QR code
    $bill_type_full = $bill['bill_type'] === 'Business' ? 'Business License Bill' : 'Property Rate Bill';
    $bill_date = date('d/m/Y', strtotime($bill['generated_at']));
    
    $qr_data = $assemblyName . "\n";
    $qr_data .= str_repeat("=", 40) . "\n";
    $qr_data .= $bill_type_full . "\n";
    $qr_data .= "Bill Date: " . $bill_date . "\n";
    $qr_data .= "Zone: " . ($payerInfo['zone_name'] ?? '') . "\n";
    $qr_data .= str_repeat("-", 40) . "\n\n";
    
    // Payer Information
    if ($bill['bill_type'] === 'Business') {
        $qr_data .= "BUSINESS INFORMATION:\n";
        $qr_data .= "Business Name: " . ($payerInfo['business_name'] ?? '') . "\n";
        $qr_data .= "Owner: " . ($payerInfo['owner_name'] ?? '') . "\n";
        $qr_data .= "Telephone: " . ($payerInfo['telephone'] ?? '') . "\n";
        $qr_data .= "Account#: " . ($payerInfo['account_number'] ?? '') . "\n";
        $qr_data .= "Zone: " . ($payerInfo['zone_name'] ?? '') . "\n";
        $qr_data .= "Business Type: " . ($payerInfo['business_type'] ?? '') . "\n";
    } else {
        $qr_data .= "PROPERTY INFORMATION:\n";
        $qr_data .= "Owner Name: " . ($payerInfo['owner_name'] ?? '') . "\n";
        $qr_data .= "Telephone: " . ($payerInfo['telephone'] ?? '') . "\n";
        $qr_data .= "Property#: " . ($payerInfo['property_number'] ?? '') . "\n";
        $qr_data .= "Zone: " . ($payerInfo['zone_name'] ?? '') . "\n";
        $qr_data .= "Structure: " . ($payerInfo['structure'] ?? '') . "\n";
    }
    
    $qr_data .= "\nBILL DETAILS:\n";
    $qr_data .= "Bill Number: " . ($bill['bill_number'] ?? '') . "\n";
    $qr_data .= "Bill Year: " . ($bill['billing_year'] ?? '') . "\n";
    $qr_data .= str_repeat("-", 40) . "\n";
    
    // Financial breakdown
    $qr_data .= "FINANCIAL BREAKDOWN:\n";
    $qr_data .= "Old Fee: GHS " . number_format($bill['old_bill'] ?? 0, 2) . "\n";
    $qr_data .= "Previous Payments: GHS " . number_format($bill['previous_payments'] ?? 0, 2) . "\n";
    $qr_data .= "Arrears: GHS " . number_format($bill['arrears'] ?? 0, 2) . "\n";
    $qr_data .= "Current Rate: GHS " . number_format($bill['current_bill'] ?? 0, 2) . "\n";
    $qr_data .= str_repeat("-", 40) . "\n";
    $qr_data .= "TOTAL AMOUNT DUE: GHS " . number_format($bill['amount_payable'] ?? 0, 2) . "\n";
    $qr_data .= str_repeat("=", 40) . "\n\n";
    
    $qr_data .= "PAYMENT INSTRUCTIONS:\n";
    $qr_data .= "Please present this bill when making payment.\n";
    $qr_data .= "Pay to DISTRICT FINANCE OFFICER or\n";
    $qr_data .= "authorized Revenue Collector.\n";
    $qr_data .= "Payment Due: 1st September 2025\n\n";
    $qr_data .= "For inquiries: 0249579191\n";
    $qr_data .= str_repeat("=", 40) . "\n";
    $qr_data .= $assemblyName;
    
    // Increase character limit for complete bill content
    if (strlen($qr_data) <= 2500) {
        $qr_file = $qr_dir . 'bill_' . $bill['bill_id'] . '.png';
        try {
            $options = new QROptions([
                'outputType' => ChillerlanQRCode::OUTPUT_IMAGE_PNG,
                'eccLevel' => ChillerlanQRCode::ECC_M, // Medium error correction for better reliability
                'imageBase64' => false,
                'scale' => 3, // Slightly smaller scale to accommodate more data
                'imageTransparent' => false,
                'quietzoneSize' => 2, // Add quiet zone for better scanning
            ]);

            if (file_exists($qr_file)) {
                unlink($qr_file);
            }

            $qrcode = new ChillerlanQRCode($options);
            $qrcode->render($qr_data, $qr_file);

            if (file_exists($qr_file) && filesize($qr_file) > 0) {
                $qr_url = '../../assets/qr_codes/bill_' . $bill['bill_id'] . '.png';
            }
        } catch (Exception $e) {
            writeLog("QR code generation failed for bill ID {$bill['bill_id']}: " . $e->getMessage(), 'ERROR');
        }
    }
    
} catch (Exception $e) {
    writeLog("Bill view error: " . $e->getMessage(), 'ERROR');
    setFlashMessage('error', 'An error occurred while loading bill details.');
    header('Location: index.php');
    exit();
}

// Get flash messages
$flashMessages = getFlashMessages();
$flashMessage = !empty($flashMessages) ? $flashMessages[0] : null;
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
        .icon-money::before { content: "💰"; }
        .icon-plus::before { content: "➕"; }
        .icon-server::before { content: "🖥️"; }
        .icon-database::before { content: "💾"; }
        .icon-shield::before { content: "🛡️"; }
        .icon-user-plus::before { content: "👤➕"; }
        .icon-history::before { content: "📜"; }
        .icon-question::before { content: "❓"; }
        
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
        .container-layout {
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
        
        .main-content {
            flex: 1;
            padding: 30px;
            background: #f8f9fa;
            transition: all 0.3s ease;
            overflow-x: auto;
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
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background: #d1fae5;
            border: 1px solid #9ae6b4;
            color: #065f46;
        }
        
        .alert-error {
            background: #fee2e2;
            border: 1px solid #fca5a5;
            color: #991b1b;
        }
        
        /* Official Bill Container */
        .bill-container {
            background: white;
            border-radius: 15px;
            padding: 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
            min-height: 600px;
            width: 100%;
            max-width: 100%;
        }
        
        .bill-wrapper {
            width: 100%;
            padding: 30px;
            position: relative;
            overflow: hidden;
            z-index: 1;
            background-color: #fff;
            box-sizing: border-box;
        }
        
        /* Watermark Styles - Using HTML elements for better print support */
        .watermark {
            position: absolute;
            font-size: 65px;
            font-weight: bold;
            font-family: Arial, Helvetica, sans-serif;
            color: rgba(0,0,0,0.08);
            z-index: 0;
            pointer-events: none;
            opacity: 0.8;
            transform: rotate(-45deg);
            user-select: none;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        
        .watermark-top-left {
            top: 15%;
            left: 15%;
            transform: translate(-50%, -50%) rotate(-45deg);
        }
        
        .watermark-center {
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
        }
        
        .watermark-bottom-right {
            bottom: 15%;
            right: 15%;
            transform: translate(50%, 50%) rotate(-45deg);
        }
        
        .watermark-top-right {
            top: 20%;
            right: 20%;
            transform: translate(50%, -50%) rotate(-45deg);
        }
        
        .watermark-bottom-left {
            bottom: 20%;
            left: 20%;
            transform: translate(-50%, 50%) rotate(-45deg);
        }
        
        .bill-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #000;
            padding-bottom: 15px;
            position: relative;
            z-index: 2;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .bill-logo {
            width: 80px;
            height: auto;
            z-index: 2;
            flex-shrink: 0;
        }
        
        .bill-logo-right {
            width: 80px;
            height: auto;
            z-index: 2;
            flex-shrink: 0;
        }
        
        .bill-header-text {
            text-align: center;
            flex-grow: 1;
            z-index: 2;
            min-width: 200px;
        }
        
        .bill-header-text h1 {
            margin: 0;
            font-size: 28px;
            font-weight: bold;
            color: #000;
        }
        
        .bill-header-text h2 {
            margin: 8px 0;
            font-size: 18px;
            font-weight: normal;
            color: #333;
        }
        
        .bill-header-text p {
            margin: 5px 0;
            font-size: 14px;
            color: #666;
            line-height: 1.4;
        }
        
        .bill-content {
            display: flex;
            justify-content: space-between;
            margin-top: 20px;
            position: relative;
            z-index: 2;
            gap: 20px;
            flex-wrap: wrap;
            align-items: flex-start;
        }
        
        .bill-left-section, .bill-right-section {
            flex: 1;
            min-width: 300px;
            max-width: calc(50% - 10px);
            box-sizing: border-box;
        }
        
        .bill-info-box {
            border: 1px solid #000;
            padding: 15px;
            margin-bottom: 20px;
            position: relative;
            z-index: 2;
            background: white;
            word-wrap: break-word;
            overflow-wrap: break-word;
            box-sizing: border-box;
        }
        
        .bill-info-box p {
            margin: 8px 0;
            font-size: 14px;
            color: #000;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        
        .bill-info-box p strong {
            display: inline-block;
            width: 130px;
            font-weight: bold;
            vertical-align: top;
        }
        
        .bill-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            position: relative;
            z-index: 2;
            table-layout: fixed;
            overflow-x: auto;
        }
        
        .bill-table, .bill-table th, .bill-table td {
            border: 1px solid #000;
            padding: 6px 4px;
            text-align: center;
            font-size: 12px;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        
        .bill-table th {
            background-color: #f0f0f0;
            font-weight: bold;
            color: #000;
            font-size: 11px;
            line-height: 1.2;
        }
        
        .bill-table td {
            color: #000;
            line-height: 1.2;
        }
        
        .bill-note {
            font-size: 14px;
            text-align: right;
            margin-bottom: 20px;
            position: relative;
            z-index: 2;
            color: #000;
            font-weight: 500;
        }
        
        .bill-qr-code {
            text-align: center;
            margin-top: 15px;
            position: relative;
            z-index: 2;
            padding: 15px 0;
        }
        
        .bill-qr-code img {
            width: 150px;
            height: 150px;
            border: 1px solid #ddd;
        }
        
        .bill-footer {
            border-top: 1px solid #000;
            padding-top: 15px;
            text-align: center;
            font-size: 13px;
            position: relative;
            z-index: 2;
            color: #000;
            margin-top: 30px;
            clear: both;
        }
        
        .bill-footer p {
            margin: 8px 0;
            line-height: 1.4;
        }
        
        /* Action Buttons */
        .bill-actions {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
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
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5a6fd8;
            transform: translateY(-2px);
            text-decoration: none;
            color: white;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6169;
            transform: translateY(-2px);
            text-decoration: none;
            color: white;
        }
        
        .btn-success {
            background: #10b981;
            color: white;
        }
        
        .btn-success:hover {
            background: #059669;
            transform: translateY(-2px);
            text-decoration: none;
            color: white;
        }
        
        /* Payment History */
        .payment-history {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            overflow-x: auto;
        }
        
        .section-title {
            font-size: 20px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-icon {
            width: 35px;
            height: 35px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }
        
        .data-table th {
            background: #f8fafc;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            color: #2d3748;
            border-bottom: 1px solid #e2e8f0;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .data-table td {
            padding: 15px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            font-size: 14px;
        }
        
        .data-table tr:hover {
            background: #f8fafc;
        }
        
        .status-badge {
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .status-successful {
            background: #d1fae5;
            color: #065f46;
        }
        
        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }
        
        .status-failed {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .amount {
            font-weight: bold;
            font-family: monospace;
            color: #10b981;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #64748b;
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        .empty-state h3 {
            margin-bottom: 10px;
            color: #2d3748;
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
                padding: 15px;
            }
            
            .bill-wrapper {
                padding: 15px;
            }
            
            .bill-content {
                flex-direction: column;
                gap: 15px;
            }
            
            .bill-left-section, .bill-right-section {
                width: 100%;
                max-width: 100%;
                min-width: auto;
            }
            
            .bill-header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .bill-table {
                font-size: 10px;
            }
            
            .bill-table th, .bill-table td {
                padding: 4px 2px;
                font-size: 10px;
            }
            
            .bill-info-box p strong {
                width: 100px;
                font-size: 13px;
            }
            
            .bill-info-box p {
                font-size: 13px;
            }
            
            .watermark {
                font-size: 45px;
            }
        }
        
        @media (max-width: 480px) {
            .bill-wrapper {
                padding: 10px;
            }
            
            .bill-header-text h1 {
                font-size: 20px;
            }
            
            .bill-header-text h2 {
                font-size: 14px;
            }
            
            .bill-table {
                font-size: 9px;
            }
            
            .bill-table th, .bill-table td {
                padding: 3px 1px;
                font-size: 9px;
            }
            
            .watermark {
                font-size: 35px;
            }
        }
        
        /* Print Styles */
        @media print {
            .top-nav,
            .sidebar,
            .breadcrumb-nav,
            .bill-actions,
            .payment-history {
                display: none !important;
            }
            
            .main-content {
                margin: 0;
                padding: 0;
            }
            
            .bill-container {
                box-shadow: none;
                border-radius: 0;
                margin: 0;
                page-break-inside: avoid;
            }
            
            .bill-wrapper {
                padding: 20px;
            }
            
            .bill-content {
                gap: 15px;
            }
            
            .bill-left-section, .bill-right-section {
                max-width: 48%;
            }
            
            body {
                background: white;
            }
            
            .bill-table {
                font-size: 11px;
            }
            
            .bill-table th, .bill-table td {
                padding: 5px 3px;
                font-size: 11px;
            }
            
            /* Enhanced watermark visibility for print */
            .watermark {
                color: rgba(0,0,0,0.12) !important;
                opacity: 1 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                font-size: 60px !important;
            }
            
            /* Ensure watermarks print */
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            
            .bill-logo, .bill-logo-right {
                width: 80px !important;
                height: auto !important;
            }
        }
        
        /* Animations */
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .bill-container,
        .bill-actions,
        .payment-history {
            animation: slideIn 0.6s ease forwards;
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
                        <a href="../users/view.php?id=<?php echo $currentUser['user_id']; ?>" class="dropdown-item">
                            <i class="fas fa-user"></i>
                            <span class="icon-users" style="display: none;"></span>
                            My Profile
                        </a>
                        <a href="../settings/index.php" class="dropdown-item">
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

    <div class="container-layout">
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
                        <a href="index.php" class="nav-link active">
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
                    <a href="index.php">Billing</a>
                    <span>/</span>
                    <span style="color: #2d3748; font-weight: 600;">Bill Details</span>
                </div>
            </div>

            <div class="container">
                <!-- Flash Messages -->
                <?php if ($flashMessage): ?>
                    <div class="alert alert-<?php echo $flashMessage['type']; ?>">
                        <i class="fas fa-<?php echo $flashMessage['type'] === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                        <div><?php echo htmlspecialchars($flashMessage['message']); ?></div>
                    </div>
                <?php endif; ?>

                <!-- Action Buttons -->
                <div class="bill-actions">
                    <div class="action-buttons">
                        <?php if ($bill['status'] !== 'Paid'): ?>
                            <a href="../payments/record.php?bill_id=<?php echo $bill['bill_id']; ?>" class="btn btn-success">
                                <i class="fas fa-credit-card"></i>
                                Record Payment
                            </a>
                        <?php endif; ?>
                        
                        <button class="btn btn-primary" onclick="printBill()">
                            <i class="fas fa-print"></i>
                            Print Bill
                        </button>
                        
                        <a href="../<?php echo strtolower($bill['bill_type']); ?>es/view.php?id=<?php echo $bill['reference_id']; ?>" class="btn btn-secondary">
                            <i class="fas fa-<?php echo $bill['bill_type'] === 'Business' ? 'building' : 'home'; ?>"></i>
                            View <?php echo $bill['bill_type']; ?> Profile
                        </a>
                        
                        <a href="index.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i>
                            Back to Billing
                        </a>
                    </div>
                </div>

                <!-- Official Bill Container -->
                <div class="bill-container">
                    <div class="bill-wrapper">
                        <!-- Multiple Watermarks using HTML elements for better print support -->
                        <div class="watermark watermark-top-left">AnDA</div>
                        <div class="watermark watermark-center">AnDA</div>
                        <div class="watermark watermark-bottom-right">AnDA</div>
                        <div class="watermark watermark-top-right">AnDA</div>
                        <div class="watermark watermark-bottom-left">AnDA</div>
                        
                        <!-- Bill Header -->
                        <div class="bill-header">
                            <img src="../../assets/images/download.png" alt="<?php echo htmlspecialchars($assemblyName); ?> Logo" class="bill-logo">
                            <div class="bill-header-text">
                                <h1><?php echo htmlspecialchars($assemblyName); ?></h1>
                                <p>P.O Box AW 36, Anloga</p>
                                <p>DA: VK-1621-0758</p>
                                <p>Location: opposite Anloga Post Office</p>
                                <h2><?php echo $bill['bill_type'] === 'Business' ? 'Business License Bill' : 'Property Rate Bill'; ?></h2>
                                <p>Bill No: <?php echo htmlspecialchars($bill['bill_number']); ?> | Bill Date: <?php echo date('d/m/Y', strtotime($bill['generated_at'])); ?> | Zone: <?php echo htmlspecialchars($payerInfo['zone_name'] ?? ''); ?></p>
                            </div>
                            <img src="../../assets/images/Anloga.jpg" alt="<?php echo htmlspecialchars($assemblyName); ?> Logo" class="bill-logo-right">
                        </div>

                        <!-- Bill Content -->
                        <div class="bill-content">
                            <div class="bill-left-section">
                                <div class="bill-info-box">
                                    <?php if ($bill['bill_type'] === 'Business'): ?>
                                        <p><strong>Business Name:</strong> <?php echo htmlspecialchars($payerInfo['business_name'] ?? ''); ?></p>
                                        <p><strong>Owner / Tel:</strong> <?php echo htmlspecialchars($payerInfo['owner_name'] ?? '') . ' / ' . htmlspecialchars($payerInfo['telephone'] ?? ''); ?></p>
                                        <p><strong>Acct#:</strong> <?php echo htmlspecialchars($payerInfo['account_number'] ?? ''); ?></p>
                                        <p><strong>Location:</strong> <?php echo htmlspecialchars($payerInfo['exact_location'] ?? ''); ?></p>
                                        <p><strong>Bus.Type:</strong> <?php echo htmlspecialchars($payerInfo['business_type'] ?? ''); ?></p>
                                    <?php else: ?>
                                        <p><strong>Owner Name:</strong> <?php echo htmlspecialchars($payerInfo['owner_name'] ?? ''); ?></p>
                                        <p><strong>Owner / Tel:</strong> <?php echo htmlspecialchars($payerInfo['owner_name'] ?? '') . ' / ' . htmlspecialchars($payerInfo['telephone'] ?? ''); ?></p>
                                        <p><strong>Property#:</strong> <?php echo htmlspecialchars($payerInfo['property_number'] ?? ''); ?></p>
                                        <p><strong>Location:</strong> <?php echo htmlspecialchars($payerInfo['location'] ?? ''); ?></p>
                                        <p><strong>Structure:</strong> <?php echo htmlspecialchars($payerInfo['structure'] ?? ''); ?></p>
                                    <?php endif; ?>
                                </div>
                                <div class="bill-qr-code">
                                    <?php if ($qr_url && file_exists(__DIR__ . '/../../assets/qr_codes/bill_' . $bill['bill_id'] . '.png')): ?>
                                        <img src="<?php echo htmlspecialchars($qr_url); ?>" alt="QR Code for Bill <?php echo htmlspecialchars($bill['bill_number']); ?>">
                                    <?php else: ?>
                                        <div style="width: 150px; height: 150px; border: 1px solid #ddd; display: flex; align-items: center; justify-content: center; margin: 0 auto; background: #f8f9fa; color: #666; font-size: 12px; text-align: center;">QR Code<br>Not Available</div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="bill-right-section">
                                <div class="bill-info-box">
                                    <p><strong>Owner:</strong> <?php echo htmlspecialchars($payerInfo['owner_name'] ?? ($payerInfo['business_name'] ?? '')); ?></p>
                                    <p><strong><?php echo $bill['bill_type'] === 'Business' ? 'Acct#' : 'Property#'; ?>:</strong> <?php echo htmlspecialchars($bill['bill_type'] === 'Business' ? ($payerInfo['account_number'] ?? '') : ($payerInfo['property_number'] ?? '')); ?></p>
                                    <p><strong>Bill Year:</strong> <?php echo htmlspecialchars($bill['billing_year']); ?></p>
                                    <p><strong>Total Amount Due:</strong> GHS <?php echo number_format($bill['amount_payable'], 2); ?></p>
                                </div>
                                <div style="overflow-x: auto;">
                                    <table class="bill-table">
                                        <thead>
                                            <tr>
                                                <th>Old Fee</th>
                                                <th>Previous Payments</th>
                                                <th>Arrears</th>
                                                <th>Current Rate</th>
                                                <th>Total Amount Due</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td>GHS <?php echo number_format($bill['old_bill'], 2); ?></td>
                                                <td>GHS <?php echo number_format($bill['previous_payments'], 2); ?></td>
                                                <td>GHS <?php echo number_format($bill['arrears'], 2); ?></td>
                                                <td>GHS <?php echo number_format($bill['current_bill'], 2); ?></td>
                                                <td>GHS <?php echo number_format($bill['amount_payable'], 2); ?></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <p class="bill-note">Please present this bill when making a payment</p>
                            </div>
                        </div>

                        <!-- Bill Footer -->
                        <div class="bill-footer">
                            <p>KINDLY pay the amount involved to the DISTRICT FINANCE OFFICER or to any Revenue Collector appointed by the Assembly ON OR BEFORE 1st of September 2025.</p>
                            <p>For inquiries, please call: 0249579191/0243558623/0205535222</p>
                        </div>
                    </div>
                </div>

                <!-- Payment History -->
                <?php if (!empty($payments)): ?>
                <div class="payment-history">
                    <div class="section-title">
                        <div class="section-icon">
                            <i class="fas fa-history"></i>
                        </div>
                        Payment History
                    </div>

                    <div style="overflow-x: auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Reference</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Status</th>
                                    <th>Processed By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $payment): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                                        <td style="font-family: monospace; font-weight: 600;"><?php echo htmlspecialchars($payment['payment_reference']); ?></td>
                                        <td class="amount">GHS <?php echo number_format($payment['amount_paid'], 2); ?></td>
                                        <td><?php echo htmlspecialchars($payment['payment_method']); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo strtolower($payment['payment_status']); ?>">
                                                <?php echo htmlspecialchars($payment['payment_status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars(trim(($payment['processed_by_name'] ?? '') . ' ' . ($payment['processed_by_surname'] ?? '')) ?: 'System'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php elseif ($bill['status'] !== 'Paid'): ?>
                <div class="payment-history">
                    <div class="section-title">
                        <div class="section-icon">
                            <i class="fas fa-history"></i>
                        </div>
                        Payment History
                    </div>
                    <div class="empty-state">
                        <i class="fas fa-credit-card"></i>
                        <h3>No Payments Recorded</h3>
                        <p>No payments have been made against this bill.</p>
                        <a href="../payments/record.php?bill_id=<?php echo $bill['bill_id']; ?>" class="btn btn-success" style="margin-top: 15px;">
                            <i class="fas fa-plus"></i>
                            Record First Payment
                        </a>
                    </div>
                </div>
                <?php endif; ?>
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

        // Handle mobile responsiveness
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth <= 768) {
                sidebar.classList.remove('hidden');
            }
        });

        function printBill() {
            // Create a new window for printing
            const printWindow = window.open('', '_blank');
            
            // Get the bill content
            const billContent = document.querySelector('.bill-container').innerHTML;
            
            // Get assembly name from PHP
            const assemblyName = <?php echo json_encode($assemblyName); ?>;
            
            // Create print content with enhanced watermark support
            const printContent = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Bill - <?php echo htmlspecialchars($bill['bill_number']); ?></title>
                    <style>
                        body { 
                            font-family: Arial, sans-serif; 
                            margin: 0; 
                            padding: 20px; 
                            background: white;
                            -webkit-print-color-adjust: exact !important;
                            print-color-adjust: exact !important;
                        }
                        .bill-wrapper {
                            width: 100%;
                            padding: 20px;
                            position: relative;
                            overflow: hidden;
                            z-index: 1;
                            background-color: #fff;
                            box-sizing: border-box;
                        }
                        
                        /* Enhanced watermark styles for printing */
                        .watermark {
                            position: absolute;
                            font-size: 60px;
                            font-weight: bold;
                            font-family: Arial, Helvetica, sans-serif;
                            color: rgba(0,0,0,0.12) !important;
                            z-index: 0;
                            pointer-events: none;
                            opacity: 1 !important;
                            transform: rotate(-45deg);
                            user-select: none;
                            -webkit-print-color-adjust: exact !important;
                            print-color-adjust: exact !important;
                        }
                        
                        .watermark-top-left {
                            top: 15%;
                            left: 15%;
                            transform: translate(-50%, -50%) rotate(-45deg);
                        }
                        
                        .watermark-center {
                            top: 50%;
                            left: 50%;
                            transform: translate(-50%, -50%) rotate(-45deg);
                        }
                        
                        .watermark-bottom-right {
                            bottom: 15%;
                            right: 15%;
                            transform: translate(50%, 50%) rotate(-45deg);
                        }
                        
                        .watermark-top-right {
                            top: 20%;
                            right: 20%;
                            transform: translate(50%, -50%) rotate(-45deg);
                        }
                        
                        .watermark-bottom-left {
                            bottom: 20%;
                            left: 20%;
                            transform: translate(-50%, 50%) rotate(-45deg);
                        }
                        
                        .bill-header {
                            display: flex;
                            justify-content: space-between;
                            align-items: center;
                            border-bottom: 1px solid #000;
                            padding-bottom: 15px;
                            position: relative;
                            z-index: 2;
                            margin-bottom: 20px;
                            flex-wrap: wrap;
                            gap: 15px;
                        }
                        .bill-logo, .bill-logo-right { width: 80px; height: auto; z-index: 2; flex-shrink: 0; }
                        .bill-header-text { text-align: center; flex-grow: 1; z-index: 2; min-width: 200px; }
                        .bill-header-text h1 { margin: 0; font-size: 28px; font-weight: bold; color: #000; }
                        .bill-header-text h2 { margin: 8px 0; font-size: 18px; font-weight: normal; color: #333; }
                        .bill-header-text p { margin: 5px 0; font-size: 14px; color: #666; line-height: 1.4; }
                        .bill-content { display: flex; justify-content: space-between; margin-top: 20px; position: relative; z-index: 2; gap: 15px; flex-wrap: wrap; }
                        .bill-left-section, .bill-right-section { flex: 1; min-width: 300px; max-width: calc(48% - 7px); box-sizing: border-box; }
                        .bill-info-box { border: 1px solid #000; padding: 15px; margin-bottom: 20px; position: relative; z-index: 2; background: white; word-wrap: break-word; overflow-wrap: break-word; box-sizing: border-box; }
                        .bill-info-box p { margin: 8px 0; font-size: 14px; color: #000; word-wrap: break-word; overflow-wrap: break-word; }
                        .bill-info-box p strong { display: inline-block; width: 130px; font-weight: bold; vertical-align: top; }
                        .bill-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; position: relative; z-index: 2; table-layout: fixed; }
                        .bill-table, .bill-table th, .bill-table td { border: 1px solid #000; padding: 5px 3px; text-align: center; font-size: 11px; word-wrap: break-word; overflow-wrap: break-word; }
                        .bill-table th { background-color: #f0f0f0; font-weight: bold; color: #000; line-height: 1.2; }
                        .bill-table td { color: #000; line-height: 1.2; }
                        .bill-note { font-size: 14px; text-align: right; margin-bottom: 20px; position: relative; z-index: 2; color: #000; font-weight: 500; }
                        .bill-qr-code { text-align: center; margin-top: 15px; position: relative; z-index: 2; padding: 15px 0; }
                        .bill-qr-code img { width: 150px; height: 150px; border: 1px solid #ddd; }
                        .bill-footer { border-top: 1px solid #000; padding-top: 15px; text-align: center; font-size: 13px; position: relative; z-index: 2; color: #000; margin-top: 30px; clear: both; }
                        .bill-footer p { margin: 8px 0; line-height: 1.4; }
                        
                        /* Force print color adjustment */
                        * {
                            -webkit-print-color-adjust: exact !important;
                            print-color-adjust: exact !important;
                        }
                        
                        @media print { 
                            .no-print { display: none; } 
                            body { margin: 0; padding: 10px; }
                            .bill-content { gap: 10px; }
                            .bill-left-section, .bill-right-section { max-width: 48%; }
                            
                            /* Ensure watermarks are visible in print */
                            .watermark {
                                color: rgba(0,0,0,0.15) !important;
                                opacity: 1 !important;
                                -webkit-print-color-adjust: exact !important;
                                print-color-adjust: exact !important;
                            }
                            
                            .bill-logo, .bill-logo-right {
                                width: 80px !important;
                                height: auto !important;
                            }
                        }
                    </style>
                </head>
                <body>
                    ${billContent}
                    <div class="no-print" style="margin-top: 30px; text-align: center;">
                        <button onclick="window.print()" style="padding: 10px 20px; margin: 5px; background: #667eea; color: white; border: none; border-radius: 5px; cursor: pointer;">Print Bill</button>
                        <button onclick="window.close()" style="padding: 10px 20px; margin: 5px; background: #6c757d; color: white; border: none; border-radius: 5px; cursor: pointer;">Close</button>
                    </div>
                </body>
                </html>
            `;
            
            printWindow.document.write(printContent);
            printWindow.document.close();
            
            // Auto-print after a short delay to ensure content is loaded
            setTimeout(() => {
                printWindow.print();
            }, 500);
        }
    </script>
</body>
</html>