<?php
/**
 * Public Portal - Pay Bill for QUICKBILL 305
 * Payment interface with outstanding balance tracking and PayStack integration
 * Updated with consistent account-level balance calculation
 */

// Define application constant
if (!defined('QUICKBILL_305')) {
    define('QUICKBILL_305', true);
}

// Include configuration files
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Start session for public portal
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Add missing authentication functions for public portal compatibility
if (!function_exists('isLoggedIn')) {
    function isLoggedIn() {
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }
}

if (!function_exists('getCurrentUserId')) {
    function getCurrentUserId() {
        return $_SESSION['user_id'] ?? null;
    }
}

// Public portal logging function (doesn't require authentication)
function logPublicActivity($action, $details = '') {
    try {
        $db = new Database();
        
        $sql = "INSERT INTO audit_logs (user_id, action, table_name, record_id, new_values, ip_address, user_agent, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $params = [
            null, // No user ID for public access
            'PUBLIC: ' . $action,
            'public_portal',
            null,
            json_encode($details),
            getClientIP(),
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ];
        
        $pdo = $db->getConnection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
    } catch (Exception $e) {
        writeLog("Failed to log public activity: " . $e->getMessage(), 'ERROR');
    }
}

// Generate payment reference
if (!function_exists('generatePaymentReference')) {
    function generatePaymentReference() {
        return 'PAY' . date('YmdHis') . mt_rand(100, 999);
    }
}

// Calculate remaining balance for account - ACCOUNT LEVEL ONLY
function calculateAccountRemainingBalance($accountId, $accountType, $totalAmountPayable) {
    try {
        $db = new Database();
        
        // Get total successful payments for this account
        $paymentsQuery = "SELECT COALESCE(SUM(p.amount_paid), 0) as total_paid
                         FROM payments p 
                         INNER JOIN bills b ON p.bill_id = b.bill_id 
                         WHERE b.bill_type = ? AND b.reference_id = ? 
                         AND p.payment_status = 'Successful'";
        
        $paymentsResult = $db->fetchRow($paymentsQuery, [$accountType, $accountId]);
        $totalPaid = $paymentsResult['total_paid'] ?? 0;
        
        // Calculate remaining balance
        $remainingBalance = max(0, $totalAmountPayable - $totalPaid);
        $isFullyPaid = $remainingBalance <= 0;
        $paymentPercentage = $totalAmountPayable > 0 ? ($totalPaid / $totalAmountPayable) * 100 : 100;
        
        return [
            'total_paid' => $totalPaid,
            'remaining_balance' => $remainingBalance,
            'payment_percentage' => min($paymentPercentage, 100),
            'is_fully_paid' => $isFullyPaid
        ];
        
    } catch (Exception $e) {
        writeLog("Error calculating account remaining balance: " . $e->getMessage(), 'ERROR');
        return [
            'total_paid' => 0,
            'remaining_balance' => $totalAmountPayable,
            'payment_percentage' => 0,
            'is_fully_paid' => false
        ];
    }
}

// Get recent payment history for this account
function getRecentPaymentHistory($accountId, $accountType, $limit = 3) {
    try {
        $db = new Database();
        
        $paymentsQuery = "SELECT p.payment_reference, p.amount_paid, p.payment_method, p.payment_status, p.payment_date
                         FROM payments p 
                         INNER JOIN bills b ON p.bill_id = b.bill_id 
                         WHERE b.bill_type = ? AND b.reference_id = ?
                         ORDER BY p.payment_date DESC 
                         LIMIT ?";
        
        return $db->fetchAll($paymentsQuery, [$accountType, $accountId, $limit]);
        
    } catch (Exception $e) {
        writeLog("Error getting payment history: " . $e->getMessage(), 'ERROR');
        return [];
    }
}

// Verify PayStack transaction
function verifyPaystackTransaction($reference, $secretKey) {
    $url = "https://api.paystack.co/transaction/verify/" . $reference;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $secretKey,
        "Cache-Control: no-cache",
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $result = json_decode($response, true);
        return $result['status'] ? $result['data'] : false;
    }
    
    return false;
}

