 <?php
/**
 * Admin System Logs Page
 * View system error logs and application logs
 */

// Define application constant
define('QUICKBILL_305', true);

// Include configuration files
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Start session
session_start();

// Include auth and security
require_once '../../includes/auth.php';
require_once '../../includes/security.php';

// Initialize auth and security
initAuth();
initSecurity();
require_once '../../includes/restriction_warning.php';

// Check if user is logged in and is admin
if (!isLoggedIn()) {
    header('Location: ../../auth/login.php');
    exit();
}

if (!isAdmin()) {
    setFlashMessage('error', 'Access denied. Admin privileges required.');
    header('Location: ../../auth/login.php');
    exit();
}

$currentUser = getCurrentUser();

// Log file paths
$logDirectory = '../../storage/logs/';
$logFiles = [
    'app.log' => 'Application Logs',
    'error.log' => 'Error Logs',
    'access.log' => 'Access Logs',
    'payment.log' => 'Payment Logs'
];

// Parameters
$selectedLog = sanitizeInput($_GET['log'] ?? 'app.log');
$lines = intval($_GET['lines'] ?? 100);
$search = sanitizeInput($_GET['search'] ?? '');
$level = sanitizeInput($_GET['level'] ?? '');

// Validate selected log
if (!array_key_exists($selectedLog, $logFiles)) {
    $selectedLog = 'app.log';
}

$logFilePath = $logDirectory . $selectedLog;

// Initialize variables
$logEntries = [];
$totalLines = 0;
$fileExists = false;
$fileSize = 0;
$lastModified = null;

// Read log file if it exists
if (file_exists($logFilePath)) {
    $fileExists = true;
    $fileSize = filesize($logFilePath);
    $lastModified = filemtime($logFilePath);
    
    // Read the file
    $fileContent = file_get_contents($logFilePath);
    $allLines = explode("\n", $fileContent);
    $totalLines = count($allLines);
    
    // Get the last N lines
    $selectedLines = array_slice($allLines, -$lines);
    $selectedLines = array_reverse($selectedLines); // Show newest first
    
    // Parse log entries
    foreach ($selectedLines as $index => $line) {
        if (empty(trim($line))) continue;
        
        $entry = parseLogLine($line);
        
        // Apply filters
        if ($search && stripos($line, $search) === false) {
            continue;
        }
        
        if ($level && $entry['level'] !== strtoupper($level)) {
            continue;
        }
        
        $entry['line_number'] = $totalLines - $lines + $index + 1;
        $logEntries[] = $entry;
    }
}

// Clear log file functionality
if (isset($_POST['clear_log']) && isset($_POST['confirm_clear']) && $fileExists) {
    if (verifyCsrfToken()) {
        $backupPath = $logDirectory . 'backup_' . $selectedLog . '_' . date('Y-m-d_H-i-s');
        
        // Create backup before clearing
        if (copy($logFilePath, $backupPath)) {
            // Clear the log file
            file_put_contents($logFilePath, '');
            
            // Log this action
            if (function_exists('logActivity')) {
                logActivity('SYSTEM_LOG_CLEARED', [
                    'log_file' => $selectedLog,
                    'backup_created' => $backupPath,
                    'cleared_by' => $currentUser['user_id']
                ]);
            }
            
            $success = "Log file cleared successfully. Backup created: " . basename($backupPath);
        } else {
            $error = "Failed to create backup. Log file not cleared.";
        }
    } else {
        $error = "Security validation failed.";
    }
    
    // Redirect to avoid form resubmission
    if (isset($success)) {
        header("Location: system_logs.php?log={$selectedLog}&success=" . urlencode($success));
    } else {
        header("Location: system_logs.php?log={$selectedLog}&error=" . urlencode($error));
    }
    exit();
}

// Handle messages from redirect
$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';

// Export functionality
if (isset($_GET['export']) && $_GET['export'] === 'txt' && $fileExists) {
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="' . $selectedLog . '_export_' . date('Y-m-d_H-i-s') . '.txt"');
    
    echo "=== " . $logFiles[$selectedLog] . " Export ===\n";
    echo "Generated: " . date('Y-m-d H:i:s') . "\n";
    echo "File: {$selectedLog}\n";
    echo "Total Lines: {$totalLines}\n";
    echo "Exported Lines: " . count($logEntries) . "\n";
    echo "=" . str_repeat("=", 50) . "\n\n";
    
    foreach (array_reverse($logEntries) as $entry) {
        echo "[{$entry['timestamp']}] [{$entry['level']}] {$entry['message']}\n";
    }
    
    exit();
}

