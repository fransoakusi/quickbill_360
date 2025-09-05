<?php
/**
 * Simple Test for Admin Directory
 */

// Define application constant
define('QUICKBILL_305', true);

echo "<h1>Admin Test Page</h1>";

// Test 1: Basic PHP
echo "<p>✅ PHP is working</p>";

// Test 2: Try to include config
try {
    require_once '../config/config.php';
    echo "<p>✅ Config loaded</p>";
} catch (Exception $e) {
    echo "<p>❌ Config error: " . $e->getMessage() . "</p>";
}

// Test 3: Try to include database
try {
    require_once '../config/database.php';
    echo "<p>✅ Database class loaded</p>";
} catch (Exception $e) {
    echo "<p>❌ Database error: " . $e->getMessage() . "</p>";
}

// Test 4: Try to include functions
try {
    require_once '../includes/functions.php';
    echo "<p>✅ Functions loaded</p>";
} catch (Exception $e) {
    echo "<p>❌ Functions error: " . $e->getMessage() . "</p>";
}

// Test 5: Start session
try {
    session_start();
    echo "<p>✅ Session started</p>";
} catch (Exception $e) {
    echo "<p>❌ Session error: " . $e->getMessage() . "</p>";
}

// Test 6: Try auth
try {
    require_once '../includes/auth.php';
    echo "<p>✅ Auth loaded</p>";
} catch (Exception $e) {
    echo "<p>❌ Auth error: " . $e->getMessage() . "</p>";
}

echo "<p><a href='simple.php'>Try Simple Dashboard</a></p>";
?>