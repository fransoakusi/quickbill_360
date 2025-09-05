<?php
/**
 * Authentication & Payment Fix
 * Step-by-step solution to fix the payment authentication issue
 */

// Define application constant
define('QUICKBILL_305', true);

// Include required files
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/auth.php';

// Start session FIRST
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Initialize authentication
initAuth();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Authentication & Payment Fix</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1000px; margin: 0 auto; background: white; padding: 20px; border-radius: 10px; }
        .header { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; padding: 20px; border-radius: 10px; margin-bottom: 20px; }
        .section { background: #f8f9fa; padding: 15px; margin: 15px 0; border-radius: 8px; border: 1px solid #e9ecef; }
        .success { color: #059669; font-weight: bold; }
        .error { color: #dc2626; font-weight: bold; }
        .warning { color: #d97706; font-weight: bold; }
        .info { color: #3b82f6; font-weight: bold; }
        .btn { background: #3b82f6; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; margin: 5px; text-decoration: none; display: inline-block; }
        .btn-success { background: #10b981; }
        .btn-warning { background: #f59e0b; }
        .step { background: #e0f2fe; padding: 15px; margin: 10px 0; border-left: 4px solid #0284c7; border-radius: 5px; }
        .code { background: #1f2937; color: #e5e7eb; padding: 10px; border-radius: 5px; font-family: monospace; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔧 Authentication & Payment System Fix</h1>
            <p>Complete solution to fix your payment processing authentication issues</p>
        </div>

        <?php
        
        echo "<div class='section'>";
        echo "<h3>🔍 Current Authentication Status</h3>";
        
        // Check current authentication status
        $isLoggedIn = isLoggedIn();
        $currentUser = getCurrentUser();
        $currentRole = getCurrentUserRole();
        $hasPaymentPerm = hasPermission('payments.create');
        
        echo "<p><strong>Login Status:</strong> " . ($isLoggedIn ? "<span class='success'>✅ Logged In</span>" : "<span class='error'>❌ Not Logged In</span>") . "</p>";
        
        if ($currentUser) {
            echo "<p><strong>Current User:</strong> <span class='info'>" . htmlspecialchars($currentUser['username']) . " (" . htmlspecialchars($currentUser['first_name'] . ' ' . $currentUser['last_name']) . ")</span></p>";
            echo "<p><strong>User Role:</strong> <span class='info'>" . htmlspecialchars($currentRole) . "</span></p>";
            echo "<p><strong>Payment Permission:</strong> " . ($hasPaymentPerm ? "<span class='success'>✅ Has payments.create</span>" : "<span class='error'>❌ No payments.create permission</span>") . "</p>";
        } else {
            echo "<p><strong>User Data:</strong> <span class='error'>❌ No user data available</span></p>";
        }
        
        echo "</div>";
        
        // Show solution steps
        if (!$isLoggedIn) {
            echo "<div class='section'>";
            echo "<h3>🚨 Problem Identified: Not Logged In</h3>";
            echo "<p>You need to log into the QuickBill system first before you can record payments.</p>";
            
            echo "<div class='step'>";
            echo "<h4>Step 1: Log Into the System</h4>";
            echo "<p>Go to your login page and log in with valid credentials:</p>";
            
            // Try to find users in the database
            try {
                $db = new Database();
                $users = $db->fetchAll("SELECT user_id, username, email, role_id, first_name, last_name, is_active FROM users WHERE is_active = 1 ORDER BY role_id ASC LIMIT 5");
                
                if ($users) {
                    echo "<h5>Available Users in Your Database:</h5>";
                    echo "<table style='width: 100%; border-collapse: collapse; margin: 10px 0;'>";
                    echo "<tr style='background: #f1f5f9;'><th style='border: 1px solid #ddd; padding: 8px;'>Username</th><th style='border: 1px solid #ddd; padding: 8px;'>Email</th><th style='border: 1px solid #ddd; padding: 8px;'>Role ID</th><th style='border: 1px solid #ddd; padding: 8px;'>Name</th></tr>";
                    
                    foreach ($users as $user) {
                        echo "<tr>";
                        echo "<td style='border: 1px solid #ddd; padding: 8px;'><strong>" . htmlspecialchars($user['username']) . "</strong></td>";
                        echo "<td style='border: 1px solid #ddd; padding: 8px;'>" . htmlspecialchars($user['email']) . "</td>";
                        echo "<td style='border: 1px solid #ddd; padding: 8px;'>" . htmlspecialchars($user['role_id']) . "</td>";
                        echo "<td style='border: 1px solid #ddd; padding: 8px;'>" . htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) . "</td>";
                        echo "</tr>";
                    }
                    echo "</table>";
                    
                    echo "<p><strong>Roles with Payment Permissions:</strong></p>";
                    echo "<ul>";
                    echo "<li>Role ID 1: <strong>Super Admin</strong> - Full access</li>";
                    echo "<li>Role ID 2: <strong>Admin</strong> - Full access including payments.create</li>";
                    echo "<li>Role ID 3: <strong>Officer</strong> - Can create payments</li>";
                    echo "<li>Role ID 4: <strong>Revenue Officer</strong> - Can create payments</li>";
                    echo "<li>Role ID 5: <strong>Data Collector</strong> - No payment permissions</li>";
                    echo "</ul>";
                    
                } else {
                    echo "<p class='error'>❌ No active users found in database</p>";
                }
                
            } catch (Exception $e) {
                echo "<p class='error'>❌ Error checking users: " . htmlspecialchars($e->getMessage()) . "</p>";
            }
            
            // Provide login link
            $loginUrl = BASE_URL . '/auth/login.php';
            echo "<p><a href='{$loginUrl}' class='btn btn-success'>🔑 Go to Login Page</a></p>";
            echo "</div>";
            
            echo "</div>";
            
        } elseif (!$hasPaymentPerm) {
            echo "<div class='section'>";
            echo "<h3>🚨 Problem Identified: No Payment Permission</h3>";
            echo "<p>You are logged in as <strong>" . htmlspecialchars($currentRole) . "</strong>, but this role doesn't have payment creation permissions.</p>";
            
            echo "<div class='step'>";
            echo "<h4>Solution: Use a Different Account</h4>";
            echo "<p>You need to log in with an account that has one of these roles:</p>";
            echo "<ul>";
            echo "<li><strong>Super Admin</strong> - Full access</li>";
            echo "<li><strong>Admin</strong> - Full access</li>";
            echo "<li><strong>Officer</strong> - Can record payments</li>";
            echo "<li><strong>Revenue Officer</strong> - Can record payments</li>";
            echo "</ul>";
            
            $logoutUrl = BASE_URL . '/auth/logout.php';
            $loginUrl = BASE_URL . '/auth/login.php';
            echo "<p><a href='{$logoutUrl}' class='btn btn-warning'>🚪 Logout Current User</a> ";
            echo "<a href='{$loginUrl}' class='btn btn-success'>🔑 Login with Different Account</a></p>";
            echo "</div>";
            
            echo "</div>";
            
        } else {
            echo "<div class='section'>";
            echo "<h3>✅ Authentication Status: GOOD!</h3>";
            echo "<p>You are properly logged in with payment permissions. Payment recording should work now!</p>";
            
            echo "<div class='step'>";
            echo "<h4>Next Steps:</h4>";
            echo "<ol>";
            echo "<li>Go to the payment recording page</li>";
            echo "<li>Search for an account (Business or Property)</li>";
            echo "<li>Make sure the account has a bill generated for " . date('Y') . "</li>";
            echo "<li>Process the payment</li>";
            echo "</ol>";
            
            // Check if we can access the payment page
            $paymentUrl = 'record.php';
            echo "<p><a href='{$paymentUrl}' class='btn btn-success'>💳 Go to Payment Recording</a></p>";
            echo "</div>";
            
            // Test bill availability
            echo "<div class='step'>";
            echo "<h4>📋 Check Bills Availability</h4>";
            
            try {
                $db = new Database();
                $currentYear = date('Y');
                
                // Check businesses with bills
                $businessBills = $db->fetchAll("
                    SELECT b.business_name, b.account_number, bills.bill_number, bills.amount_payable 
                    FROM businesses b 
                    INNER JOIN bills ON b.business_id = bills.reference_id 
                    WHERE bills.bill_type = 'Business' AND bills.billing_year = ? AND bills.amount_payable > 0
                    LIMIT 3
                ", [$currentYear]);
                
                // Check properties with bills
                $propertyBills = $db->fetchAll("
                    SELECT p.owner_name, p.property_number, bills.bill_number, bills.amount_payable 
                    FROM properties p 
                    INNER JOIN bills ON p.property_id = bills.reference_id 
                    WHERE bills.bill_type = 'Property' AND bills.billing_year = ? AND bills.amount_payable > 0
                    LIMIT 3
                ", [$currentYear]);
                
                if ($businessBills || $propertyBills) {
                    echo "<p class='success'>✅ Found accounts with bills that can accept payments:</p>";
                    
                    if ($businessBills) {
                        echo "<h5>Businesses with Bills:</h5>";
                        echo "<ul>";
                        foreach ($businessBills as $bill) {
                            echo "<li><strong>" . htmlspecialchars($bill['account_number']) . "</strong> - " . htmlspecialchars($bill['business_name']) . " (GHS " . number_format($bill['amount_payable'], 2) . " outstanding)</li>";
                        }
                        echo "</ul>";
                    }
                    
                    if ($propertyBills) {
                        echo "<h5>Properties with Bills:</h5>";
                        echo "<ul>";
                        foreach ($propertyBills as $bill) {
                            echo "<li><strong>" . htmlspecialchars($bill['property_number']) . "</strong> - " . htmlspecialchars($bill['owner_name']) . " (GHS " . number_format($bill['amount_payable'], 2) . " outstanding)</li>";
                        }
                        echo "</ul>";
                    }
                    
                } else {
                    echo "<p class='warning'>⚠️ No bills found for {$currentYear}. You need to generate bills first!</p>";
                    echo "<p>Go to Billing → Generate Bills to create bills for businesses and properties.</p>";
                }
                
            } catch (Exception $e) {
                echo "<p class='error'>❌ Error checking bills: " . htmlspecialchars($e->getMessage()) . "</p>";
            }
            
            echo "</div>";
            
            echo "</div>";
        }
        
        // Provide quick login form if not logged in
        if (!$isLoggedIn) {
            echo "<div class='section'>";
            echo "<h3>🔑 Quick Login (For Testing)</h3>";
            echo "<p>Try logging in with one of your admin accounts:</p>";
            
            echo "<form method='POST' action='../../auth/login.php' style='margin: 15px 0;'>";
            echo "<div style='margin: 10px 0;'>";
            echo "<label>Username:</label><br>";
            echo "<input type='text' name='username' value='admin' style='padding: 8px; width: 200px; margin: 5px 0;'>";
            echo "</div>";
            echo "<div style='margin: 10px 0;'>";
            echo "<label>Password:</label><br>";
            echo "<input type='password' name='password' style='padding: 8px; width: 200px; margin: 5px 0;'>";
            echo "</div>";
            echo "<button type='submit' class='btn btn-success'>🔑 Login</button>";
            echo "</form>";
            
            echo "<p><small>Try common usernames from your database: <code>admin</code>, <code>Joojo</code>, etc.</small></p>";
            echo "</div>";
        }
        
        ?>
        
        <div class='section'>
            <h3>📋 Summary & Next Steps</h3>
            
            <?php if (!$isLoggedIn): ?>
                <div class='step'>
                    <h4>🔴 Action Required: Login First</h4>
                    <ol>
                        <li>Click the "Go to Login Page" button above</li>
                        <li>Login with an admin account (admin, Joojo, etc.)</li>
                        <li>Make sure you use an account with payment permissions</li>
                        <li>Come back and test payment recording</li>
                    </ol>
                </div>
            <?php elseif (!$hasPaymentPerm): ?>
                <div class='step'>
                    <h4>🟡 Action Required: Different Account Needed</h4>
                    <ol>
                        <li>Logout of current account</li>
                        <li>Login with Admin, Officer, or Revenue Officer account</li>
                        <li>Try payment recording again</li>
                    </ol>
                </div>
            <?php else: ?>
                <div class='step'>
                    <h4>🟢 All Good! Payment System Should Work</h4>
                    <ol>
                        <li>Go to payment recording page</li>
                        <li>Use the account numbers shown above (they have bills)</li>
                        <li>Process payments normally</li>
                        <li>If it still doesn't work, there might be a different issue</li>
                    </ol>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>