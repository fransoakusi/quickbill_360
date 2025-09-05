<?php
/**
 * Payment Callback Handler
 * Handles webhook notifications from payment providers (Hubtel, PayStack)
 */

// Define application constant
define('QUICKBILL_305', true);

// Set proper headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Paystack-Signature');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    // Include required files
    require_once '../../config/config.php';
    require_once '../../config/database.php';
    require_once '../../includes/functions.php';

    // Get provider from query parameter
    $provider = $_GET['provider'] ?? 'unknown';
    
    // Get webhook data
    $input = file_get_contents('php://input');
    $webhookData = json_decode($input, true);
    
    // Log webhook received
    writeLog("Webhook received from {$provider}: " . substr($input, 0, 500), 'INFO');
    
    // Route to appropriate handler based on provider
    switch ($provider) {
        case 'hubtel':
            handleHubtelCallback($webhookData, $_SERVER);
            break;
        case 'paystack':
            handlePaystackCallback($webhookData, $_SERVER);
            break;
        default:
            writeLog("Unknown payment provider: {$provider}", 'WARNING');
            http_response_code(400);
            echo json_encode(['error' => 'Unknown provider']);
            exit();
    }

} catch (Exception $e) {
    writeLog("Callback error: " . $e->getMessage(), 'ERROR');
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}

/**
 * Handle Hubtel payment callback
 */
