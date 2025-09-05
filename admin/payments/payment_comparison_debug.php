<?php
/**
 * Payment Processing Comparison Debug
 * Compare working debug vs failing main payment
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Define application constant
define('QUICKBILL_305', true);

// Start session and output buffering
session_start();
ob_start();

// Debug log function
function debugLog($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    echo "<div style='margin:5px 0; padding:8px; background:" . 
         ($level === 'ERROR' ? '#fee' : ($level === 'SUCCESS' ? '#efe' : ($level === 'WARNING' ? '#fef3c7' : '#f9f9f9'))) . 
         "; border-left:4px solid " . 
         ($level === 'ERROR' ? '#e53e3e' : ($level === 'SUCCESS' ? '#10b981' : ($level === 'WARNING' ? '#f59e0b' : '#3b82f6'))) . 
         "; font-family:monospace; font-size:12px;'>";
    echo "<strong>[{$level}]</strong> {$message}";
    echo "</div>";
    
    if (ob_get_level()) {
        ob_flush();
    }
    flush();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Processing Comparison Debug</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1400px; margin: 0 auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%); color: white; padding: 20px; border-radius: 10px; margin-bottom: 20px; }
        .test-section { background: #f8f9fa; padding: 15px; margin: 15px 0; border-radius: 8px; border: 1px solid #e9ecef; }
        .comparison-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .debug-column { background: #d1fae5; padding: 15px; border-radius: 8px; border: 2px solid #10b981; }
        .main-column { background: #fee2e2; padding: 15px; border-radius: 8px; border: 2px solid #dc2626; }
        .form-section { background: #e3f2fd; padding: 15px; margin: 15px 0; border-radius: 8px; }
        .btn { background: #3b82f6; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; margin: 5px; }
        .btn-danger { background: #dc2626; }
        .btn-success { background: #059669; }
        .code { background: #1f2937; color: #e5e7eb; padding: 10px; border-radius: 5px; font-family: monospace; margin: 10px 0; overflow-x: auto; font-size: 11px; }
        .highlight { background: #fef3c7; padding: 10px; border-radius: 5px; border: 1px solid #f59e0b; margin: 10px 0; }
        .success { color: #059669; font-weight: bold; }
        .error { color: #dc2626; font-weight: bold; }
        .warning { color: #d97706; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔧 Payment Processing Comparison Debug</h1>
            <p>Compare working debug test vs failing main payment system</p>
            <p><strong>Goal:</strong> Find why debug works but main payment doesn't</p>
        </div>

        <?php
        
        debugLog("=== PAYMENT COMPARISON DEBUG STARTED ===", 'INFO');
        
        // Include required files
        $requiredFiles = [
            '../../config/config.php',
            '../../config/database.php', 
            '../../includes/functions.php',
            '../../includes/auth.php',
            '../../includes/security.php'
        ];
        
        foreach ($requiredFiles as $file) {
            if (file_exists($file)) {
                require_once $file;
            }
        }
        
        try {
            $db = new Database();
            debugLog("✅ Database connection established", 'SUCCESS');
        } catch (Exception $e) {
            debugLog("❌ Database connection failed: " . $e->getMessage(), 'ERROR');
            exit;
        }
        
        // Test authentication functions
        echo "<div class='test-section'>";
        echo "<h3>🔐 Authentication & Permission Checks</h3>";
        
        if (function_exists('isLoggedIn')) {
            $loggedIn = isLoggedIn();
            debugLog("isLoggedIn(): " . ($loggedIn ? 'true' : 'false'), $loggedIn ? 'SUCCESS' : 'WARNING');
        } else {
            debugLog("❌ isLoggedIn() function not found", 'ERROR');
        }
        
        if (function_exists('getCurrentUser')) {
            try {
                $currentUser = getCurrentUser();
                if ($currentUser) {
                    debugLog("✅ getCurrentUser() returned user: " . ($currentUser['username'] ?? 'unknown'), 'SUCCESS');
                } else {
                    debugLog("⚠️ getCurrentUser() returned null/empty", 'WARNING');
                }
            } catch (Exception $e) {
                debugLog("❌ getCurrentUser() error: " . $e->getMessage(), 'ERROR');
            }
        } else {
            debugLog("❌ getCurrentUser() function not found", 'ERROR');
        }
        
        if (function_exists('hasPermission')) {
            try {
                $hasPaymentPerm = hasPermission('payments.create');
                debugLog("hasPermission('payments.create'): " . ($hasPaymentPerm ? 'true' : 'false'), $hasPaymentPerm ? 'SUCCESS' : 'WARNING');
            } catch (Exception $e) {
                debugLog("❌ hasPermission() error: " . $e->getMessage(), 'ERROR');
            }
        } else {
            debugLog("❌ hasPermission() function not found", 'ERROR');
        }
        
        echo "</div>";
        
        // Process forms
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            
            if (isset($_POST['simulate_main_payment'])) {
                echo "<div class='test-section'>";
                echo "<h3>🔄 Simulating Main Payment File Process</h3>";
                
                debugLog("=== SIMULATING MAIN PAYMENT FILE LOGIC ===", 'INFO');
                
                // Simulate the exact process from the main payment file
                $formData = [
                    'account_number' => sanitizeInput($_POST['account_number'] ?? ''),
                    'account_type' => sanitizeInput($_POST['account_type'] ?? ''),
                    'payment_method' => sanitizeInput($_POST['payment_method'] ?? ''),
                    'payment_channel' => sanitizeInput($_POST['payment_channel'] ?? ''),
                    'amount_paid' => sanitizeInput($_POST['amount_paid'] ?? ''),
                    'transaction_id' => sanitizeInput($_POST['transaction_id'] ?? ''),
                    'notes' => sanitizeInput($_POST['notes'] ?? '')
                ];
                
                debugLog("Form data received: " . json_encode($formData), 'INFO');
                
                $errors = [];
                $accountData = null;
                $billData = null;
                
                // Step 1: Account Search (simulating main file logic)
                debugLog("Step 1: Account Search", 'INFO');
                
                if ($formData['account_type'] === 'Business') {
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
                    ", [$formData['account_number']]);
                    
                    if ($accountData) {
                        debugLog("✅ Business account found: " . $accountData['business_name'], 'SUCCESS');
                        
                        $currentYear = date('Y');
                        $billData = $db->fetchRow("
                            SELECT * FROM bills 
                            WHERE bill_type = 'Business' AND reference_id = ? AND billing_year = ?
                        ", [$accountData['business_id'], $currentYear]);
                        
                        if (!$billData) {
                            $errors[] = "No bill found for this business account for the year {$currentYear}. Please generate bills first before recording payments.";
                            debugLog("❌ No bill found for business", 'ERROR');
                            $accountData = null;
                        } else {
                            debugLog("✅ Bill found: " . $billData['bill_number'], 'SUCCESS');
                        }
                    } else {
                        $errors[] = "Business account not found.";
                        debugLog("❌ Business account not found", 'ERROR');
                    }
                    
                } elseif ($formData['account_type'] === 'Property') {
                    $accountData = $db->fetchRow("
                        SELECT 
                            p.*,
                            z.zone_name,
                            pfs.fee_per_room
                        FROM properties p
                        LEFT JOIN zones z ON p.zone_id = z.zone_id
                        LEFT JOIN property_fee_structure pfs ON p.structure = pfs.structure AND p.property_use = pfs.property_use
                        WHERE p.property_number = ?
                    ", [$formData['account_number']]);
                    
                    if ($accountData) {
                        debugLog("✅ Property account found: " . $accountData['owner_name'], 'SUCCESS');
                        
                        $currentYear = date('Y');
                        $billData = $db->fetchRow("
                            SELECT * FROM bills 
                            WHERE bill_type = 'Property' AND reference_id = ? AND billing_year = ?
                        ", [$accountData['property_id'], $currentYear]);
                        
                        if (!$billData) {
                            $errors[] = "No bill found for this property account for the year {$currentYear}. Please generate bills first before recording payments.";
                            debugLog("❌ No bill found for property", 'ERROR');
                            $accountData = null;
                        } else {
                            debugLog("✅ Bill found: " . $billData['bill_number'], 'SUCCESS');
                        }
                    } else {
                        $errors[] = "Property account not found.";
                        debugLog("❌ Property account not found", 'ERROR');
                    }
                }
                
                // Step 2: Validation (simulating main file logic)
                debugLog("Step 2: Payment Validation", 'INFO');
                
                if (empty($formData['payment_method'])) {
                    $errors[] = 'Payment method is required.';
                }
                
                if (empty($formData['amount_paid'])) {
                    $errors[] = 'Payment amount is required.';
                } elseif (!is_numeric($formData['amount_paid'])) {
                    $errors[] = 'Payment amount must be a valid number.';
                } elseif (floatval($formData['amount_paid']) <= 0) {
                    $errors[] = 'Payment amount must be greater than zero.';
                } elseif ($billData && floatval($formData['amount_paid']) > floatval($billData['amount_payable'])) {
                    $errors[] = 'Payment amount cannot exceed the outstanding balance of GHS ' . number_format($billData['amount_payable'], 2) . '.';
                }
                
                if (in_array($formData['payment_method'], ['Mobile Money', 'Bank Transfer', 'Online']) && empty($formData['transaction_id'])) {
                    $errors[] = 'Transaction ID is required for this payment method.';
                }
                
                if (!empty($errors)) {
                    debugLog("❌ Validation errors: " . implode(", ", $errors), 'ERROR');
                    foreach ($errors as $error) {
                        echo "<div style='color: red; margin: 5px 0;'>• {$error}</div>";
                    }
                } else {
                    debugLog("✅ Validation passed", 'SUCCESS');
                }
                
                // Step 3: Payment Processing (simulating main file logic)
                if (empty($errors) && $accountData && $billData) {
                    debugLog("Step 3: Payment Processing", 'INFO');
                    
                    try {
                        // Check if we should use main file logic or processPayment function
                        if (function_exists('processPayment')) {
                            debugLog("Using processPayment() function", 'INFO');
                            
                            // Check if user is available for processPayment
                            $processedBy = 1; // Default for testing
                            if (function_exists('getCurrentUser')) {
                                $user = getCurrentUser();
                                if ($user && isset($user['user_id'])) {
                                    $processedBy = $user['user_id'];
                                }
                            }
                            
                            $paymentData = [
                                'amount_paid' => $formData['amount_paid'],
                                'payment_method' => $formData['payment_method'],
                                'payment_channel' => $formData['payment_channel'],
                                'transaction_id' => $formData['transaction_id'],
                                'notes' => $formData['notes'],
                                'processed_by' => $processedBy,
                                'account_type' => $formData['account_type']
                            ];
                            
                            $result = processPayment($paymentData, $billData, $accountData);
                            
                            if ($result['success']) {
                                debugLog("✅ Payment processed successfully using processPayment()", 'SUCCESS');
                                debugLog("Payment Reference: " . $result['payment_reference'], 'SUCCESS');
                                debugLog("Payment ID: " . $result['payment_id'], 'SUCCESS');
                                
                                echo "<div class='highlight'>";
                                echo "<strong>🎉 Payment Successful!</strong><br>";
                                echo "Reference: " . $result['payment_reference'] . "<br>";
                                echo "Payment ID: " . $result['payment_id'] . "<br>";
                                echo "New Balance: GHS " . number_format($result['new_balance'], 2);
                                echo "</div>";
                            } else {
                                debugLog("❌ processPayment() failed: " . $result['error'], 'ERROR');
                                echo "<div style='color: red; background: #fee; padding: 10px; border-radius: 5px;'>";
                                echo "<strong>❌ Payment Failed:</strong><br>" . $result['error'];
                                echo "</div>";
                            }
                        } else {
                            debugLog("❌ processPayment() function not found, trying manual approach", 'WARNING');
                            
                            // Try manual approach like in main file
                            $db->beginTransaction();
                            
                            $amountPaid = floatval($formData['amount_paid']);
                            $paymentReference = generatePaymentReference();
                            
                            debugLog("Generated payment reference: " . $paymentReference, 'INFO');
                            
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
                                1, // Default user ID
                                $formData['notes']
                            ]);
                            
                            if ($result) {
                                debugLog("✅ Payment record inserted", 'SUCCESS');
                                
                                // Update bill and account...
                                $newAmountPayable = floatval($billData['amount_payable']) - $amountPaid;
                                $billStatus = $newAmountPayable <= 0 ? 'Paid' : 'Partially Paid';
                                
                                $db->execute("UPDATE bills SET amount_payable = ?, status = ? WHERE bill_id = ?", 
                                           [$newAmountPayable, $billStatus, $billData['bill_id']]);
                                
                                debugLog("✅ Bill updated", 'SUCCESS');
                                
                                if ($formData['account_type'] === 'Business') {
                                    $newBusinessPayable = floatval($accountData['amount_payable']) - $amountPaid;
                                    $newPreviousPayments = floatval($accountData['previous_payments']) + $amountPaid;
                                    
                                    $db->execute("UPDATE businesses SET amount_payable = ?, previous_payments = ? WHERE business_id = ?", 
                                               [$newBusinessPayable, $newPreviousPayments, $accountData['business_id']]);
                                } else {
                                    $newPropertyPayable = floatval($accountData['amount_payable']) - $amountPaid;
                                    $newPreviousPayments = floatval($accountData['previous_payments']) + $amountPaid;
                                    
                                    $db->execute("UPDATE properties SET amount_payable = ?, previous_payments = ? WHERE property_id = ?", 
                                               [$newPropertyPayable, $newPreviousPayments, $accountData['property_id']]);
                                }
                                
                                debugLog("✅ Account updated", 'SUCCESS');
                                
                                $db->commit();
                                debugLog("✅ Transaction committed", 'SUCCESS');
                                
                                echo "<div class='highlight'>";
                                echo "<strong>🎉 Manual Payment Processing Successful!</strong><br>";
                                echo "Reference: " . $paymentReference . "<br>";
                                echo "New Bill Balance: GHS " . number_format($newAmountPayable, 2);
                                echo "</div>";
                                
                            } else {
                                $db->rollback();
                                debugLog("❌ Failed to insert payment record", 'ERROR');
                            }
                        }
                        
                    } catch (Exception $e) {
                        if (isset($db)) {
                            $db->rollback();
                        }
                        debugLog("❌ Payment processing exception: " . $e->getMessage(), 'ERROR');
                        debugLog("Stack trace: " . $e->getTraceAsString(), 'ERROR');
                        
                        echo "<div style='color: red; background: #fee; padding: 10px; border-radius: 5px;'>";
                        echo "<strong>❌ Payment Processing Error:</strong><br>" . $e->getMessage();
                        echo "</div>";
                    }
                }
                
                echo "</div>";
            }
        }
        
        ?>
        
        <div class="comparison-grid">
            <div class="debug-column">
                <h3>✅ Working Debug Test Logic</h3>
                <div class="code">
// Debug test uses simple direct approach:
1. Find account
2. Find bill  
3. Call processPayment() function
4. Success!

// Key differences:
- No authentication checks
- Uses processPayment() function
- Simple error handling
- Direct database operations
                </div>
            </div>
            
            <div class="main-column">
                <h3>❌ Main Payment File Issues</h3>
                <div class="code">
// Main file has additional complexity:
1. Authentication checks (requireLogin, hasPermission)
2. Complex form processing
3. Manual database operations  
4. Session management
5. Flash messages
6. Complex validation
7. Transaction handling differences

// Potential failure points:
- Authentication failure
- Session issues
- Permission denied
- Form data processing
- Database method differences
- Error handling suppression
                </div>
            </div>
        </div>
        
        <div class="form-section">
            <h3>🧪 Test Main Payment File Logic</h3>
            <p><strong>This will simulate exactly what happens in your main payment file</strong></p>
            
            <form method="POST" style="margin: 15px 0;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <select name="account_type" required style="padding: 8px;">
                        <option value="">Select Type</option>
                        <option value="Business">Business</option>
                        <option value="Property">Property</option>
                    </select>
                    <input type="text" name="account_number" placeholder="Account Number" required style="padding: 8px;">
                    <input type="number" name="amount_paid" placeholder="Amount" step="0.01" required style="padding: 8px;">
                    <select name="payment_method" required style="padding: 8px;">
                        <option value="">Payment Method</option>
                        <option value="Cash">Cash</option>
                        <option value="Mobile Money">Mobile Money</option>
                        <option value="Bank Transfer">Bank Transfer</option>
                    </select>
                    <input type="text" name="payment_channel" placeholder="Channel (optional)" style="padding: 8px;">
                    <input type="text" name="transaction_id" placeholder="Transaction ID" style="padding: 8px;">
                </div>
                <textarea name="notes" placeholder="Notes (optional)" style="padding: 8px; width: 100%; margin: 10px 0;"></textarea>
                <button type="submit" name="simulate_main_payment" class="btn btn-danger">🔍 Simulate Main Payment Process</button>
            </form>
        </div>
        
        <div style="margin-top: 30px; padding: 15px; background: #fef3c7; border-radius: 8px;">
            <h3>🎯 Troubleshooting Strategy</h3>
            <ol>
                <li><strong>Test the simulation above</strong> - This will show us exactly what fails</li>
                <li><strong>Check authentication</strong> - Are you logged in with proper permissions?</li>
                <li><strong>Compare results</strong> - Debug works, main doesn't = authentication/session issue</li>
                <li><strong>Look for silent failures</strong> - Main file might be failing silently</li>
            </ol>
            
            <p><strong>Most likely issues:</strong></p>
            <ul>
                <li>🔐 <strong>Authentication failure</strong> - Not logged in or no permissions</li>
                <li>📝 <strong>Form processing</strong> - Data not being submitted correctly</li>
                <li>🔄 <strong>Session issues</strong> - Session data missing</li>
                <li>🛠️ <strong>Function differences</strong> - Main file using different database methods</li>
            </ul>
        </div>
    </div>
</body>
</html>