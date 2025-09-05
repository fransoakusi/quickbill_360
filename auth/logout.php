 <?php
/**
 * Logout Handler for QUICKBILL 305
 * Handles user logout and session cleanup
 */

// Define application constant
define('QUICKBILL_305', true);

// Include configuration files
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Start session
session_start();

// Include auth and security
require_once '../includes/auth.php';
require_once '../includes/security.php';

// Initialize auth and security
initAuth();
initSecurity();

// Perform logout
logout();

// Redirect to login page with success message
header('Location: login.php?success=logout');
exit();
?>
