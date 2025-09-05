<?php
/**
 * QUICKBILL 305 System Diagnostic Script
 * Save this as: diagnostic.php in your project root
 * Run via browser: http://yoursite.com/diagnostic.php
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<!DOCTYPE html>
<html>
<head>
    <title>QUICKBILL 305 System Diagnostic</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { text-align: center; margin-bottom: 30px; color: #333; }
        .section { margin-bottom: 30px; padding: 20px; border: 1px solid #ddd; border-radius: 8px; }
        .section h3 { color: #667eea; margin-top: 0; border-bottom: 2px solid #667eea; padding-bottom: 10px; }
        .check { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; }
        .check:last-child { border-bottom: none; }
        .status { font-weight: bold; }
        .ok { color: #10b981; }
        .error { color: #ef4444; }
        .warning { color: #f59e0b; }
        .info { color: #3b82f6; }
        .code { background: #f8f9fa; padding: 10px; border-radius: 5px; font-family: monospace; margin: 10px 0; }
        .recommendation { background: #fef3c7; padding: 15px; border-radius: 5px; margin: 10px 0; border-left: 4px solid #f59e0b; }
    </style>
</head>
<body>
<div class='container'>
<div class='header'>
    <h1>🔍 QUICKBILL 305 System Diagnostic</h1>
    <p>Comprehensive system health check for troubleshooting</p>
</div>";

$errors = [];
$warnings = [];
$recommendations = [];

// 1. PHP Environment Check
echo "<div class='section'>
<h3>1. PHP Environment</h3>";

$php_version = phpversion();
$php_ok = version_compare($php_version, '7.4.0', '>=');
echo "<div class='check'>
    <span>PHP Version: $php_version</span>
    <span class='status " . ($php_ok ? "ok'>" . "✅ OK" : "warning'>" . "⚠️ OLD") . "</span>
</div>";

if (!$php_ok) {
    $warnings[] = "PHP version is below 7.4. Consider upgrading for better security and performance.";
}

$required_extensions = ['mysqli', 'pdo', 'pdo_mysql', 'gd', 'mbstring', 'json', 'curl', 'openssl'];
foreach ($required_extensions as $ext) {
    $loaded = extension_loaded($ext);
    echo "<div class='check'>
        <span>Extension: $ext</span>
        <span class='status " . ($loaded ? "ok'>✅ Loaded" : "error'>❌ Missing") . "</span>
    </div>";
    
    if (!$loaded) {
        $errors[] = "Missing PHP extension: $ext";
    }
}

echo "</div>";

// 2. File Structure Check
echo "<div class='section'>
<h3>2. File Structure</h3>";

$project_root = __DIR__;
$required_files = [
    'config/config.php' => 'Configuration file',
    'config/database.php' => 'Database configuration',
    'includes/functions.php' => 'Core functions',
    'includes/auth.php' => 'Authentication functions',
    'includes/security.php' => 'Security functions',
    'classes/Database.php' => 'Database class',
    'vendor/autoload.php' => 'Composer autoload',
    'assets/qr_codes/' => 'QR codes directory'
];

foreach ($required_files as $file => $description) {
    $path = $project_root . '/' . $file;
    $exists = file_exists($path);
    $is_dir = is_dir($path);
    
    echo "<div class='check'>
        <span>$description ($file)</span>";
    
    if ($exists) {
        if ($is_dir) {
            $writable = is_writable($path);
            echo $writable
                ? "<span class='status ok'>✅ Exists & Writable</span>"
                : "<span class='status warning'>⚠️ Exists but Not Writable</span>";
            if (!$writable) {
                $warnings[] = "Directory not writable: $file";
            }
        } else {
            echo "<span class='status ok'>✅ Exists</span>";
        }
    } else {
        echo "<span class='status error'>❌ Missing</span>";
        $errors[] = "Missing file/directory: $file";
    }
    
    echo "</div>";
}

echo "</div>";

// 3. Configuration Test
echo "<div class='section'>
<h3>3. Configuration Files</h3>";

// Test config.php
$config_path = $project_root . '/config/config.php';
if (file_exists($config_path)) {
    try {
        require_once $config_path;
        echo "<div class='check'>
            <span>config.php loading</span>
            <span class='status ok'>✅ Loaded successfully</span>
        </div>";
        
        // Check if APP_NAME is defined
        if (defined('APP_NAME')) {
            echo "<div class='check'>
                <span>APP_NAME constant</span>
                <span class='status ok'>✅ Defined: " . APP_NAME . "</span>
            </div>";
        } else {
            echo "<div class='check'>
                <span>APP_NAME constant</span>
                <span class='status warning'>⚠️ Not defined</span>
            </div>";
            $warnings[] = "APP_NAME constant not defined in config.php";
        }
        
    } catch (Exception $e) {
        echo "<div class='check'>
            <span>config.php loading</span>
            <span class='status error'>❌ Error: " . $e->getMessage() . "</span>
        </div>";
        $errors[] = "Config file error: " . $e->getMessage();
    }
} else {
    echo "<div class='check'>
        <span>config.php</span>
        <span class='status error'>❌ File not found</span>
    </div>";
    $errors[] = "config.php file missing";
}

echo "</div>";

// 4. Database Connection Test
echo "<div class='section'>
<h3>4. Database Connection</h3>";

try {
    if (file_exists($project_root . '/config/database.php')) {
        require_once $project_root . '/config/database.php';
        echo "<div class='check'>
            <span>database.php loading</span>
            <span class='status ok'>✅ Loaded</span>
        </div>";
        
        // Try to create Database instance
        if (file_exists($project_root . '/classes/Database.php')) {
            require_once $project_root . '/classes/Database.php';
            
            try {
                $db = new Database();
                echo "<div class='check'>
                    <span>Database connection</span>
                    <span class='status ok'>✅ Connected</span>
                </div>";
                
                // Test basic queries
                try {
                    $result = $db->fetchRow("SELECT COUNT(*) as count FROM users");
                    echo "<div class='check'>
                        <span>Users table access</span>
                        <span class='status ok'>✅ OK ({$result['count']} users)</span>
                    </div>";
                } catch (Exception $e) {
                    echo "<div class='check'>
                        <span>Users table access</span>
                        <span class='status error'>❌ Error: " . $e->getMessage() . "</span>
                    </div>";
                    $errors[] = "Database table error: " . $e->getMessage();
                }
                
                try {
                    $result = $db->fetchRow("SELECT setting_value FROM system_settings WHERE setting_key = 'assembly_name'");
                    echo "<div class='check'>
                        <span>System settings table</span>
                        <span class='status ok'>✅ OK</span>
                    </div>";
                } catch (Exception $e) {
                    echo "<div class='check'>
                        <span>System settings table</span>
                        <span class='status error'>❌ Error: " . $e->getMessage() . "</span>
                    </div>";
                    $errors[] = "System settings error: " . $e->getMessage();
                }
                
            } catch (Exception $e) {
                echo "<div class='check'>
                    <span>Database connection</span>
                    <span class='status error'>❌ Failed: " . $e->getMessage() . "</span>
                </div>";
                $errors[] = "Database connection failed: " . $e->getMessage();
            }
        } else {
            echo "<div class='check'>
                <span>Database class</span>
                <span class='status error'>❌ Database.php missing</span>
            </div>";
            $errors[] = "Database.php class file missing";
        }
        
    } else {
        echo "<div class='check'>
            <span>Database configuration</span>
            <span class='status error'>❌ database.php missing</span>
        </div>";
        $errors[] = "database.php configuration file missing";
    }
} catch (Exception $e) {
    echo "<div class='check'>
        <span>Database test</span>
        <span class='status error'>❌ Error: " . $e->getMessage() . "</span>
    </div>";
    $errors[] = "Database configuration error: " . $e->getMessage();
}

echo "</div>";

// 5. Function Dependencies
echo "<div class='section'>
<h3>5. Required Functions</h3>";

$functions_file = $project_root . '/includes/functions.php';
if (file_exists($functions_file)) {
    try {
        require_once $functions_file;
        echo "<div class='check'>
            <span>functions.php loading</span>
            <span class='status ok'>✅ Loaded</span>
        </div>";
        
        $required_functions = [
            'writeLog',
            'setFlashMessage', 
            'getFlashMessages',
            'getCurrentUser',
            'getUserDisplayName',
            'getCurrentUserRole',
            'hasPermission'
        ];
        
        foreach ($required_functions as $func) {
            $exists = function_exists($func);
            echo "<div class='check'>
                <span>Function: $func()</span>
                <span class='status " . ($exists ? "ok'>✅ Available" : "error'>❌ Missing") . "</span>
            </div>";
            
            if (!$exists) {
                $errors[] = "Missing function: $func()";
            }
        }
        
    } catch (Exception $e) {
        echo "<div class='check'>
            <span>functions.php loading</span>
            <span class='status error'>❌ Error: " . $e->getMessage() . "</span>
        </div>";
        $errors[] = "Functions file error: " . $e->getMessage();
    }
} else {
    echo "<div class='check'>
        <span>functions.php</span>
        <span class='status error'>❌ File missing</span>
    </div>";
    $errors[] = "functions.php file missing";
}

echo "</div>";

// 6. Composer Dependencies
echo "<div class='section'>
<h3>6. Composer Dependencies</h3>";

$vendor_path = $project_root . '/vendor';
$autoload_path = $vendor_path . '/autoload.php';

if (file_exists($autoload_path)) {
    try {
        require_once $autoload_path;
        echo "<div class='check'>
            <span>Composer autoload</span>
            <span class='status ok'>✅ Loaded</span>
        </div>";
        
        // Check QR code library
        if (class_exists('chillerlan\QRCode\QRCode')) {
            echo "<div class='check'>
                <span>QR Code library</span>
                <span class='status ok'>✅ Available</span>
            </div>";
        } else {
            echo "<div class='check'>
                <span>QR Code library</span>
                <span class='status warning'>⚠️ Missing</span>
            </div>";
            $warnings[] = "QR Code library not installed";
            $recommendations[] = "Run: composer require chillerlan/php-qrcode";
        }
        
    } catch (Exception $e) {
        echo "<div class='check'>
            <span>Composer autoload</span>
            <span class='status error'>❌ Error: " . $e->getMessage() . "</span>
        </div>";
        $errors[] = "Composer autoload error: " . $e->getMessage();
    }
} else {
    echo "<div class='check'>
        <span>Composer autoload</span>
        <span class='status error'>❌ Not found</span>
    </div>";
    $errors[] = "Composer dependencies not installed";
    $recommendations[] = "Run: composer install (or composer require chillerlan/php-qrcode if no composer.json)";
}

echo "</div>";

// 7. Permissions Check
echo "<div class='section'>
<h3>7. File Permissions</h3>";

$permission_paths = [
    'assets/qr_codes/' => 'QR codes directory',
    'storage/logs/' => 'Logs directory', 
    'uploads/' => 'Uploads directory',
    'storage/backups/' => 'Backups directory'
];

foreach ($permission_paths as $path => $description) {
    $full_path = $project_root . '/' . $path;
    
    if (!is_dir($full_path)) {
        $created = mkdir($full_path, 0755, true);
        echo "<div class='check'>
            <span>$description</span>
            <span class='status " . ($created ? "ok'>✅ Created" : "error'>❌ Cannot create") . "</span>
        </div>";
        
        if (!$created) {
            $errors[] = "Cannot create directory: $path";
        }
    } else {
        $writable = is_writable($full_path);
        echo "<div class='check'>
            <span>$description</span>
            <span class='status " . ($writable ? "ok'>✅ Writable" : "error'>❌ Not writable") . "</span>
        </div>";
        
        if (!$writable) {
            $errors[] = "Directory not writable: $path";
            $recommendations[] = "Run: chmod 755 $path";
        }
    }
}

echo "</div>";

// Summary and Recommendations
echo "<div class='section'>
<h3>📋 Summary & Recommendations</h3>";

if (empty($errors) && empty($warnings)) {
    echo "<div style='background: #d1fae5; padding: 15px; border-radius: 5px; border-left: 4px solid #10b981;'>
        <strong>✅ All checks passed!</strong> Your system should work correctly.
    </div>";
} else {
    if (!empty($errors)) {
        echo "<div style='background: #fee2e2; padding: 15px; border-radius: 5px; border-left: 4px solid #ef4444; margin-bottom: 15px;'>
            <strong>❌ Critical Errors Found:</strong><br>";
        foreach ($errors as $i => $error) {
            echo ($i + 1) . ". $error<br>";
        }
        echo "</div>";
    }
    
    if (!empty($warnings)) {
        echo "<div style='background: #fef3c7; padding: 15px; border-radius: 5px; border-left: 4px solid #f59e0b; margin-bottom: 15px;'>
            <strong>⚠️ Warnings:</strong><br>";
        foreach ($warnings as $i => $warning) {
            echo ($i + 1) . ". $warning<br>";
        }
        echo "</div>";
    }
    
    if (!empty($recommendations)) {
        echo "<div style='background: #dbeafe; padding: 15px; border-radius: 5px; border-left: 4px solid #3b82f6;'>
            <strong>🔧 Recommendations:</strong><br>";
        foreach ($recommendations as $i => $rec) {
            echo ($i + 1) . ". $rec<br>";
        }
        echo "</div>";
    }
}

echo "</div>";

// Quick fixes section
echo "<div class='section'>
<h3>🚀 Quick Fixes</h3>
<div class='recommendation'>
<strong>If you're getting 'An error occurred while loading bill details', try these steps:</strong><br><br>

<strong>1. Install Composer Dependencies:</strong>
<div class='code'>cd " . $project_root . "
composer install
# OR if no composer.json exists:
composer require chillerlan/php-qrcode</div>

<strong>2. Fix File Permissions:</strong>
<div class='code'>chmod 755 assets/qr_codes/
chmod 755 storage/logs/
chmod 755 uploads/</div>

<strong>3. Check Database Configuration:</strong>
<div class='code'># Edit config/database.php with correct settings:
# - Database host, username, password, database name</div>

<strong>4. Enable Error Display (temporarily):</strong>
<div class='code'># Add to the top of admin/billing/view.php:
error_reporting(E_ALL);
ini_set('display_errors', 1);</div>

<strong>5. Check Web Server Logs:</strong>
<div class='code'># For Apache:
tail -f /var/log/apache2/error.log

# For Nginx:
tail -f /var/log/nginx/error.log</div>
</div>
</div>";

echo "<div style='text-align: center; margin-top: 30px; color: #666;'>
    <p>Diagnostic completed at " . date('Y-m-d H:i:s') . "</p>
    <p>If issues persist, check your web server error logs for detailed error messages.</p>
</div>";

echo "</div>
</body>
</html>";
?>