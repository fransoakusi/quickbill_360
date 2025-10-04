<?php
/**
 * Payment Management - Record Payment with Enhanced Confirmation
 * QUICKBILL 305 - Admin Panel - FIXED VERSION
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
if (!hasPermission('payments.create')) {
    setFlashMessage('error', 'Access denied. You do not have permission to record payments.');
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

$pageTitle = 'Record Payment';
$currentUser = getCurrentUser();
$userDisplayName = getUserDisplayName($currentUser);

// Add payment reference generator if missing
if (!function_exists('generatePaymentReference')) {
    function generatePaymentReference() {
        return 'PAY' . date('Ymd') . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));
    }
}

// Initialize variables
$errors = [];
$accountData = null;
$billData = null;
$remainingBalance = 0;
$totalPaid = 0;
$paymentSummary = null;
$formData = [
    'account_number' => '',
    'account_type' => '',
    'payment_method' => '',
    'payment_channel' => '',
    'amount_paid' => '',
    'transaction_id' => '',
    'notes' => ''
];

try {
    $db = new Database();
    
    // Handle account search
    if (isset($_POST['search_account']) && !empty($_POST['account_number'])) {
        $accountNumber = sanitizeInput($_POST['account_number']);
        $accountType = sanitizeInput($_POST['account_type']);
        
        writeLog("Account search attempt - Number: {$accountNumber}, Type: {$accountType}", 'DEBUG');
        
        if ($accountType === 'Business') {
            $accountData = $db->fetchRow("
                SELECT 
                    b.*,
                    z.zone_name,
                    sz.sub_zone_name,
                    bfs.fee_amount
                FROM businesses b
                LEFT JOIN zones z ON b.zone_id = z.zone_id
                LEFT JOIN sub_zones sz ON b.sub_zone_id = sz.sub_zone_id
                LEFT JOIN business_fee_structure bfs ON b.business_type = bfs.business_type AND b.category = bfs.category
                WHERE b.account_number = ?
            ", [$accountNumber]);
            
            if ($accountData) {
                writeLog("Business found: " . $accountData['business_name'], 'DEBUG');
                
                // Check if bill exists for current year
                $currentYear = date('Y');
                $billData = $db->fetchRow("
                    SELECT * FROM bills 
                    WHERE bill_type = 'Business' AND reference_id = ? AND billing_year = ?
                ", [$accountData['business_id'], $currentYear]);
                
                if (!$billData) {
                    writeLog("No bill found for business ID: " . $accountData['business_id'] . " for year: " . $currentYear, 'DEBUG');
                    $errors[] = "No bill found for this business account for the year {$currentYear}. Please generate bills first before recording payments.";
                    $accountData = null;
                } else {
                    writeLog("Bill found: " . $billData['bill_number'] . " with amount_payable: " . $billData['amount_payable'], 'DEBUG');
                    
                    // FIX: Use the bill's amount_payable directly - this is the correct remaining balance for the current year
                    $remainingBalance = floatval($billData['amount_payable']);
                    
                    // Get payment summary for this account (all years)
                    $paymentSummary = $db->fetchRow("
                        SELECT 
                            COALESCE(SUM(CASE WHEN p.payment_status = 'Successful' THEN p.amount_paid ELSE 0 END), 0) as total_paid,
                            COUNT(CASE WHEN p.payment_status = 'Successful' THEN 1 END) as successful_payments,
                            COUNT(*) as total_transactions
                        FROM payments p
                        JOIN bills b ON p.bill_id = b.bill_id
                        WHERE b.bill_type = 'Business' AND b.reference_id = ?
                    ", [$accountData['business_id']]);
                    
                    $totalPaid = $paymentSummary['total_paid'] ?? 0;
                }
            }
            
        } elseif ($accountType === 'Property') {
            $accountData = $db->fetchRow("
                SELECT 
                    p.*,
                    z.zone_name,
                    sz.sub_zone_name,
                    pfs.fee_per_room
                FROM properties p
                LEFT JOIN zones z ON p.zone_id = z.zone_id
                LEFT JOIN sub_zones sz ON p.sub_zone_id = sz.sub_zone_id
                LEFT JOIN property_fee_structure pfs ON p.structure = pfs.structure AND p.property_use = pfs.property_use
                WHERE p.property_number = ? OR p.account_number = ?
            ", [$accountNumber, $accountNumber]);
            
            if ($accountData) {
                writeLog("Property found: " . $accountData['owner_name'], 'DEBUG');
                
                // Check if bill exists for current year
                $currentYear = date('Y');
                $billData = $db->fetchRow("
                    SELECT * FROM bills 
                    WHERE bill_type = 'Property' AND reference_id = ? AND billing_year = ?
                ", [$accountData['property_id'], $currentYear]);
                
                if (!$billData) {
                    writeLog("No bill found for property ID: " . $accountData['property_id'] . " for year: " . $currentYear, 'DEBUG');
                    $errors[] = "No bill found for this property account for the year {$currentYear}. Please generate bills first before recording payments.";
                    $accountData = null;
                } else {
                    writeLog("Bill found: " . $billData['bill_number'] . " with amount_payable: " . $billData['amount_payable'], 'DEBUG');
                    
                    // FIX: Use the bill's amount_payable directly - this is the correct remaining balance for the current year
                    $remainingBalance = floatval($billData['amount_payable']);
                    
                    // Get payment summary for this account (all years)
                    $paymentSummary = $db->fetchRow("
                        SELECT 
                            COALESCE(SUM(CASE WHEN p.payment_status = 'Successful' THEN p.amount_paid ELSE 0 END), 0) as total_paid,
                            COUNT(CASE WHEN p.payment_status = 'Successful' THEN 1 END) as successful_payments,
                            COUNT(*) as total_transactions
                        FROM payments p
                        JOIN bills b ON p.bill_id = b.bill_id
                        WHERE b.bill_type = 'Property' AND b.reference_id = ?
                    ", [$accountData['property_id']]);
                    
                    $totalPaid = $paymentSummary['total_paid'] ?? 0;
                }
            }
        }
        
        if (!$accountData && empty($errors)) {
            $errors[] = "Account not found. Please check the account number and type.";
            writeLog("Account not found - Number: {$accountNumber}, Type: {$accountType}", 'DEBUG');
        } else if ($accountData && $billData) {
            $formData['account_number'] = $accountNumber;
            $formData['account_type'] = $accountType;
            writeLog("Account search successful with valid bill. Remaining balance: GHS " . $remainingBalance, 'DEBUG');
        }
    }
    
    // Handle payment submission
    if (isset($_POST['submit_payment']) && $_POST['submit_payment'] === 'confirmed') {
        // Re-fetch account data if needed (preserve account search)
        if (empty($accountData) && !empty($_POST['account_number']) && !empty($_POST['account_type'])) {
            $accountNumber = sanitizeInput($_POST['account_number']);
            $accountType = sanitizeInput($_POST['account_type']);
            
            if ($accountType === 'Business') {
                $accountData = $db->fetchRow("SELECT * FROM businesses WHERE account_number = ?", [$accountNumber]);
                if ($accountData) {
                    $currentYear = date('Y');
                    $billData = $db->fetchRow("SELECT * FROM bills WHERE bill_type = 'Business' AND reference_id = ? AND billing_year = ?", [$accountData['business_id'], $currentYear]);
                    if ($billData) {
                        $remainingBalance = floatval($billData['amount_payable']);
                    }
                }
            } elseif ($accountType === 'Property') {
                $accountData = $db->fetchRow("SELECT * FROM properties WHERE property_number = ? OR account_number = ?", [$accountNumber, $accountNumber]);
                if ($accountData) {
                    $currentYear = date('Y');
                    $billData = $db->fetchRow("SELECT * FROM bills WHERE bill_type = 'Property' AND reference_id = ? AND billing_year = ?", [$accountData['property_id'], $currentYear]);
                    if ($billData) {
                        $remainingBalance = floatval($billData['amount_payable']);
                    }
                }
            }
        }
        
        // Validate data availability
        if (!$accountData) {
            $errors[] = 'Account data not found. Please search for the account again.';
        } elseif (!$billData) {
            $errors[] = 'Bill data not found for this account in ' . date('Y') . '. Please generate bills first.';
        } else {
            // Validate and sanitize input
            $formData['payment_method'] = sanitizeInput($_POST['payment_method'] ?? '');
            $formData['payment_channel'] = sanitizeInput($_POST['payment_channel'] ?? '');
            $formData['amount_paid'] = sanitizeInput($_POST['amount_paid'] ?? '');
            $formData['transaction_id'] = sanitizeInput($_POST['transaction_id'] ?? '');
            $formData['notes'] = sanitizeInput($_POST['notes'] ?? '');
            
            // Keep form data for account search
            $formData['account_number'] = sanitizeInput($_POST['account_number'] ?? '');
            $formData['account_type'] = sanitizeInput($_POST['account_type'] ?? '');
            
            // Validation
            if (empty($formData['payment_method'])) {
                $errors[] = 'Payment method is required.';
            }
            
            if (empty($formData['amount_paid'])) {
                $errors[] = 'Payment amount is required.';
            } elseif (!is_numeric($formData['amount_paid'])) {
                $errors[] = 'Payment amount must be a valid number.';
            } elseif (floatval($formData['amount_paid']) <= 0) {
                $errors[] = 'Payment amount must be greater than zero.';
            } elseif (floatval($formData['amount_paid']) > $remainingBalance && $remainingBalance > 0) {
                $errors[] = 'Payment amount cannot exceed the remaining balance of GHS ' . number_format($remainingBalance, 2) . '.';
            }
            
            // Transaction ID validation for certain payment methods
            if (in_array($formData['payment_method'], ['Mobile Money', 'Bank Transfer', 'Online']) && empty($formData['transaction_id'])) {
                $errors[] = 'Transaction ID is required for this payment method.';
            }
            
            // Process payment if no errors
            if (empty($errors)) {
                try {
                    // Use database transaction methods
                    if (method_exists($db, 'beginTransaction')) {
                        $db->beginTransaction();
                    } else {
                        $db->execute("START TRANSACTION");
                    }
                    
                    $amountPaid = floatval($formData['amount_paid']);
                    $paymentReference = generatePaymentReference();
                    
                    // Get selected account details for audit logging
                    if ($billData['bill_type'] === 'Business') {
                        $auditAccount = $db->fetchRow("
                            SELECT business_id as id, account_number, business_name as name, owner_name
                            FROM businesses WHERE business_id = ?
                        ", [$billData['reference_id']]);
                    } else {
                        $auditAccount = $db->fetchRow("
                            SELECT property_id as id, property_number as account_number, owner_name as name, owner_name
                            FROM properties WHERE property_id = ?
                        ", [$billData['reference_id']]);
                    }
                    
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
                        $formData['payment_method'],
                        $formData['payment_channel'],
                        $formData['transaction_id'],
                        $currentUser['user_id'],
                        $formData['notes']
                    ]);
                    
                    if (!$result) {
                        throw new Exception("Failed to insert payment record");
                    }
                    
                    // Get payment ID
                    $paymentId = null;
                    if (method_exists($db, 'lastInsertId')) {
                        $paymentId = $db->lastInsertId();
                    } else {
                        $insertResult = $db->fetchRow("SELECT LAST_INSERT_ID() as id");
                        $paymentId = $insertResult['id'] ?? null;
                    }
                    
                    // Update bill status
                    $newAmountPayable = floatval($billData['amount_payable']) - $amountPaid;
                    $newAmountPayable = max(0, $newAmountPayable); // Ensure non-negative
                    $billStatus = $newAmountPayable <= 0 ? 'Paid' : ($newAmountPayable < floatval($billData['amount_payable']) ? 'Partially Paid' : 'Pending');
                    
                    $billUpdateResult = $db->execute("
                        UPDATE bills 
                        SET amount_payable = ?, status = ?
                        WHERE bill_id = ?
                    ", [$newAmountPayable, $billStatus, $billData['bill_id']]);
                    
                    if (!$billUpdateResult) {
                        throw new Exception("Failed to update bill status");
                    }
                    
                    // Update account balance
                    if ($formData['account_type'] === 'Business') {
                        $newBusinessPayable = floatval($accountData['amount_payable']) - $amountPaid;
                        $newBusinessPayable = max(0, $newBusinessPayable); // Ensure non-negative
                        $newPreviousPayments = floatval($accountData['previous_payments']) + $amountPaid;
                        
                        $accountUpdateResult = $db->execute("
                            UPDATE businesses 
                            SET amount_payable = ?, previous_payments = ?
                            WHERE business_id = ?
                        ", [$newBusinessPayable, $newPreviousPayments, $accountData['business_id']]);
                        
                        if (!$accountUpdateResult) {
                            throw new Exception("Failed to update business account");
                        }
                    } else {
                        $newPropertyPayable = floatval($accountData['amount_payable']) - $amountPaid;
                        $newPropertyPayable = max(0, $newPropertyPayable); // Ensure non-negative
                        $newPreviousPayments = floatval($accountData['previous_payments']) + $amountPaid;
                        
                        $accountUpdateResult = $db->execute("
                            UPDATE properties 
                            SET amount_payable = ?, previous_payments = ?
                            WHERE property_id = ?
                        ", [$newPropertyPayable, $newPreviousPayments, $accountData['property_id']]);
                        
                        if (!$accountUpdateResult) {
                            throw new Exception("Failed to update property account");
                        }
                    }
                    
                    // Enhanced audit logging for payment recording
                    try {
                        $auditData = [
                            'payment_reference' => $paymentReference,
                            'bill_id' => $billData['bill_id'],
                            'bill_number' => $billData['bill_number'] ?? 'N/A',
                            'billing_year' => $billData['billing_year'] ?? date('Y'),
                            'account_type' => $billData['bill_type'],
                            'account_id' => $billData['reference_id'],
                            'account_name' => $auditAccount['name'] ?? 'Unknown',
                            'account_number' => $auditAccount['account_number'] ?? 'N/A',
                            'account_owner' => $auditAccount['owner_name'] ?? 'Unknown',
                            'amount_paid' => $amountPaid,
                            'payment_method' => $formData['payment_method'],
                            'payment_channel' => $formData['payment_channel'],
                            'transaction_id' => $formData['transaction_id'],
                            'previous_balance' => $remainingBalance,
                            'new_balance' => $newAmountPayable,
                            'bill_amount_payable_before' => $billData['amount_payable'],
                            'bill_amount_payable_after' => $newAmountPayable,
                            'bill_status_updated' => $billStatus,
                            'payment_status' => 'Successful',
                            'processed_by_id' => $currentUser['user_id'],
                            'processed_by_name' => $userDisplayName,
                            'notes' => $formData['notes'],
                            'timestamp' => date('Y-m-d H:i:s')
                        ];
                        
                        $db->execute(
                            "INSERT INTO audit_logs (user_id, action, table_name, record_id, new_values, ip_address, user_agent, created_at)
                             VALUES (?, 'PAYMENT_RECORDED', 'payments', ?, ?, ?, ?, NOW())",
                            [
                                $currentUser['user_id'], 
                                $paymentId,
                                json_encode($auditData),
                                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', 
                                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
                            ]
                        );
                        
                        // Also log bill status update if applicable
                        if ($billStatus === 'Paid') {
                            $billAuditData = [
                                'bill_id' => $billData['bill_id'],
                                'bill_number' => $billData['bill_number'] ?? 'N/A',
                                'previous_status' => $billData['status'] ?? 'Pending',
                                'new_status' => $billStatus,
                                'final_payment_reference' => $paymentReference,
                                'final_payment_amount' => $amountPaid,
                                'total_amount_paid' => $billData['amount_payable'],
                                'account_type' => $billData['bill_type'],
                                'account_id' => $billData['reference_id'],
                                'completed_by' => $userDisplayName,
                                'completion_timestamp' => date('Y-m-d H:i:s')
                            ];
                            
                            $db->execute(
                                "INSERT INTO audit_logs (user_id, action, table_name, record_id, new_values, ip_address, user_agent, created_at)
                                 VALUES (?, 'BILL_FULLY_PAID', 'bills', ?, ?, ?, ?, NOW())",
                                [
                                    $currentUser['user_id'], 
                                    $billData['bill_id'],
                                    json_encode($billAuditData),
                                    $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', 
                                    $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
                                ]
                            );
                        }
                        
                    } catch (Exception $auditError) {
                        // Log audit failure but don't fail the payment
                        error_log("Failed to log payment audit: " . $auditError->getMessage());
                    }
                    
                    // Log the action
                    $accountName = $formData['account_type'] === 'Business' ? $accountData['business_name'] : $accountData['owner_name'];
                    writeLog("Payment recorded: GHS {$amountPaid} for {$accountName} ({$formData['account_number']}) via {$formData['payment_method']} by user {$currentUser['username']}", 'INFO');
                    
                    // Commit transaction
                    if (method_exists($db, 'commit')) {
                        $db->commit();
                    } else {
                        $db->execute("COMMIT");
                    }
                    
                    setFlashMessage('success', "Payment recorded successfully! Payment Reference: {$paymentReference}");
                    header("Location: view.php?id={$paymentId}");
                    exit();
                    
                } catch (Exception $e) {
                    // Rollback transaction
                    if (isset($db)) {
                        if (method_exists($db, 'rollback')) {
                            $db->rollback();
                        } else {
                            $db->execute("ROLLBACK");
                        }
                    }
                    
                    writeLog("Error recording payment: " . $e->getMessage(), 'ERROR');
                    $errors[] = 'An error occurred while processing the payment: ' . $e->getMessage();
                }
            }
        }
    }
    
} catch (Exception $e) {
    writeLog("Payment record page error: " . $e->getMessage(), 'ERROR');
    $errors[] = 'An error occurred while loading the page. Please try again.';
}
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
        .icon-search::before { content: "🔍"; }
        .icon-money::before { content: "💰"; }
        .icon-payment::before { content: "💳"; }
        .icon-save::before { content: "💾"; }
        .icon-info::before { content: "ℹ️"; }
        .icon-warning::before { content: "⚠️"; }
        .icon-shield::before { content: "🛡️"; }
        .icon-history::before { content: "🕒"; }
        .icon-help::before { content: "❓"; }
        
        /* Navigation and layout styles */
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
            transform: translateY(-2px);
            color: white;
        }
        
        /* Form Styles */
        .form-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }
        
        .form-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            height: fit-content;
        }
        
        .form-section {
            margin-bottom: 30px;
        }
        
        .form-section:last-child {
            margin-bottom: 0;
        }
        
        .section-title {
            font-size: 20px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-icon {
            width: 35px;
            height: 35px;
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-row.single {
            grid-template-columns: 1fr;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-label {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 8px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .required {
            color: #e53e3e;
        }
        
        .form-control {
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
            background: white;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .form-control:invalid {
            border-color: #e53e3e;
        }
        
        .form-control.error {
            border-color: #e53e3e;
            background: #fef2f2;
        }
        
        .form-help {
            font-size: 12px;
            color: #64748b;
            margin-top: 5px;
        }
        
        .currency-input {
            position: relative;
        }
        
        .currency-symbol {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-weight: 600;
            pointer-events: none;
        }
        
        .currency-input .form-control {
            padding-left: 50px;
        }
        
        /* Account Info Display */
        .account-info-card {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            border: 1px solid #93c5fd;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
        }
        
        .account-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .account-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 20px;
        }
        
        .account-details h3 {
            font-size: 18px;
            font-weight: bold;
            color: #1e40af;
            margin: 0 0 5px 0;
        }
        
        .account-number-display {
            font-family: monospace;
            color: #1e3a8a;
            font-weight: 600;
        }
        
        .account-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .account-item {
            background: rgba(255, 255, 255, 0.7);
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #bfdbfe;
        }
        
        .account-label {
            font-size: 12px;
            color: #1e40af;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 5px;
        }
        
        .account-value {
            font-size: 14px;
            font-weight: 600;
            color: #1e3a8a;
        }
        
        .amount-value {
            font-family: monospace;
            color: #059669;
            font-size: 16px;
        }
        
        .outstanding-balance {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: 2px solid #f59e0b;
        }
        
        .outstanding-balance .account-value {
            color: #92400e;
            font-size: 18px;
        }
        
        /* Alert Messages */
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
        
        .alert-warning {
            background: #fef3c7;
            border: 1px solid #fcd34d;
            color: #92400e;
        }
        
        /* Buttons */
        .btn {
            padding: 12px 24px;
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
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.3);
            color: white;
        }
        
        .btn-secondary {
            background: #64748b;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #475569;
            color: white;
        }
        
        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.3);
            color: white;
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(239, 68, 68, 0.3);
            color: white;
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            padding-top: 20px;
            border-top: 2px solid #e2e8f0;
            margin-top: 30px;
        }
        
        /* Payment Methods */
        .payment-method-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }
        
        .payment-method-option {
            position: relative;
        }
        
        .payment-method-input {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .payment-method-label {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 15px 10px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s;
            background: white;
        }
        
        .payment-method-input:checked + .payment-method-label {
            border-color: #3b82f6;
            background: #eff6ff;
            color: #1e40af;
        }
        
        .payment-method-icon {
            font-size: 24px;
            margin-bottom: 8px;
        }
        
        .payment-method-text {
            font-size: 12px;
            font-weight: 600;
            text-align: center;
        }
        
        /* Enhanced Confirmation Modal */
        .confirmation-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(8px);
            z-index: 20000;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
            padding: 20px;
            box-sizing: border-box;
        }
        
        .confirmation-modal {
            background: white;
            border-radius: 20px;
            text-align: center;
            box-shadow: 0 25px 80px rgba(0, 0, 0, 0.4);
            max-width: 500px;
            width: 100%;
            max-height: 90vh;
            animation: modalSlideIn 0.4s ease;
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        
        .confirmation-header {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            padding: 30px;
            position: relative;
        }
        
        .confirmation-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            box-shadow: 0 10px 30px rgba(245, 158, 11, 0.3);
            animation: pulse 2s infinite;
        }
        
        .confirmation-title {
            font-size: 24px;
            font-weight: 700;
            color: #92400e;
            margin-bottom: 10px;
        }
        
        .confirmation-subtitle {
            font-size: 14px;
            color: #a16207;
            opacity: 0.9;
        }
        
        .confirmation-body {
            padding: 30px;
            flex: 1;
            overflow-y: auto;
            min-height: 0;
        }
        
        .payment-details {
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
        }
        
        .payment-amount {
            font-size: 32px;
            font-weight: 800;
            color: #059669;
            margin-bottom: 15px;
            font-family: monospace;
        }
        
        .payment-method-display {
            font-size: 16px;
            color: #475569;
            margin-bottom: 10px;
        }
        
        .payment-method-display strong {
            color: #1e293b;
        }
        
        .account-info-display {
            font-size: 14px;
            color: #64748b;
            background: rgba(59, 130, 246, 0.1);
            padding: 10px 15px;
            border-radius: 8px;
            margin-top: 10px;
        }
        
        .warning-section {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            border: 2px solid #f87171;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .warning-icon {
            width: 40px;
            height: 40px;
            background: #dc2626;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
            animation: shake 0.5s ease-in-out;
            flex-shrink: 0;
        }
        
        .warning-text {
            flex: 1;
            text-align: left;
        }
        
        .warning-title {
            font-size: 16px;
            font-weight: 700;
            color: #dc2626;
            margin-bottom: 5px;
        }
        
        .warning-description {
            font-size: 14px;
            color: #991b1b;
            line-height: 1.4;
        }
        
        .confirmation-actions {
            display: flex;
            gap: 15px;
            justify-content: center;
            padding: 20px 30px 30px;
            border-top: 1px solid #e2e8f0;
            background: white;
            flex-shrink: 0;
        }
        
        .confirmation-btn {
            padding: 15px 30px;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 16px;
            min-width: 140px;
            position: relative;
            overflow: hidden;
        }
        
        .confirmation-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s;
        }
        
        .confirmation-btn:hover::before {
            left: 100%;
        }
        
        .btn-cancel {
            background: #64748b;
            color: white;
        }
        
        .btn-cancel:hover {
            background: #475569;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(100, 116, 139, 0.3);
        }
        
        .btn-confirm {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            color: white;
        }
        
        .btn-confirm:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(220, 38, 38, 0.4);
        }
        
        /* Responsive Design */
        @media (max-width: 1024px) {
            .form-container {
                grid-template-columns: 1fr;
            }
            
            .confirmation-modal {
                max-width: 95%;
            }
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
            
            .form-row {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .header-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .account-grid {
                grid-template-columns: 1fr;
            }
            
            .payment-method-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .confirmation-backdrop {
                padding: 10px;
            }
            
            .confirmation-modal {
                max-height: 95vh;
            }
            
            .confirmation-header {
                padding: 20px;
            }
            
            .confirmation-body {
                padding: 20px;
            }
            
            .confirmation-actions {
                flex-direction: column;
                gap: 10px;
                padding: 15px 20px 20px;
            }
            
            .confirmation-btn {
                min-width: auto;
                width: 100%;
            }
            
            .payment-amount {
                font-size: 28px;
            }
            
            .warning-section {
                flex-direction: column;
                text-align: center;
                gap: 10px;
            }
            
            .warning-text {
                text-align: center;
            }
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes modalSlideIn {
            from { transform: translateY(-30px) scale(0.9); opacity: 0; }
            to { transform: translateY(0) scale(1); opacity: 1; }
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }
        
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .form-card {
            animation: slideDown 0.3s ease forwards;
        }
        
        /* Loading states */
        .btn-loading {
            position: relative;
            color: transparent !important;
        }
        
        .btn-loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 20px;
            height: 20px;
            margin: -10px 0 0 -10px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top: 2px solid white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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
                    display: block;
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
                        <a href="../settings/index.php" class="dropdown-item">
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
                            <span class="icon-history" style="display: none;"></span>
                            Activity Log
                        </a>
                        <a href="../settings/index.php" class="dropdown-item">
                            <i class="fas fa-question-circle"></i>
                            <span class="icon-help" style="display: none;"></span>
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
                        <a href="index.php" class="nav-link active">
                            <span class="nav-icon">
                                <i class="fas fa-credit-card"></i>
                                <span class="icon-credit" style="display: none;"></span>
                            </span>
                            Payments
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../fee_structure/property_fees.php" class="nav-link">
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
                    <a href="index.php">Payments</a>
                    <span>/</span>
                    <span class="breadcrumb-current">Record Payment</span>
                </div>
            </div>

            <!-- Flash Messages -->
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <strong>Please fix the following errors:</strong>
                        <ul style="margin: 10px 0 0 0; padding-left: 20px;">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Page Header -->
            <div class="page-header">
                <div class="header-content">
                    <div>
                        <h1 class="page-title">
                            <i class="fas fa-plus-circle"></i>
                            Record Payment
                        </h1>
                        <p style="color: #64748b; margin: 5px 0 0 0;">Process payment transactions for businesses and properties</p>
                    </div>
                    <a href="index.php" class="back-btn">
                        <i class="fas fa-arrow-left"></i>
                        Back to Payments
                    </a>
                </div>
            </div>

            <div class="form-container">
                <!-- Account Search -->
                <div class="form-card">
                    <div class="form-section">
                        <h3 class="section-title">
                            <div class="section-icon">
                                <i class="fas fa-search"></i>
                                <span class="icon-search" style="display: none;"></span>
                            </div>
                            Search Account
                        </h3>
                        
                        <form method="POST" action="">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">
                                        Account Type <span class="required">*</span>
                                    </label>
                                    <select name="account_type" class="form-control" required>
                                        <option value="">Select account type</option>
                                        <option value="Business" <?php echo $formData['account_type'] === 'Business' ? 'selected' : ''; ?>>Business</option>
                                        <option value="Property" <?php echo $formData['account_type'] === 'Property' ? 'selected' : ''; ?>>Property</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">
                                        Account Number <span class="required">*</span>
                                    </label>
                                    <input type="text" name="account_number" class="form-control" 
                                           value="<?php echo htmlspecialchars($formData['account_number']); ?>"
                                           placeholder="Enter account number" required>
                                </div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" name="search_account" class="btn btn-primary">
                                    <i class="fas fa-search"></i>
                                    <span class="icon-search" style="display: none;"></span>
                                    Search Account
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <?php if ($accountData && $billData): ?>
                        <!-- Account Information Display -->
                        <div class="account-info-card">
                            <div class="account-header">
                                <div class="account-avatar">
                                    <?php if ($formData['account_type'] === 'Business'): ?>
                                        <i class="fas fa-building"></i>
                                    <?php else: ?>
                                        <i class="fas fa-home"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="account-details">
                                    <h3>
                                        <?php echo htmlspecialchars($formData['account_type'] === 'Business' ? $accountData['business_name'] : $accountData['owner_name']); ?>
                                    </h3>
                                    <div class="account-number-display"><?php echo htmlspecialchars($formData['account_number']); ?></div>
                                </div>
                            </div>
                            
                            <div class="account-grid">
                                <?php if ($formData['account_type'] === 'Business'): ?>
                                    <div class="account-item">
                                        <div class="account-label">Business Type</div>
                                        <div class="account-value"><?php echo htmlspecialchars($accountData['business_type']); ?></div>
                                    </div>
                                    <div class="account-item">
                                        <div class="account-label">Category</div>
                                        <div class="account-value"><?php echo htmlspecialchars($accountData['category']); ?></div>
                                    </div>
                                    <div class="account-item">
                                        <div class="account-label">Owner</div>
                                        <div class="account-value"><?php echo htmlspecialchars($accountData['owner_name']); ?></div>
                                    </div>
                                <?php else: ?>
                                    <div class="account-item">
                                        <div class="account-label">Structure</div>
                                        <div class="account-value"><?php echo htmlspecialchars($accountData['structure']); ?></div>
                                    </div>
                                    <div class="account-item">
                                        <div class="account-label">Property Use</div>
                                        <div class="account-value"><?php echo htmlspecialchars($accountData['property_use']); ?></div>
                                    </div>
                                    <div class="account-item">
                                        <div class="account-label">Rooms</div>
                                        <div class="account-value"><?php echo number_format($accountData['number_of_rooms']); ?></div>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="account-item">
                                    <div class="account-label">Zone</div>
                                    <div class="account-value"><?php echo htmlspecialchars($accountData['zone_name'] ?? 'Not Set'); ?></div>
                                </div>
                                
                                <div class="account-item">
                                    <div class="account-label">Phone</div>
                                    <div class="account-value"><?php echo htmlspecialchars($accountData['telephone'] ?? 'Not Set'); ?></div>
                                </div>
                                
                                <div class="account-item outstanding-balance">
                                    <div class="account-label">Current Bill Balance</div>
                                    <div class="account-value amount-value">GHS <?php echo number_format($remainingBalance, 2); ?></div>
                                </div>
                            </div>
                            
                            <?php if ($paymentSummary && $paymentSummary['total_paid'] > 0): ?>
                                <div style="margin-top: 15px; padding: 15px; background: rgba(255,255,255,0.5); border-radius: 8px; border: 1px solid #93c5fd;">
                                    <div style="font-size: 12px; color: #1e40af; margin-bottom: 8px; font-weight: 600;">
                                        Payment History Summary
                                    </div>
                                    <div style="display: flex; justify-content: space-between; font-size: 13px;">
                                        <span>Total Paid (All Years): <strong>GHS <?php echo number_format($paymentSummary['total_paid'], 2); ?></strong></span>
                                        <span>Successful Payments: <strong><?php echo $paymentSummary['successful_payments']; ?></strong></span>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Payment Form -->
                <?php if ($accountData && $billData): ?>
                    <div class="form-card">
                        <form method="POST" action="" id="paymentForm">
                            <!-- Hidden fields -->
                            <input type="hidden" name="account_number" value="<?php echo htmlspecialchars($formData['account_number']); ?>">
                            <input type="hidden" name="account_type" value="<?php echo htmlspecialchars($formData['account_type']); ?>">
                            <input type="hidden" name="submit_payment" value="" id="submitPaymentField">
                            
                            <div class="form-section">
                                <h3 class="section-title">
                                    <div class="section-icon">
                                        <i class="fas fa-credit-card"></i>
                                        <span class="icon-payment" style="display: none;"></span>
                                    </div>
                                    Payment Details
                                </h3>
                                
                                <div class="form-group">
                                    <label class="form-label">
                                        Payment Method <span class="required">*</span>
                                    </label>
                                    <div class="payment-method-grid">
                                        <div class="payment-method-option">
                                            <input type="radio" name="payment_method" id="mobile_money" value="Mobile Money" class="payment-method-input" <?php echo $formData['payment_method'] === 'Mobile Money' ? 'checked' : ''; ?>>
                                            <label for="mobile_money" class="payment-method-label">
                                                <div class="payment-method-icon">📱</div>
                                                <div class="payment-method-text">Mobile Money</div>
                                            </label>
                                        </div>
                                        
                                        <div class="payment-method-option">
                                            <input type="radio" name="payment_method" id="cash" value="Cash" class="payment-method-input" <?php echo $formData['payment_method'] === 'Cash' ? 'checked' : ''; ?>>
                                            <label for="cash" class="payment-method-label">
                                                <div class="payment-method-icon">💵</div>
                                                <div class="payment-method-text">Cash</div>
                                            </label>
                                        </div>
                                        
                                        <div class="payment-method-option">
                                            <input type="radio" name="payment_method" id="bank_transfer" value="Bank Transfer" class="payment-method-input" <?php echo $formData['payment_method'] === 'Bank Transfer' ? 'checked' : ''; ?>>
                                            <label for="bank_transfer" class="payment-method-label">
                                                <div class="payment-method-icon">🏦</div>
                                                <div class="payment-method-text">Bank Transfer</div>
                                            </label>
                                        </div>
                                        
                                        <div class="payment-method-option">
                                            <input type="radio" name="payment_method" id="online" value="Online" class="payment-method-input" <?php echo $formData['payment_method'] === 'Online' ? 'checked' : ''; ?>>
                                            <label for="online" class="payment-method-label">
                                                <div class="payment-method-icon">💳</div>
                                                <div class="payment-method-text">Online</div>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label">
                                            <i class="fas fa-money-bill-wave"></i>
                                            <span class="icon-money" style="display: none;"></span>
                                            Amount Paid <span class="required">*</span>
                                        </label>
                                        <div class="currency-input">
                                            <span class="currency-symbol">GHS</span>
                                            <input type="number" name="amount_paid" id="amountPaid" class="form-control" 
                                                   value="<?php echo htmlspecialchars($formData['amount_paid']); ?>" 
                                                   placeholder="0.00" 
                                                   min="0" max="<?php echo $remainingBalance; ?>" step="0.01" required>
                                        </div>
                                        <div class="form-help">Maximum payable: GHS <?php echo number_format($remainingBalance, 2); ?></div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">
                                            Payment Channel
                                        </label>
                                        <input type="text" name="payment_channel" class="form-control" 
                                               value="<?php echo htmlspecialchars($formData['payment_channel']); ?>"
                                               placeholder="e.g., MTN, Vodafone, Bank Name">
                                        <div class="form-help">Specify the service provider or channel used</div>
                                    </div>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label">
                                            Transaction ID
                                        </label>
                                        <input type="text" name="transaction_id" class="form-control" 
                                               value="<?php echo htmlspecialchars($formData['transaction_id']); ?>"
                                               placeholder="Transaction reference number">
                                        <div class="form-help">Required for Mobile Money, Bank Transfer, and Online payments</div>
                                    </div>
                                </div>
                                
                                <div class="form-row single">
                                    <div class="form-group">
                                        <label class="form-label">
                                            Notes
                                        </label>
                                        <textarea name="notes" class="form-control" rows="3" 
                                                  placeholder="Additional notes about this payment"><?php echo htmlspecialchars($formData['notes']); ?></textarea>
                                        <div class="form-help">Optional additional information about the payment</div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-actions">
                                <a href="index.php" class="btn btn-secondary">
                                    <i class="fas fa-times"></i>
                                    Cancel
                                </a>
                                <button type="submit" id="recordPaymentBtn" class="btn btn-success">
                                    <i class="fas fa-save"></i>
                                    <span class="icon-save" style="display: none;"></span>
                                    Record Payment
                                </button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Enhanced Payment Confirmation System loaded');
            
            // Icon fallback check
            setTimeout(function() {
                const testIcon = document.querySelector('.fas.fa-bars');
                if (!testIcon || getComputedStyle(testIcon, ':before').content === 'none') {
                    console.log('Font Awesome not loaded, using emoji fallbacks');
                    document.querySelectorAll('.fas, .far').forEach(function(icon) {
                        icon.style.display = 'none';
                    });
                    document.querySelectorAll('[class*="icon-"]').forEach(function(emoji) {
                        emoji.style.display = 'inline';
                    });
                } else {
                    console.log('Font Awesome loaded successfully');
                }
            }, 100);
            
            // Initialize payment form
            initializeEnhancedPaymentForm();
        });

        function initializeEnhancedPaymentForm() {
            const form = document.getElementById('paymentForm');
            if (!form) {
                console.log('Payment form not available yet');
                return;
            }

            console.log('Initializing enhanced payment form...');
            
            // Enhanced form submission with confirmation
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                // Get form data
                const formData = new FormData(form);
                const paymentMethod = formData.get('payment_method');
                const amount = formData.get('amount_paid');
                const accountNumber = formData.get('account_number');
                const accountType = formData.get('account_type');
                const transactionId = formData.get('transaction_id');
                const paymentChannel = formData.get('payment_channel');
                
                // Basic validation
                if (!paymentMethod) {
                    showAlert('Please select a payment method', 'error');
                    return;
                }
                
                if (!amount || parseFloat(amount) <= 0) {
                    showAlert('Please enter a valid payment amount', 'error');
                    return;
                }
                
                // Show enhanced confirmation dialog
                showEnhancedPaymentConfirmation({
                    amount: parseFloat(amount),
                    paymentMethod: paymentMethod,
                    accountNumber: accountNumber,
                    accountType: accountType,
                    transactionId: transactionId,
                    paymentChannel: paymentChannel
                });
            });
            
            // Payment method change handler
            const paymentMethods = document.querySelectorAll('input[name="payment_method"]');
            paymentMethods.forEach(method => {
                method.addEventListener('change', function() {
                    const transactionField = document.querySelector('input[name="transaction_id"]');
                    const channelField = document.querySelector('input[name="payment_channel"]');
                    
                    if (this.value === 'Cash') {
                        if (transactionField) {
                            transactionField.required = false;
                            transactionField.placeholder = 'Receipt number (optional)';
                        }
                        if (channelField) channelField.placeholder = 'Cash collection point';
                    } else {
                        if (transactionField) {
                            transactionField.required = true;
                            transactionField.placeholder = 'Transaction reference number';
                        }
                        if (channelField) {
                            channelField.placeholder = 'Service provider (e.g., MTN, Vodafone)';
                        }
                    }
                });
            });
        }

        function showEnhancedPaymentConfirmation(paymentData) {
            // Get account info from the page
            const accountName = document.querySelector('.account-details h3')?.textContent || 'Unknown Account';
            const outstandingBalance = document.querySelector('.outstanding-balance .amount-value')?.textContent || 'GHS 0.00';
            
            // Create backdrop
            const backdrop = document.createElement('div');
            backdrop.className = 'confirmation-backdrop';
            backdrop.setAttribute('data-payment-confirmation', 'true');
            
            // Create modal
            const modal = document.createElement('div');
            modal.className = 'confirmation-modal';
            
            modal.innerHTML = `
                <div class="confirmation-header">
                    <div class="confirmation-icon">
                        <i class="fas fa-exclamation-triangle" style="font-size: 2rem; color: white;"></i>
                        <span class="icon-warning" style="display: none; font-size: 2rem;">⚠️</span>
                    </div>
                    <h2 class="confirmation-title">Payment Confirmation Required</h2>
                    <p class="confirmation-subtitle">Please review the details before proceeding</p>
                </div>
                
                <div class="confirmation-body">
                    <div class="payment-details">
                        <div class="payment-amount">GHS ${paymentData.amount.toFixed(2)}</div>
                        <div class="payment-method-display">
                            Payment Method: <strong>${paymentData.paymentMethod}</strong>
                        </div>
                        ${paymentData.paymentChannel ? `
                            <div class="payment-method-display">
                                Channel: <strong>${paymentData.paymentChannel}</strong>
                            </div>
                        ` : ''}
                        ${paymentData.transactionId ? `
                            <div class="payment-method-display">
                                Transaction ID: <strong>${paymentData.transactionId}</strong>
                            </div>
                        ` : ''}
                        
                        <div class="account-info-display">
                            <strong>${accountName}</strong> (${paymentData.accountNumber})<br>
                            Current Bill Balance: ${outstandingBalance}
                        </div>
                    </div>
                    
                    <div class="warning-section">
                        <div class="warning-icon">
                            <i class="fas fa-shield-alt"></i>
                            <span class="icon-shield" style="display: none;">🛡️</span>
                        </div>
                        <div class="warning-text">
                            <div class="warning-title">Important Security Notice</div>
                            <div class="warning-description">
                                This payment transaction <strong>cannot be undone</strong> once confirmed. 
                                Please verify all details are correct before proceeding.
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="confirmation-actions">
                    <button type="button" class="confirmation-btn btn-cancel" onclick="cancelPaymentConfirmation()">
                        <i class="fas fa-times"></i>
                        Cancel Payment
                    </button>
                    <button type="button" class="confirmation-btn btn-confirm" onclick="confirmPaymentSubmission()">
                        <i class="fas fa-check-circle"></i>
                        <span class="icon-save" style="display: none;">💾</span>
                        Confirm Payment
                    </button>
                </div>
            `;
            
            backdrop.appendChild(modal);
            document.body.appendChild(backdrop);
            
            // Ensure modal is properly positioned and visible
            setTimeout(() => {
                const modalRect = modal.getBoundingClientRect();
                const viewportHeight = window.innerHeight;
                
                // If modal is taller than viewport, ensure it's scrollable
                if (modalRect.height > viewportHeight * 0.9) {
                    modal.style.maxHeight = '90vh';
                    const confirmationBody = modal.querySelector('.confirmation-body');
                    if (confirmationBody) {
                        confirmationBody.style.overflowY = 'auto';
                    }
                }
                
                // Ensure buttons are visible
                const actionsSection = modal.querySelector('.confirmation-actions');
                if (actionsSection) {
                    actionsSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            }, 100);
            
            // Add event listeners
            backdrop.addEventListener('click', function(e) {
                if (e.target === backdrop) {
                    cancelPaymentConfirmation();
                }
            });
            
            // ESC key handler
            document.addEventListener('keydown', function escHandler(e) {
                if (e.key === 'Escape') {
                    cancelPaymentConfirmation();
                    document.removeEventListener('keydown', escHandler);
                }
            });
            
            // Button hover effects
            const buttons = modal.querySelectorAll('.confirmation-btn');
            buttons.forEach(btn => {
                btn.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-2px)';
                });
                btn.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });
        }

        function cancelPaymentConfirmation() {
            const backdrop = document.querySelector('[data-payment-confirmation="true"]');
            if (backdrop) {
                backdrop.style.animation = 'fadeOut 0.3s ease forwards';
                setTimeout(() => {
                    backdrop.remove();
                }, 300);
            }
        }

        function confirmPaymentSubmission() {
            const confirmBtn = document.querySelector('.btn-confirm');
            const backdrop = document.querySelector('[data-payment-confirmation="true"]');
            
            // Show loading state
            confirmBtn.classList.add('btn-loading');
            confirmBtn.disabled = true;
            
            // Set the hidden field to indicate confirmed submission
            document.getElementById('submitPaymentField').value = 'confirmed';
            
            // Submit the form
            setTimeout(() => {
                const form = document.getElementById('paymentForm');
                if (form) {
                    form.submit();
                }
            }, 500);
            
            // Remove backdrop after slight delay
            setTimeout(() => {
                if (backdrop) {
                    backdrop.remove();
                }
            }, 1000);
        }

        function showAlert(message, type = 'info') {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type}`;
            alertDiv.innerHTML = `
                <i class="fas fa-${type === 'error' ? 'exclamation-triangle' : 'info-circle'}"></i>
                <div>${message}</div>
            `;
            
            // Insert after breadcrumb
            const breadcrumb = document.querySelector('.breadcrumb-nav');
            if (breadcrumb) {
                breadcrumb.insertAdjacentElement('afterend', alertDiv);
            }
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                alertDiv.remove();
            }, 5000);
        }

        // Utility functions
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('hidden');
            
            const isHidden = sidebar.classList.contains('hidden');
            localStorage.setItem('sidebarHidden', isHidden);
        }

        function toggleUserDropdown() {
            const dropdown = document.getElementById('userDropdown');
            dropdown.classList.toggle('show');
        }

        // Restore sidebar state
        document.addEventListener('DOMContentLoaded', function() {
            const sidebarHidden = localStorage.getItem('sidebarHidden');
            if (sidebarHidden === 'true') {
                document.getElementById('sidebar').classList.add('hidden');
            }
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('userDropdown');
            const profile = document.getElementById('userProfile');
            
            if (profile && !profile.contains(event.target)) {
                dropdown.classList.remove('show');
            }
        });

        console.log('Enhanced Payment Confirmation System JavaScript loaded successfully');
    </script>
</body>
</html>