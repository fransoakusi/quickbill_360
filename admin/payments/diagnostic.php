<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Debug Tool - QuickBill 305</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }
        
        .header p {
            opacity: 0.9;
            font-size: 1.1rem;
        }
        
        .content {
            padding: 40px;
        }
        
        .debug-section {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            border-left: 5px solid #3b82f6;
        }
        
        .debug-title {
            font-size: 1.5rem;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .checklist {
            list-style: none;
            padding: 0;
        }
        
        .checklist li {
            background: white;
            margin-bottom: 15px;
            padding: 20px;
            border-radius: 10px;
            border: 2px solid #e2e8f0;
            transition: all 0.3s;
            position: relative;
        }
        
        .checklist li:hover {
            border-color: #3b82f6;
            transform: translateX(5px);
        }
        
        .check-number {
            position: absolute;
            top: -10px;
            left: 20px;
            background: #3b82f6;
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
        }
        
        .check-title {
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 10px;
            margin-top: 5px;
            font-size: 1.1rem;
        }
        
        .check-desc {
            color: #64748b;
            line-height: 1.6;
            margin-bottom: 15px;
        }
        
        .code-block {
            background: #1f2937;
            color: #f9fafb;
            padding: 15px;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            overflow-x: auto;
            margin: 10px 0;
        }
        
        .warning {
            background: #fef3c7;
            border-left-color: #f59e0b;
            color: #92400e;
        }
        
        .critical {
            background: #fef2f2;
            border-left-color: #ef4444;
            color: #991b1b;
        }
        
        .success {
            background: #ecfdf5;
            border-left-color: #10b981;
            color: #065f46;
        }
        
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #3b82f6;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
        }
        
        .btn:hover {
            background: #2563eb;
            transform: translateY(-2px);
        }
        
        .btn-danger {
            background: #ef4444;
        }
        
        .btn-danger:hover {
            background: #dc2626;
        }
        
        .solution-box {
            background: #dbeafe;
            border: 2px solid #3b82f6;
            border-radius: 10px;
            padding: 20px;
            margin-top: 15px;
        }
        
        .solution-title {
            font-weight: bold;
            color: #1e40af;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .file-path {
            background: #374151;
            color: #f9fafb;
            padding: 5px 10px;
            border-radius: 5px;
            font-family: monospace;
            display: inline-block;
            margin: 5px 0;
        }
        
        .steps {
            counter-reset: step-counter;
        }
        
        .step {
            counter-increment: step-counter;
            position: relative;
            padding-left: 40px;
            margin-bottom: 20px;
        }
        
        .step::before {
            content: counter(step-counter);
            position: absolute;
            left: 0;
            top: 0;
            background: #10b981;
            color: white;
            width: 25px;
            height: 25px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚨 Payment Debug Tool</h1>
            <p>Diagnose and fix payment processing issues in QuickBill 305</p>
        </div>
        
        <div class="content">
            <div class="debug-section critical">
                <div class="debug-title">
                    🔍 Common Issues Checklist
                </div>
                
                <ul class="checklist">
                    <li>
                        <div class="check-number">1</div>
                        <div class="check-title">JavaScript Console Errors</div>
                        <div class="check-desc">The most common cause is JavaScript errors preventing form submission.</div>
                        <div class="solution-box">
                            <div class="solution-title">💡 Solution:</div>
                            <div class="steps">
                                <div class="step">Open browser Developer Tools (F12)</div>
                                <div class="step">Go to Console tab</div>
                                <div class="step">Look for red error messages</div>
                                <div class="step">Common errors: "Cannot read property", "function not defined", etc.</div>
                            </div>
                        </div>
                    </li>
                    
                    <li>
                        <div class="check-number">2</div>
                        <div class="check-title">Account Search Not Completed</div>
                        <div class="check-desc">Payment form only appears after successful account search.</div>
                        <div class="solution-box">
                            <div class="solution-title">💡 Solution:</div>
                            <div class="steps">
                                <div class="step">Ensure you've searched for an account first</div>
                                <div class="step">Verify account info displays correctly</div>
                                <div class="step">Check if payment form appears below account info</div>
                            </div>
                        </div>
                    </li>
                    
                    <li class="warning">
                        <div class="check-number">3</div>
                        <div class="check-title">Missing PHP Functions</div>
                        <div class="check-desc">Payment processing depends on several helper functions.</div>
                        <div class="solution-box">
                            <div class="solution-title">💡 Solution:</div>
                            Add this to your <span class="file-path">includes/functions.php</span>:
                            <div class="code-block">
// Generate payment reference
function generatePaymentReference() {
    return 'PAY' . date('Y') . strtoupper(substr(uniqid(), -6));
}

// Enhanced logging function
function writeLog($message, $level = 'INFO') {
    $logFile = '../logs/payment.log';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$level] $message" . PHP_EOL;
    
    // Create logs directory if it doesn't exist
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}
                            </div>
                        </div>
                    </li>
                    
                    <li class="critical">
                        <div class="check-number">4</div>
                        <div class="check-title">Database Transaction Methods</div>
                        <div class="check-desc">Your Database class might not have transaction methods.</div>
                        <div class="solution-box">
                            <div class="solution-title">💡 Solution:</div>
                            Add these methods to your <span class="file-path">config/database.php</span> Database class:
                            <div class="code-block">
