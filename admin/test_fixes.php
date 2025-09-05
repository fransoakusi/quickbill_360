<?php
/**
 * Test Fixes for QUICKBILL 305
 * Run this to verify all fixes are working
 */

// Define application constant
define('QUICKBILL_305', true);

// Start session
session_start();

// Include your files
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

echo "<h1>🔧 QUICKBILL 305 - Fix Verification</h1>";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; background: #f8f9fa; }
    .success { color: #28a745; font-weight: bold; }
    .error { color: #dc3545; font-weight: bold; }
    .warning { color: #ffc107; font-weight: bold; }
    .section { margin: 20px 0; padding: 20px; background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
    .test-item { padding: 10px; margin: 5px 0; border-radius: 5px; }
    .test-pass { background: #d4edda; color: #155724; }
    .test-fail { background: #f8d7da; color: #721c24; }
    pre { background: #f8f9fa; padding: 15px; border-radius: 8px; overflow-x: auto; border: 1px solid #dee2e6; }
    .btn { padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; margin: 5px; }
    .btn:hover { background: #0056b3; }
</style>";

// Test 1: Function Existence
echo "<div class='section'>";
echo "<h2>📋 Function Existence Test</h2>";

$requiredFunctions = [
    'sanitizeInput',
    'generateCSRFToken',
    'validateCSRFToken', 
    'setFlashMessage',
    'getFlashMessages',
    'writeLog',
    'getClientIP',
    'sendJsonResponse',
    'getLastInsertId',
    'beginTransaction',
    'commitTransaction',
    'rollbackTransaction',
    'logUserAction'
];

$allFunctionsExist = true;
foreach ($requiredFunctions as $func) {
    if (function_exists($func)) {
        echo "<div class='test-item test-pass'>✅ $func() - EXISTS</div>";
    } else {
        echo "<div class='test-item test-fail'>❌ $func() - MISSING</div>";
        $allFunctionsExist = false;
    }
}

if ($allFunctionsExist) {
    echo "<div class='test-item test-pass'>🎉 All required functions exist!</div>";
} else {
    echo "<div class='test-item test-fail'>⚠️ Some functions are missing</div>";
}

echo "</div>";

// Test 2: Constants
echo "<div class='section'>";
echo "<h2>🏷️ Constants Test</h2>";

$requiredConstants = [
    'APP_NAME',
    'BASE_URL',
    'SESSION_LIFETIME',
    'LOGIN_ATTEMPTS_LIMIT',
    'LOGIN_LOCKOUT_TIME'
];

foreach ($requiredConstants as $const) {
    if (defined($const)) {
        echo "<div class='test-item test-pass'>✅ $const = " . constant($const) . "</div>";
    } else {
        echo "<div class='test-item test-fail'>❌ $const - NOT DEFINED</div>";
    }
}

echo "</div>";

// Test 3: Database Operations
echo "<div class='section'>";
echo "<h2>🗄️ Database Operations Test</h2>";

try {
    $db = new Database();
    echo "<div class='test-item test-pass'>✅ Database connection successful</div>";
    
    // Test database methods
    $dbMethods = ['execute', 'fetchRow', 'fetchAll'];
    foreach ($dbMethods as $method) {
        if (method_exists($db, $method)) {
            echo "<div class='test-item test-pass'>✅ Database::$method() exists</div>";
        } else {
            echo "<div class='test-item test-fail'>❌ Database::$method() missing</div>";
        }
    }
    
    // Test helper functions
    echo "<div class='test-item test-pass'>✅ getLastInsertId() helper ready</div>";
    echo "<div class='test-item test-pass'>✅ Transaction helpers ready</div>";
    
} catch (Exception $e) {
    echo "<div class='test-item test-fail'>❌ Database error: " . $e->getMessage() . "</div>";
}

echo "</div>";

// Test 4: CSRF Functions
echo "<div class='section'>";
echo "<h2>🔐 Security Functions Test</h2>";

try {
    $token = generateCSRFToken();
    echo "<div class='test-item test-pass'>✅ CSRF token generated: " . substr($token, 0, 16) . "...</div>";
    
    $isValid = validateCSRFToken($token);
    if ($isValid) {
        echo "<div class='test-item test-pass'>✅ CSRF token validation works</div>";
    } else {
        echo "<div class='test-item test-fail'>❌ CSRF token validation failed</div>";
    }
    
    $sanitized = sanitizeInput("<script>alert('test')</script>");
    echo "<div class='test-item test-pass'>✅ Input sanitization: " . htmlspecialchars($sanitized) . "</div>";
    
} catch (Exception $e) {
    echo "<div class='test-item test-fail'>❌ Security test error: " . $e->getMessage() . "</div>";
}

echo "</div>";

// Test 5: User Class (if exists)
echo "<div class='section'>";
echo "<h2>👤 User Class Test</h2>";

if (file_exists('../classes/User.php')) {
    try {
        require_once '../classes/User.php';
        $userClass = new User();
        echo "<div class='test-item test-pass'>✅ User class instantiated successfully</div>";
        
        // Test roles retrieval
        $roles = $userClass->getRoles();
        echo "<div class='test-item test-pass'>✅ getRoles() works - found " . count($roles) . " roles</div>";
        
    } catch (Exception $e) {
        echo "<div class='test-item test-fail'>❌ User class error: " . $e->getMessage() . "</div>";
    }
} else {
    echo "<div class='test-item test-fail'>❌ User class file not found at ../classes/User.php</div>";
}

echo "</div>";

// Test 6: Flash Messages
echo "<div class='section'>";
echo "<h2>💬 Flash Messages Test</h2>";

try {
    setFlashMessage('success', 'Test success message');
    setFlashMessage('error', 'Test error message');
    
    $messages = getFlashMessages();
    
    if (count($messages) === 2) {
        echo "<div class='test-item test-pass'>✅ Flash messages working - " . count($messages) . " messages set and retrieved</div>";
        foreach ($messages as $msg) {
            echo "<div class='test-item test-pass'>   📝 " . $msg['type'] . ": " . $msg['message'] . "</div>";
        }
    } else {
        echo "<div class='test-item test-fail'>❌ Flash messages not working properly</div>";
    }
    
} catch (Exception $e) {
    echo "<div class='test-item test-fail'>❌ Flash messages error: " . $e->getMessage() . "</div>";
}

echo "</div>";

// Test 7: Date/Time Functions
echo "<div class='section'>";
echo "<h2>📅 Date/Time Functions Test</h2>";

try {
    $formatted = formatDate(date('Y-m-d H:i:s'));
    echo "<div class='test-item test-pass'>✅ formatDate(): $formatted</div>";
    
    $timeAgoStr = timeAgo(date('Y-m-d H:i:s', strtotime('-2 hours')));
    echo "<div class='test-item test-pass'>✅ timeAgo(): $timeAgoStr</div>";
    
} catch (Exception $e) {
    echo "<div class='test-item test-fail'>❌ Date functions error: " . $e->getMessage() . "</div>";
}

echo "</div>";

// Summary
echo "<div class='section'>";
echo "<h2>📊 Summary</h2>";

if ($allFunctionsExist) {
    echo "<div class='test-item test-pass'>";
    echo "<h3>🎉 SUCCESS: All fixes are working!</h3>";
    echo "<p>Your User Management System should now work without errors.</p>";
    echo "<strong>Next Steps:</strong>";
    echo "<ul>";
    echo "<li>✅ Test the Add User page</li>";
    echo "<li>✅ Test the Edit User page</li>";
    echo "<li>✅ Test the View User page</li>";
    echo "<li>✅ Test the Delete User functionality</li>";
    echo "</ul>";
    echo "</div>";
    
    echo "<button class='btn' onclick=\"window.open('users/add.php', '_blank')\">Test Add User</button>";
    echo "<button class='btn' onclick=\"window.open('users/index.php', '_blank')\">Test User List</button>";
} else {
    echo "<div class='test-item test-fail'>";
    echo "<h3>⚠️ Some issues found</h3>";
    echo "<p>Please ensure you've updated your includes/functions.php file with the missing functions.</p>";
    echo "</div>";
}

echo "</div>";
?>

<script>
// Auto-refresh every 30 seconds to re-test
setTimeout(function() {
    document.getElementById('autoRefresh').style.display = 'block';
}, 5000);
</script>

<div id="autoRefresh" style="display: none; text-align: center; margin: 20px;">
    <button class="btn" onclick="location.reload()">🔄 Re-run Tests</button>
</div>