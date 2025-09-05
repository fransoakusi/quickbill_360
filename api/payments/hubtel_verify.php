<?php
/**
 * Hubtel Payment Verification API
 * Verifies the status of Hubtel mobile money payments
 */

// Define application constant
define('QUICKBILL_305', true);

// Set proper headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ]);
    exit();
}

try {
    // Include required files
    require_once '../../config/config.php';
    require_once '../../config/database.php';
    require_once '../../includes/functions.php';
    require_once '../../classes/Payment.php';

    // Get input data
    $input = file_get_contents('php://input');
    $verifyData = json_decode($input, true);

    // Validate input data
    if (!$verifyData) {
        throw new Exception('Invalid verification data received');
    }

    // Required fields validation
    if (!isset($verifyData['reference']) || empty($verifyData['reference'])) {
        throw new Exception('Payment reference is required');
    }

    if (!isset($verifyData['bill_id']) || empty($verifyData['bill_id'])) {
        throw new Exception('Bill ID is required');
    }

    $paymentReference = $verifyData['reference'];
    $billId = (int)$verifyData['bill_id'];

    // Get Hubtel configuration
    $hubtelConfig = getHubtelConfig();
    if (!$hubtelConfig || !$hubtelConfig['enabled']) {
        throw new Exception('Hubtel payment gateway is not available');
    }

    // Initialize database
    $db = new Database();

    // Get payment record from database
    $paymentQuery = "
        SELECT p.*, b.bill_number, b.amount_payable, b.bill_type, b.reference_id
        FROM payments p
        JOIN bills b ON p.bill_id = b.bill_id
        WHERE p.payment_reference = ? AND p.bill_id = ?
    ";
    
    $paymentData = $db->fetchRow($paymentQuery, [$paymentReference, $billId]);

    if (!$paymentData) {
        throw new Exception('Payment record not found');
    }

    // If payment is already successful, return success
    if ($paymentData['payment_status'] === 'Successful') {
        echo json_encode([
            'success' => true,
            'status' => 'completed',
            'message' => 'Payment completed successfully',
            'reference' => $paymentReference,
            'amount' => number_format($paymentData['amount_paid'], 2),
            'transaction_id' => $paymentData['transaction_id']
        ]);
        exit();
    }

    // If payment has failed, return failure
    if ($paymentData['payment_status'] === 'Failed') {
        echo json_encode([
            'success' => false,
            'status' => 'failed',
            'message' => 'Payment has already failed',
            'reference' => $paymentReference
        ]);
        exit();
    }

    // Verify payment status with Hubtel
    $hubtelStatus = verifyHubtelPayment($hubtelConfig, $paymentData['transaction_id'] ?: $paymentReference);

    if (!$hubtelStatus['success']) {
        throw new Exception($hubtelStatus['message'] ?? 'Payment verification failed');
    }

    $verificationResult = $hubtelStatus['data'];
    
    // Update payment status based on verification result
    $newStatus = mapHubtelStatusToLocal($verificationResult['status']);
    $updateSuccess = false;

    if ($newStatus === 'Successful') {
        // Payment successful - update payment and bill
        $updateSuccess = processSuccessfulPayment($db, $paymentData, $verificationResult);
        
        if ($updateSuccess) {
            // Send SMS notification
            sendPaymentNotification($paymentData, 'success');
            
            // Log successful payment
            writeLog("Hubtel payment verified successfully: {$paymentReference} - Amount: {$paymentData['amount_paid']}", 'INFO');
            
            echo json_encode([
                'success' => true,
                'status' => 'completed',
                'message' => 'Payment completed successfully',
                'reference' => $paymentReference,
                'amount' => number_format($paymentData['amount_paid'], 2),
                'transaction_id' => $verificationResult['transaction_id'] ?? $paymentData['transaction_id']
            ]);
        } else {
            throw new Exception('Failed to update payment status');
        }
        
    } elseif ($newStatus === 'Failed') {
        // Payment failed
        updatePaymentStatus($db, $paymentData['payment_id'], 'Failed', $verificationResult['message'] ?? 'Payment failed');
        
        writeLog("Hubtel payment failed: {$paymentReference} - Reason: " . ($verificationResult['message'] ?? 'Unknown'), 'WARNING');
        
        echo json_encode([
            'success' => false,
            'status' => 'failed',
            'message' => $verificationResult['message'] ?? 'Payment failed',
            'reference' => $paymentReference
        ]);
        
    } else {
        // Payment still pending
        writeLog("Hubtel payment still pending: {$paymentReference}", 'INFO');
        
        echo json_encode([
            'success' => false,
            'status' => 'pending',
            'message' => 'Payment is still being processed. Please try again in a few moments.',
            'reference' => $paymentReference,
            'retry' => true
        ]);
    }

} catch (Exception $e) {
    // Log error
    writeLog("Hubtel payment verification error: " . $e->getMessage(), 'ERROR');
    
    // Return error response
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Verify payment status with Hubtel API
 */
function verifyHubtelPayment($config, $transactionRef) {
    // Hubtel transaction status endpoint
    $url = $config['base_url'] . '/v1/merchantaccount/merchants/' . $config['api_id'] . '/transactions/status/' . $transactionRef;
    
    $headers = [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode($config['api_id'] . ':' . $config['api_key'])
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        writeLog("Hubtel verification cURL error: {$error}", 'ERROR');
        return [
            'success' => false,
            'message' => 'Verification service temporarily unavailable'
        ];
    }
    
    $responseData = json_decode($response, true);
    
    if ($httpCode !== 200) {
        $errorMessage = $responseData['Message'] ?? 'Verification failed';
        writeLog("Hubtel verification API error: HTTP {$httpCode} - {$errorMessage}", 'ERROR');
        return [
            'success' => false,
            'message' => $errorMessage
        ];
    }
    
    // Check if we got valid response data
    if (!isset($responseData['Data'])) {
        return [
            'success' => false,
            'message' => 'Invalid verification response'
        ];
    }
    
    $transactionData = $responseData['Data'];
    
    return [
        'success' => true,
        'data' => [
            'status' => $transactionData['TransactionStatus'] ?? 'Unknown',
            'transaction_id' => $transactionData['TransactionId'] ?? null,
            'amount' => $transactionData['Amount'] ?? 0,
            'message' => $transactionData['Description'] ?? '',
            'external_transaction_id' => $transactionData['ExternalTransactionId'] ?? null
        ]
    ];
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
                transaction_id = ?,
                notes = JSON_SET(COALESCE(notes, '{}'), '$.verification_data', ?)
            WHERE payment_id = ?
        ";
        
        $verificationNotes = json_encode([
            'verified_at' => date('Y-m-d H:i:s'),
            'hubtel_transaction_id' => $verificationResult['transaction_id'],
            'external_transaction_id' => $verificationResult['external_transaction_id']
        ]);
        
        $db->execute($updatePaymentSql, [
            $verificationResult['transaction_id'] ?? $paymentData['transaction_id'],
            $verificationNotes,
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
        writeLog("Error processing successful payment: " . $e->getMessage(), 'ERROR');
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
            notes = JSON_SET(COALESCE(notes, '{}'), '$.verification_notes', ?)
        WHERE payment_id = ?
    ";
    
    $verificationNotes = json_encode([
        'verified_at' => date('Y-m-d H:i:s'),
        'status_reason' => $notes
    ]);
    
    return $db->execute($sql, [$status, $verificationNotes, $paymentId]);
}

/**
 * Send payment notification
 */
function sendPaymentNotification($paymentData, $type = 'success') {
    try {
        // Extract payer info from notes
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
        
        // Send SMS (implement SMS sending logic here)
        // This would typically use Twilio or another SMS service
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
    // This is a placeholder - implement actual SMS sending
    // You would integrate with Twilio, Hubtel SMS, or another provider
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
    
    // Create directory if it doesn't exist
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}
?>