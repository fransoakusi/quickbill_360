<?php
/**
 * Payment Processing Debug Tool - ENHANCED VERSION
 * QUICKBILL 305 - Debug Payment Issues
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
    $logMessage = "[{$timestamp}] [{$level}] {$message}\n";
    
    // Display in browser
    echo "<div style='margin:5px 0; padding:8px; background:" . 
         ($level === 'ERROR' ? '#fee' : ($level === 'SUCCESS' ? '#efe' : '#f9f9f9')) . 
         "; border-left:4px solid " . 
         ($level === 'ERROR' ? '#e53e3e' : ($level === 'SUCCESS' ? '#10b981' : '#3b82f6')) . 
         "; font-family:monospace; font-size:12px;'>";
    echo "<strong>[{$level}]</strong> {$message}";
    echo "</div>";
    
    // Also log to file if possible
    @file_put_contents('payment_debug.log', $logMessage, FILE_APPEND);
    
    // Flush output
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
    <title>Enhanced Payment Processing Debug Tool</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1400px; margin: 0 auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 10px; margin-bottom: 20px; }
        .test-section { background: #f8f9fa; padding: 15px; margin: 15px 0; border-radius: 8px; border: 1px solid #e9ecef; }
        .test-title { font-weight: bold; color: #2d3748; margin-bottom: 10px; font-size: 16px; }
        .form-section { background: #e3f2fd; padding: 15px; margin: 15px 0; border-radius: 8px; }
        .btn { background: #3b82f6; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; margin: 5px; }
        .btn:hover { background: #2563eb; }
        .btn-danger { background: #dc2626; }
        .btn-success { background: #059669; }
        .success { color: #059669; font-weight: bold; }
        .error { color: #dc2626; font-weight: bold; }
        .warning { color: #d97706; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f1f5f9; }
        .code { background: #1f2937; color: #e5e7eb; padding: 10px; border-radius: 5px; font-family: monospace; margin: 10px 0; overflow-x: auto; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .highlight { background: #fef3c7; padding: 10px; border-radius: 5px; border: 1px solid #f59e0b; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔧 Enhanced Payment Processing Debug Tool</h1>
            <p>Comprehensive debugging for QuickBill 305 payment issues</p>
            <p><strong>Current Time:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
        </div>

        <?php
        
        debugLog("=== ENHANCED PAYMENT PROCESSING DEBUG SESSION STARTED ===", 'INFO');
        debugLog("PHP Version: " . PHP_VERSION, 'INFO');
        debugLog("Server: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'), 'INFO');
        
        // Test 1: Include required files
        echo "<div class='test-section'>";
        echo "<div class='test-title'>📁 Test 1: File Includes and Dependencies</div>";
        
        $requiredFiles = [
            '../../config/config.php',
            '../../config/database.php',
            '../../includes/functions.php',
            '../../includes/auth.php',
            '../../includes/security.php'
        ];
        
        $filesLoaded = 0;
        foreach ($requiredFiles as $file) {
            if (file_exists($file)) {
                try {
                    require_once $file;
                    debugLog("✅ Successfully loaded: {$file}", 'SUCCESS');
                    $filesLoaded++;
                } catch (Exception $e) {
                    debugLog("❌ Error loading {$file}: " . $e->getMessage(), 'ERROR');
                }
            } else {
                debugLog("❌ File not found: {$file}", 'ERROR');
            }
        }
        
        if ($filesLoaded === count($requiredFiles)) {
            debugLog("🎉 All required files loaded successfully!", 'SUCCESS');
        } else {
            debugLog("⚠️ Some files failed to load. This may cause issues.", 'WARNING');
        }
        echo "</div>";
        
        // Test 2: Database Connection
        echo "<div class='test-section'>";
        echo "<div class='test-title'>🗄️ Test 2: Database Connection</div>";
        
        try {
            $db = new Database();
            debugLog("✅ Database connection established", 'SUCCESS');
            
            // Test a simple query
            $testQuery = $db->fetchRow("SELECT COUNT(*) as count FROM users");
            if ($testQuery) {
                debugLog("✅ Database query test successful. Found {$testQuery['count']} users", 'SUCCESS');
            } else {
                debugLog("❌ Database query test failed", 'ERROR');
            }
            
            // Test database methods
            $methods = ['execute', 'fetchRow', 'fetchAll', 'beginTransaction', 'commit', 'rollback', 'lastInsertId'];
            foreach ($methods as $method) {
                if (method_exists($db, $method)) {
                    debugLog("✅ Database method '{$method}' exists", 'SUCCESS');
                } else {
                    debugLog("❌ Database method '{$method}' missing", 'ERROR');
                }
            }
            
        } catch (Exception $e) {
            debugLog("❌ Database connection failed: " . $e->getMessage(), 'ERROR');
        }
        echo "</div>";
        
        // Test 3: Payment Functions Check
        echo "<div class='test-section'>";
        echo "<div class='test-title'>🔧 Test 3: Payment Functions Availability</div>";
        
        $paymentFunctions = [
            'generatePaymentReference',
            'generateBillNumber', 
            'validatePaymentAmount',
            'processPayment',
            'getPaymentMethods',
            'sanitizeInput',
            'writeLog',
            'getCurrentUser',
            'hasPermission'
        ];
        
        foreach ($paymentFunctions as $func) {
            if (function_exists($func)) {
                debugLog("✅ Function '{$func}' exists", 'SUCCESS');
                
                // Test specific functions
                if ($func === 'generatePaymentReference') {
                    try {
                        $ref = generatePaymentReference();
                        debugLog("  ↳ Test call returned: {$ref}", 'INFO');
                    } catch (Exception $e) {
                        debugLog("  ↳ Test call failed: " . $e->getMessage(), 'ERROR');
                    }
                }
                
                if ($func === 'getPaymentMethods') {
                    try {
                        $methods = getPaymentMethods();
                        debugLog("  ↳ Found " . count($methods) . " payment methods", 'INFO');
                    } catch (Exception $e) {
                        debugLog("  ↳ Test call failed: " . $e->getMessage(), 'ERROR');
                    }
                }
                
            } else {
                debugLog("❌ Function '{$func}' not found", 'ERROR');
            }
        }
        echo "</div>";
        
        // Test 4: Database Data Check with Bills
        echo "<div class='test-section'>";
        echo "<div class='test-title'>📊 Test 4: Database Data and Bills Check</div>";
        
        if (isset($db)) {
            // Check businesses with bills
            try {
                $businessData = $db->fetchAll("
                    SELECT 
                        b.business_id, b.account_number, b.business_name, b.amount_payable,
                        bills.bill_id, bills.bill_number, bills.amount_payable as bill_amount, bills.status as bill_status
                    FROM businesses b
                    LEFT JOIN bills ON b.business_id = bills.reference_id AND bills.bill_type = 'Business' AND bills.billing_year = YEAR(NOW())
                    LIMIT 5
                ");
                
                if ($businessData) {
                    debugLog("✅ Found " . count($businessData) . " businesses", 'SUCCESS');
                    echo "<details><summary>📋 Businesses and their Bills</summary>";
                    echo "<table><tr><th>Account Number</th><th>Business Name</th><th>Amount Payable</th><th>Bill Number</th><th>Bill Amount</th><th>Bill Status</th></tr>";
                    foreach ($businessData as $business) {
                        $billStatus = $business['bill_number'] ? "✅ Has Bill" : "❌ No Bill";
                        echo "<tr>";
                        echo "<td>{$business['account_number']}</td>";
                        echo "<td>{$business['business_name']}</td>";
                        echo "<td>GHS " . number_format($business['amount_payable'], 2) . "</td>";
                        echo "<td>" . ($business['bill_number'] ?? 'No Bill') . "</td>";
                        echo "<td>" . ($business['bill_amount'] ? 'GHS ' . number_format($business['bill_amount'], 2) : 'N/A') . "</td>";
                        echo "<td style='color: " . ($business['bill_number'] ? 'green' : 'red') . ";'>{$billStatus}</td>";
                        echo "</tr>";
                    }
                    echo "</table></details>";
                }
            } catch (Exception $e) {
                debugLog("❌ Error fetching business data: " . $e->getMessage(), 'ERROR');
            }
            
            // Check properties with bills
            try {
                $propertyData = $db->fetchAll("
                    SELECT 
                        p.property_id, p.property_number, p.owner_name, p.amount_payable,
                        bills.bill_id, bills.bill_number, bills.amount_payable as bill_amount, bills.status as bill_status
                    FROM properties p
                    LEFT JOIN bills ON p.property_id = bills.reference_id AND bills.bill_type = 'Property' AND bills.billing_year = YEAR(NOW())
                    LIMIT 5
                ");
                
                if ($propertyData) {
                    debugLog("✅ Found " . count($propertyData) . " properties", 'SUCCESS');
                    echo "<details><summary>🏠 Properties and their Bills</summary>";
                    echo "<table><tr><th>Property Number</th><th>Owner Name</th><th>Amount Payable</th><th>Bill Number</th><th>Bill Amount</th><th>Bill Status</th></tr>";
                    foreach ($propertyData as $property) {
                        $billStatus = $property['bill_number'] ? "✅ Has Bill" : "❌ No Bill";
                        echo "<tr>";
                        echo "<td>{$property['property_number']}</td>";
                        echo "<td>{$property['owner_name']}</td>";
                        echo "<td>GHS " . number_format($property['amount_payable'], 2) . "</td>";
                        echo "<td>" . ($property['bill_number'] ?? 'No Bill') . "</td>";
                        echo "<td>" . ($property['bill_amount'] ? 'GHS ' . number_format($property['bill_amount'], 2) : 'N/A') . "</td>";
                        echo "<td style='color: " . ($property['bill_number'] ? 'green' : 'red') . ";'>{$billStatus}</td>";
                        echo "</tr>";
                    }
                    echo "</table></details>";
                }
            } catch (Exception $e) {
                debugLog("❌ Error fetching property data: " . $e->getMessage(), 'ERROR');
            }
        }
        echo "</div>";
        
        // Test 5: Enhanced Form Processing
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            echo "<div class='test-section'>";
            echo "<div class='test-title'>📝 Test 5: Form Data Processing Results</div>";
            
            debugLog("=== FORM SUBMISSION DETECTED ===", 'INFO');
            debugLog("POST Data: " . json_encode($_POST, JSON_PRETTY_PRINT), 'INFO');
            
            if (isset($_POST['test_account_search'])) {
                $accountNumber = trim($_POST['account_number'] ?? '');
                $accountType = trim($_POST['account_type'] ?? '');
                
                debugLog("Testing account search for: {$accountNumber} ({$accountType})", 'INFO');
                
                if (empty($accountNumber) || empty($accountType)) {
                    debugLog("❌ Account number or type is empty", 'ERROR');
                } else {
                    try {
                        if ($accountType === 'Business') {
                            // Test the exact query from the payment file
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
                                debugLog("✅ Business account found: " . $accountData['business_name'], 'SUCCESS');
                                echo "<div class='highlight'><strong>Account Data:</strong><br>";
                                echo "<div class='code'>" . json_encode($accountData, JSON_PRETTY_PRINT) . "</div></div>";
                                
                                // Check for bills
                                $currentYear = date('Y');
                                $billData = $db->fetchRow("
                                    SELECT * FROM bills 
                                    WHERE bill_type = 'Business' AND reference_id = ? AND billing_year = ?
                                ", [$accountData['business_id'], $currentYear]);
                                
                                if ($billData) {
                                    debugLog("✅ Bill found for business: " . $billData['bill_number'], 'SUCCESS');
                                    echo "<div class='highlight'><strong>Bill Data:</strong><br>";
                                    echo "<div class='code'>" . json_encode($billData, JSON_PRETTY_PRINT) . "</div></div>";
                                } else {
                                    debugLog("❌ No bill found for business in year {$currentYear}", 'ERROR');
                                    debugLog("This is why payment form doesn't appear!", 'WARNING');
                                }
                            } else {
                                debugLog("❌ Business account not found", 'ERROR');
                            }
                            
                        } elseif ($accountType === 'Property') {
                            $accountData = $db->fetchRow("
                                SELECT 
                                    p.*,
                                    z.zone_name,
                                    pfs.fee_per_room
                                FROM properties p
                                LEFT JOIN zones z ON p.zone_id = z.zone_id
                                LEFT JOIN property_fee_structure pfs ON p.structure = pfs.structure AND p.property_use = pfs.property_use
                                WHERE p.property_number = ?
                            ", [$accountNumber]);
                            
                            if ($accountData) {
                                debugLog("✅ Property account found: " . $accountData['owner_name'], 'SUCCESS');
                                echo "<div class='highlight'><strong>Account Data:</strong><br>";
                                echo "<div class='code'>" . json_encode($accountData, JSON_PRETTY_PRINT) . "</div></div>";
                                
                                // Check for bills
                                $currentYear = date('Y');
                                $billData = $db->fetchRow("
                                    SELECT * FROM bills 
                                    WHERE bill_type = 'Property' AND reference_id = ? AND billing_year = ?
                                ", [$accountData['property_id'], $currentYear]);
                                
                                if ($billData) {
                                    debugLog("✅ Bill found for property: " . $billData['bill_number'], 'SUCCESS');
                                    echo "<div class='highlight'><strong>Bill Data:</strong><br>";
                                    echo "<div class='code'>" . json_encode($billData, JSON_PRETTY_PRINT) . "</div></div>";
                                } else {
                                    debugLog("❌ No bill found for property in year {$currentYear}", 'ERROR');
                                    debugLog("This is why payment form doesn't appear!", 'WARNING');
                                }
                            } else {
                                debugLog("❌ Property account not found", 'ERROR');
                            }
                        }
                        
                    } catch (Exception $e) {
                        debugLog("❌ Error during account search: " . $e->getMessage(), 'ERROR');
                        debugLog("Stack trace: " . $e->getTraceAsString(), 'ERROR');
                    }
                }
            }
            
            if (isset($_POST['test_complete_payment_flow'])) {
                debugLog("=== TESTING COMPLETE PAYMENT FLOW ===", 'INFO');
                
                $accountNumber = trim($_POST['flow_account_number'] ?? '');
                $accountType = trim($_POST['flow_account_type'] ?? '');
                $amount = floatval($_POST['flow_amount'] ?? 0);
                $paymentMethod = trim($_POST['flow_payment_method'] ?? '');
                
                if (empty($accountNumber) || empty($accountType) || $amount <= 0 || empty($paymentMethod)) {
                    debugLog("❌ Missing required data for payment flow test", 'ERROR');
                } else {
                    try {
                        // Step 1: Find account
                        debugLog("Step 1: Finding account...", 'INFO');
                        
                        if ($accountType === 'Business') {
                            $accountData = $db->fetchRow("SELECT * FROM businesses WHERE account_number = ?", [$accountNumber]);
                        } else {
                            $accountData = $db->fetchRow("SELECT * FROM properties WHERE property_number = ?", [$accountNumber]);
                        }
                        
                        if (!$accountData) {
                            debugLog("❌ Account not found", 'ERROR');
                        } else {
                            debugLog("✅ Account found", 'SUCCESS');
                            
                            // Step 2: Find bill
                            debugLog("Step 2: Finding bill...", 'INFO');
                            $currentYear = date('Y');
                            $referenceId = $accountType === 'Business' ? $accountData['business_id'] : $accountData['property_id'];
                            
                            $billData = $db->fetchRow("
                                SELECT * FROM bills 
                                WHERE bill_type = ? AND reference_id = ? AND billing_year = ?
                            ", [$accountType, $referenceId, $currentYear]);
                            
                            if (!$billData) {
                                debugLog("❌ No bill found for {$accountType} in year {$currentYear}", 'ERROR');
                                debugLog("SOLUTION: Generate bills first!", 'WARNING');
                            } else {
                                debugLog("✅ Bill found: " . $billData['bill_number'], 'SUCCESS');
                                
                                // Step 3: Test payment processing
                                debugLog("Step 3: Testing payment processing...", 'INFO');
                                
                                if ($amount > floatval($billData['amount_payable'])) {
                                    debugLog("❌ Amount ({$amount}) exceeds bill amount (" . $billData['amount_payable'] . ")", 'ERROR');
                                } else {
                                    debugLog("✅ Amount validation passed", 'SUCCESS');
                                    
                                    // Test the processPayment function
                                    if (function_exists('processPayment')) {
                                        $paymentData = [
                                            'amount_paid' => $amount,
                                            'payment_method' => $paymentMethod,
                                            'payment_channel' => 'Test Channel',
                                            'transaction_id' => 'TEST' . time(),
                                            'notes' => 'Debug test payment',
                                            'processed_by' => 1,
                                            'account_type' => $accountType
                                        ];
                                        
                                        debugLog("Calling processPayment function...", 'INFO');
                                        $result = processPayment($paymentData, $billData, $accountData);
                                        
                                        if ($result['success']) {
                                            debugLog("✅ Payment processing test successful!", 'SUCCESS');
                                            debugLog("Payment Reference: " . $result['payment_reference'], 'SUCCESS');
                                        } else {
                                            debugLog("❌ Payment processing failed: " . $result['error'], 'ERROR');
                                        }
                                    } else {
                                        debugLog("❌ processPayment function not found", 'ERROR');
                                    }
                                }
                            }
                        }
                        
                    } catch (Exception $e) {
                        debugLog("❌ Error during complete flow test: " . $e->getMessage(), 'ERROR');
                        debugLog("Stack trace: " . $e->getTraceAsString(), 'ERROR');
                    }
                }
            }
            
            echo "</div>";
        }
        
        ?>
        
        <!-- Enhanced Test Forms -->
        <div class="grid">
            <div class="form-section">
                <h3>🧪 Quick Account Search Test</h3>
                <form method="POST" style="margin: 15px 0;">
                    <select name="account_type" required style="padding: 8px; margin: 5px; width: 45%;">
                        <option value="">Select Type</option>
                        <option value="Business">Business</option>
                        <option value="Property">Property</option>
                    </select>
                    <input type="text" name="account_number" placeholder="Account Number" required style="padding: 8px; margin: 5px; width: 45%;">
                    <br>
                    <button type="submit" name="test_account_search" class="btn">🔍 Test Account Search</button>
                </form>
            </div>
            
            <div class="form-section">
                <h3>💳 Complete Payment Flow Test</h3>
                <form method="POST" style="margin: 15px 0;">
                    <select name="flow_account_type" required style="padding: 8px; margin: 5px; width: 100%;">
                        <option value="">Select Type</option>
                        <option value="Business">Business</option>
                        <option value="Property">Property</option>
                    </select>
                    <input type="text" name="flow_account_number" placeholder="Account Number" required style="padding: 8px; margin: 5px; width: 100%;">
                    <input type="number" name="flow_amount" placeholder="Amount" step="0.01" required style="padding: 8px; margin: 5px; width: 100%;">
                    <select name="flow_payment_method" required style="padding: 8px; margin: 5px; width: 100%;">
                        <option value="">Select Method</option>
                        <option value="Cash">Cash</option>
                        <option value="Mobile Money">Mobile Money</option>
                        <option value="Bank Transfer">Bank Transfer</option>
                    </select>
                    <br>
                    <button type="submit" name="test_complete_payment_flow" class="btn btn-success">🚀 Test Complete Payment Flow</button>
                </form>
            </div>
        </div>
        
        <?php
        debugLog("=== DEBUG SESSION COMPLETED ===", 'INFO');
        ?>
        
        <div style="margin-top: 30px; padding: 15px; background: #f1f5f9; border-radius: 8px;">
            <h3>📋 Quick Reference</h3>
            <p><strong>From your database schema, try these account numbers:</strong></p>
            <ul>
                <li><strong>Business:</strong> BIZ000001 (KabTech Consulting)</li>
                <li><strong>Property:</strong> PROP000001 (Joojo's property)</li>
            </ul>
            
            <h4>🔧 Common Issues & Solutions:</h4>
            <ul>
                <li><strong>No bills found:</strong> Generate bills first using the billing module</li>
                <li><strong>Functions not found:</strong> Update functions.php with payment functions</li>
                <li><strong>Database errors:</strong> Check database connection and permissions</li>
                <li><strong>Amount validation fails:</strong> Ensure amount is less than bill amount</li>
            </ul>
        </div>
    </div>
</body>
</html>