public function beginTransaction() {
    try {
        return $this->pdo->beginTransaction();
    } catch (PDOException $e) {
        error_log("Begin transaction failed: " . $e->getMessage());
        return false;
    }
}

public function commit() {
    try {
        return $this->pdo->commit();
    } catch (PDOException $e) {
        error_log("Commit failed: " . $e->getMessage());
        return false;
    }
}

public function rollback() {
    try {
        return $this->pdo->rollback();
    } catch (PDOException $e) {
        error_log("Rollback failed: " . $e->getMessage());
        return false;
    }
}

public function lastInsertId() {
    try {
        return $this->pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log("Last insert ID failed: " . $e->getMessage());
        return false;
    }
}
                            </div>
                        </div>
                    </li>
                    
                    <li>
                        <div class="check-number">5</div>
                        <div class="check-title">Permission Issues</div>
                        <div class="check-desc">User might not have payment creation permissions.</div>
                        <div class="solution-box">
                            <div class="solution-title">💡 Solution:</div>
                            <div class="steps">
                                <div class="step">Check if user has 'payments.create' permission</div>
                                <div class="step">Verify user role in database</div>
                                <div class="step">Temporarily comment out permission check for testing</div>
                            </div>
                        </div>
                    </li>
                    
                    <li>
                        <div class="check-number">6</div>
                        <div class="check-title">Form Validation Blocking Submission</div>
                        <div class="check-desc">Client-side validation might be preventing form submission.</div>
                        <div class="solution-box">
                            <div class="solution-title">💡 Solution:</div>
                            <div class="steps">
                                <div class="step">Check console for validation errors</div>
                                <div class="step">Try submitting with minimum required fields</div>
                                <div class="step">Temporarily disable JavaScript validation</div>
                            </div>
                        </div>
                    </li>
                </ul>
            </div>
            
            <div class="debug-section success">
                <div class="debug-title">
                    🛠️ Quick Fix Code
                </div>
                <p>Replace your payment processing section with this simplified version for testing:</p>
                
                <div class="code-block">
