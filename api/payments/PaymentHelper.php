<?php
/**
 * Payment Helper Class
 * Shared utilities for payment processing across different gateways
 */

class PaymentHelper {
    
    /**
     * Generate unique payment reference
     */
    public static function generatePaymentReference($prefix = 'PAY') {
        return $prefix . date('YmdHis') . strtoupper(substr(uniqid(), -5));
    }
    
    /**
     * Format phone number for Ghana mobile money
     */
    public static function formatPhoneNumber($phone, $country = 'GH') {
        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        if ($country === 'GH') {
            // Ghana phone number formatting
            if (substr($phone, 0, 1) === '0') {
                $phone = '233' . substr($phone, 1);
            } elseif (substr($phone, 0, 3) !== '233') {
                $phone = '233' . $phone;
            }
        }
        
        return $phone;
    }
    
    /**
     * Validate Ghana phone number
     */
    public static function isValidGhanaPhone($phone) {
        $phone = self::formatPhoneNumber($phone);
        return preg_match('/^233[0-9]{9}$/', $phone);
    }
    
    /**
     * Validate email address
     */
    public static function isValidEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * Sanitize payment amount
     */
    public static function sanitizeAmount($amount) {
        $amount = (float)$amount;
        return max(0, round($amount, 2));
    }
    
    /**
     * Format currency for display
     */
    public static function formatCurrency($amount, $currency = 'GHS') {
        $symbols = [
            'GHS' => '₵',
            'USD' => '$',
            'EUR' => '€'
        ];
        
        $symbol = $symbols[$currency] ?? $currency;
        return $symbol . ' ' . number_format($amount, 2);
    }
    
    /**
     * Get payment method display name
     */
    public static function getPaymentMethodName($method) {
        $methods = [
            'Mobile Money' => 'Mobile Money',
            'card' => 'Credit/Debit Card',
            'bank_transfer' => 'Bank Transfer',
            'cash' => 'Cash',
            'hubtel' => 'Hubtel Mobile Money',
            'paystack' => 'PayStack'
        ];
        
        return $methods[$method] ?? ucfirst($method);
    }
    
    /**
     * Get mobile money provider display name
     */
    public static function getMomoProviderName($provider) {
        $providers = [
            'mtn-gh' => 'MTN Mobile Money',
            'tgo-gh' => 'Telecel Cash',
            'airtel-gh' => 'AirtelTigo Money',
            'MTN' => 'MTN Mobile Money',
            'Telecel' => 'Telecel Cash',
            'AirtelTigo' => 'AirtelTigo Money'
        ];
        
        return $providers[$provider] ?? $provider;
    }
    