// Process PayStack payment callback
function processPaystackPayment($paymentReference, $billId) {
    try {
        $db = new Database();
        
        // Get PayStack configuration
        $paystackConfig = getConfig('paystack');
        $secretKey = $paystackConfig['secret_key'] ?? '';
        
        if (empty($secretKey)) {
            throw new Exception('PayStack secret key not configured');
        }
        
        // Verify transaction with PayStack
        $transactionData = verifyPaystackTransaction($paymentReference, $secretKey);
        
        if (!$transactionData || $transactionData['status'] !== 'success') {
            throw new Exception('Payment verification failed');
        }
        
        // Check if payment already exists
        $existingPayment = $db->fetchRow(
            "SELECT payment_id FROM payments WHERE paystack_reference = ?", 
            [$paymentReference]
        );
        
        if ($existingPayment) {
            return ['success' => true, 'message' => 'Payment already processed', 'payment_id' => $existingPayment['payment_id']];
        }
        
        // Get bill details with account info
        $billQuery = "
            SELECT 
                b.bill_id,
                b.bill_number,
                b.bill_type,
                b.reference_id,
                b.billing_year,
                b.amount_payable,
                b.status,
                CASE 
                    WHEN b.bill_type = 'Business' THEN bs.business_name
                    WHEN b.bill_type = 'Property' THEN p.owner_name
                END as account_name,
                CASE 
                    WHEN b.bill_type = 'Business' THEN bs.account_number
                    WHEN b.bill_type = 'Property' THEN p.property_number
                END as account_number,
                CASE 
                    WHEN b.bill_type = 'Business' THEN bs.amount_payable
                    WHEN b.bill_type = 'Property' THEN p.amount_payable
                END as account_total_payable
            FROM bills b
            LEFT JOIN businesses bs ON b.bill_type = 'Business' AND b.reference_id = bs.business_id
            LEFT JOIN properties p ON b.bill_type = 'Property' AND b.reference_id = p.property_id
            WHERE b.bill_id = ?
        ";
        
        $billData = $db->fetchRow($billQuery, [$billId]);
        
        if (!$billData) {
            throw new Exception('Bill not found');
        }
        
        // Get current account balance info
        $balanceInfo = calculateAccountRemainingBalance(
            $billData['reference_id'], 
            $billData['bill_type'], 
            $billData['account_total_payable']
        );
        
        // Start database transaction
        if (method_exists($db, 'beginTransaction')) {
            $db->beginTransaction();
        } else {
            $db->execute("START TRANSACTION");
        }
        
        // Calculate payment amount (convert from kobo to cedis)
        $amountPaid = $transactionData['amount'] / 100;
        
        // Validate payment amount doesn't exceed remaining balance
        if ($amountPaid > $balanceInfo['remaining_balance']) {
            throw new Exception('Payment amount exceeds outstanding balance');
        }
        
        // Generate internal payment reference
        $internalReference = generatePaymentReference();
        
        // Extract payer information from transaction metadata
        $metadata = $transactionData['metadata'] ?? [];
        $payerName = $metadata['payer_name'] ?? $transactionData['customer']['email'] ?? 'Online Payment';
        $payerEmail = $transactionData['customer']['email'] ?? '';
        $payerPhone = $metadata['payer_phone'] ?? '';
        
        // Insert payment record
        $paymentQuery = "
            INSERT INTO payments (payment_reference, bill_id, amount_paid, payment_method, 
                                payment_channel, transaction_id, paystack_reference, payment_status, 
                                payment_date, processed_by, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'Successful', NOW(), ?, ?)
        ";
        
        $notes = json_encode([
            'payer_name' => $payerName,
            'payer_email' => $payerEmail,
            'payer_phone' => $payerPhone,
            'paystack_reference' => $paymentReference,
            'gateway' => 'PayStack',
            'authorization_code' => $transactionData['authorization']['authorization_code'] ?? '',
            'card_type' => $transactionData['authorization']['card_type'] ?? '',
            'bank' => $transactionData['authorization']['bank'] ?? '',
            'last4' => $transactionData['authorization']['last4'] ?? ''
        ]);
        
        $result = $db->execute($paymentQuery, [
            $internalReference,
            $billId,
            $amountPaid,
            'Online',
            'PayStack',
            $paymentReference,
            $paymentReference,
            null, // No user ID for public payments
            $notes
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
        
        // Update account balance - SINGLE SOURCE OF TRUTH
        if ($billData['bill_type'] === 'Business') {
            // Update business account
            $businessData = $db->fetchRow("SELECT * FROM businesses WHERE business_id = ?", [$billData['reference_id']]);
            if ($businessData) {
                $newBusinessPayable = max(0, floatval($businessData['amount_payable']) - $amountPaid);
                $newPreviousPayments = floatval($businessData['previous_payments']) + $amountPaid;
                
                $accountUpdateResult = $db->execute("
                    UPDATE businesses 
                    SET amount_payable = ?, previous_payments = ?
                    WHERE business_id = ?
                ", [$newBusinessPayable, $newPreviousPayments, $billData['reference_id']]);
                
                if (!$accountUpdateResult) {
                    throw new Exception("Failed to update business account");
                }
            }
        } else {
            // Update property account
            $propertyData = $db->fetchRow("SELECT * FROM properties WHERE property_id = ?", [$billData['reference_id']]);
            if ($propertyData) {
                $newPropertyPayable = max(0, floatval($propertyData['amount_payable']) - $amountPaid);
                $newPreviousPayments = floatval($propertyData['previous_payments']) + $amountPaid;
                
                $accountUpdateResult = $db->execute("
                    UPDATE properties 
                    SET amount_payable = ?, previous_payments = ?
                    WHERE property_id = ?
                ", [$newPropertyPayable, $newPreviousPayments, $billData['reference_id']]);
                
                if (!$accountUpdateResult) {
                    throw new Exception("Failed to update property account");
                }
            }
        }
        
        // Update bill status (optional - for record keeping)
        $newRemainingBalance = max(0, $balanceInfo['remaining_balance'] - $amountPaid);
        $billStatus = $newRemainingBalance <= 0 ? 'Paid' : 'Partially Paid';
        
        $billUpdateResult = $db->execute("
            UPDATE bills 
            SET status = ?
            WHERE bill_id = ?
        ", [$billStatus, $billId]);
        
        if (!$billUpdateResult) {
            throw new Exception("Failed to update bill status");
        }
        
        // Log the action
        logPublicActivity(
            "Online payment processed", 
            [
                'payment_reference' => $internalReference,
                'paystack_reference' => $paymentReference,
                'amount' => $amountPaid,
                'bill_number' => $billData['bill_number'],
                'account_number' => $billData['account_number'],
                'account_name' => $billData['account_name'],
                'remaining_balance_before' => $balanceInfo['remaining_balance'],
                'remaining_balance_after' => $newRemainingBalance,
                'payer_email' => $payerEmail,
                'payer_phone' => $payerPhone,
                'payment_method' => 'PayStack',
                'timestamp' => date('Y-m-d H:i:s')
            ]
        );
        
        // Commit transaction
        if (method_exists($db, 'commit')) {
            $db->commit();
        } else {
            $db->execute("COMMIT");
        }
        
        writeLog("PayStack payment processed successfully: {$internalReference} for bill {$billData['bill_number']}", 'INFO');
        
        return [
            'success' => true, 
            'message' => 'Payment processed successfully', 
            'payment_id' => $paymentId,
            'internal_reference' => $internalReference
        ];
        
    } catch (Exception $e) {
        // Rollback transaction
        if (isset($db)) {
            if (method_exists($db, 'rollback')) {
                $db->rollback();
            } else {
                $db->execute("ROLLBACK");
            }
        }
        
        writeLog("Error processing PayStack payment: " . $e->getMessage(), 'ERROR');
        
        return [
            'success' => false, 
            'message' => 'Payment processing failed: ' . $e->getMessage()
        ];
    }
}

// Handle PayStack callback/webhook (this should be called from payment_success.php or a webhook endpoint)
if (isset($_GET['process_paystack_payment']) && isset($_GET['reference']) && isset($_GET['bill_id'])) {
    header('Content-Type: application/json');
    
    $paymentReference = sanitizeInput($_GET['reference']);
    $billId = (int)$_GET['bill_id'];
    
    $result = processPaystackPayment($paymentReference, $billId);
    
    echo json_encode($result);
    exit();
}

// Debug payment processing (add ?debug_payment=1 to URL)
if (isset($_GET['debug_payment']) && $_GET['debug_payment'] == '1' && isset($_GET['bill_id'])) {
    header('Content-Type: text/plain');
    
    $billId = (int)$_GET['bill_id'];
    
    echo "=== PAYMENT DEBUG INFO ===\n";
    echo "Bill ID: " . $billId . "\n";
    echo "Time: " . date('Y-m-d H:i:s') . "\n\n";
    
    try {
        $db = new Database();
        
        // Check bill exists with account info
        $billQuery = "
            SELECT 
                b.*,
                CASE 
                    WHEN b.bill_type = 'Business' THEN bs.business_name
                    WHEN b.bill_type = 'Property' THEN p.owner_name
                END as account_name,
                CASE 
                    WHEN b.bill_type = 'Business' THEN bs.amount_payable
                    WHEN b.bill_type = 'Property' THEN p.amount_payable
                END as account_total_payable
            FROM bills b
            LEFT JOIN businesses bs ON b.bill_type = 'Business' AND b.reference_id = bs.business_id
            LEFT JOIN properties p ON b.bill_type = 'Property' AND b.reference_id = p.property_id
            WHERE b.bill_id = ?
        ";
        $bill = $db->fetchRow($billQuery, [$billId]);
        echo "Bill Found: " . ($bill ? 'YES' : 'NO') . "\n";
        if ($bill) {
            echo "Bill Number: " . $bill['bill_number'] . "\n";
            echo "Bill Status: " . $bill['status'] . "\n";
            echo "Bill Amount Payable: " . $bill['amount_payable'] . "\n";
            echo "Account Name: " . $bill['account_name'] . "\n";
            echo "Account Total Payable: " . $bill['account_total_payable'] . "\n";
        }
        echo "\n";
        
        // Check recent payments
        $payments = $db->fetchAll("
            SELECT p.* FROM payments p 
            INNER JOIN bills b ON p.bill_id = b.bill_id 
            WHERE b.bill_type = ? AND b.reference_id = ? 
            ORDER BY p.payment_date DESC 
            LIMIT 5
        ", [$bill['bill_type'], $bill['reference_id']]);
        echo "Recent Account Payments: " . count($payments) . "\n";
        foreach ($payments as $payment) {
            echo "- Ref: " . $payment['payment_reference'] . 
                 ", PayStack: " . ($payment['paystack_reference'] ?? 'N/A') .
                 ", Status: " . $payment['payment_status'] . 
                 ", Amount: " . $payment['amount_paid'] . 
                 ", Date: " . $payment['payment_date'] . "\n";
        }
        echo "\n";
        
        // Check account balance
        $balance = calculateAccountRemainingBalance($bill['reference_id'], $bill['bill_type'], $bill['account_total_payable']);
        echo "Account Balance Info:\n";
        echo "- Total Paid: " . $balance['total_paid'] . "\n";
        echo "- Remaining: " . $balance['remaining_balance'] . "\n";
        echo "- Percentage: " . $balance['payment_percentage'] . "%\n";
        echo "- Fully Paid: " . ($balance['is_fully_paid'] ? 'YES' : 'NO') . "\n";
        
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
    
    exit();
}

// Define missing functions if not exists
if (!function_exists('setFlashMessage')) {
    function setFlashMessage($type, $message) {
        $_SESSION['flash_messages'][$type] = $message;
    }
}

if (!function_exists('getFlashMessage')) {
    function getFlashMessage($type) {
        if (isset($_SESSION['flash_messages'][$type])) {
            $message = $_SESSION['flash_messages'][$type];
            unset($_SESSION['flash_messages'][$type]);
            return $message;
        }
        return null;
    }
}

if (!function_exists('getPaymentMethods')) {
    function getPaymentMethods() {
        return ['card', 'cash', 'bank_transfer'];
    }
}

// Basic Header content
if (!function_exists('includeHeader')) {
    function includeHeader() {
        $appName = defined('APP_NAME') ? APP_NAME : 'QUICKBILL 305';
        echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pay Bill - ' . htmlspecialchars($appName) . '</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #2d3748;
            background: #f7fafc;
        }
    </style>
</head>
<body>';
    }
}

// Basic Footer content  
if (!function_exists('includeFooter')) {
    function includeFooter() {
        $appName = defined('APP_NAME') ? APP_NAME : 'QUICKBILL 305';
        echo '<footer style="text-align: center; padding: 20px; background: #2d3748; color: white; margin-top: 40px;">
            <p>&copy; ' . date('Y') . ' ' . htmlspecialchars($appName) . '. All rights reserved.</p>
        </footer>
        </body>
        </html>';
    }
}

// Add missing system setting function if not exists
if (!function_exists('getSystemSetting')) {
    function getSystemSetting($key, $default = null) {
        global $app_settings;
        return $app_settings[$key] ?? $default;
    }
}

// Add missing config functions if not exists
if (!function_exists('getConfig')) {
    function getConfig($key) {
        // Return PayStack configuration
        if ($key === 'paystack') {
            return [
                'public_key' => 'pk_test_your_paystack_public_key_here', // Replace with your actual public key
                'secret_key' => 'sk_test_your_paystack_secret_key_here', // Replace with your actual secret key
                'live_mode' => false // Set to true for live mode
            ];
        }
        return [];
    }
}

// Add missing constants if not defined
if (!defined('BASE_URL')) {
    define('BASE_URL', 'http://localhost/quickbill_305/public_portal');
}

// Add missing utility functions if not exists
if (!function_exists('getClientIP')) {
    function getClientIP() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        }
    }
}

if (!function_exists('formatDate')) {
    function formatDate($date) {
        return date('M d, Y', strtotime($date));
    }
}

if (!function_exists('sanitizeInput')) {
    function sanitizeInput($input) {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('writeLog')) {
    function writeLog($message, $level = 'INFO') {
        error_log("[{$level}] " . date('Y-m-d H:i:s') . " - " . $message);
    }
}

// Get bill ID from URL
$billId = isset($_GET['bill_id']) ? (int)$_GET['bill_id'] : 0;

if (!$billId) {
    setFlashMessage('error', 'Invalid bill ID provided.');
    header('Location: search_bill.php');
    exit();
}

// Initialize variables
$billData = null;
$accountData = null;
$balanceInfo = [];
$paymentHistory = [];
$paymentMethods = getPaymentMethods();
$assemblyName = getSystemSetting('assembly_name', 'Municipal Assembly');

// Get PayStack configuration
$paystackConfig = getConfig('paystack');
$paystackPublicKey = $paystackConfig['public_key'] ?? '';
$paystackTestMode = !($paystackConfig['live_mode'] ?? false);

try {
    $db = new Database();
    
    // Log payment page access
    logPublicActivity(
        "Payment page accessed", 
        [
            'bill_id' => $billId,
            'timestamp' => date('Y-m-d H:i:s')
        ]
    );
    
    // Get bill details with account information
    $billQuery = "
        SELECT 
            b.bill_id,
            b.bill_number,
            b.bill_type,
            b.reference_id,
            b.billing_year,
            b.old_bill,
            b.previous_payments,
            b.arrears,
            b.current_bill,
            b.amount_payable,
            b.status,
            b.generated_at,
            CASE 
                WHEN b.bill_type = 'Business' THEN bs.business_name
                WHEN b.bill_type = 'Property' THEN p.owner_name
            END as account_name,
            CASE 
                WHEN b.bill_type = 'Business' THEN bs.account_number
                WHEN b.bill_type = 'Property' THEN p.property_number
            END as account_number,
            CASE 
                WHEN b.bill_type = 'Business' THEN bs.owner_name
                WHEN b.bill_type = 'Property' THEN p.owner_name
            END as owner_name,
            CASE 
                WHEN b.bill_type = 'Business' THEN bs.telephone
                WHEN b.bill_type = 'Property' THEN p.telephone
            END as telephone,
            CASE 
                WHEN b.bill_type = 'Business' THEN bs.amount_payable
                WHEN b.bill_type = 'Property' THEN p.amount_payable
            END as account_total_payable
        FROM bills b
        LEFT JOIN businesses bs ON b.bill_type = 'Business' AND b.reference_id = bs.business_id
        LEFT JOIN properties p ON b.bill_type = 'Property' AND b.reference_id = p.property_id
        WHERE b.bill_id = ?
    ";
    
    $billData = $db->fetchRow($billQuery, [$billId]);
    
    if (!$billData) {
        setFlashMessage('error', 'Bill not found.');
        header('Location: search_bill.php');
        exit();
    }
    
    // Calculate remaining balance for this account - ACCOUNT LEVEL ONLY
    $balanceInfo = calculateAccountRemainingBalance(
        $billData['reference_id'], 
        $billData['bill_type'], 
        $billData['account_total_payable']
    );
    
    // Get recent payment history for this account
    $paymentHistory = getRecentPaymentHistory($billData['reference_id'], $billData['bill_type']);
    
    // Check if account is payable
    if ($balanceInfo['remaining_balance'] <= 0) {
        setFlashMessage('info', 'This account has been paid in full.');
        header('Location: view_bill.php?bill_id=' . $billId);
        exit();
    }
    
} catch (Exception $e) {
    writeLog("Payment page error: " . $e->getMessage(), 'ERROR');
    setFlashMessage('error', 'An error occurred while loading bill details.');
    header('Location: search_bill.php');
    exit();
}

includeHeader();
?>

<div class="payment-page">
    <div class="container">
        <!-- Outstanding Balance Alert -->
        <?php if ($balanceInfo['remaining_balance'] > 0): ?>
        <div class="balance-alert balance-outstanding">
            <div class="alert-icon">💳</div>
            <div class="alert-content">
                <h3>Ready to Pay</h3>
                <div class="alert-amount">₵ <?php echo number_format($balanceInfo['remaining_balance'], 2); ?></div>
                <p>Outstanding balance for <?php echo htmlspecialchars($billData['bill_number']); ?></p>
            </div>
            <div class="alert-progress">
                <div class="progress-info">
                    <span class="progress-label">Payment Progress</span>
                    <span class="progress-percentage"><?php echo number_format($balanceInfo['payment_percentage'], 1); ?>%</span>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo min($balanceInfo['payment_percentage'], 100); ?>%"></div>
                </div>
                <div class="progress-details">
                    <span class="paid-amount">Paid: ₵ <?php echo number_format($balanceInfo['total_paid'], 2); ?></span>
                    <span class="remaining-amount">Remaining: ₵ <?php echo number_format($balanceInfo['remaining_balance'], 2); ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Payment Header -->
        <div class="payment-header">
            <div class="payment-progress">
                <div class="progress-step active">
                    <div class="step-number">1</div>
                    <span>Bill Details</span>
                </div>
                <div class="progress-line"></div>
                <div class="progress-step active">
                    <div class="step-number">2</div>
                    <span>Payment</span>
                </div>
                <div class="progress-line"></div>
                <div class="progress-step">
                    <div class="step-number">3</div>
                    <span>Confirmation</span>
                </div>
            </div>
            
            <div class="payment-title">
                <h1>💳 Make Payment</h1>
                <p>Secure online payment for your <?php echo strtolower($billData['bill_type']); ?> account</p>
            </div>
        </div>

        <?php $errorMessage = getFlashMessage('error'); ?>
        <?php if ($errorMessage): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($errorMessage); ?>
            </div>
        <?php endif; ?>

        <div class="payment-content">
            <!-- Bill Summary Card -->
            <div class="bill-summary-card">
                <div class="bill-summary-header">
                    <h3>📄 Account Summary</h3>
                    <span class="bill-number"><?php echo htmlspecialchars($billData['bill_number']); ?></span>
                </div>
                
                <div class="bill-summary-details">
                    <div class="summary-row">
                        <span class="summary-label">Account:</span>
                        <span class="summary-value"><?php echo htmlspecialchars($billData['account_number']); ?></span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label"><?php echo $billData['bill_type'] === 'Business' ? 'Business:' : 'Owner:'; ?></span>
                        <span class="summary-value"><?php echo htmlspecialchars($billData['account_name']); ?></span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label">Billing Year:</span>
                        <span class="summary-value"><?php echo $billData['billing_year']; ?></span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label">Total Account Payable:</span>
                        <span class="summary-value">₵ <?php echo number_format($billData['account_total_payable'], 2); ?></span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label">Amount Paid:</span>
                        <span class="summary-value paid">₵ <?php echo number_format($balanceInfo['total_paid'], 2); ?></span>
                    </div>
                    <div class="summary-row outstanding">
                        <span class="summary-label"><strong>Outstanding Balance:</strong></span>
                        <span class="summary-value amount"><strong>₵ <?php echo number_format($balanceInfo['remaining_balance'], 2); ?></strong></span>
                    </div>
                </div>
                
                <div class="bill-summary-actions">
                    <a href="view_bill.php?bill_id=<?php echo $billId; ?>" class="btn btn-outline btn-sm">
                        <i class="fas fa-eye"></i>
                        View Full Bill
                    </a>
                    
                    <?php if (!empty($paymentHistory)): ?>
                    <button onclick="togglePaymentHistory()" class="btn btn-secondary btn-sm">
                        <i class="fas fa-history"></i>
                        Payment History
                    </button>
                    <?php endif; ?>
                </div>

                <!-- Payment History Section (initially hidden) -->
                <?php if (!empty($paymentHistory)): ?>
                <div class="payment-history-mini" id="paymentHistoryMini" style="display: none;">
                    <h4>Recent Payments</h4>
                    <?php foreach ($paymentHistory as $payment): ?>
                    <div class="history-item">
                        <div class="history-info">
                            <span class="history-reference"><?php echo htmlspecialchars($payment['payment_reference']); ?></span>
                            <span class="history-date"><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></span>
                        </div>
                        <div class="history-amount">
                            <span class="amount">₵ <?php echo number_format($payment['amount_paid'], 2); ?></span>
                            <span class="status-badge <?php echo strtolower($payment['payment_status']); ?>">
                                <?php echo htmlspecialchars($payment['payment_status']); ?>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Payment Form -->
            <div class="payment-form-card">
                <div class="payment-form-header">
                    <h3>💰 Payment Details</h3>
                    <div class="security-badge">
                        <i class="fas fa-shield-alt"></i>
                        <span>SSL Secured</span>
                    </div>
                </div>
                
                <form id="paymentForm" class="payment-form" method="POST">
                    <input type="hidden" name="process_payment" value="1">
                    
                    <!-- Payment Amount -->
                    <div class="form-section">
                        <h4>Payment Amount</h4>
                        <div class="amount-selection">
                            <div class="amount-option active" data-amount="<?php echo $balanceInfo['remaining_balance']; ?>">
                                <div class="option-radio">●</div>
                                <div class="option-details">
                                    <div class="option-label">Pay Outstanding Balance</div>
                                    <div class="option-amount">₵ <?php echo number_format($balanceInfo['remaining_balance'], 2); ?></div>
                                    <div class="option-description">Clear your full outstanding balance</div>
                                </div>
                            </div>
                            
                            <div class="amount-option" data-amount="custom">
                                <div class="option-radio">○</div>
                                <div class="option-details">
                                    <div class="option-label">Pay Partial Amount</div>
                                    <div class="custom-amount-input" style="display: none;">
                                        <input type="number" 
                                               id="customAmount" 
                                               placeholder="Enter amount"
                                               min="1" 
                                               max="<?php echo $balanceInfo['remaining_balance']; ?>"
                                               step="0.01"
                                               class="form-control">
                                        <small>Maximum: ₵ <?php echo number_format($balanceInfo['remaining_balance'], 2); ?></small>
                                    </div>
                                    <div class="option-description">Pay a portion of your outstanding balance</div>
                                </div>
                            </div>
                        </div>
                        
                        <input type="hidden" id="selectedAmount" name="selected_amount" value="<?php echo $balanceInfo['remaining_balance']; ?>">
                    </div>

                    <!-- Payer Information -->
                    <div class="form-section">
                        <h4>Payer Information</h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="payerName">Full Name *</label>
                                <input type="text" 
                                       id="payerName"
                                       name="payer_name"
                                       value="<?php echo htmlspecialchars($billData['owner_name']); ?>"
                                       required 
                                       class="form-control">
                            </div>
                            
                            <div class="form-group">
                                <label for="payerEmail">Email Address *</label>
                                <input type="email" 
                                       id="payerEmail"
                                       name="payer_email"
                                       placeholder="your.email@example.com"
                                       required 
                                       class="form-control">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="payerPhone">Phone Number *</label>
                                <input type="tel" 
                                       id="payerPhone"
                                       name="payer_phone"
                                       value="<?php echo htmlspecialchars($billData['telephone'] ?? ''); ?>"
                                       placeholder="0XX XXX XXXX"
                                       required 
                                       class="form-control">
                            </div>
                        </div>
                    </div>

                    <!-- Payment Method -->
                    <div class="form-section">
                        <h4>Payment Method</h4>
                        <div class="payment-methods-grid">
                            <div class="payment-method-card active" data-method="card">
                                <div class="method-icon">💳</div>
                                <div class="method-info">
                                    <div class="method-name">Debit/Credit Card</div>
                                    <div class="method-description">Visa, Mastercard, Verve</div>
                                </div>
                                <div class="method-check">✓</div>
                            </div>
                            
                            <div class="payment-method-card" data-method="mobile_money">
                                <div class="method-icon">📱</div>
                                <div class="method-info">
                                    <div class="method-name">Mobile Money</div>
                                    <div class="method-description">MTN, Vodafone, AirtelTigo</div>
                                </div>
                                <div class="method-check">○</div>
                            </div>
                        </div>
                        
                        <input type="hidden" id="selectedPaymentMethod" name="payment_method" value="card">
                        
                        <!-- Mobile Money Provider Selection (initially hidden) -->
                        <div class="mobile-money-providers" id="mobileMoneyProviders" style="display: none;">
                            <h5>Select Mobile Money Provider</h5>
                            <div class="providers-grid">
                                <div class="provider-card active" data-provider="mtn">
                                    <div class="provider-logo">🟡</div>
                                    <div class="provider-name">MTN MoMo</div>
                                </div>
                                <div class="provider-card" data-provider="vodafone">
                                    <div class="provider-logo">🔴</div>
                                    <div class="provider-name">Vodafone Cash</div>
                                </div>
                                <div class="provider-card" data-provider="tigo">
                                    <div class="provider-logo">🔵</div>
                                    <div class="provider-name">AirtelTigo Money</div>
                                </div>
                            </div>
                            <input type="hidden" id="selectedProvider" name="mobile_provider" value="mtn">
                        </div>
                    </div>

                    <!-- Terms and Conditions -->
                    <div class="form-section">
                        <div class="terms-section">
                            <label class="checkbox-container">
                                <input type="checkbox" id="acceptTerms" required>
                                <span class="checkmark"></span>
                                I agree to the 
                                <a href="#" onclick="showTerms()">Terms and Conditions</a> 
                                and 
                                <a href="#" onclick="showPrivacy()">Privacy Policy</a>
                            </label>
                        </div>
                    </div>

                    <!-- Payment Button -->
                    <div class="payment-submit-section">
                        <div class="payment-summary">
                            <div class="summary-amount">
                                You will pay: <strong id="finalAmount">₵ <?php echo number_format($balanceInfo['remaining_balance'], 2); ?></strong>
                            </div>
                            <div class="summary-remaining">
                                Remaining balance after payment: <span id="remainingAfterPayment">₵ 0.00</span>
                            </div>
                            <div class="summary-note">
                                <i class="fas fa-info-circle"></i>
                                No additional charges. Your payment is secure and encrypted.
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary btn-lg btn-payment" id="payButton">
                            <i class="fas fa-lock"></i>
                            Pay Securely Now
                        </button>
                        
                        <div class="payment-security">
                            <div class="security-item">
                                <i class="fas fa-shield-alt"></i>
                                <span>256-bit SSL Encryption</span>
                            </div>
                            <div class="security-item">
                                <i class="fas fa-certificate"></i>
                                <span>PCI DSS Compliant</span>
                            </div>
                            <div class="security-item">
                                <i class="fas fa-lock"></i>
                                <span>Bank-level Security</span>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Support Section -->
        <div class="payment-support">
            <div class="support-content">
                <h4>🤝 Need Help?</h4>
                <p>Our support team is available to assist you with your payment</p>
                <div class="support-contacts">
                    <a href="tel:+233123456789" class="support-item">
                        <i class="fas fa-phone"></i>
                        <span>+233 123 456 789</span>
                    </a>
                    <a href="mailto:support@<?php echo strtolower(str_replace(' ', '', $assemblyName)); ?>.gov.gh" class="support-item">
                        <i class="fas fa-envelope"></i>
                        <span>Email Support</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Payment Page Styles */
.payment-page {
    padding: 40px 0;
    min-height: 700px;
    background: linear-gradient(135deg, #f0f9ff 0%, #f7fafc 100%);
}

.container {
    max-width: 800px;
    margin: 0 auto;
    padding: 0 20px;
}

/* Outstanding Balance Alert */
.balance-alert {
    background: white;
    border-radius: 15px;
    padding: 30px;
    margin-bottom: 30px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
    display: grid;
    grid-template-columns: auto 1fr auto;
    gap: 25px;
    align-items: center;
    animation: slideUp 0.6s ease;
    border-left: 5px solid #48bb78;
    background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
}

.alert-icon {
    font-size: 3rem;
    flex-shrink: 0;
}

.alert-content {
    flex: 1;
}

.alert-content h3 {
    color: #2d3748;
    margin-bottom: 8px;
    font-size: 1.5rem;
}

.alert-amount {
    font-size: 2.5rem;
    font-weight: bold;
    margin: 10px 0;
    color: #48bb78;
}

.alert-content p {
    color: #4a5568;
    margin: 0;
}

.alert-progress {
    text-align: center;
    min-width: 200px;
}

.progress-info {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
    font-size: 0.9rem;
    color: #4a5568;
}

.progress-label {
    font-weight: 600;
}

.progress-percentage {
    color: #48bb78;
    font-weight: bold;
}

.progress-bar {
    height: 8px;
    background: #e2e8f0;
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 8px;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #48bb78, #38a169);
    border-radius: 4px;
    transition: width 1s ease;
}

.progress-details {
    display: flex;
    justify-content: space-between;
    font-size: 0.8rem;
    color: #4a5568;
}

.paid-amount {
    color: #38a169;
    font-weight: 600;
}

.remaining-amount {
    color: #d69e2e;
    font-weight: 600;
}

/* Alert Styles */
.alert {
    padding: 15px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.alert-error {
    background: #fed7d7;
    border: 1px solid #feb2b2;
    color: #c53030;
}

.alert i {
    font-size: 1.1rem;
}

/* Payment Header */
.payment-header {
    text-align: center;
    margin-bottom: 40px;
}

.payment-progress {
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 30px;
    max-width: 400px;
    margin-left: auto;
    margin-right: auto;
}

.progress-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    opacity: 0.5;
    transition: all 0.3s;
}

.progress-step.active {
    opacity: 1;
}

.step-number {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #e2e8f0;
    color: #4a5568;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    transition: all 0.3s;
}

.progress-step.active .step-number {
    background: #667eea;
    color: white;
}

.progress-step span {
    font-size: 0.9rem;
    color: #4a5568;
    font-weight: 500;
}

.progress-step.active span {
    color: #2d3748;
    font-weight: 600;
}

.progress-line {
    width: 60px;
    height: 2px;
    background: #e2e8f0;
    margin: 0 20px;
}

.payment-title h1 {
    color: #2d3748;
    margin-bottom: 8px;
    font-size: 2.2rem;
}

.payment-title p {
    color: #718096;
    font-size: 1.1rem;
}

/* Payment Content */
.payment-content {
    display: grid;
    grid-template-columns: 350px 1fr;
    gap: 30px;
    margin-bottom: 30px;
}

/* Bill Summary Card */
.bill-summary-card {
    background: white;
    border-radius: 15px;
    padding: 25px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    height: fit-content;
    border: 1px solid #e2e8f0;
    position: sticky;
    top: 100px;
}

.bill-summary-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid #e2e8f0;
}

.bill-summary-header h3 {
    color: #2d3748;
    margin: 0;
    font-size: 1.2rem;
}

.bill-number {
    background: #667eea;
    color: white;
    padding: 4px 8px;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 600;
    font-family: monospace;
}

.bill-summary-details {
    margin-bottom: 20px;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid #f7fafc;
}

.summary-row:last-child {
    border-bottom: none;
}

.summary-row.outstanding {
    border-top: 2px solid #f59e0b;
    padding-top: 15px;
    margin-top: 10px;
    background: #fef3c7;
    margin-left: -10px;
    margin-right: -10px;
    padding-left: 10px;
    padding-right: 10px;
    border-radius: 8px;
}

.summary-label {
    color: #4a5568;
    font-size: 0.9rem;
}

.summary-value {
    color: #2d3748;
    font-weight: 600;
    text-align: right;
}

.summary-value.amount {
    color: #d69e2e;
    font-size: 1.1rem;
}

.summary-value.paid {
    color: #38a169;
}

.bill-summary-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 15px;
}

/* Payment History Mini */
.payment-history-mini {
    border-top: 1px solid #e2e8f0;
    padding-top: 15px;
    margin-top: 15px;
}

.payment-history-mini h4 {
    color: #2d3748;
    font-size: 1rem;
    margin-bottom: 10px;
}

.history-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid #f7fafc;
    font-size: 0.85rem;
}

.history-item:last-child {
    border-bottom: none;
}

.history-info {
    flex: 1;
}

.history-reference {
    display: block;
    font-weight: 600;
    color: #2d3748;
}

.history-date {
    display: block;
    color: #718096;
    font-size: 0.8rem;
}

.history-amount {
    text-align: right;
}

.history-amount .amount {
    display: block;
    font-weight: 600;
    color: #38a169;
}

/* Payment Form Card */
.payment-form-card {
    background: white;
    border-radius: 15px;
    padding: 30px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    border: 1px solid #e2e8f0;
}

.payment-form-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 1px solid #e2e8f0;
}

.payment-form-header h3 {
    color: #2d3748;
    margin: 0;
    font-size: 1.3rem;
}

.security-badge {
    display: flex;
    align-items: center;
    gap: 6px;
    background: #f0fff4;
    color: #22543d;
    padding: 6px 12px;
    border-radius: 15px;
    border: 1px solid #9ae6b4;
    font-size: 0.8rem;
    font-weight: 600;
}

.security-badge i {
    color: #38a169;
}

/* Form Sections */
.form-section {
    margin-bottom: 30px;
    padding-bottom: 25px;
    border-bottom: 1px solid #f7fafc;
}

.form-section:last-child {
    border-bottom: none;
    margin-bottom: 0;
}

.form-section h4 {
    color: #2d3748;
    margin-bottom: 15px;
    font-size: 1.1rem;
    font-weight: 600;
}

.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
}

.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    color: #2d3748;
    font-weight: 500;
    margin-bottom: 6px;
}