function handleHubtelCallback($data, $headers) {
    try {
        // Validate Hubtel webhook data
        if (!$data || !isset($data['Data'])) {
            throw new Exception('Invalid Hubtel webhook data');
        }
        
        $transactionData = $data['Data'];
        
        // Extract key information
        $clientReference = $transactionData['ClientReference'] ?? null;
        $transactionId = $transactionData['TransactionId'] ?? null;
        $status = $transactionData['TransactionStatus'] ?? 'Unknown';
        $amount = $transactionData['Amount'] ?? 0;
        $description = $transactionData['Description'] ?? '';
        
        if (!$clientReference) {
            throw new Exception('Missing client reference in Hubtel webhook');
        }
        
        // Get Hubtel configuration for verification
        $hubtelConfig = getHubtelConfig();
        if (!$hubtelConfig || !$hubtelConfig['enabled']) {
            throw new Exception('Hubtel configuration not available');
        }
        
        // Initialize database
        $db = new Database();
        
        // Find payment record
        $paymentQuery = "
            SELECT p.*, b.bill_number, b.amount_payable, b.bill_type, b.reference_id
            FROM payments p
            JOIN bills b ON p.bill_id = b.bill_id
            WHERE p.payment_reference = ?
        ";
        
        $paymentData = $db->fetchRow($paymentQuery, [$clientReference]);
        
        if (!$paymentData) {
            writeLog("Hubtel callback: Payment not found for reference {$clientReference}", 'WARNING');
            // Still return success to prevent retries
            echo json_encode(['status' => 'success', 'message' => 'Payment not found']);
            return;
        }
        
        // Skip if payment is already processed
        if ($paymentData['payment_status'] === 'Successful') {
            writeLog("Hubtel callback: Payment already processed for {$clientReference}", 'INFO');
            echo json_encode(['status' => 'success', 'message' => 'Already processed']);
            return;
        }
        
        // Map Hubtel status to local status
        $newStatus = mapHubtelStatusToLocal($status);
        
        if ($newStatus === 'Successful') {
            // Process successful payment
            $success = processSuccessfulPayment($db, $paymentData, [
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'status' => $status,
                'description' => $description
            ]);
            
            if ($success) {
                // Send success notification
                sendPaymentNotification($paymentData, 'success');
                writeLog("Hubtel callback: Payment processed successfully for {$clientReference}", 'INFO');
            } else {
                throw new Exception('Failed to process successful payment');
            }
            
        } elseif ($newStatus === 'Failed') {
            // Update payment as failed
            updatePaymentStatus($db, $paymentData['payment_id'], 'Failed', $description);
            
            // Send failure notification
            sendPaymentNotification($paymentData, 'failure');
            writeLog("Hubtel callback: Payment failed for {$clientReference} - {$description}", 'WARNING');
            
        } else {
            // Update status but don't process yet
            updatePaymentStatus($db, $paymentData['payment_id'], $newStatus, $description);
            writeLog("Hubtel callback: Payment status updated to {$newStatus} for {$clientReference}", 'INFO');
        }
        
        // Respond with success
        echo json_encode(['status' => 'success', 'message' => 'Callback processed']);
        
    } catch (Exception $e) {
        writeLog("Hubtel callback error: " . $e->getMessage(), 'ERROR');
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

/**
 * Handle PayStack payment callback
 */
function handlePaystackCallback($data, $headers) {
    try {
        // Verify PayStack signature
        $paystackConfig = getConfig('paystack');
        $secretKey = $paystackConfig['secret_key'] ?? '';
        
        if (!$secretKey) {
            throw new Exception('PayStack configuration not available');
        }
        
        $signature = $headers['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
        $computedSignature = hash_hmac('sha512', file_get_contents('php://input'), $secretKey);
        
        if ($signature !== $computedSignature) {
            throw new Exception('Invalid PayStack signature');
        }
        
        // Process PayStack webhook
        $event = $data['event'] ?? '';
        $eventData = $data['data'] ?? [];
        
        if ($event === 'charge.success') {
            processPaystackSuccess($eventData);
        } elseif ($event === 'charge.failed') {
            processPaystackFailure($eventData);
        }
        
        echo json_encode(['status' => 'success']);
        
    } catch (Exception $e) {
        writeLog("PayStack callback error: " . $e->getMessage(), 'ERROR');
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

/**
 * Process PayStack successful payment
 */
function processPaystackSuccess($data) {
    $reference = $data['reference'] ?? null;
    
    if (!$reference) {
        throw new Exception('Missing reference in PayStack callback');
    }
    
    $db = new Database();
    
    // Find payment by reference
    $paymentQuery = "
        SELECT p.*, b.bill_number, b.amount_payable, b.bill_type, b.reference_id
        FROM payments p
        JOIN bills b ON p.bill_id = b.bill_id
        WHERE p.payment_reference = ? OR p.transaction_id = ?
    ";
    
    $paymentData = $db->fetchRow($paymentQuery, [$reference, $reference]);
    
    if ($paymentData && $paymentData['payment_status'] !== 'Successful') {
        processSuccessfulPayment($db, $paymentData, [
            'transaction_id' => $reference,
            'amount' => $data['amount'] / 100, // Convert from kobo
            'status' => 'Success'
        ]);
        
        sendPaymentNotification($paymentData, 'success');
    }
}

/**
 * Process PayStack failed payment
 */
function processPaystackFailure($data) {
    $reference = $data['reference'] ?? null;
    
    if (!$reference) {
        return;
    }
    
    $db = new Database();
    
    $paymentQuery = "SELECT payment_id FROM payments WHERE payment_reference = ? OR transaction_id = ?";
    $payment = $db->fetchRow($paymentQuery, [$reference, $reference]);
    
    if ($payment) {
        updatePaymentStatus($db, $payment['payment_id'], 'Failed', 'Payment failed via PayStack');
    }
}

/**
 * Map Hubtel status to local payment status
 */
function mapHubtelStatusToLocal($hubtelStatus) {
    $statusMap = [
        'Success' => 'Successful',
        'Successful' => 'Successful',
        'Failed' => 'Failed',
        'Fail' => 'Failed',
        'Expired' => 'Failed',
        'Cancelled' => 'Failed',
        'Pending' => 'Pending',
        'Processing' => 'Pending'
    ];
    
    return $statusMap[$hubtelStatus] ?? 'Pending';
}

/**
 * Process successful payment
 */
function processSuccessfulPayment($db, $paymentData, $verificationResult) {
    try {
        $db->beginTransaction();
        
        // Update payment status
        $updatePaymentSql = "
            UPDATE payments 
            SET payment_status = 'Successful',
                transaction_id = COALESCE(?, transaction_id),
                notes = JSON_SET(COALESCE(notes, '{}'), '$.callback_data', ?)
            WHERE payment_id = ?
        ";
        
        $callbackNotes = json_encode([
            'processed_at' => date('Y-m-d H:i:s'),
            'callback_transaction_id' => $verificationResult['transaction_id'],
            'callback_amount' => $verificationResult['amount'],
            'callback_status' => $verificationResult['status']
        ]);
        
        $db->execute($updatePaymentSql, [
            $verificationResult['transaction_id'] ?? null,
            $callbackNotes,
            $paymentData['payment_id']
        ]);
        
        // Update bill status
        $newAmountPayable = max(0, $paymentData['amount_payable'] - $paymentData['amount_paid']);
        $newBillStatus = $newAmountPayable <= 0 ? 'Paid' : 'Partially Paid';
        
        $updateBillSql = "
            UPDATE bills 
            SET amount_payable = ?,
                status = ?
            WHERE bill_id = ?
        ";
        
        $db->execute($updateBillSql, [
            $newAmountPayable,
            $newBillStatus,
            $paymentData['bill_id']
        ]);
        
        // Update business or property record
        if ($paymentData['bill_type'] === 'Business') {
            $updateBusinessSql = "
                UPDATE businesses 
                SET amount_payable = GREATEST(0, amount_payable - ?),
                    previous_payments = previous_payments + ?
                WHERE business_id = ?
            ";
            $db->execute($updateBusinessSql, [
                $paymentData['amount_paid'],
                $paymentData['amount_paid'],
                $paymentData['reference_id']
            ]);
        } else {
            $updatePropertySql = "
                UPDATE properties 
                SET amount_payable = GREATEST(0, amount_payable - ?),
                    previous_payments = previous_payments + ?
                WHERE property_id = ?
            ";
            $db->execute($updatePropertySql, [
                $paymentData['amount_paid'],
                $paymentData['amount_paid'],
                $paymentData['reference_id']
            ]);
        }
        
        $db->commit();
        return true;
        
    } catch (Exception $e) {
        $db->rollback();
        writeLog("Error processing successful payment callback: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

/**
 * Update payment status
 */
function updatePaymentStatus($db, $paymentId, $status, $notes = null) {
    $sql = "
        UPDATE payments 
        SET payment_status = ?,
            notes = JSON_SET(COALESCE(notes, '{}'), '$.callback_notes', ?)
        WHERE payment_id = ?
    ";
    
    $callbackNotes = json_encode([
        'updated_at' => date('Y-m-d H:i:s'),
        'status_reason' => $notes
    ]);
    
    return $db->execute($sql, [$status, $callbackNotes, $paymentId]);
}

/**
 * Send payment notification
 */
function sendPaymentNotification($paymentData, $type = 'success') {
    try {
        $notes = json_decode($paymentData['notes'], true);
        $payerPhone = $notes['payer_phone'] ?? null;
        
        if (!$payerPhone) {
            return false;
        }
        
        if ($type === 'success') {
            $message = "Payment successful! Amount: GHS " . number_format($paymentData['amount_paid'], 2) . 
                      " for bill " . $paymentData['bill_number'] . 
                      ". Reference: " . $paymentData['payment_reference'];
        } else {
            $message = "Payment failed for bill " . $paymentData['bill_number'] . 
                      ". Reference: " . $paymentData['payment_reference'] . 
                      ". Please try again or contact support.";
        }
        
        return sendSMS($payerPhone, $message);
        
    } catch (Exception $e) {
        writeLog("Error sending payment notification: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

/**
 * Send SMS notification
 */
function sendSMS($phone, $message) {
    writeLog("SMS notification sent to {$phone}: {$message}", 'INFO');
    return true;
}

/**
 * Write log entry
 */
function writeLog($message, $level = 'INFO') {
    $logFile = STORAGE_PATH . '/logs/payment.log';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
    
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}
?>