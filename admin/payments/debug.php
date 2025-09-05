<?php
/**
 * DEBUGGING VERSION - Payment Management
 * Add this debugging code to identify the issue
 */

// Add at the top after session_start()
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Add debugging function (no file logging)
function debugLog($message, $data = null) {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message";
    if ($data !== null) {
        $logMessage .= " | Data: " . json_encode($data, JSON_UNESCAPED_SLASHES);
    }
    
    // Output to browser console
    echo "<script>console.log(" . json_encode('[PHP DEBUG] ' . $logMessage) . ");</script>";
    
    // Also show on screen during development
    echo "<div style='background: #f0f8ff; border: 1px solid #0066cc; padding: 8px; margin: 5px 0; font-family: monospace; font-size: 12px;'>";
    echo "<strong>DEBUG:</strong> " . htmlspecialchars($logMessage);
    echo "</div>";
}

// Add right after the try block starts
debugLog("Payment page loaded", [
    'POST_data' => $_POST,
    'session_id' => session_id(),
    'user_id' => $currentUser['user_id'] ?? 'not_set'
]);

// Replace the existing payment submission block with this debug version:
if (isset($_POST['submit_payment'])) {
    debugLog("Payment submission detected", $_POST);
    
    // Check if account data exists
    if (!$accountData) {
        debugLog("ERROR: Account data missing");
        $errors[] = "Account data missing. Please search for the account again.";
    }
    
    if (!$billData) {
        debugLog("ERROR: Bill data missing");
        $errors[] = "Bill data missing. Please search for the account again.";
    }
    
    if ($accountData && $billData) {
        debugLog("Account and bill data available", [
            'account_type' => $formData['account_type'],
            'account_number' => $formData['account_number'],
            'bill_id' => $billData['bill_id'],
            'amount_payable' => $billData['amount_payable']
        ]);
        
        // Your existing validation code here...
        // But add debugging for each step
        
        $formData['payment_method'] = sanitizeInput($_POST['payment_method'] ?? '');
        $formData['amount_paid'] = sanitizeInput($_POST['amount_paid'] ?? '');
        
        debugLog("Form data after sanitization", $formData);
        
        // Validation with debug
        if (empty($formData['payment_method'])) {
            debugLog("Validation error: Payment method empty");
            $errors[] = 'Payment method is required.';
        }
        
        if (empty($formData['amount_paid'])) {
            debugLog("Validation error: Amount empty");
            $errors[] = 'Payment amount is required.';
        } elseif (!is_numeric($formData['amount_paid'])) {
            debugLog("Validation error: Amount not numeric", $formData['amount_paid']);
            $errors[] = 'Payment amount must be a valid number.';
        }
        
        debugLog("Validation complete", ['errors_count' => count($errors)]);
        
        if (empty($errors)) {
            debugLog("Starting payment processing");
            
            try {
                // Test database connection first
                $testQuery = $db->fetchRow("SELECT 1 as test");
                debugLog("Database connection test", $testQuery);
                
                // Start transaction
                debugLog("Starting database transaction");
                if (method_exists($db, 'beginTransaction')) {
                    $db->beginTransaction();
                    debugLog("Transaction started using beginTransaction()");
                } else {
                    $db->execute("START TRANSACTION");
                    debugLog("Transaction started using START TRANSACTION");
                }
                
                $paymentReference = generatePaymentReference();
                debugLog("Generated payment reference", $paymentReference);
                
                // Insert payment with debug
                $paymentQuery = "
                    INSERT INTO payments (payment_reference, bill_id, amount_paid, payment_method, 
                                        payment_channel, transaction_id, payment_status, payment_date, 
                                        processed_by, notes)
                    VALUES (?, ?, ?, ?, ?, ?, 'Successful', NOW(), ?, ?)
                ";
                
                $paymentParams = [
                    $paymentReference,
                    $billData['bill_id'],
                    floatval($formData['amount_paid']),
                    $formData['payment_method'],
                    $formData['payment_channel'],
                    $formData['transaction_id'],
                    $currentUser['user_id'],
                    $formData['notes']
                ];
                
                debugLog("About to insert payment", [
                    'query' => $paymentQuery,
                    'params' => $paymentParams
                ]);
                
                $result = $db->execute($paymentQuery, $paymentParams);
                debugLog("Payment insert result", $result);
                
                if (!$result) {
                    throw new Exception("Failed to insert payment record");
                }
                
                // Get last insert ID
                $paymentId = null;
                if (method_exists($db, 'lastInsertId')) {
                    $paymentId = $db->lastInsertId();
                } else {
                    $insertResult = $db->fetchRow("SELECT LAST_INSERT_ID() as id");
                    $paymentId = $insertResult['id'] ?? null;
                }
                
                debugLog("Payment ID retrieved", $paymentId);
                
                // Update bill status
                $newAmountPayable = floatval($billData['amount_payable']) - floatval($formData['amount_paid']);
                $billStatus = $newAmountPayable <= 0 ? 'Paid' : 'Partially Paid';
                
                debugLog("Updating bill", [
                    'bill_id' => $billData['bill_id'],
                    'old_amount' => $billData['amount_payable'],
                    'new_amount' => $newAmountPayable,
                    'status' => $billStatus
                ]);
                
                $billUpdateResult = $db->execute("
                    UPDATE bills 
                    SET amount_payable = ?, status = ?
                    WHERE bill_id = ?
                ", [$newAmountPayable, $billStatus, $billData['bill_id']]);
                
                debugLog("Bill update result", $billUpdateResult);
                
                // Commit transaction
                if (method_exists($db, 'commit')) {
                    $db->commit();
                } else {
                    $db->execute("COMMIT");
                }
                
                debugLog("Transaction committed successfully");
                
                setFlashMessage('success', "Payment recorded successfully! Reference: {$paymentReference}");
                debugLog("Redirecting to payment view", "view.php?id={$paymentId}");
                
                header("Location: view.php?id={$paymentId}");
                exit();
                
            } catch (Exception $e) {
                debugLog("ERROR in payment processing", [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                // Rollback
                if (isset($db)) {
                    if (method_exists($db, 'rollback')) {
                        $db->rollback();
                    } else {
                        $db->execute("ROLLBACK");
                    }
                }
                
                $errors[] = 'Payment processing failed: ' . $e->getMessage();
            }
        }
    }
}

// Also add this JavaScript debugging at the bottom of your script section:
?>

<script>
// Add comprehensive JavaScript debugging
document.addEventListener('DOMContentLoaded', function() {
    console.log('=== PAYMENT DEBUG MODE ===');
    console.log('Page loaded successfully');
    
    // Check if account data exists
    const accountCard = document.querySelector('.account-info-card');
    console.log('Account card found:', !!accountCard);
    
    const paymentForm = document.getElementById('paymentForm');
    console.log('Payment form found:', !!paymentForm);
    
    if (paymentForm) {
        console.log('Payment form elements:');
        console.log('- Payment methods:', paymentForm.querySelectorAll('input[name="payment_method"]').length);
        console.log('- Amount field:', !!paymentForm.querySelector('input[name="amount_paid"]'));
        console.log('- Submit button:', !!paymentForm.querySelector('button[name="submit_payment"]'));
        
        // Override the form submit to add debugging
        paymentForm.addEventListener('submit', function(e) {
            console.log('=== FORM SUBMISSION TRIGGERED ===');
            e.preventDefault();
            
            const formData = new FormData(paymentForm);
            const formObject = {};
            formData.forEach((value, key) => {
                formObject[key] = value;
            });
            
            console.log('Form data being submitted:', formObject);
            
            // Check if all required fields are filled
            const paymentMethod = formData.get('payment_method');
            const amount = formData.get('amount_paid');
            
            console.log('Payment method selected:', paymentMethod);
            console.log('Amount entered:', amount);
            
            if (!paymentMethod) {
                console.error('ERROR: No payment method selected');
                alert('Please select a payment method');
                return;
            }
            
            if (!amount || parseFloat(amount) <= 0) {
                console.error('ERROR: Invalid amount');
                alert('Please enter a valid amount');
                return;
            }
            
            console.log('Validation passed, submitting form...');
            
            // Remove the event listener to avoid recursion
            paymentForm.removeEventListener('submit', arguments.callee);
            
            // Actually submit the form
            paymentForm.submit();
        });
    }
});

// Add a manual test function
window.testPayment = function() {
    console.log('=== MANUAL PAYMENT TEST ===');
    const form = document.getElementById('paymentForm');
    if (form) {
        console.log('Form found, triggering submit...');
        form.dispatchEvent(new Event('submit'));
    } else {
        console.error('Payment form not found');
    }
};

console.log('Debug mode loaded. Run testPayment() to manually test the form.');
</script>