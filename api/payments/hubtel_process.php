<?php
/**
 * Hubtel Payment Processing API
 * Handles mobile money payment requests through Hubtel
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

    // Get input data
    $input = file_get_contents('php://input');
    $paymentData = json_decode($input, true);

    // Validate input data
    if (!$paymentData) {
        throw new Exception('Invalid payment data received');
    }

    // Required fields validation
    $requiredFields = [
        'amount', 'payerName', 'payerEmail', 'payerPhone', 
        'billId', 'billNumber', 'accountNumber', 
        'hubtelProvider', 'hubtelNumber'
    ];

    foreach ($requiredFields as $field) {
        if (!isset($paymentData[$field]) || empty($paymentData[$field])) {
            throw new Exception("Missing required field: {$field}");
        }
    }

    // Get Hubtel configuration
    $hubtelConfig = getHubtelConfig();
    if (!$hubtelConfig || !$hubtelConfig['enabled']) {
        throw new Exception('Hubtel payment gateway is not available');
    }

    // Initialize database
    $db = new Database();

    // Verify bill exists and is payable
    $billQuery = "SELECT * FROM bills WHERE bill_id = ? AND amount_payable > 0";
    $billData = $db->fetchRow($billQuery, [$paymentData['billId']]);

    if (!$billData) {
        throw new Exception('Bill not found or already paid');
    }

    // Validate payment amount
    $paymentAmount = (float)$paymentData['amount'];
    if ($paymentAmount <= 0 || $paymentAmount > $billData['amount_payable']) {
        throw new Exception('Invalid payment amount');
    }

    // Generate unique payment reference
    $paymentReference = generatePaymentReference();
    
    // Prepare Hubtel payment data
    $hubtelPaymentData = [
        'CustomerName' => $paymentData['payerName'],
        'CustomerEmail' => $paymentData['payerEmail'],
        'CustomerMsisdn' => formatPhoneForHubtel($paymentData['hubtelNumber']),
        'Channel' => $paymentData['hubtelProvider'],
        'Amount' => $paymentAmount,
        'PrimaryCallbackUrl' => getCallbackUrl('hubtel'),
        'Description' => "Payment for bill: {$paymentData['billNumber']}",
        'ClientReference' => $paymentReference,
        'ExtraData' => json_encode([
            'bill_id' => $paymentData['billId'],
            'bill_number' => $paymentData['billNumber'],
            'account_number' => $paymentData['accountNumber'],
            'payer_email' => $paymentData['payerEmail']
        ])
    ];

    // Make request to Hubtel API
    $hubtelResponse = makeHubtelPaymentRequest($hubtelConfig, $hubtelPaymentData);

    if (!$hubtelResponse['success']) {
        throw new Exception($hubtelResponse['message'] ?? 'Payment request failed');
    }

    // Create payment record in database
    $paymentId = createPaymentRecord($db, [
        'payment_reference' => $paymentReference,
        'bill_id' => $paymentData['billId'],
        'amount_paid' => $paymentAmount,
        'payment_method' => 'Mobile Money',
        'payment_channel' => getHubtelChannelName($paymentData['hubtelProvider']),
        'transaction_id' => $hubtelResponse['transaction_id'] ?? null,
        'payment_status' => 'Pending',
        'payer_name' => $paymentData['payerName'],
        'payer_email' => $paymentData['payerEmail'],
        'payer_phone' => $paymentData['payerPhone']
    ]);

    // Log the payment attempt
    writeLog("Hubtel payment initiated: {$paymentReference} for bill {$paymentData['billNumber']}", 'INFO');

    // Prepare response based on Hubtel response
    $response = [
        'success' => true,
        'reference' => $paymentReference,
        'transaction_id' => $hubtelResponse['transaction_id'] ?? null,
        'message' => 'Payment request sent successfully'
    ];

    // Check if payment requires user confirmation (typical for mobile money)
    if (isset($hubtelResponse['requires_confirmation']) && $hubtelResponse['requires_confirmation']) {
        $response['requires_confirmation'] = true;
        $response['message'] = "A payment request has been sent to {$paymentData['hubtelNumber']}. Please check your phone and enter your PIN to complete the payment.";
    }

    echo json_encode($response);

} catch (Exception $e) {
    // Log error
    writeLog("Hubtel payment error: " . $e->getMessage(), 'ERROR');
    
    // Return error response
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Generate unique payment reference
 */
function generatePaymentReference() {
    return 'PAY' . date('YmdHis') . strtoupper(substr(uniqid(), -5));
}

/**
 * Format phone number for Hubtel API
 */