.form-control {
    width: 100%;
    padding: 12px 15px;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    font-size: 16px;
    transition: all 0.3s;
}

.form-control:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.form-control:invalid {
    border-color: #f56565;
}

.form-help {
    display: flex;
    align-items: center;
    gap: 5px;
    color: #718096;
    font-size: 0.8rem;
    margin-top: 5px;
}

/* Amount Selection */
.amount-selection {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.amount-option {
    display: flex;
    align-items: flex-start;
    gap: 15px;
    padding: 15px;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.3s;
}

.amount-option:hover {
    border-color: #667eea;
    background: #f0f9ff;
}

.amount-option.active {
    border-color: #667eea;
    background: #f0f9ff;
}

.option-radio {
    color: #667eea;
    font-size: 1.2rem;
    font-weight: bold;
    margin-top: 2px;
}

.option-details {
    flex: 1;
}

.option-label {
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 3px;
}

.option-amount {
    color: #d69e2e;
    font-weight: bold;
    font-size: 1.1rem;
    margin-bottom: 3px;
}

.option-description {
    color: #718096;
    font-size: 0.9rem;
}

.custom-amount-input {
    margin-top: 10px;
}

.custom-amount-input input {
    margin-bottom: 5px;
}

.custom-amount-input small {
    color: #718096;
    font-size: 0.8rem;
}

/* Payment Methods */
.payment-methods-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
}

