<?php
/**
 * Admin Backup Management - QuickBill 305
 * Create and manage database backups
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

// Check if user is logged in and is admin
if (!isLoggedIn()) {
    header('Location: ../../auth/login.php');
    exit();
}

if (!isAdmin()) {
    setFlashMessage('error', 'Access denied. Admin privileges required.');
    header('Location: ../index.php');
    exit();
}

$currentUser = getCurrentUser();
$userDisplayName = getUserDisplayName($currentUser);

// Backup directory
$backupDir = '../../storage/backups';
if (!is_dir($backupDir)) {
    if (!mkdir($backupDir, 0755, true)) {
        setFlashMessage('error', 'Failed to create backup directory.');
    }
}

// Handle backup actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        $db = new Database();

        switch ($action) {
            case 'create_backup':
                $backupType = $_POST['backup_type'] ?? 'Full';
                $includeUploads = isset($_POST['include_uploads']);
                
                // Generate backup filename
                $timestamp = date('Y-m-d_H-i-s');
                $backupFilename = "quickbill_305_backup_{$timestamp}.sql";
                $backupPath = $backupDir . '/' . $backupFilename;
                
                // Log backup start
                $logSql = "INSERT INTO backup_logs (backup_type, backup_path, status, started_by, started_at) VALUES (?, ?, 'In Progress', ?, NOW())";
                $db->execute($logSql, [$backupType, $backupPath, $currentUser['user_id']]);
                $backupLogId = $db->lastInsertId();

                // Create database backup
                $backupResult = createDatabaseBackup($backupPath, $backupType, $db);
                
                if ($backupResult['success']) {
                    $backupSize = filesize($backupPath);
                    
                    // Include uploads if requested
                    if ($includeUploads) {
                        $zipPath = str_replace('.sql', '_with_uploads.zip', $backupPath);
                        $zipResult = createFullBackupWithUploads($backupPath, $zipPath);
                        
                        if ($zipResult['success']) {
                            unlink($backupPath); // Remove SQL file as it's now in ZIP
                            $backupPath = $zipPath;
                            $backupSize = filesize($zipPath);
                        }
                    }
                    
                    // Update backup log
                    $updateSql = "UPDATE backup_logs SET status = 'Completed', backup_size = ?, completed_at = NOW() WHERE backup_id = ?";
                    $db->execute($updateSql, [$backupSize, $backupLogId]);
                    
                    // Log audit activity
                    logActivity($currentUser['user_id'], 'BACKUP_CREATED', 'backup_logs', $backupLogId, 
                                   json_encode(['type' => $backupType, 'size' => $backupSize]));
                    
                    setFlashMessage('success', "Backup created successfully: " . basename($backupPath));
                } else {
                    // Update backup log with error
                    $updateSql = "UPDATE backup_logs SET status = 'Failed', error_message = ?, completed_at = NOW() WHERE backup_id = ?";
                    $db->execute($updateSql, [$backupResult['error'], $backupLogId]);
                    
                    throw new Exception($backupResult['error']);
                }
                break;

            case 'download_backup':
                $filename = $_POST['filename'];
                $filepath = $backupDir . '/' . $filename;
                
                if (!file_exists($filepath) || strpos($filename, '..') !== false) {
                    throw new Exception('Backup file not found or invalid');
                }
                
                logActivity($currentUser['user_id'], 'BACKUP_DOWNLOADED', 'backup_logs', null, 
                               json_encode(['filename' => $filename]));
                
                // Force download
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . filesize($filepath));
                header('Cache-Control: must-revalidate');
                readfile($filepath);
                exit;

            case 'delete_backup':
                $filename = $_POST['filename'];
                $filepath = $backupDir . '/' . $filename;
                
                if (!file_exists($filepath) || strpos($filename, '..') !== false) {
                    throw new Exception('Backup file not found or invalid');
                }
                
                if (unlink($filepath)) {
                    logActivity($currentUser['user_id'], 'BACKUP_DELETED', 'backup_logs', null, 
                                   json_encode(['filename' => $filename]));
                    setFlashMessage('success', 'Backup file deleted successfully');
                } else {
                    throw new Exception('Failed to delete backup file');
                }
                break;
        }

    } catch (Exception $e) {
        error_log("Backup management error: " . $e->getMessage());
        setFlashMessage('error', $e->getMessage());
    }

    header('Location: backup.php');
    exit();
}

// Get backup files
$backupFiles = [];
try {
    if (is_dir($backupDir)) {
        $files = scandir($backupDir);
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..' && (str_ends_with($file, '.sql') || str_ends_with($file, '.zip'))) {
                $filepath = $backupDir . '/' . $file;
                $backupFiles[] = [
                    'filename' => $file,
                    'size' => filesize($filepath),
                    'created' => filemtime($filepath),
                    'type' => str_ends_with($file, '.zip') ? 'Full with Uploads' : 'Database Only'
                ];
            }
        }
        
        // Sort by creation date (newest first)
        usort($backupFiles, function($a, $b) {
            return $b['created'] - $a['created'];
        });
    }
} catch (Exception $e) {
    error_log("Error reading backup directory: " . $e->getMessage());
}

// Get backup logs
try {
    $db = new Database();
    $backupLogs = $db->fetchAll("
        SELECT bl.*, u.first_name, u.last_name 
        FROM backup_logs bl
        LEFT JOIN users u ON bl.started_by = u.user_id
        ORDER BY bl.started_at DESC
        LIMIT 20
    ");
} catch (Exception $e) {
    $backupLogs = [];
}

// Database backup function
function createDatabaseBackup($backupPath, $backupType = 'Full', $db) {
    try {
        $conn = $db->getConnection();
        
        // Start building SQL dump
        $sqlDump = "-- QuickBill 305 Database Backup\n";
        $sqlDump .= "-- Generated on: " . date('Y-m-d H:i:s') . "\n";
        $sqlDump .= "-- Backup Type: $backupType\n\n";
        
        $sqlDump .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
        $sqlDump .= "START TRANSACTION;\n";
        $sqlDump .= "SET time_zone = \"+00:00\";\n\n";
        
        // Get all tables
        $tables = $conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($tables as $table) {
            // Get table structure
            $sqlDump .= "\n-- Table structure for table `$table`\n";
            $sqlDump .= "DROP TABLE IF EXISTS `$table`;\n";
            
            $createTable = $conn->query("SHOW CREATE TABLE `$table`")->fetch();
            $sqlDump .= $createTable['Create Table'] . ";\n\n";
            
            // Get table data (skip for incremental backups of audit logs)
            if ($backupType === 'Incremental' && in_array($table, ['audit_logs', 'backup_logs'])) {
                continue;
            }
            
            $sqlDump .= "-- Dumping data for table `$table`\n";
            
            $rows = $conn->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($rows)) {
                $columns = array_keys($rows[0]);
                $columnList = '`' . implode('`, `', $columns) . '`';
                
                $sqlDump .= "INSERT INTO `$table` ($columnList) VALUES\n";
                
                $values = [];
                foreach ($rows as $row) {
                    $rowValues = [];
                    foreach ($row as $value) {
                        if ($value === null) {
                            $rowValues[] = 'NULL';
                        } else {
                            $rowValues[] = "'" . addslashes($value) . "'";
                        }
                    }
                    $values[] = '(' . implode(', ', $rowValues) . ')';
                }
                
                $sqlDump .= implode(",\n", $values) . ";\n\n";
            }
        }
        
        $sqlDump .= "COMMIT;\n";
        
        // Write to file
        if (file_put_contents($backupPath, $sqlDump)) {
            return ['success' => true];
        } else {
            return ['success' => false, 'error' => 'Failed to write backup file'];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Create full backup with uploads
function createFullBackupWithUploads($sqlPath, $zipPath) {
    try {
        $zip = new ZipArchive();
        
        if ($zip->open($zipPath, ZipArchive::CREATE) !== TRUE) {
            return ['success' => false, 'error' => 'Cannot create ZIP file'];
        }
        
        // Add SQL backup
        $zip->addFile($sqlPath, 'database_backup.sql');
        
        // Add uploads directory if it exists
        $uploadsPath = '../../uploads';
        if (is_dir($uploadsPath)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($uploadsPath, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            
            foreach ($iterator as $file) {
                $filePath = $file->getRealPath();
                $relativePath = 'uploads/' . substr($filePath, strlen($uploadsPath) + 1);
                $zip->addFile($filePath, $relativePath);
            }
        }
        
        $zip->close();
        
        return ['success' => true];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Helper function to format bytes
function formatBytes($size, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }
    
    return round($size, $precision) . ' ' . $units[$i];
}

// Get flash messages once
$flashMessages = getFlashMessages();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup Management - <?php echo APP_NAME; ?></title>
    
    <!-- Icons and Fonts -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #00d2ff 0%, #3a7bd5 100%);
            --warning-gradient: linear-gradient(135deg, #feca57 0%, #ff9ff3 100%);
            --danger-gradient: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
            --info-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --dark-gradient: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            --light-gradient: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            --shadow-soft: 0 15px 35px rgba(0, 0, 0, 0.1);
            --shadow-hover: 0 20px 40px rgba(0, 0, 0, 0.15);
            --border-radius: 20px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #2d3748;
        }
        
        /* Top Navigation */
        .top-nav {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            color: #2d3748;
            padding: 15px 30px;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255,255,255,0.2);
        }
        
        .nav-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .brand {
            font-size: 24px;
            font-weight: 700;
            color: #667eea;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .back-btn {
            background: var(--primary-gradient);
            color: white;
            padding: 8px 16px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .back-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-hover);
            color: white;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .user-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: white;
            font-size: 18px;
        }
        
        /* Main Container */
        .main-container {
            margin-top: 80px;
            padding: 30px;
            max-width: 1400px;
            margin-left: auto;
            margin-right: auto;
        }
        
        /* Page Header */
        .page-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: var(--border-radius);
            padding: 40px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-soft);
            text-align: center;
        }
        
        .page-title {
            font-size: 3rem;
            font-weight: 700;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 10px;
        }
        
        .page-subtitle {
            font-size: 1.2rem;
            color: #6c757d;
            max-width: 600px;
            margin: 0 auto;
        }
        
        /* Flash Messages */
        .alert {
            border-radius: 15px;
            border: none;
            padding: 20px;
            margin-bottom: 30px;
            font-weight: 500;
            box-shadow: var(--shadow-soft);
        }
        
        .alert-success {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
        }
        
        .alert-danger {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
        }
        
        /* Cards */
        .card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: var(--border-radius);
            margin-bottom: 30px;
            box-shadow: var(--shadow-soft);
            overflow: hidden;
            border: none;
        }
        
        .card-header {
            background: var(--light-gradient);
            border-bottom: 1px solid #e2e8f0;
            padding: 25px;
        }
        
        .card-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #2d3748;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .card-body {
            padding: 30px;
        }
        
        /* Forms */
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-label {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 8px;
            display: block;
        }
        
        .form-control, .form-select {
            padding: 15px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 1rem;
            transition: var(--transition);
            background: white;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            outline: none;
        }
        
        .form-text {
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 5px;
        }
        
        .form-check {
            margin-bottom: 15px;
        }
        
        .form-check-input {
            width: 1.2em;
            height: 1.2em;
            margin-right: 10px;
        }
        
        .form-check-input:checked {
            background-color: #667eea;
            border-color: #667eea;
        }
        
        /* Buttons */
        .btn {
            border-radius: 12px;
            padding: 12px 24px;
            font-weight: 600;
            font-size: 1rem;
            border: none;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-success {
            background: var(--success-gradient);
            color: white;
        }
        
        .btn-primary {
            background: var(--primary-gradient);
            color: white;
        }
        
        .btn-danger {
            background: var(--danger-gradient);
            color: white;
        }
        
        .btn-outline-primary {
            background: transparent;
            color: #667eea;
            border: 2px solid #667eea;
        }
        
        .btn-outline-danger {
            background: transparent;
            color: #dc3545;
            border: 2px solid #dc3545;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-hover);
        }
        
        .btn-sm {
            padding: 8px 16px;
            font-size: 0.875rem;
        }
        
        .btn-block {
            width: 100%;
            justify-content: center;
        }
        
        /* Backup Information */
        .backup-info {
            background: var(--light-gradient);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 25px;
        }
        
        .backup-info h6 {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 15px;
        }
        
        .backup-items {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .backup-items li {
            padding: 8px 0;
            color: #6c757d;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .backup-items i {
            color: #28a745;
            width: 20px;
        }
        
        /* Stats */
        .backup-stats {
            background: var(--light-gradient);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .stat-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .stat-item:last-child {
            border-bottom: none;
        }
        
        .stat-item label {
            font-weight: 500;
            margin-bottom: 0;
            color: #6c757d;
        }
        
        .badge {
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-primary {
            background: #667eea;
            color: white;
        }
        
        .badge-info {
            background: #4299e1;
            color: white;
        }
        
        .badge-secondary {
            background: #6c757d;
            color: white;
        }
        
        .badge-success {
            background: #48bb78;
            color: white;
        }
        
        .badge-warning {
            background: #ed8936;
            color: white;
        }
        
        .badge-danger {
            background: #f56565;
            color: white;
        }
        
        /* Tables */
        .table {
            margin-bottom: 0;
        }
        
        .table th {
            background: #f7fafc;
            border: none;
            font-weight: 600;
            color: #2d3748;
            padding: 15px;
        }
        
        .table td {
            border-color: #e2e8f0;
            vertical-align: middle;
            padding: 15px;
        }
        
        .table-responsive {
            border-radius: 10px;
            overflow: hidden;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 40px;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .empty-state h5 {
            margin-bottom: 10px;
            color: #2d3748;
        }
        
        .backup-recommendations {
            margin-top: 20px;
        }
        
        .backup-recommendations h6 {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 15px;
        }
        
        .backup-recommendations ul {
            padding-left: 20px;
            margin: 0;
        }
        
        .backup-recommendations li {
            margin-bottom: 8px;
            color: #6c757d;
        }
        
        /* Grid Layout */
        .backup-grid {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 30px;
        }
        
        /* Responsive Design */
        @media (max-width: 992px) {
            .backup-grid {
                grid-template-columns: 1fr;
            }
            
            .main-container {
                padding: 15px;
            }
            
            .page-header {
                padding: 30px 20px;
            }
            
            .page-title {
                font-size: 2rem;
            }
        }
        
        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .fade-in-up {
            animation: fadeInUp 0.6s ease-out;
        }
        
        /* Loading States */
        .btn.loading {
            position: relative;
            color: transparent;
        }
        
        .btn.loading::after {
            content: '';
            position: absolute;
            width: 16px;
            height: 16px;
            top: 50%;
            left: 50%;
            margin-left: -8px;
            margin-top: -8px;
            border: 2px solid transparent;
            border-top-color: currentColor;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <div class="top-nav">
        <div class="nav-left">
            <a href="../index.php" class="brand">
                <i class="fas fa-download"></i>
                <?php echo APP_NAME; ?> Backup
            </a>
        </div>
        
        <div class="user-info">
            <a href="index.php" class="back-btn">
                <i class="fas fa-arrow-left"></i>
                Back to Settings
            </a>
            <div class="user-avatar">
                <?php echo strtoupper(substr($currentUser['first_name'], 0, 1)); ?>
            </div>
            <div>
                <div style="font-weight: 600;"><?php echo htmlspecialchars($userDisplayName); ?></div>
                <div style="font-size: 0.8rem; color: #6c757d;">Administrator</div>
            </div>
        </div>
    </div>

    <div class="main-container">
        <!-- Page Header -->
        <div class="page-header fade-in-up">
            <h1 class="page-title">Backup Management</h1>
            <p class="page-subtitle">Create, download, and manage database backups to protect your system data.</p>
        </div>

        <!-- Flash Messages -->
        <?php if ($flashMessages && isset($flashMessages['type']) && isset($flashMessages['message'])): ?>
            <div class="alert alert-<?php echo $flashMessages['type'] === 'error' ? 'danger' : $flashMessages['type']; ?> fade-in-up">
                <i class="fas fa-<?php echo $flashMessages['type'] === 'error' ? 'exclamation-triangle' : 'check-circle'; ?> me-2"></i>
                <?php echo htmlspecialchars($flashMessages['message']); ?>
            </div>
        <?php endif; ?>

        <!-- Main Content Grid -->
        <div class="backup-grid fade-in-up">
            <!-- Left Column: Create Backup & Info -->
            <div>
                <!-- Create New Backup -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">
                            <i class="fas fa-plus-circle"></i>
                            Create New Backup
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="backupForm">
                            <input type="hidden" name="action" value="create_backup">
                            
                            <div class="form-group">
                                <label class="form-label" for="backup_type">Backup Type</label>
                                <select class="form-select" id="backup_type" name="backup_type" required>
                                    <option value="Full">Full Backup</option>
                                    <option value="Incremental">Incremental Backup</option>
                                </select>
                                <div class="form-text">
                                    Full backup includes all data. Incremental excludes logs.
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="include_uploads" name="include_uploads">
                                    <label class="form-check-label" for="include_uploads">
                                        Include Uploaded Files
                                    </label>
                                </div>
                                <div class="form-text">
                                    Creates a ZIP file with database and all uploads
                                </div>
                            </div>
                            
                            <div class="backup-info">
                                <h6>What will be backed up:</h6>
                                <ul class="backup-items">
                                    <li><i class="fas fa-database"></i> Database structure and data</li>
                                    <li><i class="fas fa-users"></i> User accounts and roles</li>
                                    <li><i class="fas fa-building"></i> Business and property records</li>
                                    <li><i class="fas fa-file-invoice"></i> Bills and payments</li>
                                    <li><i class="fas fa-cog"></i> System settings</li>
                                    <li id="uploads-item" style="display: none;"><i class="fas fa-folder"></i> Uploaded files</li>
                                </ul>
                            </div>
                            
                            <button type="submit" class="btn btn-success btn-block">
                                <i class="fas fa-download"></i>
                                Create Backup
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Backup Information -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">
                            <i class="fas fa-info-circle"></i>
                            Backup Statistics
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="backup-stats">
                            <div class="stat-item">
                                <label>Total Backups:</label>
                                <span class="badge badge-primary"><?php echo count($backupFiles); ?></span>
                            </div>
                            <div class="stat-item">
                                <label>Storage Used:</label>
                                <span class="badge badge-info">
                                    <?php 
                                    $totalSize = array_sum(array_column($backupFiles, 'size'));
                                    echo formatBytes($totalSize);
                                    ?>
                                </span>
                            </div>
                            <div class="stat-item">
                                <label>Last Backup:</label>
                                <span class="badge badge-secondary">
                                    <?php 
                                    if (!empty($backupFiles)) {
                                        echo date('M d, Y H:i', $backupFiles[0]['created']);
                                    } else {
                                        echo 'Never';
                                    }
                                    ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="backup-recommendations">
                            <h6>Backup Best Practices:</h6>
                            <ul>
                                <li>Create regular backups before major updates</li>
                                <li>Store backups in multiple secure locations</li>
                                <li>Test backup restoration periodically</li>
                                <li>Keep at least 3 recent backups</li>
                                <li>Include uploads for complete data protection</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Backup Files & History -->
            <div>
                <!-- Available Backups -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">
                            <i class="fas fa-file-archive"></i>
                            Available Backups (<?php echo count($backupFiles); ?>)
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($backupFiles)): ?>
                            <div class="empty-state">
                                <i class="fas fa-folder-open"></i>
                                <h5>No Backups Found</h5>
                                <p>Create your first backup to get started with data protection.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Backup File</th>
                                            <th>Type</th>
                                            <th>Size</th>
                                            <th>Created</th>
                                            <th width="120">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($backupFiles as $backup): ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <i class="fas fa-<?php echo str_ends_with($backup['filename'], '.zip') ? 'file-archive' : 'database'; ?> me-2"></i>
                                                        <div>
                                                            <div class="fw-bold"><?php echo htmlspecialchars($backup['filename']); ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge badge-<?php echo str_ends_with($backup['filename'], '.zip') ? 'success' : 'info'; ?>">
                                                        <?php echo $backup['type']; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo formatBytes($backup['size']); ?></td>
                                                <td><?php echo date('M d, Y H:i', $backup['created']); ?></td>
                                                <td>
                                                    <div class="d-flex gap-2">
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="action" value="download_backup">
                                                            <input type="hidden" name="filename" value="<?php echo htmlspecialchars($backup['filename']); ?>">
                                                            <button type="submit" class="btn btn-outline-primary btn-sm" title="Download">
                                                                <i class="fas fa-download"></i>
                                                            </button>
                                                        </form>
                                                        
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="action" value="delete_backup">
                                                            <input type="hidden" name="filename" value="<?php echo htmlspecialchars($backup['filename']); ?>">
                                                            <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete" 
                                                                    onclick="return confirm('Are you sure you want to delete this backup file?')">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Backup History -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">
                            <i class="fas fa-history"></i>
                            Recent Backup Activity
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($backupLogs)): ?>
                            <div class="empty-state">
                                <i class="fas fa-clock"></i>
                                <h5>No Activity Yet</h5>
                                <p>Backup activity will appear here once you create your first backup.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Type</th>
                                            <th>Status</th>
                                            <th>Size</th>
                                            <th>Created By</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_slice($backupLogs, 0, 10) as $log): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($log['backup_type']); ?></td>
                                                <td>
                                                    <span class="badge badge-<?php 
                                                        echo $log['status'] === 'Completed' ? 'success' : 
                                                             ($log['status'] === 'Failed' ? 'danger' : 'warning'); 
                                                    ?>">
                                                        <?php echo $log['status']; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php echo $log['backup_size'] ? formatBytes($log['backup_size']) : '-'; ?>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? '')); ?>
                                                </td>
                                                <td><?php echo date('M d, Y H:i', strtotime($log['started_at'])); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Show/hide uploads item based on checkbox
        document.getElementById('include_uploads').addEventListener('change', function() {
            const uploadsItem = document.getElementById('uploads-item');
            uploadsItem.style.display = this.checked ? 'list-item' : 'none';
        });

        // Form submission handler
        document.getElementById('backupForm').addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            
            // Show loading state
            submitBtn.classList.add('loading');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating Backup...';
            
            // Show progress message
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-info mt-3';
            alertDiv.innerHTML = '<i class="fas fa-info-circle me-2"></i>Creating backup... This may take a few minutes depending on database size and selected options.';
            this.appendChild(alertDiv);
            
            // Re-enable after 2 minutes (fallback)
            setTimeout(() => {
                submitBtn.classList.remove('loading');
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
                if (alertDiv.parentNode) {
                    alertDiv.remove();
                }
            }, 120000);
        });

        // Auto-dismiss alerts
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(() => {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(alert => {
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 300);
                });
            }, 5000);
        });

        // Confirmation for delete actions
        document.querySelectorAll('form button[title="Delete"]').forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                if (confirm('Are you sure you want to delete this backup file? This action cannot be undone.')) {
                    this.closest('form').submit();
                }
            });
        });
    </script>
</body>
</html>