 <?php
/**
 * Admin System Restore - QUICKBILL 305
 * Restore database from backup files
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

// Check if user is logged in and has restore privileges
if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

if (!hasPermission('backup.restore')) {
    setFlashMessage('error', 'Access denied. System restore privileges required.');
    redirect('../admin/index.php');
}

$currentUser = getCurrentUser();
$pageTitle = 'System Restore';

// Backup directory
$backupDir = STORAGE_PATH . '/backups';
if (!is_dir($backupDir)) {
    createDirectory($backupDir);
}

// Handle restore actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Invalid security token. Please try again.');
        redirect($_SERVER['PHP_SELF']);
    }

    $action = $_POST['action'] ?? '';

    try {
        $db = new Database();

        switch ($action) {
            case 'restore_database':
                $backupFile = sanitizeInput($_POST['backup_file']);
                $confirmText = sanitizeInput($_POST['confirm_text']);
                
                // Validate confirmation text
                if ($confirmText !== 'RESTORE') {
                    throw new Exception('Please type "RESTORE" to confirm this dangerous operation');
                }
                
                $backupPath = $backupDir . '/' . $backupFile;
                
                // Validate backup file
                if (!file_exists($backupPath) || strpos($backupFile, '..') !== false) {
                    throw new Exception('Backup file not found or invalid');
                }
                
                if (!str_ends_with($backupFile, '.sql')) {
                    throw new Exception('Only SQL backup files can be restored');
                }
                
                // Create restoration point (current database backup)
                $restorationPoint = createRestorationPoint();
                
                if (!$restorationPoint['success']) {
                    throw new Exception('Failed to create restoration point: ' . $restorationPoint['error']);
                }
                
                // Perform database restore
                $restoreResult = restoreDatabase($backupPath);
                
                if ($restoreResult['success']) {
                    logUserAction('DATABASE_RESTORED', 'system_settings', null, null, [
                        'backup_file' => $backupFile,
                        'restoration_point' => $restorationPoint['file']
                    ]);
                    
                    setFlashMessage('success', 'Database restored successfully from backup: ' . $backupFile);
                    setFlashMessage('info', 'A restoration point was created before restore: ' . basename($restorationPoint['file']));
                } else {
                    throw new Exception($restoreResult['error']);
                }
                break;

            case 'restore_uploads':
                $uploadFile = sanitizeInput($_POST['upload_file']);
                $uploadPath = $backupDir . '/' . $uploadFile;
                
                if (!file_exists($uploadPath) || strpos($uploadFile, '..') !== false) {
                    throw new Exception('Upload backup file not found or invalid');
                }
                
                if (!str_ends_with($uploadFile, '.zip')) {
                    throw new Exception('Only ZIP upload backups can be restored');
                }
                
                $restoreResult = restoreUploads($uploadPath);
                
                if ($restoreResult['success']) {
                    logUserAction('UPLOADS_RESTORED', 'system_settings', null, null, ['upload_file' => $uploadFile]);
                    setFlashMessage('success', 'Upload files restored successfully from: ' . $uploadFile);
                } else {
                    throw new Exception($restoreResult['error']);
                }
                break;

            case 'upload_backup':
                if (!isset($_FILES['backup_upload']) || $_FILES['backup_upload']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('No backup file uploaded or upload error occurred');
                }
                
                $uploadedFile = $_FILES['backup_upload'];
                $fileName = $uploadedFile['name'];
                $tempPath = $uploadedFile['tmp_name'];
                $fileSize = $uploadedFile['size'];
                
                // Validate file type
                if (!str_ends_with($fileName, '.sql') && !str_ends_with($fileName, '.zip')) {
                    throw new Exception('Only SQL and ZIP backup files are allowed');
                }
                
                // Validate file size (max 100MB)
                if ($fileSize > 100 * 1024 * 1024) {
                    throw new Exception('Backup file too large. Maximum size is 100MB');
                }
                
                // Generate safe filename
                $safeFileName = 'uploaded_' . date('Y-m-d_H-i-s') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $fileName);
                $destinationPath = $backupDir . '/' . $safeFileName;
                
                if (move_uploaded_file($tempPath, $destinationPath)) {
                    logUserAction('BACKUP_UPLOADED', 'system_settings', null, null, ['filename' => $safeFileName]);
                    setFlashMessage('success', 'Backup file uploaded successfully: ' . $safeFileName);
                } else {
                    throw new Exception('Failed to save uploaded backup file');
                }
                break;
        }

    } catch (Exception $e) {
        writeLog("Restore operation error: " . $e->getMessage(), 'ERROR');
        setFlashMessage('error', $e->getMessage());
    }

    redirect($_SERVER['PHP_SELF']);
}

// Get available backup files
$backupFiles = [];
$sqlBackups = [];
$zipBackups = [];

try {
    if (is_dir($backupDir)) {
        $files = scandir($backupDir);
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..') {
                $filepath = $backupDir . '/' . $file;
                $fileInfo = [
                    'filename' => $file,
                    'size' => filesize($filepath),
                    'created' => filemtime($filepath),
                    'path' => $filepath
                ];
                
                if (str_ends_with($file, '.sql')) {
                    $sqlBackups[] = $fileInfo;
                } elseif (str_ends_with($file, '.zip')) {
                    $zipBackups[] = $fileInfo;
                }
                
                $backupFiles[] = $fileInfo;
            }
        }
        
        // Sort by creation date (newest first)
        usort($sqlBackups, function($a, $b) { return $b['created'] - $a['created']; });
        usort($zipBackups, function($a, $b) { return $b['created'] - $a['created']; });
    }
} catch (Exception $e) {
    writeLog("Error reading backup directory: " . $e->getMessage(), 'ERROR');
}

// Function to create restoration point
function createRestorationPoint() {
    global $backupDir, $currentUser, $db;
    
    try {
        $timestamp = date('Y-m-d_H-i-s');
        $filename = "restoration_point_before_restore_{$timestamp}.sql";
        $filepath = $backupDir . '/' . $filename;
        
        $result = createDatabaseBackup($filepath, 'Full');
        
        if ($result['success']) {
            return ['success' => true, 'file' => $filepath];
        } else {
            return ['success' => false, 'error' => $result['error']];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Function to restore database
function restoreDatabase($backupPath) {
    try {
        $db = new Database();
        $conn = $db->getConnection();
        
        // Read SQL file
        $sql = file_get_contents($backupPath);
        
        if ($sql === false) {
            return ['success' => false, 'error' => 'Failed to read backup file'];
        }
        
        // Disable foreign key checks
        $conn->exec('SET FOREIGN_KEY_CHECKS = 0');
        
        // Split SQL into individual statements
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        
        $successCount = 0;
        $errorCount = 0;
        
        foreach ($statements as $statement) {
            if (!empty($statement) && !str_starts_with($statement, '--')) {
                try {
                    $conn->exec($statement);
                    $successCount++;
                } catch (PDOException $e) {
                    $errorCount++;
                    writeLog("SQL statement error during restore: " . $e->getMessage(), 'ERROR');
                }
            }
        }
        
        // Re-enable foreign key checks
        $conn->exec('SET FOREIGN_KEY_CHECKS = 1');
        
        if ($errorCount > 0) {
            writeLog("Database restore completed with $errorCount errors out of " . ($successCount + $errorCount) . " statements", 'WARNING');
        }
        
        return ['success' => true, 'statements' => $successCount, 'errors' => $errorCount];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Function to restore uploads
function restoreUploads($zipPath) {
    try {
        $zip = new ZipArchive();
        
        if ($zip->open($zipPath) !== TRUE) {
            return ['success' => false, 'error' => 'Cannot open ZIP file'];
        }
        
        // Extract uploads to uploads directory
        $extractPath = dirname(UPLOADS_PATH);
        
        if (!$zip->extractTo($extractPath)) {
            $zip->close();
            return ['success' => false, 'error' => 'Failed to extract files'];
        }
        
        $zip->close();
        
        return ['success' => true];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Reuse the backup creation function from backup.php
function createDatabaseBackup($backupPath, $backupType = 'Full') {
    try {
        $db = new Database();
        $conn = $db->getConnection();
        
        // Get database name
        $dbName = DB_NAME;
        
        // Start building SQL dump
        $sqlDump = "-- QUICKBILL 305 Database Backup (Restoration Point)\n";
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
            
            // Get table data
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

include '../header.php';
?>

<div class="admin-content">
    <div class="container-fluid">
        <!-- Page Header -->
        <div class="page-header">
            <div class="row align-items-center">
                <div class="col">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="../index.php">Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="index.php">Settings</a></li>
                            <li class="breadcrumb-item active">System Restore</li>
                        </ol>
                    </nav>
                    <h1 class="page-title">
                        <i class="fas fa-upload"></i>
                        System Restore
                    </h1>
                    <p class="page-subtitle">Restore database and files from backup</p>
                </div>
                <div class="col-auto">
                    <a href="backup.php" class="btn btn-outline-success">
                        <i class="fas fa-download"></i>
                        Create Backup
                    </a>
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Back to Settings
                    </a>
                </div>
            </div>
        </div>

        <!-- Flash Messages -->
        <?php include '../includes/flash_messages.php'; ?>

        <!-- Warning Alert -->
        <div class="alert alert-danger">
            <div class="row align-items-center">
                <div class="col-auto">
                    <i class="fas fa-exclamation-triangle fa-2x"></i>
                </div>
                <div class="col">
                    <h4 class="alert-heading">⚠️ Critical Warning</h4>
                    <p class="mb-2">
                        <strong>System restore is a dangerous operation that will overwrite your current data.</strong>
                    </p>
                    <ul class="mb-0">
                        <li>All current data will be permanently replaced with backup data</li>
                        <li>A restoration point will be automatically created before restore</li>
                        <li>This operation cannot be undone without another restore</li>
                        <li>Ensure all users are logged out before proceeding</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Upload New Backup -->
            <div class="col-xl-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">
                            <i class="fas fa-cloud-upload-alt"></i>
                            Upload Backup File
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data" id="uploadForm">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <input type="hidden" name="action" value="upload_backup">
                            
                            <div class="form-group">
                                <label for="backup_upload">Select Backup File</label>
                                <div class="custom-file">
                                    <input type="file" 
                                           class="custom-file-input" 
                                           id="backup_upload" 
                                           name="backup_upload" 
                                           accept=".sql,.zip"
                                           required>
                                    <label class="custom-file-label" for="backup_upload">Choose file...</label>
                                </div>
                                <small class="form-text text-muted">
                                    Supported formats: .sql (database), .zip (with uploads)<br>
                                    Maximum size: 100MB
                                </small>
                            </div>
                            
                            <button type="submit" class="btn btn-primary btn-block">
                                <i class="fas fa-upload"></i>
                                Upload Backup
                            </button>
                        </form>
                    </div>
                </div>

                <!-- System Status -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">
                            <i class="fas fa-info-circle"></i>
                            System Status
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="status-list">
                            <div class="status-item">
                                <label>Database:</label>
                                <span class="badge badge-success">Connected</span>
                            </div>
                            <div class="status-item">
                                <label>Backup Directory:</label>
                                <span class="badge badge-<?php echo is_writable($backupDir) ? 'success' : 'danger'; ?>">
                                    <?php echo is_writable($backupDir) ? 'Writable' : 'Not Writable'; ?>
                                </span>
                            </div>
                            <div class="status-item">
                                <label>Available Backups:</label>
                                <span class="badge badge-info"><?php echo count($backupFiles); ?></span>
                            </div>
                            <div class="status-item">
                                <label>SQL Backups:</label>
                                <span class="badge badge-primary"><?php echo count($sqlBackups); ?></span>
                            </div>
                            <div class="status-item">
                                <label>ZIP Backups:</label>
                                <span class="badge badge-success"><?php echo count($zipBackups); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Database Restore -->
            <div class="col-xl-8">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">
                            <i class="fas fa-database"></i>
                            Database Restore
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($sqlBackups)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="fas fa-database fa-3x mb-3"></i>
                                <h5>No SQL Backups Available</h5>
                                <p>Upload a SQL backup file to restore the database.</p>
                            </div>
                        <?php else: ?>
                            <form method="POST" id="restoreForm">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                <input type="hidden" name="action" value="restore_database">
                                
                                <div class="form-group">
                                    <label for="backup_file">Select Backup to Restore</label>
                                    <select class="form-control" id="backup_file" name="backup_file" required>
                                        <option value="">Choose backup file...</option>
                                        <?php foreach ($sqlBackups as $backup): ?>
                                            <option value="<?php echo htmlspecialchars($backup['filename']); ?>">
                                                <?php echo htmlspecialchars($backup['filename']); ?> 
                                                (<?php echo formatBytes($backup['size']); ?>, 
                                                <?php echo formatDateTime(date('Y-m-d H:i:s', $backup['created'])); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="restore-warning">
                                    <div class="alert alert-warning">
                                        <h6><i class="fas fa-exclamation-triangle"></i> Before You Proceed:</h6>
                                        <ul class="mb-2">
                                            <li>A restoration point will be created automatically</li>
                                            <li>All current database data will be overwritten</li>
                                            <li>User sessions will be terminated</li>
                                            <li>System settings will be reset to backup state</li>
                                        </ul>
                                        
                                        <div class="form-group mt-3">
                                            <label for="confirm_text">Type <strong>RESTORE</strong> to confirm:</label>
                                            <input type="text" 
                                                   class="form-control" 
                                                   id="confirm_text" 
                                                   name="confirm_text" 
                                                   placeholder="Type RESTORE to confirm"
                                                   required>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-actions">
                                    <button type="submit" class="btn btn-danger btn-lg" id="restoreBtn" disabled>
                                        <i class="fas fa-upload"></i>
                                        Restore Database
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Upload Files Restore -->
                <?php if (!empty($zipBackups)): ?>
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">
                            <i class="fas fa-folder"></i>
                            Upload Files Restore
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="uploadRestoreForm">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <input type="hidden" name="action" value="restore_uploads">
                            
                            <div class="form-group">
                                <label for="upload_file">Select ZIP Backup to Restore</label>
                                <select class="form-control" id="upload_file" name="upload_file" required>
                                    <option value="">Choose ZIP backup...</option>
                                    <?php foreach ($zipBackups as $backup): ?>
                                        <option value="<?php echo htmlspecialchars($backup['filename']); ?>">
                                            <?php echo htmlspecialchars($backup['filename']); ?> 
                                            (<?php echo formatBytes($backup['size']); ?>, 
                                            <?php echo formatDateTime(date('Y-m-d H:i:s', $backup['created'])); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i>
                                This will restore uploaded files (logos, receipts, etc.) from the ZIP backup.
                                Existing upload files will be overwritten.
                            </div>

                            <button type="submit" class="btn btn-warning">
                                <i class="fas fa-folder-open"></i>
                                Restore Upload Files
                            </button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Available Backups List -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">
                            <i class="fas fa-list"></i>
                            Available Backup Files
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($backupFiles)): ?>
                            <div class="text-center text-muted py-3">
                                <p>No backup files available</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-striped">
                                    <thead>
                                        <tr>
                                            <th>Filename</th>
                                            <th>Type</th>
                                            <th>Size</th>
                                            <th>Created</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($backupFiles as $backup): ?>
                                            <tr>
                                                <td>
                                                    <i class="fas fa-<?php echo str_ends_with($backup['filename'], '.zip') ? 'file-archive' : 'database'; ?>"></i>
                                                    <?php echo htmlspecialchars($backup['filename']); ?>
                                                </td>
                                                <td>
                                                    <span class="badge badge-<?php echo str_ends_with($backup['filename'], '.zip') ? 'success' : 'primary'; ?>">
                                                        <?php echo str_ends_with($backup['filename'], '.zip') ? 'ZIP' : 'SQL'; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo formatBytes($backup['size']); ?></td>
                                                <td><?php echo formatDateTime(date('Y-m-d H:i:s', $backup['created'])); ?></td>
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
</div>

<style>
.status-list .status-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.5rem 0;
    border-bottom: 1px solid #f8f9fa;
}

.status-list .status-item:last-child {
    border-bottom: none;
}

.status-list label {
    font-weight: 500;
    margin-bottom: 0;
    color: #6c757d;
}

.restore-warning {
    margin: 1.5rem 0;
}

.form-actions {
    margin-top: 2rem;
    padding-top: 1.5rem;
    border-top: 1px solid #e9ecef;
}

.custom-file-label::after {
    content: "Browse";
}

.page-header {
    margin-bottom: 2rem;
}

#restoreBtn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
</style>

<script>
// Enable restore button only when confirmation text is correct
document.getElementById('confirm_text').addEventListener('input', function() {
    const restoreBtn = document.getElementById('restoreBtn');
    restoreBtn.disabled = this.value !== 'RESTORE';
});

// Custom file input label update
document.querySelector('.custom-file-input').addEventListener('change', function(e) {
    const fileName = e.target.files[0]?.name || 'Choose file...';
    const label = e.target.nextElementSibling;
    label.textContent = fileName;
});

// Form submission handlers
document.getElementById('restoreForm').addEventListener('submit', function(e) {
    if (!confirm('Are you absolutely sure you want to restore the database? This action cannot be undone!')) {
        e.preventDefault();
        return;
    }
    
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Restoring Database...';
    submitBtn.disabled = true;
    
    // Show progress message
    const progressDiv = document.createElement('div');
    progressDiv.className = 'alert alert-info mt-3';
    progressDiv.innerHTML = '<i class="fas fa-info-circle"></i> Restoring database... Please do not close this page. This may take several minutes.';
    this.appendChild(progressDiv);
});

document.getElementById('uploadRestoreForm').addEventListener('submit', function(e) {
    if (!confirm('Are you sure you want to restore upload files? Existing files will be overwritten.')) {
        e.preventDefault();
        return;
    }
    
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Restoring Files...';
    submitBtn.disabled = true;
    
    // Re-enable after 10 seconds (fallback)
    setTimeout(() => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }, 10000);
});

document.getElementById('uploadForm').addEventListener('submit', function(e) {
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
    submitBtn.disabled = true;
    
    // Re-enable after 30 seconds (fallback)
    setTimeout(() => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }, 30000);
});

// Format bytes function (same as backup.php)
<?php
function formatBytes($size, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }
    
    return round($size, $precision) . ' ' . $units[$i];
}
?>
</script>

<?php include '../footer.php'; ?>