.payment-method-card {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 15px;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.3s;
}

.payment-method-card:hover {
    border-color: #667eea;
    background: #f0f9ff;
}

.payment-method-card.active {
    border-color: #667eea;
    background: #f0f9ff;
}

.method-icon {
    font-size: 2rem;
}

.method-info {
    flex: 1;
}

.method-name {
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 3px;
}

.method-description {
    color: #718096;
    font-size: 0.9rem;
}

.method-check {
    color: #667eea;
    font-size: 1.2rem;
    font-weight: bold;
}

/* Mobile Money Providers */
.mobile-money-providers {
    margin-top: 20px;
    padding: 20px;
    background: #f7fafc;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
}

.mobile-money-providers h5 {
    color: #2d3748;
    margin-bottom: 15px;
    font-size: 1rem;
    font-weight: 600;
}

.providers-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 12px;
}

.provider-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 15px 10px;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s;
    background: white;
}

.provider-card:hover {
    border-color: #667eea;
    background: #f0f9ff;
}

.provider-card.active {
    border-color: #667eea;
    background: #f0f9ff;
    box-shadow: 0 2px 8px rgba(102, 126, 234, 0.2);
}

.provider-logo {
    font-size: 2rem;
    margin-bottom: 8px;
}

.provider-name {
    font-weight: 600;
    color: #2d3748;
    text-align: center;
    font-size: 0.9rem;
}