// Simplified payment processing (for debugging)
if (isset($_POST['submit_payment'])) {
    try {
        echo "&lt;div style='background: yellow; padding: 20px; margin: 20px 0;'&gt;";
        echo "&lt;h3&gt;🔧 DEBUG: Payment submission detected!&lt;/h3&gt;";
        echo "&lt;pre&gt;" . print_r($_POST, true) . "&lt;/pre&gt;";
        echo "&lt;/div&gt;";
        
        // Basic validation
        $accountNumber = $_POST['account_number'] ?? '';
        $accountType = $_POST['account_type'] ?? '';
        $paymentMethod = $_POST['payment_method'] ?? '';
        $amount = $_POST['amount_paid'] ?? '';
        
        if (empty($accountNumber) || empty($accountType) || empty($paymentMethod) || empty($amount)) {
            throw new Exception("Missing required fields");
        }
        
        echo "&lt;div style='background: lightgreen; padding: 20px; margin: 20px 0;'&gt;";
        echo "&lt;h3&gt;✅ Basic validation passed!&lt;/h3&gt;";
        echo "Account: $accountType - $accountNumber&lt;br&gt;";
        echo "Payment: GHS $amount via $paymentMethod";
        echo "&lt;/div&gt;";
        
        // Test database connection
        $testQuery = $db->fetchRow("SELECT 1 as test, NOW() as current_time");
        if ($testQuery) {
            echo "&lt;div style='background: lightblue; padding: 20px; margin: 20px 0;'&gt;";
            echo "&lt;h3&gt;✅ Database connection working!&lt;/h3&gt;";
            echo "Current time: " . $testQuery['current_time'];
            echo "&lt;/div&gt;";
        } else {
            throw new Exception("Database connection failed");
        }
        
        // If we get here, the basic structure is working
        echo "&lt;div style='background: gold; padding: 20px; margin: 20px 0;'&gt;";
        echo "&lt;h3&gt;🎉 Payment processing structure is working!&lt;/h3&gt;";
        echo "You can now implement the full payment logic.";
        echo "&lt;/div&gt;";
        
    } catch (Exception $e) {
        echo "&lt;div style='background: salmon; padding: 20px; margin: 20px 0;'&gt;";
        echo "&lt;h3&gt;❌ Error: " . $e->getMessage() . "&lt;/h3&gt;";
        echo "&lt;/div&gt;";
    }
}
                </div>
            </div>
            
            <div class="debug-section">
                <div class="debug-title">
                    🚀 Immediate Action Steps
                </div>
                
                <div class="steps">
                    <div class="step">
                        <strong>Add debug parameter:</strong> Visit your payment page with <span class="file-path">?debug=1</span> at the end of the URL
                    </div>
                    <div class="step">
                        <strong>Check browser console:</strong> Open F12 → Console tab and look for errors
                    </div>
                    <div class="step">
                        <strong>Test with minimal data:</strong> Try the simplest payment possible (Cash, small amount)
                    </div>
                    <div class="step">
                        <strong>Add the missing functions:</strong> Copy the PHP functions above to your includes/functions.php
                    </div>
                    <div class="step">
                        <strong>Test database methods:</strong> Add the transaction methods to your Database class
                    </div>
                    <div class="step">
                        <strong>Use the debug code:</strong> Replace payment processing temporarily with the debug version above
                    </div>
                </div>
            </div>
            
            <div style="text-align: center; margin-top: 40px;">
                <button onclick="copyDebugCode()" class="btn">📋 Copy Debug Code</button>
                <button onclick="showTestForm()" class="btn btn-danger">🧪 Show Test Form</button>
            </div>
        </div>
    </div>
    
    <script>
        function copyDebugCode() {
            const code = `// Simplified payment processing (for debugging)
if (isset($_POST['submit_payment'])) {
    try {
        echo "<div style='background: yellow; padding: 20px; margin: 20px 0;'>";
        echo "<h3>🔧 DEBUG: Payment submission detected!</h3>";
        echo "<pre>" . print_r($_POST, true) . "</pre>";
        echo "</div>";
        
        // Basic validation
        $accountNumber = $_POST['account_number'] ?? '';
        $accountType = $_POST['account_type'] ?? '';
        $paymentMethod = $_POST['payment_method'] ?? '';
        $amount = $_POST['amount_paid'] ?? '';
        
        if (empty($accountNumber) || empty($accountType) || empty($paymentMethod) || empty($amount)) {
            throw new Exception("Missing required fields");
        }
        
        echo "<div style='background: lightgreen; padding: 20px; margin: 20px 0;'>";
        echo "<h3>✅ Basic validation passed!</h3>";
        echo "Account: $accountType - $accountNumber<br>";
        echo "Payment: GHS $amount via $paymentMethod";
        echo "</div>";
        
        // Test database connection
        $testQuery = $db->fetchRow("SELECT 1 as test, NOW() as current_time");
        if ($testQuery) {
            echo "<div style='background: lightblue; padding: 20px; margin: 20px 0;'>";
            echo "<h3>✅ Database connection working!</h3>";
            echo "Current time: " . $testQuery['current_time'];
            echo "</div>";
        } else {
            throw new Exception("Database connection failed");
        }
        
        // If we get here, the basic structure is working
        echo "<div style='background: gold; padding: 20px; margin: 20px 0;'>";
        echo "<h3>🎉 Payment processing structure is working!</h3>";
        echo "You can now implement the full payment logic.";
        echo "</div>";
        
    } catch (Exception $e) {
        echo "<div style='background: salmon; padding: 20px; margin: 20px 0;'>";
        echo "<h3>❌ Error: " . $e->getMessage() . "</h3>";
        echo "</div>";
    }
}`;
            
            navigator.clipboard.writeText(code).then(() => {
                alert('Debug code copied to clipboard! Replace your payment processing section with this code.');
            });
        }
        
        function showTestForm() {
            alert('Create a simple test form without JavaScript validation to isolate the issue:\n\n<form method="POST">\n  Account: <input name="account_number" value="BIZ000001">\n  Type: <input name="account_type" value="Business">\n  Method: <input name="payment_method" value="Cash">\n  Amount: <input name="amount_paid" value="100">\n  <button name="submit_payment">Test Payment</button>\n</form>');
        }
    </script>
</body>
</html>