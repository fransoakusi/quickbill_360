<?php
/**
 * Assets Folder Write Permission Test
 * Save this as: test_assets_permissions.php
 * Place it in your admin/billing/ directory (same location as your bill view file)
 */

// Prevent direct access in production
if (!defined('TESTING_PERMISSIONS')) {
    define('TESTING_PERMISSIONS', true);
}

// Try to load QR libraries at the top (if available)
$qr_library_available = false;
$autoload_paths = [
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
    __DIR__ . '/../../../../vendor/autoload.php'
];

foreach ($autoload_paths as $autoload_path) {
    if (file_exists($autoload_path)) {
        require_once $autoload_path;
        if (class_exists('chillerlan\QRCode\QRCode')) {
            $qr_library_available = true;
        }
        break;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assets Folder Permission Test</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            background: #f8f9fa;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            margin: -30px -30px 30px -30px;
            border-radius: 10px 10px 0 0;
        }
        .test-section {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .test-result {
            padding: 10px 15px;
            border-radius: 5px;
            margin: 10px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .success {
            background: #d1fae5;
            border: 1px solid #9ae6b4;
            color: #065f46;
        }
        .error {
            background: #fee2e2;
            border: 1px solid #fca5a5;
            color: #991b1b;
        }
        .warning {
            background: #fef3c7;
            border: 1px solid #fcd34d;
            color: #92400e;
        }
        .info {
            background: #dbeafe;
            border: 1px solid #93c5fd;
            color: #1e40af;
        }
        .code {
            background: #f1f5f9;
            border: 1px solid #cbd5e0;
            border-radius: 4px;
            padding: 10px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            margin: 10px 0;
            overflow-x: auto;
        }
        .path-info {
            background: #f8fafc;
            border-left: 4px solid #667eea;
            padding: 15px;
            margin: 10px 0;
        }
        .fix-section {
            background: #fff5f5;
            border: 1px solid #fed7d7;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
        }
        .fix-section h3 {
            color: #c53030;
            margin-top: 0;
        }
        .cmd {
            background: #1a202c;
            color: #e2e8f0;
            padding: 10px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            margin: 5px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        th, td {
            border: 1px solid #e2e8f0;
            padding: 10px;
            text-align: left;
        }
        th {
            background: #f7fafc;
            font-weight: 600;
        }
        .permission-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        .permission-item {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 15px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔐 Assets Folder Write Permission Test</h1>
            <p>Comprehensive test to verify web server write permissions for QR code generation</p>
        </div>

        <?php
        $tests = [];
        $errors = [];
        $warnings = [];
        $fixes = [];
        
        // Test configurations
        $base_dir = __DIR__;
        $assets_relative = '../../assets';
        $qr_relative = '../../assets/qr_codes';
        $assets_absolute = realpath($base_dir . '/' . $assets_relative);
        $qr_absolute = realpath($base_dir . '/' . $qr_relative);
        
        // If realpath fails, construct the paths manually
        if (!$assets_absolute) {
            $assets_absolute = $base_dir . '/' . $assets_relative;
        }
        if (!$qr_absolute) {
            $qr_absolute = $base_dir . '/' . $qr_relative;
        }
        
        echo "<div class='test-section'>";
        echo "<h2>📍 Path Information</h2>";
        echo "<div class='path-info'>";
        echo "<strong>Current Script Location:</strong> " . $base_dir . "<br>";
        echo "<strong>Assets Relative Path:</strong> " . $assets_relative . "<br>";
        echo "<strong>Assets Absolute Path:</strong> " . $assets_absolute . "<br>";
        echo "<strong>QR Codes Relative Path:</strong> " . $qr_relative . "<br>";
        echo "<strong>QR Codes Absolute Path:</strong> " . $qr_absolute . "<br>";
        echo "<strong>Web Server User:</strong> " . (function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? posix_getpwuid(posix_geteuid())['name'] : 'Unknown') . "<br>";
        echo "<strong>PHP Version:</strong> " . PHP_VERSION . "<br>";
        echo "<strong>Operating System:</strong> " . PHP_OS . "<br>";
        echo "</div>";
        echo "</div>";

        // Test 1: Check if assets directory exists
        echo "<div class='test-section'>";
        echo "<h2>📁 Directory Existence Test</h2>";
        
        if (is_dir($assets_absolute)) {
            echo "<div class='test-result success'>✅ Assets directory exists: $assets_absolute</div>";
            $tests['assets_exists'] = true;
        } else {
            echo "<div class='test-result error'>❌ Assets directory does NOT exist: $assets_absolute</div>";
            $tests['assets_exists'] = false;
            $errors[] = "Assets directory missing";
            $fixes[] = "Create assets directory: mkdir -p " . $assets_absolute;
        }
        
        if (is_dir($qr_absolute)) {
            echo "<div class='test-result success'>✅ QR codes directory exists: $qr_absolute</div>";
            $tests['qr_exists'] = true;
        } else {
            echo "<div class='test-result warning'>⚠️ QR codes directory does NOT exist: $qr_absolute</div>";
            $tests['qr_exists'] = false;
            $warnings[] = "QR codes directory missing (will be created automatically)";
        }
        echo "</div>";

        // Test 2: Check permissions
        echo "<div class='test-section'>";
        echo "<h2>🔒 Permission Analysis</h2>";
        
        if ($tests['assets_exists']) {
            $assets_perms = fileperms($assets_absolute);
            $assets_octal = sprintf('%o', $assets_perms & 0777);
            
            echo "<div class='permission-grid'>";
            echo "<div class='permission-item'>";
            echo "<h4>Assets Directory</h4>";
            echo "<strong>Permissions:</strong> " . $assets_octal . "<br>";
            echo "<strong>Readable:</strong> " . (is_readable($assets_absolute) ? "✅ Yes" : "❌ No") . "<br>";
            echo "<strong>Writable:</strong> " . (is_writable($assets_absolute) ? "✅ Yes" : "❌ No") . "<br>";
            echo "<strong>Executable:</strong> " . (is_executable($assets_absolute) ? "✅ Yes" : "❌ No") . "<br>";
            echo "</div>";
            
            if ($tests['qr_exists']) {
                $qr_perms = fileperms($qr_absolute);
                $qr_octal = sprintf('%o', $qr_perms & 0777);
                
                echo "<div class='permission-item'>";
                echo "<h4>QR Codes Directory</h4>";
                echo "<strong>Permissions:</strong> " . $qr_octal . "<br>";
                echo "<strong>Readable:</strong> " . (is_readable($qr_absolute) ? "✅ Yes" : "❌ No") . "<br>";
                echo "<strong>Writable:</strong> " . (is_writable($qr_absolute) ? "✅ Yes" : "❌ No") . "<br>";
                echo "<strong>Executable:</strong> " . (is_executable($qr_absolute) ? "✅ Yes" : "❌ No") . "<br>";
                echo "</div>";
            }
            echo "</div>";
            
            if (!is_writable($assets_absolute)) {
                $errors[] = "Assets directory not writable";
                $fixes[] = "Fix assets permissions: chmod 755 " . $assets_absolute;
            }
        }
        echo "</div>";

        // Test 3: Attempt to create QR directory
        echo "<div class='test-section'>";
        echo "<h2>📂 Directory Creation Test</h2>";
        
        if (!$tests['qr_exists']) {
            if (mkdir($qr_absolute, 0755, true)) {
                echo "<div class='test-result success'>✅ Successfully created QR codes directory</div>";
                $tests['qr_created'] = true;
            } else {
                echo "<div class='test-result error'>❌ Failed to create QR codes directory</div>";
                $tests['qr_created'] = false;
                $errors[] = "Cannot create QR codes directory";
                $fixes[] = "Manually create QR directory: mkdir -p " . $qr_absolute . " && chmod 755 " . $qr_absolute;
            }
        } else {
            echo "<div class='test-result info'>ℹ️ QR codes directory already exists</div>";
            $tests['qr_created'] = true;
        }
        echo "</div>";

        // Test 4: File write test
        echo "<div class='test-section'>";
        echo "<h2>✍️ File Write Test</h2>";
        
        $test_file = $qr_absolute . '/write_test_' . time() . '.txt';
        $test_content = "Write test performed at " . date('Y-m-d H:i:s') . "\nPHP Version: " . PHP_VERSION . "\nServer: " . $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
        
        if (file_put_contents($test_file, $test_content)) {
            echo "<div class='test-result success'>✅ Successfully wrote test file: " . basename($test_file) . "</div>";
            
            // Test file reading
            if (file_get_contents($test_file) === $test_content) {
                echo "<div class='test-result success'>✅ Successfully read test file content</div>";
                $tests['file_write'] = true;
            } else {
                echo "<div class='test-result error'>❌ File content mismatch on read</div>";
                $tests['file_write'] = false;
                $errors[] = "File read/write integrity issue";
            }
            
            // Test file deletion
            if (unlink($test_file)) {
                echo "<div class='test-result success'>✅ Successfully deleted test file</div>";
            } else {
                echo "<div class='test-result warning'>⚠️ Could not delete test file</div>";
                $warnings[] = "Test file cleanup failed";
            }
        } else {
            echo "<div class='test-result error'>❌ Failed to write test file</div>";
            $tests['file_write'] = false;
            $errors[] = "Cannot write files to QR directory";
            $fixes[] = "Fix QR directory permissions: chmod 755 " . $qr_absolute;
        }
        echo "</div>";

        // Test 5: QR Library Test
        echo "<div class='test-section'>";
        echo "<h2>📦 QR Code Library Test</h2>";
        
        // Check if composer autoload exists
        $autoload_found = false;
        foreach ($autoload_paths as $autoload_path) {
            if (file_exists($autoload_path)) {
                echo "<div class='test-result success'>✅ Found Composer autoload: $autoload_path</div>";
                $autoload_found = true;
                break;
            }
        }
        
        if (!$autoload_found) {
            echo "<div class='test-result error'>❌ Composer autoload not found in expected locations</div>";
            $errors[] = "Composer autoload missing";
            $fixes[] = "Run composer install in your project root";
        } else {
            // Test QR library
            if ($qr_library_available) {
                echo "<div class='test-result success'>✅ QR Code library (chillerlan/php-qrcode) is available</div>";
                $tests['qr_library'] = true;
                
                // Test actual QR generation
                try {
                    $test_qr_file = $qr_absolute . '/test_qr_' . time() . '.png';
                    
                    // Create QR options
                    $options = new \chillerlan\QRCode\QROptions([
                        'outputType' => \chillerlan\QRCode\QRCode::OUTPUT_IMAGE_PNG,
                        'eccLevel' => \chillerlan\QRCode\QRCode::ECC_L,
                        'scale' => 4,
                    ]);
                    
                    $qrcode = new \chillerlan\QRCode\QRCode($options);
                    $qrcode->render('Test QR Code - ' . date('Y-m-d H:i:s'), $test_qr_file);
                    
                    if (file_exists($test_qr_file) && filesize($test_qr_file) > 0) {
                        echo "<div class='test-result success'>✅ QR code generation test successful (" . filesize($test_qr_file) . " bytes)</div>";
                        
                        // Display the generated QR code
                        $qr_relative_path = '../../assets/qr_codes/' . basename($test_qr_file);
                        echo "<div style='text-align: center; margin: 15px 0;'>";
                        echo "<img src='" . $qr_relative_path . "' alt='Test QR Code' style='max-width: 150px; border: 1px solid #ddd; border-radius: 8px;'>";
                        echo "<br><small>Test QR Code Generated Successfully</small>";
                        echo "</div>";
                        
                        $tests['qr_generation'] = true;
                        
                        // Cleanup
                        unlink($test_qr_file);
                    } else {
                        echo "<div class='test-result error'>❌ QR code file was not created or is empty</div>";
                        $tests['qr_generation'] = false;
                        $errors[] = "QR code generation failed";
                    }
                } catch (Exception $e) {
                    echo "<div class='test-result error'>❌ QR code generation error: " . htmlspecialchars($e->getMessage()) . "</div>";
                    $tests['qr_generation'] = false;
                    $errors[] = "QR generation exception: " . $e->getMessage();
                }
            } else {
                echo "<div class='test-result error'>❌ QR Code library (chillerlan/php-qrcode) is NOT available</div>";
                $tests['qr_library'] = false;
                $errors[] = "QR Code library missing";
                $fixes[] = "Install QR library: composer require chillerlan/php-qrcode";
            }
        }
        echo "</div>";

        // Test 6: Web Accessibility Test
        echo "<div class='test-section'>";
        echo "<h2>🌐 Web Accessibility Test</h2>";
        
        $web_test_file = $qr_absolute . '/web_test_' . time() . '.txt';
        if (file_put_contents($web_test_file, 'Web accessibility test')) {
            $relative_web_path = str_replace($_SERVER['DOCUMENT_ROOT'], '', $web_test_file);
            $web_url = 'http' . (isset($_SERVER['HTTPS']) ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . $relative_web_path;
            
            echo "<div class='test-result info'>ℹ️ Test file created for web access check</div>";
            echo "<div class='test-result info'>🔗 Web URL would be: <code>" . htmlspecialchars($web_url) . "</code></div>";
            
            // Check if file is in web-accessible location
            if (strpos($web_test_file, $_SERVER['DOCUMENT_ROOT']) === 0) {
                echo "<div class='test-result success'>✅ QR codes directory is within web document root</div>";
                $tests['web_accessible'] = true;
            } else {
                echo "<div class='test-result warning'>⚠️ QR codes directory may not be web accessible</div>";
                $tests['web_accessible'] = false;
                $warnings[] = "QR directory outside document root";
            }
            
            unlink($web_test_file);
        } else {
            echo "<div class='test-result error'>❌ Cannot create test file for web accessibility check</div>";
            $tests['web_accessible'] = false;
        }
        echo "</div>";

        // Test Summary
        echo "<div class='test-section'>";
        echo "<h2>📊 Test Summary</h2>";
        
        $total_tests = count($tests);
        $passed_tests = array_sum($tests);
        $success_rate = $total_tests > 0 ? ($passed_tests / $total_tests) * 100 : 0;
        
        echo "<table>";
        echo "<tr><th>Test</th><th>Status</th><th>Result</th></tr>";
        foreach ($tests as $test_name => $result) {
            $status_icon = $result ? "✅" : "❌";
            $status_text = $result ? "PASS" : "FAIL";
            $status_class = $result ? "success" : "error";
            echo "<tr>";
            echo "<td>" . ucwords(str_replace('_', ' ', $test_name)) . "</td>";
            echo "<td><span class='test-result $status_class' style='display: inline-flex; padding: 5px 10px;'>$status_icon $status_text</span></td>";
            echo "<td>" . ($result ? "Working correctly" : "Needs attention") . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        echo "<div style='text-align: center; margin: 20px 0;'>";
        if ($success_rate == 100) {
            echo "<div class='test-result success' style='font-size: 18px; display: inline-flex;'>🎉 All tests passed! QR code generation should work perfectly.</div>";
        } elseif ($success_rate >= 70) {
            echo "<div class='test-result warning' style='font-size: 18px; display: inline-flex;'>⚠️ Most tests passed, but some issues need attention.</div>";
        } else {
            echo "<div class='test-result error' style='font-size: 18px; display: inline-flex;'>❌ Multiple issues detected. QR code generation will likely fail.</div>";
        }
        echo "<br><strong>Success Rate: " . round($success_rate) . "% ($passed_tests/$total_tests tests passed)</strong>";
        echo "</div>";
        echo "</div>";

        // Show fixes if needed
        if (!empty($errors) || !empty($warnings)) {
            echo "<div class='fix-section'>";
            echo "<h3>🔧 Required Fixes</h3>";
            
            if (!empty($errors)) {
                echo "<h4 style='color: #dc3545;'>Critical Issues (Must Fix):</h4>";
                echo "<ul>";
                foreach ($errors as $error) {
                    echo "<li style='color: #dc3545;'>❌ $error</li>";
                }
                echo "</ul>";
            }
            
            if (!empty($warnings)) {
                echo "<h4 style='color: #fd7e14;'>Warnings (Should Fix):</h4>";
                echo "<ul>";
                foreach ($warnings as $warning) {
                    echo "<li style='color: #fd7e14;'>⚠️ $warning</li>";
                }
                echo "</ul>";
            }
            
            if (!empty($fixes)) {
                echo "<h4>📋 Recommended Commands:</h4>";
                foreach ($fixes as $fix) {
                    echo "<div class='cmd'>$ $fix</div>";
                }
                
                echo "<h4>🐧 For Linux/Unix Systems:</h4>";
                echo "<div class='cmd'># Navigate to your project root<br>";
                echo "cd /path/to/your/quickbill305/<br><br>";
                echo "# Create directories<br>";
                echo "mkdir -p assets/qr_codes<br><br>";
                echo "# Set proper permissions<br>";
                echo "chmod 755 assets/<br>";
                echo "chmod 755 assets/qr_codes/<br><br>";
                echo "# Install QR library<br>";
                echo "composer require chillerlan/php-qrcode<br><br>";
                echo "# Verify ownership (if needed)<br>";
                echo "chown -R www-data:www-data assets/ # (for Apache)<br>";
                echo "# or<br>";
                echo "chown -R nginx:nginx assets/ # (for Nginx)</div>";
                
                echo "<h4>🪟 For Windows Systems:</h4>";
                echo "<div class='cmd'># Open Command Prompt as Administrator<br>";
                echo "cd C:\\path\\to\\your\\quickbill305\\<br><br>";
                echo "# Create directories<br>";
                echo "mkdir assets\\qr_codes<br><br>";
                echo "# Install QR library<br>";
                echo "composer require chillerlan/php-qrcode<br><br>";
                echo "# Set folder permissions via Properties > Security</div>";
            }
            echo "</div>";
        }

        // Additional recommendations
        echo "<div class='test-section'>";
        echo "<h2>💡 Additional Recommendations</h2>";
        echo "<div class='test-result info'>ℹ️ <strong>Security:</strong> Consider setting QR codes directory to 755 instead of 777 for better security</div>";
        echo "<div class='test-result info'>ℹ️ <strong>Performance:</strong> QR codes are cached - delete old QR files periodically to save space</div>";
        echo "<div class='test-result info'>ℹ️ <strong>Backup:</strong> Include the assets/qr_codes directory in your backup strategy</div>";
        echo "<div class='test-result info'>ℹ️ <strong>Monitoring:</strong> Check error logs regularly for QR generation issues</div>";
        echo "</div>";

        // Quick action buttons
        echo "<div style='text-align: center; margin: 30px 0;'>";
        echo "<a href='?retest=1' style='background: #667eea; color: white; padding: 12px 20px; border-radius: 8px; text-decoration: none; margin: 0 10px;'>🔄 Re-run Tests</a>";
        echo "<a href='../billing/view.php?id=" . ($_GET['bill_id'] ?? '1') . "' style='background: #10b981; color: white; padding: 12px 20px; border-radius: 8px; text-decoration: none; margin: 0 10px;'>📄 Back to Bill View</a>";
        echo "</div>";
        ?>
        
        <div class="test-section">
            <h2>📋 Next Steps</h2>
            <?php if ($success_rate == 100): ?>
                <p>🎉 <strong>Excellent!</strong> Your server is properly configured for QR code generation. If you're still having issues:</p>
                <ol>
                    <li>Clear your browser cache</li>
                    <li>Check if the QR code library is included properly in your bill view file</li>
                    <li>Verify the QR generation code syntax</li>
                </ol>
            <?php elseif ($success_rate >= 70): ?>
                <p>✅ <strong>Good progress!</strong> Fix the issues listed above and QR codes should work.</p>
            <?php else: ?>
                <p>⚠️ <strong>Action required:</strong></p>
                <ol>
                    <li>Follow the command-line instructions above</li>
                    <li>Re-run this test to verify fixes</li>
                    <li>Contact your hosting provider if permission issues persist</li>
                </ol>
            <?php endif; ?>
        </div>

        <div style="margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #667eea;">
            <h3>🚀 Pro Tips</h3>
            <ul>
                <li><strong>Development:</strong> Use 777 permissions for testing, 755 for production</li>
                <li><strong>Hosting:</strong> Some shared hosts require specific permission settings</li>
                <li><strong>Debugging:</strong> Check PHP error logs if tests pass but QR still fails</li>
                <li><strong>Alternative:</strong> Consider using a QR API service if server limitations persist</li>
            </ul>
        </div>
    </div>
</body>
</html>