function formatPhoneForHubtel($phone) {
    // Remove all non-numeric characters
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    // Convert to international format for Ghana
    if (substr($phone, 0, 1) === '0') {
        $phone = '233' . substr($phone, 1);
    } elseif (substr($phone, 0, 3) !== '233') {
        $phone = '233' . $phone;
    }
    
    return $phone;
}

/**
 * Validate Ghana phone number
 */
function isValidGhanaPhone($phone) {
    $phone = formatPhoneForHubtel($phone);
    return preg_match('/^233[0-9]{9}$/', $phone);
}

/**
 * Validate email address
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Sanitize payment amount
 */
function sanitizeAmount($amount) {
    $amount = (float)$amount;
    return max(0, round($amount, 2));
}

/**
 * Validate payment data
 */
function validatePaymentData($data) {
    $errors = [];
    
    // Required fields
    $required = ['amount', 'payerName', 'payerEmail', 'payerPhone', 'billId', 'hubtelProvider', 'hubtelNumber'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            $errors[] = "Missing required field: {$field}";
        }
    }
    
    // Validate amount
    if (isset($data['amount'])) {
        $amount = (float)$data['amount'];
        if ($amount <= 0) {
            $errors[] = 'Payment amount must be greater than zero';
        }
        if ($amount > 999999.99) {
            $errors[] = 'Payment amount is too large';
        }
    }
    
    // Validate email
    if (isset($data['payerEmail']) && !isValidEmail($data['payerEmail'])) {
        $errors[] = 'Invalid email address';
    }
    
    // Validate phone
    if (isset($data['hubtelNumber']) && !isValidGhanaPhone($data['hubtelNumber'])) {
        $errors[] = 'Invalid Ghana phone number';
    }
    
    return $errors;
}

/**
 * Get callback URL for payment notifications
 */
function getCallbackUrl($provider = 'hubtel') {
    $baseUrl = rtrim(BASE_URL, '/');
    return "{$baseUrl}/api/payments/callback.php?provider={$provider}";
}

/**
 * Get human-readable channel name
 */
function getHubtelChannelName($channel) {
    $channels = [
        'mtn-gh' => 'MTN Mobile Money',
        'tgo-gh' => 'Telecel Cash',
        'airtel-gh' => 'AirtelTigo Money'
    ];
    
    return $channels[$channel] ?? 'Mobile Money';
}

/**
 * Make payment request to Hubtel API
 */
function makeHubtelPaymentRequest($config, $paymentData) {
    $url = $config['base_url'] . '/v1/merchantaccount/merchants/' . $config['api_id'] . '/receive/mobilemoney';
    
    $headers = [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode($config['api_id'] . ':' . $config['api_key'])
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($paymentData),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        writeLog("Hubtel cURL error: {$error}", 'ERROR');
        return [
            'success' => false,
            'message' => 'Payment service temporarily unavailable'
        ];
    }
    
    $responseData = json_decode($response, true);
    
    if ($httpCode !== 200) {
        $errorMessage = $responseData['Message'] ?? 'Payment request failed';
        writeLog("Hubtel API error: HTTP {$httpCode} - {$errorMessage}", 'ERROR');
        return [
            'success' => false,
            'message' => $errorMessage
        ];
    }
    
    // Check response status
    if (!isset($responseData['ResponseCode']) || $responseData['ResponseCode'] !== '0000') {
        $errorMessage = $responseData['Data']['Description'] ?? $responseData['Message'] ?? 'Payment request failed';
        return [
            'success' => false,
            'message' => $errorMessage
        ];
    }
    
    return [
        'success' => true,
        'transaction_id' => $responseData['Data']['TransactionId'] ?? null,
        'requires_confirmation' => true, // Mobile money typically requires user confirmation
        'message' => 'Payment request sent successfully'
    ];
}

/**
 * Create payment record in database
 */
function createPaymentRecord($db, $data) {
    $sql = "
        INSERT INTO payments (
            payment_reference, bill_id, amount_paid, payment_method, 
            payment_channel, transaction_id, payment_status, payment_date,
            notes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)
    ";
    
    $notes = json_encode([
        'payer_name' => $data['payer_name'],
        'payer_email' => $data['payer_email'],
        'payer_phone' => $data['payer_phone'],
        'payment_gateway' => 'Hubtel'
    ]);
    
    $params = [
        $data['payment_reference'],
        $data['bill_id'],
        $data['amount_paid'],
        $data['payment_method'],
        $data['payment_channel'],
        $data['transaction_id'],
        $data['payment_status'],
        $notes
    ];
    
    return $db->execute($sql, $params);
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