    /**
     * Generate QR code data for payment
     */
    public static function generateQRData($paymentData) {
        return json_encode([
            'type' => 'payment',
            'bill_id' => $paymentData['bill_id'] ?? null,
            'bill_number' => $paymentData['bill_number'] ?? null,
            'amount' => $paymentData['amount'] ?? 0,
            'account_number' => $paymentData['account_number'] ?? null,
            'generated_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Validate payment data
     */
    public static function validatePaymentData($data) {
        $errors = [];
        
        // Required fields
        $required = ['amount', 'payerName', 'payerEmail', 'payerPhone', 'billId'];
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
        if (isset($data['payerEmail']) && !self::isValidEmail($data['payerEmail'])) {
            $errors[] = 'Invalid email address';
        }
        
        // Validate phone
        if (isset($data['payerPhone']) && !self::isValidGhanaPhone($data['payerPhone'])) {
            $errors[] = 'Invalid Ghana phone number';
        }
        
        return $errors;
    }
    
    /**
     * Log payment activity
     */
    public static function logPaymentActivity($message, $level = 'INFO', $context = []) {
        $logFile = STORAGE_PATH . '/logs/payment.log';
        $timestamp = date('Y-m-d H:i:s');
        
        $contextStr = $context ? ' ' . json_encode($context) : '';
        $logEntry = "[{$timestamp}] [{$level}] {$message}{$contextStr}" . PHP_EOL;
        
        // Create directory if it doesn't exist
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Send SMS notification
     */
    public static function sendSMSNotification($phone, $message, $provider = 'twilio') {
        try {
            $phone = self::formatPhoneNumber($phone);
            
            if (!self::isValidGhanaPhone($phone)) {
                self::logPaymentActivity("Invalid phone number for SMS: {$phone}", 'WARNING');
                return false;
            }
            
            // Here you would integrate with your preferred SMS provider
            // For now, we'll just log it
            self::logPaymentActivity("SMS sent to {$phone}: {$message}", 'INFO');
            
            return true;
            
        } catch (Exception $e) {
            self::logPaymentActivity("SMS sending failed: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    /**
     * Get payment status color for UI
     */
    public static function getPaymentStatusColor($status) {
        $colors = [
            'Pending' => 'warning',
            'Successful' => 'success',
            'Failed' => 'danger',
            'Cancelled' => 'secondary',
            'Partially Paid' => 'info'
        ];
        
        return $colors[$status] ?? 'secondary';
    }
    
    /**
     * Get payment status icon
     */
    public static function getPaymentStatusIcon($status) {
        $icons = [
            'Pending' => 'fas fa-clock',
            'Successful' => 'fas fa-check-circle',
            'Failed' => 'fas fa-times-circle',
            'Cancelled' => 'fas fa-ban',
            'Partially Paid' => 'fas fa-exclamation-triangle'
        ];
        
        return $icons[$status] ?? 'fas fa-question-circle';
    }
    
    /**
     * Calculate payment fee (if applicable)
     */
    public static function calculatePaymentFee($amount, $method, $provider = null) {
        // Define fee structure
        $fees = [
            'Mobile Money' => [
                'mtn-gh' => 0.01, // 1%
                'tgo-gh' => 0.01, // 1%
                'airtel-gh' => 0.01 // 1%
            ],
            'card' => 0.025, // 2.5%
            'bank_transfer' => 0.00 // Free
        ];
        
        $feeRate = 0;
        
        if ($method === 'Mobile Money' && $provider) {
            $feeRate = $fees['Mobile Money'][$provider] ?? 0;
        } else {
            $feeRate = $fees[$method] ?? 0;
        }
        
        return round($amount * $feeRate, 2);
    }
    
    /**
     * Get callback URL for payment provider
     */
    public static function getCallbackUrl($provider) {
        $baseUrl = rtrim(BASE_URL, '/');
        return "{$baseUrl}/api/payments/callback.php?provider={$provider}";
    }
    
    /**
     * Mask sensitive data for logging
     */
    public static function maskSensitiveData($data, $fields = ['password', 'pin', 'api_key', 'secret']) {
        if (!is_array($data)) {
            return $data;
        }
        
        $masked = $data;
        
        foreach ($fields as $field) {
            if (isset($masked[$field])) {
                $value = $masked[$field];
                if (is_string($value) && strlen($value) > 4) {
                    $masked[$field] = substr($value, 0, 2) . str_repeat('*', strlen($value) - 4) . substr($value, -2);
                } else {
                    $masked[$field] = '***';
                }
            }
        }
        
        return $masked;
    }
    
    /**
     * Convert amount to smallest currency unit (e.g., kobo for NGN, pesewas for GHS)
     */
    public static function toSmallestUnit($amount, $currency = 'GHS') {
        $multipliers = [
            'GHS' => 100, // pesewas
            'NGN' => 100, // kobo
            'USD' => 100, // cents
            'EUR' => 100  // cents
        ];
        
        $multiplier = $multipliers[$currency] ?? 100;
        return (int)($amount * $multiplier);
    }
    
    /**
     * Convert amount from smallest currency unit
     */
    public static function fromSmallestUnit($amount, $currency = 'GHS') {
        $divisors = [
            'GHS' => 100,
            'NGN' => 100,
            'USD' => 100,
            'EUR' => 100
        ];
        
        $divisor = $divisors[$currency] ?? 100;
        return round($amount / $divisor, 2);
    }
}