/* Terms Section */
.terms-section {
    background: #f7fafc;
    padding: 15px;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
}

.checkbox-container {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    color: #4a5568;
    font-size: 0.9rem;
    line-height: 1.4;
}

.checkbox-container input[type="checkbox"] {
    display: none;
}

.checkmark {
    width: 18px;
    height: 18px;
    border: 2px solid #667eea;
    border-radius: 3px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s;
    flex-shrink: 0;
}

.checkbox-container input[type="checkbox"]:checked + .checkmark {
    background: #667eea;
    color: white;
}

.checkbox-container input[type="checkbox"]:checked + .checkmark:before {
    content: "✓";
    font-size: 12px;
    font-weight: bold;
}

.checkbox-container a {
    color: #667eea;
    text-decoration: none;
}

.checkbox-container a:hover {
    text-decoration: underline;
}

/* Payment Submit Section */
.payment-submit-section {
    margin-top: 30px;
    text-align: center;
}

.payment-summary {
    background: #f0fff4;
    border: 1px solid #9ae6b4;
    padding: 20px;
    border-radius: 10px;
    margin-bottom: 20px;
}

.summary-amount {
    font-size: 1.2rem;
    color: #22543d;
    margin-bottom: 5px;
}

.summary-remaining {
    font-size: 1rem;
    color: #2f855a;
    margin-bottom: 8px;
}