function parseLogLine($line) {
    $entry = [
        'timestamp' => '',
        'level' => 'INFO',
        'message' => $line,
        'raw' => $line
    ];
    
    // Try to parse common log formats
    // Format: [2025-01-01 12:00:00] [ERROR] Message here
    if (preg_match('/^\[([^\]]+)\]\s*\[([^\]]+)\]\s*(.*)$/', $line, $matches)) {
        $entry['timestamp'] = $matches[1];
        $entry['level'] = strtoupper(trim($matches[2]));
        $entry['message'] = $matches[3];
    }
    // Format: 2025-01-01 12:00:00 ERROR: Message here
    elseif (preg_match('/^(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\s+([A-Z]+):\s*(.*)$/', $line, $matches)) {
        $entry['timestamp'] = $matches[1];
        $entry['level'] = strtoupper($matches[2]);
        $entry['message'] = $matches[3];
    }
    // Format: ERROR - 2025-01-01 12:00:00 - Message here
    elseif (preg_match('/^([A-Z]+)\s*-\s*([^-]+)\s*-\s*(.*)$/', $line, $matches)) {
        $entry['level'] = strtoupper(trim($matches[1]));
        $entry['timestamp'] = trim($matches[2]);
        $entry['message'] = $matches[3];
    }
    
    return $entry;
}

function getLogLevelClass($level) {
    $level = strtoupper($level);
    
    switch ($level) {
        case 'ERROR':
        case 'CRITICAL':
        case 'FATAL':
            return 'danger';
        case 'WARNING':
        case 'WARN':
            return 'warning';
        case 'INFO':
        case 'INFORMATION':
            return 'info';
        case 'DEBUG':
            return 'secondary';
        case 'SUCCESS':
            return 'success';
        default:
            return 'primary';
    }
}

function formatFileSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $index = 0;
    
    while ($bytes >= 1024 && $index < count($units) - 1) {
        $bytes /= 1024;
        $index++;
    }
    
    return round($bytes, 2) . ' ' . $units[$index];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Logs - <?php echo APP_NAME; ?></title>
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
            color: #2d3748;
        }

        /* Header */
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .header h1 {
            font-size: 28px;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .header p {
            opacity: 0.9;
            font-size: 16px;
        }

        .back-link {
            color: rgba(255,255,255,0.9);
            text-decoration: none;
            font-size: 14px;
            margin-bottom: 10px;
            display: inline-block;
        }

        .back-link:hover {
            color: white;
        }

        /* Container */
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px;
        }

        /* Alerts */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Controls */
        .controls-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .controls-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .controls-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-label {
            font-size: 14px;
            font-weight: 600;
            color: #4a5568;
            margin-bottom: 5px;
        }

        .form-control {
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 14px;
            transition: all 0.3s;
            background: white;
        }

        .form-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .control-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: #667eea;
            color: white;
        }

        .btn-primary:hover {
            background: #5a67d8;
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: #e2e8f0;
            color: #4a5568;
        }

        .btn-secondary:hover {
            background: #cbd5e0;
            color: #2d3748;
        }

        .btn-success {
            background: #48bb78;
            color: white;
        }

        .btn-success:hover {
            background: #38a169;
        }

        .btn-danger {
            background: #f56565;
            color: white;
        }

        .btn-danger:hover {
            background: #e53e3e;
        }

        /* File Info */
        .file-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .info-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }

        .info-value {
            font-size: 24px;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 5px;
        }

        .info-label {
            font-size: 14px;
            color: #718096;
            font-weight: 500;
        }

        /* Log Display */
        .log-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .log-header {
            background: #f7fafc;
            padding: 20px 25px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .log-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
        }

        .log-content {
            max-height: 600px;
            overflow-y: auto;
            background: #1a202c;
            color: #e2e8f0;
            font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
            font-size: 13px;
            line-height: 1.6;
        }

        .log-entry {
            padding: 8px 15px;
            border-bottom: 1px solid #2d3748;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            transition: background 0.2s;
        }

        .log-entry:hover {
            background: rgba(255,255,255,0.05);
        }

        .log-entry-number {
            color: #718096;
            font-size: 11px;
            min-width: 50px;
            text-align: right;
            padding-top: 2px;
        }

        .log-entry-timestamp {
            color: #9ca3af;
            min-width: 150px;
            font-size: 12px;
            padding-top: 2px;
        }

        .log-entry-level {
            min-width: 70px;
        }

        .log-entry-message {
            flex: 1;
            word-break: break-word;
        }

        /* Badges */
        .badge {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }

        .badge-primary { background: #3182ce; color: white; }
        .badge-success { background: #38a169; color: white; }
        .badge-warning { background: #d69e2e; color: white; }
        .badge-danger { background: #e53e3e; color: white; }
        .badge-secondary { background: #718096; color: white; }
        .badge-info { background: #3182ce; color: white; }

        /* No data */
        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #718096;
        }

        .no-data-icon {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        /* Tab Navigation */
        .tab-nav {
            display: flex;
            gap: 5px;
            margin-bottom: 20px;
            background: white;
            padding: 5px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .tab-nav a {
            padding: 12px 20px;
            border-radius: 8px;
            text-decoration: none;
            color: #4a5568;
            font-weight: 500;
            transition: all 0.3s;
            flex: 1;
            text-align: center;
        }

        .tab-nav a:hover {
            background: #f7fafc;
            color: #2d3748;
        }

        .tab-nav a.active {
            background: #667eea;
            color: white;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 15px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }

        .modal-title {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 15px;
            color: #2d3748;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }

            .controls-grid {
                grid-template-columns: 1fr;
            }

            .file-info-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .log-header {
                flex-direction: column;
                align-items: stretch;
            }

            .control-actions {
                justify-content: center;
            }

            .tab-nav {
                flex-direction: column;
            }

            .log-entry {
                flex-direction: column;
                gap: 5px;
            }

            .log-entry-number,
            .log-entry-timestamp,
            .log-entry-level {
                min-width: auto;
            }
        }

        /* Icons */
        .icon-logs::before { content: "🗂️"; }
        .icon-filter::before { content: "🔍"; }
        .icon-download::before { content: "📥"; }
        .icon-refresh::before { content: "🔄"; }
        .icon-trash::before { content: "🗑️"; }
        .icon-warning::before { content: "⚠️"; }
        .icon-check::before { content: "✅"; }
        .icon-error::before { content: "❌"; }

        /* Auto-refresh indicator */
        .refresh-indicator {
            position: fixed;
            top: 100px;
            right: 20px;
            background: #667eea;
            color: white;
            padding: 10px 15px;
            border-radius: 25px;
            font-size: 12px;
            z-index: 100;
            display: none;
        }

        .refresh-indicator.active {
            display: block;
            animation: pulse 2s infinite;
        }
    </style>
</head>
<body>
    <div class="header">
        <a href="../index.php" class="back-link">← Back to Dashboard</a>
        <h1><span class="icon-logs"></span> System Logs</h1>
        <p>Monitor system errors, application logs, and debug information</p>
    </div>

    <div class="container">
        <!-- Alerts -->
        <?php if ($success): ?>
            <div class="alert alert-success">
                <span class="icon-check"></span>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <span class="icon-error"></span>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Tab Navigation -->
        <div class="tab-nav">
            <?php foreach ($logFiles as $file => $name): ?>
                <a href="?log=<?php echo urlencode($file); ?>" 
                   class="<?php echo $selectedLog === $file ? 'active' : ''; ?>">
                    <?php echo htmlspecialchars($name); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Controls -->
        <div class="controls-card">
            <h3 class="controls-title">
                <span class="icon-filter"></span>
                Log Controls
            </h3>
            
            <form method="GET" action="">
                <input type="hidden" name="log" value="<?php echo htmlspecialchars($selectedLog); ?>">
                
                <div class="controls-grid">
                    <div class="form-group">
                        <label class="form-label">Lines to Show</label>
                        <select name="lines" class="form-control">
                            <option value="50" <?php echo $lines == 50 ? 'selected' : ''; ?>>Last 50 lines</option>
                            <option value="100" <?php echo $lines == 100 ? 'selected' : ''; ?>>Last 100 lines</option>
                            <option value="200" <?php echo $lines == 200 ? 'selected' : ''; ?>>Last 200 lines</option>
                            <option value="500" <?php echo $lines == 500 ? 'selected' : ''; ?>>Last 500 lines</option>
                            <option value="1000" <?php echo $lines == 1000 ? 'selected' : ''; ?>>Last 1000 lines</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Log Level</label>
                        <select name="level" class="form-control">
                            <option value="">All Levels</option>
                            <option value="error" <?php echo $level === 'error' ? 'selected' : ''; ?>>Error</option>
                            <option value="warning" <?php echo $level === 'warning' ? 'selected' : ''; ?>>Warning</option>
                            <option value="info" <?php echo $level === 'info' ? 'selected' : ''; ?>>Info</option>
                            <option value="debug" <?php echo $level === 'debug' ? 'selected' : ''; ?>>Debug</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Search in Logs</label>
                        <input type="text" name="search" class="form-control" 
                               placeholder="Search log entries..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>

                <div class="control-actions">
                    <button type="submit" class="btn btn-primary">
                        <span class="icon-filter"></span>
                        Apply Filters
                    </button>
                    <a href="?log=<?php echo urlencode($selectedLog); ?>" class="btn btn-secondary">
                        <span class="icon-refresh"></span>
                        Clear Filters
                    </a>
                    <?php if ($fileExists): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'txt'])); ?>" class="btn btn-success">
                            <span class="icon-download"></span>
                            Export
                        </a>
                        <button type="button" class="btn btn-danger" onclick="showClearModal()">
                            <span class="icon-trash"></span>
                            Clear Log
                        </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-secondary" onclick="toggleAutoRefresh()" id="autoRefreshBtn">
                        <span class="icon-refresh"></span>
                        Auto Refresh
                    </button>
                </div>
            </form>
        </div>

        <!-- File Info -->
        <?php if ($fileExists): ?>
            <div class="file-info-grid">
                <div class="info-card">
                    <div class="info-value"><?php echo formatFileSize($fileSize); ?></div>
                    <div class="info-label">File Size</div>
                </div>
                <div class="info-card">
                    <div class="info-value"><?php echo number_format($totalLines); ?></div>
                    <div class="info-label">Total Lines</div>
                </div>
                <div class="info-card">
                    <div class="info-value"><?php echo number_format(count($logEntries)); ?></div>
                    <div class="info-label">Filtered Entries</div>
                </div>
                <div class="info-card">
                    <div class="info-value"><?php echo date('M j, g:i A', $lastModified); ?></div>
                    <div class="info-label">Last Modified</div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Log Display -->
        <div class="log-card">
            <div class="log-header">
                <h3 class="log-title"><?php echo htmlspecialchars($logFiles[$selectedLog]); ?></h3>
                <div class="control-actions">
                    <button class="btn btn-secondary" onclick="window.location.reload()">
                        <span class="icon-refresh"></span>
                        Refresh
                    </button>
                </div>
            </div>

            <?php if (!$fileExists): ?>
                <div class="no-data">
                    <div class="no-data-icon">📂</div>
                    <h3>Log File Not Found</h3>
                    <p>The log file <strong><?php echo htmlspecialchars($selectedLog); ?></strong> doesn't exist yet.<br>
                    It will be created automatically when the first log entry is written.</p>
                </div>
            <?php elseif (empty($logEntries)): ?>
                <div class="no-data">
                    <div class="no-data-icon">📄</div>
                    <h3>No Log Entries</h3>
                    <p>No log entries found matching your current filters.</p>
                </div>
            <?php else: ?>
                <div class="log-content">
                    <?php foreach ($logEntries as $entry): ?>
                        <div class="log-entry">
                            <div class="log-entry-number">#<?php echo $entry['line_number']; ?></div>
                            <div class="log-entry-timestamp"><?php echo htmlspecialchars($entry['timestamp']); ?></div>
                            <div class="log-entry-level">
                                <span class="badge badge-<?php echo getLogLevelClass($entry['level']); ?>">
                                    <?php echo htmlspecialchars($entry['level']); ?>
                                </span>
                            </div>
                            <div class="log-entry-message"><?php echo htmlspecialchars($entry['message']); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Clear Log Modal -->
    <div class="modal" id="clearModal">
        <div class="modal-content">
            <h3 class="modal-title">
                <span class="icon-warning"></span>
                Clear Log File
            </h3>
            <p>Are you sure you want to clear the <strong><?php echo htmlspecialchars($logFiles[$selectedLog]); ?></strong> file?</p>
            <p><strong>This action cannot be undone.</strong> A backup will be created before clearing.</p>
            
            <form method="POST" action="">
                <?php echo csrfField(); ?>
                <input type="hidden" name="clear_log" value="1">
                <input type="hidden" name="confirm_clear" value="1">
                
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="hideClearModal()">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <span class="icon-trash"></span>
                        Clear Log File
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Auto-refresh indicator -->
    <div class="refresh-indicator" id="refreshIndicator">
        Auto-refreshing in <span id="countdown">30</span>s
    </div>

    <script>
        let autoRefresh = false;
        let refreshInterval;
        let countdownInterval;
        let countdown = 30;

        function toggleAutoRefresh() {
            autoRefresh = !autoRefresh;
            const btn = document.getElementById('autoRefreshBtn');
            const indicator = document.getElementById('refreshIndicator');
            
            if (autoRefresh) {
                btn.innerHTML = '<span class="icon-refresh"></span> Stop Auto-Refresh';
                btn.classList.remove('btn-secondary');
                btn.classList.add('btn-danger');
                
                indicator.classList.add('active');
                startAutoRefresh();
            } else {
                btn.innerHTML = '<span class="icon-refresh"></span> Auto Refresh';
                btn.classList.remove('btn-danger');
                btn.classList.add('btn-secondary');
                
                indicator.classList.remove('active');
                stopAutoRefresh();
            }
        }

        function startAutoRefresh() {
            countdown = 30;
            updateCountdown();
            
            countdownInterval = setInterval(() => {
                countdown--;
                updateCountdown();
                
                if (countdown <= 0) {
                    window.location.reload();
                }
            }, 1000);
        }

        function stopAutoRefresh() {
            if (countdownInterval) {
                clearInterval(countdownInterval);
            }
        }

        function updateCountdown() {
            const countdownElement = document.getElementById('countdown');
            if (countdownElement) {
                countdownElement.textContent = countdown;
            }
        }

        function showClearModal() {
            document.getElementById('clearModal').classList.add('show');
        }

        function hideClearModal() {
            document.getElementById('clearModal').classList.remove('show');
        }

        // Close modal when clicking outside
        document.getElementById('clearModal').addEventListener('click', function(e) {
            if (e.target === this) {
                hideClearModal();
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+R for refresh
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                window.location.reload();
            }
            // Escape to close modal
            if (e.key === 'Escape') {
                hideClearModal();
            }
            // Ctrl+E for export
            if (e.ctrlKey && e.key === 'e') {
                e.preventDefault();
                const exportBtn = document.querySelector('a[href*="export=txt"]');
                if (exportBtn) exportBtn.click();
            }
        });

        // Auto-scroll to bottom of logs
        document.addEventListener('DOMContentLoaded', function() {
            const logContent = document.querySelector('.log-content');
            if (logContent) {
                // Scroll to top to show newest entries first
                logContent.scrollTop = 0;
            }
        });

        // Highlight search terms
        function highlightSearchTerms() {
            const search = '<?php echo addslashes($search); ?>';
            if (!search) return;
            
            const entries = document.querySelectorAll('.log-entry-message');
            entries.forEach(entry => {
                const text = entry.textContent;
                const regex = new RegExp(`(${search})`, 'gi');
                entry.innerHTML = text.replace(regex, '<mark style="background: yellow; padding: 2px;">$1</mark>');
            });
        }

        // Call highlight function if there's a search term
        <?php if ($search): ?>
            document.addEventListener('DOMContentLoaded', highlightSearchTerms);
        <?php endif; ?>
    </script>
</body>
</html>