.summary-note {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    color: #2f855a;
    font-size: 0.9rem;
}

.btn-payment {
    width: 100%;
    padding: 18px 24px;
    font-size: 1.2rem;
    margin-bottom: 20px;
    background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
    border: none;
    transition: all 0.3s;
}

.btn-payment:hover {
    background: linear-gradient(135deg, #38a169 0%, #2f855a 100%);
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(72, 187, 120, 0.3);
}

.btn-payment:disabled {
    background: #e2e8f0;
    color: #a0aec0;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

.payment-security {
    display: flex;
    justify-content: center;
    gap: 20px;
    flex-wrap: wrap;
}

.security-item {
    display: flex;
    align-items: center;
    gap: 6px;
    color: #4a5568;
    font-size: 0.8rem;
}

.security-item i {
    color: #48bb78;
}

/* Payment Support */
.payment-support {
    background: white;
    border-radius: 15px;
    padding: 25px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    text-align: center;
    border: 1px solid #e2e8f0;
}

.support-content h4 {
    color: #2d3748;
    margin-bottom: 8px;
}

.support-content p {
    color: #718096;
    margin-bottom: 20px;
}

.support-contacts {
    display: flex;
    justify-content: center;
    gap: 30px;
    flex-wrap: wrap;
}

.support-item {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #667eea;
    text-decoration: none;
    font-weight: 500;
    transition: all 0.3s;
}

.support-item:hover {
    color: #5a67d8;
    transform: translateY(-2px);
    text-decoration: none;
}

.support-item i {
    width: 16px;
}

/* Status Badges */
.status-badge {
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-badge.successful,
.status-badge.paid {
    background: #c6f6d5;
    color: #22543d;
}

.status-badge.pending {
    background: #feebc8;
    color: #c05621;
}

.status-badge.failed {
    background: #fed7d7;
    color: #c53030;
}

/* Button Styles */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.3s;
    font-size: 1rem;
}

.btn-primary {
    background: #667eea;
    color: white;
}

.btn-primary:hover {
    background: #5a67d8;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
    color: white;
    text-decoration: none;
}

.btn-outline {
    background: transparent;
    color: #667eea;
    border: 2px solid #667eea;
}

.btn-outline:hover {
    background: #667eea;
    color: white;
    text-decoration: none;
}

.btn-secondary {
    background: #4a5568;
    color: white;
}

.btn-secondary:hover {
    background: #2d3748;
    color: white;
    text-decoration: none;
}

.btn-sm {
    padding: 8px 16px;
    font-size: 0.9rem;
}

.btn-lg {
    padding: 15px 30px;
    font-size: 1.1rem;
}

/* Animations */
@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Responsive Design */
@media (max-width: 768px) {
    .balance-alert {
        grid-template-columns: 1fr;
        text-align: center;
        gap: 15px;
    }
    
    .alert-progress {
        min-width: auto;
        width: 100%;
    }
    
    .payment-content {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .bill-summary-card {
        position: static;
        order: 2;
    }
    
    .payment-form-card {
        order: 1;
    }
    
    .payment-progress {
        margin-bottom: 20px;
    }
    
    .progress-line {
        width: 40px;
        margin: 0 10px;
    }
    
    .payment-title h1 {
        font-size: 1.8rem;
    }
    
    .form-row {
        grid-template-columns: 1fr;
    }
    
    .payment-methods-grid {
        grid-template-columns: 1fr;
    }
    
    .providers-grid {
        grid-template-columns: repeat(3, 1fr);
        gap: 8px;
    }
    
    .provider-card {
        padding: 12px 8px;
    }
    
    .provider-logo {
        font-size: 1.5rem;
    }
    
    .provider-name {
        font-size: 0.8rem;
    }
    
    .payment-security,
    .support-contacts {
        flex-direction: column;
        gap: 15px;
    }
    
    .bill-summary-actions {
        flex-direction: column;
        gap: 8px;
    }
}

@media (max-width: 480px) {
    .container {
        padding: 0 15px;
    }
    
    .payment-form-card,
    .bill-summary-card {
        padding: 20px;
    }
    
    .progress-step span {
        display: none;
    }
    
    .payment-title h1 {
        font-size: 1.5rem;
    }
    
    .alert-amount {
        font-size: 2rem;
    }
}

/* Form Validation Styles */
.form-control.error {
    border-color: #f56565 !important;
    box-shadow: 0 0 0 3px rgba(245, 101, 101, 0.1) !important;
}

.btn-disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

/* Loading States */
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

<!-- PayStack Inline Script -->
<script src="https://js.paystack.co/v1/inline.js"></script>

<script>
// PayStack Configuration
const paystackConfig = {
    publicKey: '<?php echo $paystackPublicKey; ?>',
    testMode: <?php echo $paystackTestMode ? 'true' : 'false'; ?>
};

// Bill Data - ACCOUNT LEVEL
const billData = {
    billId: <?php echo $billId; ?>,
    billNumber: '<?php echo htmlspecialchars($billData['bill_number']); ?>',
    accountNumber: '<?php echo htmlspecialchars($billData['account_number']); ?>',
    accountName: '<?php echo htmlspecialchars($billData['account_name']); ?>',
    accountType: '<?php echo htmlspecialchars($billData['bill_type']); ?>',
    referenceId: <?php echo $billData['reference_id']; ?>,
    totalPayable: <?php echo $billData['account_total_payable']; ?>,
    remainingBalance: <?php echo $balanceInfo['remaining_balance']; ?>,
    totalPaid: <?php echo $balanceInfo['total_paid']; ?>
};

document.addEventListener('DOMContentLoaded', function() {
    // Initialize payment form
    initializePaymentForm();
    
    // Set up event listeners
    setupEventListeners();
    
    // Initialize progress bar animation
    initializeProgressBar();
    
    // Validate PayStack
    if (!paystackConfig.publicKey) {
        console.error('PayStack public key not configured');
    }
});

function initializePaymentForm() {
    // Initialize amount selection
    updatePaymentAmount();
    
    // Initialize payment method
    updatePaymentMethod();
    
    // Auto-format phone numbers
    formatPhoneNumbers();
    
    // Update remaining balance calculation
    updateRemainingBalance();
}

function initializeProgressBar() {
    setTimeout(() => {
        const progressFill = document.querySelector('.progress-fill');
        if (progressFill) {
            const width = progressFill.style.width;
            progressFill.style.width = '0%';
            setTimeout(() => {
                progressFill.style.width = width;
            }, 100);
        }
    }, 500);
}

function setupEventListeners() {
    // Amount selection
    document.querySelectorAll('.amount-option').forEach(option => {
        option.addEventListener('click', function() {
            selectAmountOption(this);
        });
    });
    
    // Payment method selection
    document.querySelectorAll('.payment-method-card').forEach(card => {
        card.addEventListener('click', function() {
            selectPaymentMethod(this);
        });
    });
    
    // Mobile money provider selection
    document.querySelectorAll('.provider-card').forEach(card => {
        card.addEventListener('click', function() {
            selectMobileProvider(this);
        });
    });
    
    // Custom amount input
    document.getElementById('customAmount').addEventListener('input', function() {
        updateCustomAmount();
    });
    
    // Form submission
    document.getElementById('paymentForm').addEventListener('submit', function(e) {
        e.preventDefault();
        processPayment();
    });
    
    // Form validation
    document.querySelectorAll('.form-control').forEach(input => {
        input.addEventListener('blur', validateField);
        input.addEventListener('input', updatePaymentButton);
    });
}

function selectAmountOption(option) {
    // Remove active class from all options
    document.querySelectorAll('.amount-option').forEach(opt => {
        opt.classList.remove('active');
        opt.querySelector('.option-radio').textContent = '○';
    });
    
    // Add active class to selected option
    option.classList.add('active');
    option.querySelector('.option-radio').textContent = '●';
    
    // Show/hide custom amount input
    const customInput = document.querySelector('.custom-amount-input');
    const isCustom = option.dataset.amount === 'custom';
    
    if (isCustom) {
        customInput.style.display = 'block';
        document.getElementById('customAmount').focus();
    } else {
        customInput.style.display = 'none';
        document.getElementById('selectedAmount').value = option.dataset.amount;
        updatePaymentAmount();
        updateRemainingBalance();
    }
}

function updateCustomAmount() {
    const customAmount = parseFloat(document.getElementById('customAmount').value) || 0;
    const maxAmount = billData.remainingBalance;
    
    if (customAmount > 0 && customAmount <= maxAmount) {
        document.getElementById('selectedAmount').value = customAmount;
        updatePaymentAmount();
        updateRemainingBalance();
    }
}

function updatePaymentAmount() {
    const amount = parseFloat(document.getElementById('selectedAmount').value) || 0;
    const finalAmountElement = document.getElementById('finalAmount');
    
    if (finalAmountElement) {
        finalAmountElement.textContent = `₵ ${amount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
    }
    
    updatePaymentButton();
}

function updateRemainingBalance() {
    const paymentAmount = parseFloat(document.getElementById('selectedAmount').value) || 0;
    const remainingAfter = Math.max(0, billData.remainingBalance - paymentAmount);
    const remainingElement = document.getElementById('remainingAfterPayment');
    
    if (remainingElement) {
        remainingElement.textContent = `₵ ${remainingAfter.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
        
        // Update color based on whether it will be fully paid
        if (remainingAfter <= 0) {
            remainingElement.style.color = '#38a169';
            remainingElement.parentElement.innerHTML = 'This will fully pay your account! ✅';
        } else {
            remainingElement.style.color = '#d69e2e';
            remainingElement.parentElement.innerHTML = `Remaining balance after payment: <span id="remainingAfterPayment">₵ ${remainingAfter.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>`;
        }
    }
}

function selectPaymentMethod(card) {
    // Remove active class from all cards
    document.querySelectorAll('.payment-method-card').forEach(c => {
        c.classList.remove('active');
        c.querySelector('.method-check').textContent = '○';
    });
    
    // Add active class to selected card
    card.classList.add('active');
    card.querySelector('.method-check').textContent = '✓';
    
    // Update selected method
    const method = card.dataset.method;
    document.getElementById('selectedPaymentMethod').value = method;
    
    // Show/hide mobile money providers
    const mobileProvidersSection = document.getElementById('mobileMoneyProviders');
    if (method === 'mobile_money') {
        mobileProvidersSection.style.display = 'block';
    } else {
        mobileProvidersSection.style.display = 'none';
    }
    
    updatePaymentMethod();
}

function selectMobileProvider(card) {
    // Remove active class from all provider cards
    document.querySelectorAll('.provider-card').forEach(c => {
        c.classList.remove('active');
    });
    
    // Add active class to selected provider
    card.classList.add('active');
    
    // Update selected provider
    const provider = card.dataset.provider;
    document.getElementById('selectedProvider').value = provider;
    
    updatePaymentMethod();
}

function updatePaymentMethod() {
    const method = document.getElementById('selectedPaymentMethod').value;
    const payButton = document.getElementById('payButton');
    
    if (method === 'mobile_money') {
        const provider = document.getElementById('selectedProvider').value;
        const providerNames = {
            'mtn': 'MTN MoMo',
            'vodafone': 'Vodafone Cash',
            'tigo': 'AirtelTigo Money'
        };
        payButton.innerHTML = `<i class="fas fa-mobile-alt"></i> Pay with ${providerNames[provider]}`;
    } else {
        payButton.innerHTML = '<i class="fas fa-credit-card"></i> Pay with Card';
    }
    
    updatePaymentButton();
}

function formatPhoneNumbers() {
    const phoneInputs = document.querySelectorAll('input[type="tel"]');
    phoneInputs.forEach(input => {
        input.addEventListener('input', function() {
            let value = this.value.replace(/\D/g, ''); // Remove non-digits
            
            // Format Ghana phone number
            if (value.startsWith('233')) {
                value = '+' + value;
            } else if (value.startsWith('0')) {
                value = value; // Keep as is for Ghana local format
            } else if (value.length > 0 && !value.startsWith('0') && !value.startsWith('233')) {
                value = '0' + value;
            }
            
            this.value = value;
        });
    });
}

function validateField(event) {
    const field = event.target;
    const value = field.value.trim();
    
    // Remove existing error styling
    field.classList.remove('error');
    
    // Validate based on field type
    switch (field.type) {
        case 'email':
            if (value && !isValidEmail(value)) {
                field.classList.add('error');
            }
            break;
        case 'tel':
            if (value && !isValidPhone(value)) {
                field.classList.add('error');
            }
            break;
        case 'number':
            const numValue = parseFloat(value);
            const max = parseFloat(field.max);
            const min = parseFloat(field.min);
            if (value && (numValue < min || numValue > max)) {
                field.classList.add('error');
            }
            break;
    }
}

function isValidEmail(email) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(email);
}

function isValidPhone(phone) {
    const phoneRegex = /^0[0-9]{9}$/;
    return phoneRegex.test(phone);
}

function updatePaymentButton() {
    const payButton = document.getElementById('payButton');
    const form = document.getElementById('paymentForm');
    const amount = parseFloat(document.getElementById('selectedAmount').value) || 0;
    
    // Check if form is valid and amount is within limits
    const isValid = form.checkValidity() && amount > 0 && amount <= billData.remainingBalance;
    
    payButton.disabled = !isValid;
    
    if (isValid) {
        payButton.classList.remove('btn-disabled');
    } else {
        payButton.classList.add('btn-disabled');
    }
}

// Enhanced payment processing with retry logic
async function processPaymentWithRetry(reference, billId, maxRetries = 3) {
    for (let attempt = 1; attempt <= maxRetries; attempt++) {
        try {
            console.log(`Processing payment attempt ${attempt}/${maxRetries}`);
            
            const response = await fetch(`${window.location.pathname}?process_paystack_payment=1&reference=${reference}&bill_id=${billId}`, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const result = await response.json();
            
            if (result.success) {
                console.log('Payment processed successfully:', result);
                return result;
            } else {
                console.error('Payment processing failed:', result.message);
                
                // If it's the last attempt, throw the error
                if (attempt === maxRetries) {
                    throw new Error(result.message || 'Payment processing failed');
                }
                
                // Wait before retry (exponential backoff)
                await new Promise(resolve => setTimeout(resolve, attempt * 2000));
            }
            
        } catch (error) {
            console.error(`Payment processing attempt ${attempt} failed:`, error);
            
            // If it's the last attempt, throw the error
            if (attempt === maxRetries) {
                throw error;
            }
            
            // Wait before retry
            await new Promise(resolve => setTimeout(resolve, attempt * 2000));
        }
    }
}

function processPayment() {
    const form = document.getElementById('paymentForm');
    const payButton = document.getElementById('payButton');
    
    // Validate form
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    if (typeof PaystackPop === 'undefined') {
        alert('Payment system not available. Please try again later.');
        return;
    }
    
    // Show loading state
    payButton.classList.add('btn-loading');
    payButton.disabled = true;
    
    // Get form data
    const paymentData = {
        amount: parseFloat(document.getElementById('selectedAmount').value),
        payerName: document.getElementById('payerName').value.trim(),
        payerEmail: document.getElementById('payerEmail').value.trim(),
        payerPhone: document.getElementById('payerPhone').value.trim(),
        paymentMethod: document.getElementById('selectedPaymentMethod').value,
        mobileProvider: document.getElementById('selectedProvider').value
    };
    
    // Validate amount doesn't exceed remaining balance
    if (paymentData.amount > billData.remainingBalance) {
        alert(`Payment amount cannot exceed outstanding balance of ₵${billData.remainingBalance.toFixed(2)}`);
        payButton.classList.remove('btn-loading');
        payButton.disabled = false;
        return;
    }
    
    // Generate payment reference
    const reference = 'PAY' + Date.now() + Math.random().toString(36).substr(2, 5).toUpperCase();
    
    // Prepare PayStack configuration
    const paystackSetup = {
        key: paystackConfig.publicKey,
        email: paymentData.payerEmail,
        amount: Math.round(paymentData.amount * 100), // Convert to kobo
        currency: 'GHS',
        ref: reference,
        metadata: {
            bill_id: billData.billId,
            bill_number: billData.billNumber,
            account_number: billData.accountNumber,
            account_type: billData.accountType,
            reference_id: billData.referenceId,
            payer_name: paymentData.payerName,
            payer_phone: paymentData.payerPhone,
            remaining_balance: billData.remainingBalance,
            payment_type: paymentData.amount >= billData.remainingBalance ? 'full' : 'partial',
            payment_method: paymentData.paymentMethod,
            mobile_provider: paymentData.mobileProvider
        },
        callback: function(response) {
            console.log('PayStack callback received:', response);
            
            // Show processing state
            payButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing Payment...';
            
            // Process payment with better error handling and retry logic
            processPaymentWithRetry(response.reference, billData.billId, 3)
                .then((result) => {
                    console.log('Payment processed successfully:', result);
                    // Only redirect on successful processing
                    window.location.href = `payment_success.php?reference=${response.reference}&bill_id=${billData.billId}`;
                })
                .catch((error) => {
                    console.error('Payment processing failed:', error);
                    
                    // Show error message but still redirect to let success page handle it
                    alert('Payment completed but processing is delayed. You will be redirected to check the status.');
                    
                    // Redirect to success page which will show processing state
                    window.location.href = `payment_success.php?reference=${response.reference}&bill_id=${billData.billId}`;
                });
        },
        onClose: function() {
            console.log('Payment cancelled by user');
            payButton.classList.remove('btn-loading');
            payButton.disabled = false;
            
            // Reset button text based on payment method
            const method = document.getElementById('selectedPaymentMethod').value;
            if (method === 'mobile_money') {
                const provider = document.getElementById('selectedProvider').value;
                const providerNames = {
                    'mtn': 'MTN MoMo',
                    'vodafone': 'Vodafone Cash',
                    'tigo': 'AirtelTigo Money'
                };
                payButton.innerHTML = `<i class="fas fa-mobile-alt"></i> Pay with ${providerNames[provider]}`;
            } else {
                payButton.innerHTML = '<i class="fas fa-credit-card"></i> Pay with Card';
            }
        }
    };
    
    // Add mobile money specific configurations
    if (paymentData.paymentMethod === 'mobile_money') {
        paystackSetup.channels = ['mobile_money'];
        
        // Map providers to PayStack mobile money channels
        const providerChannels = {
            'mtn': 'mtn',
            'vodafone': 'vod',
            'tigo': 'tgo'
        };
        
        paystackSetup.mobile_money = {
            phone: paymentData.payerPhone,
            provider: providerChannels[paymentData.mobileProvider] || 'mtn'
        };
    } else {
        // For card payments
        paystackSetup.channels = ['card'];
    }
    
    const handler = PaystackPop.setup(paystackSetup);
    handler.openIframe();
}

function togglePaymentHistory() {
    const historySection = document.getElementById('paymentHistoryMini');
    if (historySection.style.display === 'none') {
        historySection.style.display = 'block';
    } else {
        historySection.style.display = 'none';
    }
}

function showTerms() {
    alert('Terms and Conditions\n\n• Payment processing fees may apply\n• All payments are final and non-refundable\n• You must provide accurate payment information\n• Disputes must be reported within 7 days\n• We use secure encryption for all transactions\n• Partial payments will reduce your outstanding balance');
}

function showPrivacy() {
    alert('Privacy Policy\n\n• Your personal information is protected\n• Payment data is encrypted and secure\n• We do not share your information with third parties\n• Transaction records are kept for audit purposes\n• Contact us for data protection inquiries');
}

// Initialize balance tracking - ACCOUNT LEVEL
console.log('💳 Payment Page Initialized (Account Level):', {
    billNumber: billData.billNumber,
    accountNumber: billData.accountNumber,
    accountType: billData.accountType,
    accountTotalPayable: billData.totalPayable,
    totalPaid: billData.totalPaid,
    remainingBalance: billData.remainingBalance
});
</script>

<?php includeFooter(); ?>