<?php
/**
 * Fee Structure Import - QUICKBILL 305 (FIXED)
 * Bulk import business and property fee structures from CSV/Excel files
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

// Check authentication and permissions
if (!isAdmin()) {
    setFlashMessage('error', 'Access denied. Admin privileges required.');
    header('Location: ../../auth/login.php');
    exit();
}

if (!hasPermission('fees.create')) {
    setFlashMessage('error', 'Access denied. Fee creation permission required.');
    header('Location: index.php');
    exit();
}

// Check session expiration
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > 5600)) {
    // Session expired (30 minutes)
    session_unset();
    session_destroy();
    setFlashMessage('error', 'Your session has expired. Please log in again.');
    header('Location: ../../index.php');
    exit();
}

$pageTitle = 'Import Fee Structures';
$currentUser = getCurrentUser();
$userDisplayName = getUserDisplayName($currentUser);

// Initialize variables
$importResults = null;
$previewData = null;
$errors = [];
$importType = '';

// Handle file upload and processing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Debug POST data
    error_log("POST REQUEST - Action: " . ($_POST['action'] ?? 'none'));
    error_log("POST REQUEST - Import Type: " . ($_POST['import_type'] ?? 'none'));
    error_log("POST REQUEST - Preview Data Length: " . strlen($_POST['preview_data'] ?? ''));
    
    // Verify CSRF token
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Invalid security token. Please try again.');
        header('Location: import_fees.php');
        exit();
    }
    
    $importType = $_POST['import_type'] ?? '';
    $action = $_POST['action'] ?? '';
    
    if ($action === 'upload' && isset($_FILES['import_file'])) {
        $uploadResult = handleFileUpload($_FILES['import_file'], $importType);
        if ($uploadResult['success']) {
            $previewData = $uploadResult['data'];
            // Store any info messages
            if (isset($uploadResult['info'])) {
                setFlashMessage('info', $uploadResult['info']);
            }
        } else {
            $errors = $uploadResult['errors'];
        }
    } elseif ($action === 'import' && isset($_POST['preview_data'])) {
        $importResult = processImport($_POST['preview_data'], $importType);
        $importResults = $importResult;
    }
}

/**
 * Handle file upload and validation - ENHANCED FOR LARGE FILES
 */
function handleFileUpload($file, $importType) {
    $result = ['success' => false, 'data' => [], 'errors' => []];
    
    // Validate file upload
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $result['errors'][] = 'File upload failed. Error code: ' . $file['error'];
        return $result;
    }
    
    // Check file size (increase limit for large imports)
    $maxSize = 10 * 1024 * 1024; // 10MB limit
    if ($file['size'] > $maxSize) {
        $result['errors'][] = 'File size too large. Maximum size is 10MB. Your file: ' . round($file['size'] / 1024 / 1024, 2) . 'MB';
        return $result;
    }
    
    // Log file info
    error_log("Uploading file: " . $file['name'] . " (" . $file['size'] . " bytes)");
    
    // Check file extension
    $allowedExtensions = ['csv', 'xlsx', 'xls'];
    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($fileExtension, $allowedExtensions)) {
        $result['errors'][] = 'Invalid file format. Please upload CSV, XLS, or XLSX files only.';
        return $result;
    }
    
    // Create upload directory if it doesn't exist
    $uploadDir = '../../uploads/imports/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Generate unique filename
    $filename = uniqid('import_') . '_' . time() . '.' . $fileExtension;
    $filepath = $uploadDir . $filename;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        $result['errors'][] = 'Failed to save uploaded file.';
        return $result;
    }
    
    // Parse file based on extension
    try {
        if ($fileExtension === 'csv') {
            $data = parseCSV($filepath);
        } else {
            $result['errors'][] = 'Excel files not currently supported. Please use CSV format.';
            unlink($filepath);
            return $result;
        }
        
        // Check if file is too large (more than 500 rows)
        if (count($data) >= 500) {
            $result['errors'][] = "Large file detected (" . count($data) . " rows). Only first 500 rows will be processed. Consider splitting your file into smaller chunks.";
            // Don't return error, just warn and continue
        }
        
        // Validate data structure
        $validationResult = validateImportData($data, $importType);
        if (!$validationResult['success']) {
            $result['errors'] = array_merge($result['errors'], $validationResult['errors']);
            unlink($filepath);
            return $result;
        }
        
        $result['success'] = true;
        $result['data'] = $validationResult['data'];
        
        // Add info about processed rows
        if (count($validationResult['data']) !== count($data)) {
            $skipped = count($data) - count($validationResult['data']);
            $result['info'] = "Processed " . count($validationResult['data']) . " valid rows, skipped $skipped rows due to validation errors.";
        }
        
        // Clean up uploaded file
        unlink($filepath);
        
    } catch (Exception $e) {
        error_log("Import file parsing error: " . $e->getMessage());
        $result['errors'][] = 'Error parsing file: ' . $e->getMessage();
        if (file_exists($filepath)) {
            unlink($filepath);
        }
    }
    
    return $result;
}

/**
 * Parse CSV file - ENHANCED FOR LARGE FILES & ENCODING
 */
function parseCSV($filepath) {
    $data = [];
    
    // Debug: Log file info
    error_log("Parsing CSV file: " . $filepath);
    error_log("File size: " . filesize($filepath) . " bytes");
    
    // Detect file encoding
    $content = file_get_contents($filepath);
    $encoding = mb_detect_encoding($content, ['UTF-8', 'UTF-16', 'Windows-1252', 'ISO-8859-1'], true);
    error_log("Detected encoding: " . ($encoding ?: 'unknown'));
    
    // Convert to UTF-8 if needed
    if ($encoding && $encoding !== 'UTF-8') {
        $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        // Save converted content to temporary file
        $tempFile = $filepath . '_utf8';
        file_put_contents($tempFile, $content);
        $filepath = $tempFile;
        error_log("Converted file to UTF-8: " . $tempFile);
    }
    
    if (($handle = fopen($filepath, 'r')) !== FALSE) {
        $headers = fgetcsv($handle); // Get header row
        
        if (!$headers) {
            fclose($handle);
            throw new Exception('Invalid CSV file - no headers found');
        }
        
        // Debug: Log original headers
        error_log("Original headers: " . print_r($headers, true));
        
        // Clean headers (remove BOM, trim whitespace, handle special characters)
        $headers = array_map(function($header) {
            $cleaned = trim(str_replace(["\xEF\xBB\xBF", "\n", "\r", "\0"], '', $header));
            return $cleaned;
        }, $headers);
        
        // Debug: Log cleaned headers
        error_log("Cleaned headers: " . print_r($headers, true));
        
        $rowNumber = 1;
        $totalRows = 0;
        $skippedRows = 0;
        $processedRows = 0;
        $maxRows = 500; // Limit for large files
        
        while (($row = fgetcsv($handle)) !== FALSE && $processedRows < $maxRows) {
            $rowNumber++;
            $totalRows++;
            
            // Debug: Log first few rows
            if ($totalRows <= 5) {
                error_log("Row $rowNumber data: " . print_r($row, true));
                error_log("Row $rowNumber column count: " . count($row) . " vs headers: " . count($headers));
            }
            
            // Skip empty rows
            if (empty(array_filter($row, function($cell) { return trim($cell) !== ''; }))) {
                $skippedRows++;
                continue;
            }
            
            // Handle rows with different column counts
            if (count($row) < count($headers)) {
                // Pad with empty strings
                $row = array_pad($row, count($headers), '');
                error_log("Padded row $rowNumber to match header count");
            } elseif (count($row) > count($headers)) {
                // Trim extra columns
                $row = array_slice($row, 0, count($headers));
                error_log("Trimmed row $rowNumber to match header count");
            }
            
            $rowData = array_combine($headers, $row);
            $rowData['_row_number'] = $rowNumber;
            $data[] = $rowData;
            $processedRows++;
        }
        
        error_log("CSV parsing complete - Total rows: $totalRows, Processed: $processedRows, Valid: " . count($data) . ", Skipped: $skippedRows");
        
        if ($totalRows > $maxRows) {
            error_log("WARNING: File has $totalRows rows, only processed first $maxRows rows");
        }
        
        fclose($handle);
        
        // Clean up temporary UTF-8 file if created
        if (isset($tempFile) && file_exists($tempFile)) {
            unlink($tempFile);
        }
        
    } else {
        throw new Exception('Unable to open CSV file');
    }
    
    return $data;
}

/**
 * Validate import data - ENHANCED DEBUG VERSION
 */
function validateImportData($data, $importType) {
    $result = ['success' => false, 'data' => [], 'errors' => []];
    
    error_log("Validating import data - Type: $importType, Records: " . count($data));
    
    if (empty($data)) {
        $result['errors'][] = 'No data found in the file.';
        return $result;
    }
    
    $validData = [];
    $errors = [];
    $rejectedCount = 0;
    
    foreach ($data as $index => $row) {
        $rowErrors = [];
        $rowNumber = $row['_row_number'] ?? $index + 2;
        
        // Debug: Log first few rows being validated
        if ($index < 3) {
            error_log("Validating row $rowNumber: " . print_r($row, true));
        }
        
        if ($importType === 'business') {
            $validatedRow = validateBusinessFeeRow($row, $rowNumber, $rowErrors);
        } else {
            $validatedRow = validatePropertyFeeRow($row, $rowNumber, $rowErrors);
        }
        
        if (!empty($rowErrors)) {
            $errors = array_merge($errors, $rowErrors);
            $rejectedCount++;
            if ($index < 3) {
                error_log("Row $rowNumber rejected with errors: " . print_r($rowErrors, true));
            }
        } elseif ($validatedRow !== null) {
            $validData[] = $validatedRow;
            if ($index < 3) {
                error_log("Row $rowNumber validated successfully: " . print_r($validatedRow, true));
            }
        } else {
            $rejectedCount++;
            error_log("Row $rowNumber returned null from validation (no specific errors)");
        }
    }
    
    error_log("Validation complete - Valid: " . count($validData) . ", Rejected: $rejectedCount, Errors: " . count($errors));
    
    if (!empty($errors)) {
        $result['errors'] = $errors;
        return $result;
    }
    
    $result['success'] = true;
    $result['data'] = $validData;
    return $result;
}

/**
 * Validate business fee row - ENHANCED DEBUG VERSION
 */
function validateBusinessFeeRow($row, $rowNumber, &$errors) {
    $validRow = [];
    
    // Debug: Log available columns
    $availableColumns = array_keys($row);
    error_log("Row $rowNumber available columns: " . implode(', ', $availableColumns));
    
    // Required fields for business fees
    $requiredFields = ['business_type', 'category', 'fee_amount'];
    
    foreach ($requiredFields as $field) {
        if (!isset($row[$field])) {
            $errors[] = "Row $rowNumber: Column '$field' not found. Available columns: " . implode(', ', $availableColumns);
            return null;
        }
        
        if (trim($row[$field]) === '') {
            $errors[] = "Row $rowNumber: Field '$field' is empty";
            return null;
        }
    }
    
    $validRow['business_type'] = trim($row['business_type']);
    $validRow['category'] = trim($row['category']);
    
    // Validate fee amount
    $feeAmount = str_replace(['₵', ',', ' '], '', $row['fee_amount']);
    if (!is_numeric($feeAmount) || $feeAmount < 0) {
        $errors[] = "Row $rowNumber: Invalid fee amount '$feeAmount'. Must be a positive number.";
        return null;
    }
    $validRow['fee_amount'] = floatval($feeAmount);
    
    // Optional is_active field (default to 1)
    $validRow['is_active'] = isset($row['is_active']) ? 
        (in_array(strtolower(trim($row['is_active'])), ['1', 'true', 'yes', 'active']) ? 1 : 0) : 1;
    
    error_log("Row $rowNumber validated successfully for business: " . $validRow['business_type'] . " - " . $validRow['category']);
    
    return $validRow;
}

/**
 * Validate property fee row - ENHANCED DEBUG VERSION
 */
function validatePropertyFeeRow($row, $rowNumber, &$errors) {
    $validRow = [];
    
    // Debug: Log available columns
    $availableColumns = array_keys($row);
    error_log("Row $rowNumber available columns: " . implode(', ', $availableColumns));
    
    // Required fields for property fees
    $requiredFields = ['structure', 'property_use', 'fee_per_room'];
    
    foreach ($requiredFields as $field) {
        if (!isset($row[$field])) {
            $errors[] = "Row $rowNumber: Column '$field' not found. Available columns: " . implode(', ', $availableColumns);
            return null;
        }
        
        if (trim($row[$field]) === '') {
            $errors[] = "Row $rowNumber: Field '$field' is empty";
            return null;
        }
    }
    
    $validRow['structure'] = trim($row['structure']);
    
    // Validate property use
    $propertyUse = trim($row['property_use']);
    if (!in_array($propertyUse, ['Commercial', 'Residential'])) {
        $errors[] = "Row $rowNumber: Invalid property use '$propertyUse'. Must be 'Commercial' or 'Residential'.";
        return null;
    }
    $validRow['property_use'] = $propertyUse;
    
    // Validate fee per room
    $feePerRoom = str_replace(['₵', ',', ' '], '', $row['fee_per_room']);
    if (!is_numeric($feePerRoom) || $feePerRoom < 0) {
        $errors[] = "Row $rowNumber: Invalid fee per room '$feePerRoom'. Must be a positive number.";
        return null;
    }
    $validRow['fee_per_room'] = floatval($feePerRoom);
    
    // Optional is_active field (default to 1)
    $validRow['is_active'] = isset($row['is_active']) ? 
        (in_array(strtolower(trim($row['is_active'])), ['1', 'true', 'yes', 'active']) ? 1 : 0) : 1;
    
    error_log("Row $rowNumber validated successfully for property: " . $validRow['structure'] . " - " . $validRow['property_use']);
    
    return $validRow;
}

/**
 * Process import - FIXED VERSION
 */
function processImport($previewData, $importType) {
    global $currentUser;
    $result = ['success' => 0, 'failed' => 0, 'duplicates' => 0, 'errors' => []];
    
    // Debug logging
    error_log("processImport called with importType: " . $importType);
    error_log("previewData length: " . strlen($previewData));
    error_log("previewData first 100 chars: " . substr($previewData, 0, 100));
    
    if (empty($previewData)) {
        $result['errors'][] = 'No data to import.';
        return $result;
    }
    
    $data = json_decode($previewData, true);
    if (!$data || json_last_error() !== JSON_ERROR_NONE) {
        $result['errors'][] = 'Invalid import data format. JSON Error: ' . json_last_error_msg() . '. Data received: ' . substr($previewData, 0, 200);
        return $result;
    }
    
    if (empty($data)) {
        $result['errors'][] = 'Decoded data is empty. Original data: ' . substr($previewData, 0, 200);
        return $result;
    }
    
    try {
        // Get database connection - FIXED
        $pdo = getDBConnection();
        
        if (!$pdo) {
            throw new Exception('Database connection failed');
        }
        
        foreach ($data as $index => $row) {
            try {
                if ($importType === 'business') {
                    $imported = importBusinessFee($pdo, $row);
                } else {
                    $imported = importPropertyFee($pdo, $row);
                }
                
                if ($imported === 'duplicate') {
                    $result['duplicates']++;
                } elseif ($imported) {
                    $result['success']++;
                } else {
                    $result['failed']++;
                    $result['errors'][] = "Failed to import row " . ($index + 1);
                }
                
            } catch (Exception $e) {
                $result['failed']++;
                $typeName = isset($row['business_type']) ? $row['business_type'] : (isset($row['structure']) ? $row['structure'] : 'Row ' . ($index + 1));
                $result['errors'][] = "Failed to import $typeName: " . $e->getMessage();
                error_log("Import error for row " . ($index + 1) . ": " . $e->getMessage());
            }
        }

        // Log the import activity - FIXED
        try {
            logImportActivity($pdo, $importType, $result);
        } catch (Exception $e) {
            error_log("Failed to log import activity: " . $e->getMessage());
        }

    } catch (Exception $e) {
        error_log("Import processing error: " . $e->getMessage());
        $result['errors'][] = 'Database error during import: ' . $e->getMessage();
    }

    return $result;
}

/**
 * Get database connection - FIXED
 */
function getDBConnection() {
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database connection error: " . $e->getMessage());
        return false;
    }
}

/**
 * Import business fee - FIXED
 */
function importBusinessFee($pdo, $row) {
    global $currentUser;
    
    try {
        // Check for duplicate
        $stmt = $pdo->prepare("
            SELECT fee_id FROM business_fee_structure 
            WHERE business_type = ? AND category = ?
        ");
        $stmt->execute([$row['business_type'], $row['category']]);
        
        if ($stmt->fetch()) {
            return 'duplicate';
        }
        
        // Insert new business fee
        $stmt = $pdo->prepare("
            INSERT INTO business_fee_structure 
            (business_type, category, fee_amount, is_active, created_by, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
        ");
        
        $result = $stmt->execute([
            $row['business_type'],
            $row['category'],
            $row['fee_amount'],
            $row['is_active'],
            $currentUser['user_id']
        ]);
        
        return $result;
        
    } catch (PDOException $e) {
        error_log("Business fee import error: " . $e->getMessage());
        throw new Exception("Database error: " . $e->getMessage());
    }
}

/**
 * Import property fee - FIXED
 */
function importPropertyFee($pdo, $row) {
    global $currentUser;
    
    try {
        // Check for duplicate
        $stmt = $pdo->prepare("
            SELECT fee_id FROM property_fee_structure 
            WHERE structure = ? AND property_use = ?
        ");
        $stmt->execute([$row['structure'], $row['property_use']]);
        
        if ($stmt->fetch()) {
            return 'duplicate';
        }
        
        // Insert new property fee
        $stmt = $pdo->prepare("
            INSERT INTO property_fee_structure 
            (structure, property_use, fee_per_room, is_active, created_by, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
        ");
        
        $result = $stmt->execute([
            $row['structure'],
            $row['property_use'],
            $row['fee_per_room'],
            $row['is_active'],
            $currentUser['user_id']
        ]);
        
        return $result;
        
    } catch (PDOException $e) {
        error_log("Property fee import error: " . $e->getMessage());
        throw new Exception("Database error: " . $e->getMessage());
    }
}

/**
 * Log import activity - FIXED
 */
function logImportActivity($pdo, $importType, $results) {
    global $currentUser;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs 
            (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $currentUser['user_id'],
            'IMPORT_FEES',
            $importType . '_fee_structure',
            null,
            null,
            json_encode([
                'type' => $importType,
                'success' => $results['success'],
                'failed' => $results['failed'],
                'duplicates' => $results['duplicates'],
                'total_records' => $results['success'] + $results['failed'] + $results['duplicates']
            ]),
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
        
    } catch (PDOException $e) {
        error_log("Audit log error: " . $e->getMessage());
        throw new Exception("Failed to log activity: " . $e->getMessage());
    }
}

// Get flash messages
$flashMessages = getFlashMessages();
$flashMessage = !empty($flashMessages) ? $flashMessages[0] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - <?php echo APP_NAME; ?></title>
    
    <!-- Multiple Icon Sources -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://use.fontawesome.com/releases/v6.4.0/css/all.css">
    
    <!-- Bootstrap for backup -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
            overflow-x: hidden;
        }
        
        /* Custom Icons (fallback if Font Awesome fails) */
        .icon-dashboard::before { content: "📊"; }
        .icon-users::before { content: "👥"; }
        .icon-building::before { content: "🏢"; }
        .icon-home::before { content: "🏠"; }
        .icon-map::before { content: "🗺️"; }
        .icon-invoice::before { content: "📄"; }
        .icon-credit::before { content: "💳"; }
        .icon-tags::before { content: "🏷️"; }
        .icon-chart::before { content: "📈"; }
        .icon-bell::before { content: "🔔"; }
        .icon-cog::before { content: "⚙️"; }
        .icon-receipt::before { content: "🧾"; }
        .icon-menu::before { content: "☰"; }
        .icon-logout::before { content: "🚪"; }
        .icon-user::before { content: "👤"; }
        .icon-upload::before { content: "📁"; }
        .icon-download::before { content: "⬇️"; }
        .icon-import::before { content: "📥"; }
        .icon-export::before { content: "📤"; }
        .icon-check::before { content: "✅"; }
        .icon-times::before { content: "❌"; }
        .icon-warning::before { content: "⚠️"; }
        
        /* Top Navigation */
        .top-nav {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 20px;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .nav-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .toggle-btn {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            font-size: 18px;
            padding: 10px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .toggle-btn:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .brand {
            font-size: 24px;
            font-weight: bold;
            color: white;
            text-decoration: none;
        }
        
        .user-section {
            display: flex;
            align-items: center;
            gap: 15px;
            position: relative;
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            padding: 8px 12px;
            border-radius: 10px;
            transition: all 0.3s;
            position: relative;
        }
        
        .user-profile:hover {
            background: rgba(255,255,255,0.1);
        }
        
        .user-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(255,255,255,0.3) 0%, rgba(255,255,255,0.1) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            border: 2px solid rgba(255,255,255,0.2);
            transition: all 0.3s;
        }
        
        .user-info {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }
        
        .user-name {
            font-weight: 600;
            font-size: 14px;
            color: white;
        }
        
        .user-role {
            font-size: 12px;
            opacity: 0.8;
            color: rgba(255,255,255,0.8);
        }
        
        /* Layout */
        .container {
            margin-top: 80px;
            display: flex;
            min-height: calc(100vh - 80px);
        }
        
        /* Sidebar */
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, #2d3748 0%, #1a202c 100%);
            color: white;
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        .sidebar.hidden {
            width: 0;
            min-width: 0;
        }
        
        .sidebar-content {
            width: 280px;
            padding: 20px 0;
        }
        
        .nav-section {
            margin-bottom: 30px;
        }
        
        .nav-title {
            color: #a0aec0;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
            padding: 0 20px;
            margin-bottom: 10px;
            letter-spacing: 1px;
        }
        
        .nav-item {
            margin-bottom: 2px;
        }
        
        .nav-link {
            color: #e2e8f0;
            text-decoration: none;
            padding: 12px 20px;
            display: block;
            transition: all 0.3s;
            border-left: 3px solid transparent;
        }
        
        .nav-link:hover {
            background: rgba(255,255,255,0.1);
            color: white;
            border-left-color: #667eea;
        }
        
        .nav-link.active {
            background: rgba(102, 126, 234, 0.3);
            color: white;
            border-left-color: #667eea;
        }
        
        .nav-icon {
            display: inline-block;
            width: 20px;
            margin-right: 12px;
            text-align: center;
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            padding: 30px;
            background: #f8f9fa;
            transition: all 0.3s ease;
        }
        
        /* Breadcrumb */
        .breadcrumb-nav {
            background: white;
            border-radius: 15px;
            padding: 20px 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
            color: #64748b;
        }
        
        .breadcrumb a {
            color: #667eea;
            text-decoration: none;
        }
        
        .breadcrumb-current {
            color: #2d3748;
            font-weight: 600;
        }
        
        /* Page Header */
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 20px;
            padding: 40px;
            margin-bottom: 40px;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
            position: relative;
            overflow: hidden;
        }
        
        .page-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
            pointer-events: none;
        }
        
        .header-content {
            position: relative;
            z-index: 2;
            display: flex;
            align-items: center;
            gap: 30px;
        }
        
        .header-icon {
            width: 80px;
            height: 80px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            backdrop-filter: blur(10px);
        }
        
        .header-info h1 {
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .header-info p {
            font-size: 18px;
            opacity: 0.9;
            margin: 0;
        }
        
        /* Import Container */
        .import-container {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            position: relative;
        }
        
        .import-steps {
            display: flex;
            justify-content: space-between;
            margin-bottom: 40px;
            position: relative;
        }
        
        .import-steps::before {
            content: '';
            position: absolute;
            top: 25px;
            left: 25px;
            right: 25px;
            height: 2px;
            background: #e2e8f0;
            z-index: 1;
        }
        
        .step {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            z-index: 2;
        }
        
        .step-number {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: #e2e8f0;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-bottom: 10px;
            transition: all 0.3s;
        }
        
        .step.active .step-number {
            background: #667eea;
            color: white;
        }
        
        .step.completed .step-number {
            background: #48bb78;
            color: white;
        }
        
        .step-title {
            font-weight: 600;
            color: #2d3748;
            text-align: center;
        }
        
        /* Form Styles */
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2d3748;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s;
            background: white;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .file-upload-area {
            border: 2px dashed #cbd5e0;
            border-radius: 15px;
            padding: 40px;
            text-align: center;
            transition: all 0.3s;
            cursor: pointer;
            position: relative;
            background: #f8fafc;
        }
        
        .file-upload-area:hover {
            border-color: #667eea;
            background: #f0f4ff;
        }
        
        .file-upload-area.dragover {
            border-color: #667eea;
            background: #f0f4ff;
            transform: scale(1.02);
        }
        
        .upload-icon {
            font-size: 48px;
            color: #667eea;
            margin-bottom: 20px;
        }
        
        .upload-text {
            font-size: 18px;
            color: #2d3748;
            margin-bottom: 10px;
        }
        
        .upload-hint {
            color: #64748b;
            font-size: 14px;
        }
        
        .file-input {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }
        
        /* Preview Table */
        .preview-container {
            margin-top: 30px;
        }
        
        .preview-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        
        .preview-title {
            font-size: 20px;
            font-weight: bold;
            color: #2d3748;
        }
        
        .preview-count {
            background: #667eea;
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }
        
        .table-container {
            overflow-x: auto;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .preview-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }
        
        .preview-table th {
            background: #f8fafc;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #2d3748;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .preview-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .preview-table tr:hover {
            background: #f8fafc;
        }
        
        /* Results */
        .import-results {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .results-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .results-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }
        
        .results-icon.success {
            color: #48bb78;
        }
        
        .results-icon.warning {
            color: #ed8936;
        }
        
        .results-title {
            font-size: 24px;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 10px;
        }
        
        .results-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .result-stat {
            background: #f8fafc;
            padding: 20px;
            border-radius: 15px;
            text-align: center;
        }
        
        .result-number {
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 8px;
        }
        
        .result-number.success {
            color: #48bb78;
        }
        
        .result-number.error {
            color: #e53e3e;
        }
        
        .result-number.warning {
            color: #ed8936;
        }
        
        .result-label {
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 1px;
        }
        
        /* Buttons */
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            justify-content: center;
            font-size: 14px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(66, 153, 225, 0.3);
            color: white;
        }
        
        .btn-success {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(72, 187, 120, 0.3);
            color: white;
        }
        
        .btn-secondary {
            background: #e2e8f0;
            color: #2d3748;
        }
        
        .btn-secondary:hover {
            background: #cbd5e0;
            transform: translateY(-2px);
            color: #2d3748;
        }
        
        .btn-outline {
            background: transparent;
            color: #667eea;
            border: 2px solid #667eea;
        }
        
        .btn-outline:hover {
            background: #667eea;
            color: white;
        }
        
        .btn-group {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 30px;
        }
        
        /* Download Templates */
        .templates-section {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .templates-title {
            font-size: 20px;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 20px;
        }
        
        .template-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            margin-bottom: 15px;
            transition: all 0.3s;
        }
        
        .template-item:hover {
            border-color: #667eea;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .template-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .template-icon {
            width: 50px;
            height: 50px;
            background: #f0f4ff;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: #667eea;
        }
        
        .template-details h4 {
            margin: 0 0 5px 0;
            color: #2d3748;
            font-weight: 600;
        }
        
        .template-details p {
            margin: 0;
            color: #64748b;
            font-size: 14px;
        }
        
        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background: #d1fae5;
            border: 1px solid #9ae6b4;
            color: #065f46;
        }
        
        .alert-error {
            background: #fee2e2;
            border: 1px solid #fca5a5;
            color: #991b1b;
        }
        
        .alert-warning {
            background: #fef3cd;
            border: 1px solid #fde68a;
            color: #92400e;
        }
        
        .alert-info {
            background: #dbeafe;
            border: 1px solid #93c5fd;
            color: #1e40af;
        }
        
        /* Loading Overlay - FIXED */
        .loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.9);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            border-radius: 20px;
        }
        
        .loading-overlay.show {
            display: flex;
        }
        
        .spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        .loading-text {
            margin-left: 20px;
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
        }
        
        /* File Checker Modal */
        .file-checker-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
        }
        
        .file-checker-modal.show {
            display: flex;
        }
        
        .file-checker-content {
            background: white;
            border-radius: 15px;
            padding: 30px;
            max-width: 600px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .file-checker-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .file-checker-title {
            font-size: 20px;
            font-weight: bold;
            color: #2d3748;
        }
        
        .close-btn {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #64748b;
        }
        
        .format-example {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 15px;
            margin: 10px 0;
            font-family: monospace;
            font-size: 14px;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                height: 100%;
                z-index: 999;
                transform: translateX(-100%);
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            .sidebar.hidden {
                transform: translateX(-100%);
                width: 280px;
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .import-steps {
                flex-direction: column;
                gap: 20px;
            }
            
            .import-steps::before {
                display: none;
            }
            
            .header-content {
                flex-direction: column;
                text-align: center;
                gap: 20px;
            }
            
            .results-summary {
                grid-template-columns: 1fr;
            }
            
            .btn-group {
                flex-direction: column;
            }
        }
        
        /* Animations */
        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .import-container {
            animation: fadeInUp 0.5s ease forwards;
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <div class="top-nav">
        <div class="nav-left">
            <button class="toggle-btn" onclick="toggleSidebar()" id="toggleBtn">
                <i class="fas fa-bars"></i>
                <span class="icon-menu" style="display: none;"></span>
            </button>
            
            <a href="../index.php" class="brand">
                <i class="fas fa-receipt"></i>
                <span class="icon-receipt" style="display: none;"></span>
                <?php echo APP_NAME; ?>
            </a>
        </div>
        
        <div class="user-section">
            <div class="user-profile">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($currentUser['first_name'], 0, 1)); ?>
                </div>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($userDisplayName); ?></div>
                    <div class="user-role"><?php echo htmlspecialchars(getCurrentUserRole()); ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- File Format Checker Modal -->
    <div class="file-checker-modal" id="fileCheckerModal">
        <div class="file-checker-content">
            <div class="file-checker-header">
                <h3 class="file-checker-title">CSV Format Checker</h3>
                <button class="close-btn" onclick="hideFileChecker()">&times;</button>
            </div>
            
            <div>
                <h4>📋 Business Fee Structure Format</h4>
                <p>Your CSV must have exactly these headers (case-sensitive):</p>
                <div class="format-example">business_type,category,fee_amount,is_active
Restaurant,Small Scale,500.00,1
Shop,Medium Scale,800.00,1</div>
                
                <h4>🏠 Property Fee Structure Format</h4>
                <p>Your CSV must have exactly these headers (case-sensitive):</p>
                <div class="format-example">structure,property_use,fee_per_room,is_active
Concrete Block,Residential,50.00,1
Modern Building,Commercial,100.00,1</div>
                
                <h4>⚠️ Common Issues</h4>
                <ul style="margin-left: 20px;">
                    <li><strong>Wrong headers:</strong> Column names must match exactly</li>
                    <li><strong>Extra spaces:</strong> No spaces before/after column names</li>
                    <li><strong>Missing commas:</strong> Use commas as separators</li>
                    <li><strong>Currency symbols:</strong> Use numbers only (500.00, not ₵500.00)</li>
                    <li><strong>Property use:</strong> Must be exactly "Commercial" or "Residential"</li>
                    <li><strong>Empty rows:</strong> Remove any blank rows</li>
                </ul>
                
                <div style="margin-top: 20px; text-align: center;">
                    <button onclick="downloadTemplate('business')" class="btn btn-primary">
                        Download Business Template
                    </button>
                    <button onclick="downloadTemplate('property')" class="btn btn-primary" style="margin-left: 10px;">
                        Download Property Template
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-content">
                <!-- Dashboard -->
                <div class="nav-section">
                    <div class="nav-item">
                        <a href="../index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-tachometer-alt"></i>
                                <span class="icon-dashboard" style="display: none;"></span>
                            </span>
                            Dashboard
                        </a>
                    </div>
                </div>
                
                <!-- Core Management -->
                <div class="nav-section">
                    <div class="nav-title">Core Management</div>
                    <div class="nav-item">
                        <a href="../users/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-users"></i>
                                <span class="icon-users" style="display: none;"></span>
                            </span>
                            Users
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../businesses/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-building"></i>
                                <span class="icon-building" style="display: none;"></span>
                            </span>
                            Businesses
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../properties/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-home"></i>
                                <span class="icon-home" style="display: none;"></span>
                            </span>
                            Properties
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../zones/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-map-marked-alt"></i>
                                <span class="icon-map" style="display: none;"></span>
                            </span>
                            Zones & Areas
                        </a>
                    </div>
                </div>
                
                <!-- Billing & Payments -->
                <div class="nav-section">
                    <div class="nav-title">Billing & Payments</div>
                    <div class="nav-item">
                        <a href="../billing/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-file-invoice"></i>
                                <span class="icon-invoice" style="display: none;"></span>
                            </span>
                            Billing
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../payments/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-credit-card"></i>
                                <span class="icon-credit" style="display: none;"></span>
                            </span>
                            Payments
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="index.php" class="nav-link active">
                            <span class="nav-icon">
                                <i class="fas fa-tags"></i>
                                <span class="icon-tags" style="display: none;"></span>
                            </span>
                            Fee Structure
                        </a>
                    </div>
                </div>
                
                <!-- Reports & System -->
                <div class="nav-section">
                    <div class="nav-title">Reports & System</div>
                    <div class="nav-item">
                        <a href="../reports/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-chart-bar"></i>
                                <span class="icon-chart" style="display: none;"></span>
                            </span>
                            Reports
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../notifications/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-bell"></i>
                                <span class="icon-bell" style="display: none;"></span>
                            </span>
                            Notifications
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../settings/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-cog"></i>
                                <span class="icon-cog" style="display: none;"></span>
                            </span>
                            Settings
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Breadcrumb -->
            <div class="breadcrumb-nav">
                <div class="breadcrumb">
                    <a href="../index.php">Dashboard</a>
                    <span>/</span>
                    <a href="index.php">Fee Structure</a>
                    <span>/</span>
                    <span class="breadcrumb-current">Import Fees</span>
                </div>
            </div>

            <!-- Flash Messages -->
            <?php if ($flashMessage): ?>
                <div class="alert alert-<?php echo $flashMessage['type']; ?>">
                    <i class="fas fa-<?php echo $flashMessage['type'] === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                    <div><?php echo htmlspecialchars($flashMessage['message']); ?></div>
                </div>
            <?php endif; ?>

            <!-- Display Errors -->
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <strong>Import Errors:</strong>
                        <ul style="margin: 10px 0 0 20px;">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        
                        <!-- Debug Help Section -->
                        <div style="margin-top: 20px; padding: 15px; background: #f8fafc; border-radius: 8px;">
                            <h4>Common Issues & Solutions:</h4>
                            <ul style="margin: 10px 0 0 20px; font-size: 14px;">
                                <li><strong>Column not found:</strong> Check that your CSV headers match exactly (case-sensitive)</li>
                                <li><strong>Business fees need:</strong> business_type, category, fee_amount</li>
                                <li><strong>Property fees need:</strong> structure, property_use, fee_per_room</li>
                                <li><strong>Empty fields:</strong> Ensure no required cells are empty</li>
                                <li><strong>Number format:</strong> Use numbers only (no currency symbols)</li>
                            </ul>
                            
                            <div style="margin-top: 15px;">
                                <a href="#" onclick="downloadTemplate('<?php echo $importType ?: 'business'; ?>')" class="btn btn-outline" style="font-size: 12px; padding: 8px 16px;">
                                    <i class="fas fa-download"></i>
                                    Download Correct Template
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Page Header -->
            <div class="page-header">
                <div class="header-content">
                    <div class="header-icon">
                        <i class="fas fa-upload"></i>
                        <span class="icon-upload" style="display: none;"></span>
                    </div>
                    <div class="header-info">
                        <h1>Import Fee Structures</h1>
                        <p>Bulk import business and property fee structures from CSV files</p>
                    </div>
                </div>
            </div>

            <!-- Download Templates Section -->
            <div class="templates-section">
                <h3 class="templates-title">
                    <i class="fas fa-download"></i>
                    <span class="icon-download" style="display: none;"></span>
                    Download Import Templates
                </h3>
                
                <div class="alert alert-info" style="margin-bottom: 20px;">
                    <i class="fas fa-info-circle"></i>
                    <div>
                        <strong>Important:</strong> Your CSV must have the exact column headers shown below (case-sensitive). 
                        Do not add extra columns or change the order.
                    </div>
                </div>
                
                <div class="template-item">
                    <div class="template-info">
                        <div class="template-icon">
                            <i class="fas fa-building"></i>
                            <span class="icon-building" style="display: none;"></span>
                        </div>
                        <div class="template-details">
                            <h4>Business Fee Structure Template</h4>
                            <p><strong>Required columns:</strong> business_type, category, fee_amount, is_active</p>
                            <p><small>Example: Restaurant, Small Scale, 500.00, 1</small></p>
                        </div>
                    </div>
                    <a href="#" onclick="downloadTemplate('business')" class="btn btn-outline">
                        <i class="fas fa-download"></i>
                        <span class="icon-download" style="display: none;"></span>
                        Download CSV
                    </a>
                </div>
                
                <div class="template-item">
                    <div class="template-info">
                        <div class="template-icon">
                            <i class="fas fa-home"></i>
                            <span class="icon-home" style="display: none;"></span>
                        </div>
                        <div class="template-details">
                            <h4>Property Fee Structure Template</h4>
                            <p><strong>Required columns:</strong> structure, property_use, fee_per_room, is_active</p>
                            <p><small>Example: Concrete Block, Residential, 50.00, 1</small></p>
                        </div>
                    </div>
                    <a href="#" onclick="downloadTemplate('property')" class="btn btn-outline">
                        <i class="fas fa-download"></i>
                        <span class="icon-download" style="display: none;"></span>
                        Download CSV
                    </a>
                </div>
                
                <!-- Debug Tools -->
                <div style="margin-top: 20px; padding: 15px; background: #f8fafc; border-radius: 10px; border: 1px solid #e2e8f0;">
                    <h4 style="margin-bottom: 10px;">📋 Troubleshooting Tools</h4>
                    <p style="margin-bottom: 15px; font-size: 14px;">Having import issues? Use these tools to diagnose the problem:</p>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <button onclick="showFileChecker()" class="btn btn-outline" style="font-size: 12px; padding: 8px 16px;">
                            <i class="fas fa-search"></i>
                            Check My File Format
                        </button>
                        <button onclick="testSmallSample()" class="btn btn-outline" style="font-size: 12px; padding: 8px 16px;">
                            <i class="fas fa-vial"></i>
                            Test First 5 Rows Only
                        </button>
                        <a href="?debug_mode=1" class="btn btn-outline" style="font-size: 12px; padding: 8px 16px;">
                            <i class="fas fa-bug"></i>
                            Enable Debug Mode
                        </a>
                    </div>
                </div>
            </div>

            <!-- Import Results -->
            <?php if ($importResults !== null): ?>
                <div class="import-results">
                    <div class="results-header">
                        <div class="results-icon <?php echo $importResults['success'] > 0 ? 'success' : 'warning'; ?>">
                            <i class="fas fa-<?php echo $importResults['success'] > 0 ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                            <span class="icon-<?php echo $importResults['success'] > 0 ? 'check' : 'warning'; ?>" style="display: none;"></span>
                        </div>
                        <h3 class="results-title">Import Completed</h3>
                    </div>
                    
                    <div class="results-summary">
                        <div class="result-stat">
                            <div class="result-number success"><?php echo $importResults['success']; ?></div>
                            <div class="result-label">Successfully Imported</div>
                        </div>
                        <div class="result-stat">
                            <div class="result-number warning"><?php echo $importResults['duplicates']; ?></div>
                            <div class="result-label">Duplicates Skipped</div>
                        </div>
                        <div class="result-stat">
                            <div class="result-number error"><?php echo $importResults['failed']; ?></div>
                            <div class="result-label">Failed Imports</div>
                        </div>
                    </div>
                    
                    <?php if (!empty($importResults['errors'])): ?>
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-triangle"></i>
                            <div>
                                <strong>Import Errors:</strong>
                                <ul style="margin: 10px 0 0 20px;">
                                    <?php foreach ($importResults['errors'] as $error): ?>
                                        <li><?php echo htmlspecialchars($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div class="btn-group">
                        <a href="index.php" class="btn btn-primary">
                            <i class="fas fa-arrow-left"></i>
                            Back to Fee Structure
                        </a>
                        <a href="import_fees.php" class="btn btn-secondary">
                            <i class="fas fa-upload"></i>
                            Import More Files
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <!-- Import Process -->
                <div class="import-container">
                    <!-- Loading Overlay - FIXED -->
                    <div class="loading-overlay" id="loadingOverlay">
                        <div class="spinner"></div>
                        <div class="loading-text">Processing import...</div>
                    </div>
                    
                    <!-- Import Steps -->
                    <div class="import-steps">
                        <div class="step <?php echo empty($previewData) ? 'active' : 'completed'; ?>">
                            <div class="step-number">1</div>
                            <div class="step-title">Select Import Type</div>
                        </div>
                        <div class="step <?php echo !empty($previewData) ? 'active' : ''; ?>">
                            <div class="step-number">2</div>
                            <div class="step-title">Upload & Preview</div>
                        </div>
                        <div class="step">
                            <div class="step-number">3</div>
                            <div class="step-title">Import Data</div>
                        </div>
                    </div>

                    <?php if (empty($previewData)): ?>
                        <!-- Step 1: Upload Form -->
                        <form method="POST" enctype="multipart/form-data" id="uploadForm">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <input type="hidden" name="action" value="upload">
                            
                            <div class="form-group">
                                <label class="form-label">Select Import Type</label>
                                <select name="import_type" id="importType" class="form-control" required>
                                    <option value="">Choose fee structure type...</option>
                                    <option value="business" <?php echo $importType === 'business' ? 'selected' : ''; ?>>Business Fee Structure</option>
                                    <option value="property" <?php echo $importType === 'property' ? 'selected' : ''; ?>>Property Fee Structure</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Upload CSV File</label>
                                
                                <div class="alert alert-info" style="margin-bottom: 15px;">
                                    <i class="fas fa-info-circle"></i>
                                    <div>
                                        <strong>Large File Support:</strong> Files up to 10MB and 500 rows are supported. 
                                        For larger datasets, split your file into smaller chunks.
                                        <br><strong>Encoding:</strong> Files are automatically converted from Windows (cp1252) to UTF-8.
                                    </div>
                                </div>
                                
                                <div class="file-upload-area" onclick="document.getElementById('fileInput').click()">
                                    <input type="file" id="fileInput" name="import_file" accept=".csv,.xlsx,.xls" class="file-input" required onchange="handleFileSelect(this)">
                                    <div class="upload-icon">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                        <span class="icon-upload" style="display: none;"></span>
                                    </div>
                                    <div class="upload-text">Click to select file or drag and drop</div>
                                    <div class="upload-hint">Supported formats: CSV, XLS, XLSX (Max 10MB, 500 rows)</div>
                                </div>
                                <div id="selectedFile" style="margin-top: 15px; display: none;"></div>
                            </div>
                            
                            <div class="btn-group">
                                <button type="submit" class="btn btn-primary" id="uploadBtn" disabled>
                                    <i class="fas fa-upload"></i>
                                    <span class="icon-upload" style="display: none;"></span>
                                    Upload & Preview
                                </button>
                                <a href="index.php" class="btn btn-secondary">
                                    <i class="fas fa-times"></i>
                                    <span class="icon-times" style="display: none;"></span>
                                    Cancel
                                </a>
                            </div>
                        </form>
                    <?php else: ?>
                        <!-- Step 2: Preview Data -->
                        <div class="preview-container">
                            <!-- Large file warning -->
                            <?php if (count($previewData) >= 400): ?>
                                <div class="alert alert-warning" style="margin-bottom: 20px;">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <div>
                                        <strong>Large Dataset Detected:</strong> Your file contains <?php echo count($previewData); ?> records. 
                                        Import may take 30-60 seconds to complete. Please be patient and do not refresh the page.
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="preview-header">
                                <h3 class="preview-title">
                                    Preview Import Data - <?php echo ucfirst($importType); ?> Fees
                                </h3>
                                <div class="preview-count">
                                    <?php echo count($previewData); ?> records found
                                </div>
                            </div>
                            
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i>
                                <div>Please review the data below before proceeding with the import. Duplicates will be automatically skipped.</div>
                            </div>
                            
                            <div class="table-container">
                                <table class="preview-table">
                                    <thead>
                                        <tr>
                                            <?php if ($importType === 'business'): ?>
                                                <th>Business Type</th>
                                                <th>Category</th>
                                                <th>Fee Amount</th>
                                                <th>Status</th>
                                            <?php else: ?>
                                                <th>Structure</th>
                                                <th>Property Use</th>
                                                <th>Fee Per Room</th>
                                                <th>Status</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_slice($previewData, 0, 10) as $row): ?>
                                            <tr>
                                                <?php if ($importType === 'business'): ?>
                                                    <td><?php echo htmlspecialchars($row['business_type']); ?></td>
                                                    <td><?php echo htmlspecialchars($row['category']); ?></td>
                                                    <td>₵<?php echo number_format($row['fee_amount'], 2); ?></td>
                                                    <td><?php echo $row['is_active'] ? 'Active' : 'Inactive'; ?></td>
                                                <?php else: ?>
                                                    <td><?php echo htmlspecialchars($row['structure']); ?></td>
                                                    <td><?php echo htmlspecialchars($row['property_use']); ?></td>
                                                    <td>₵<?php echo number_format($row['fee_per_room'], 2); ?></td>
                                                    <td><?php echo $row['is_active'] ? 'Active' : 'Inactive'; ?></td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                
                                <?php if (count($previewData) > 10): ?>
                                    <div style="padding: 15px; text-align: center; color: #64748b; background: #f8fafc;">
                                        Showing first 10 of <?php echo count($previewData); ?> records
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <form method="POST" style="margin-top: 30px;" id="importForm" onsubmit="showLoading(event)">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                <input type="hidden" name="action" value="import">
                                <input type="hidden" name="import_type" value="<?php echo htmlspecialchars($importType); ?>">
                                <input type="hidden" name="preview_data" value="<?php echo htmlspecialchars(json_encode($previewData, JSON_HEX_QUOT | JSON_HEX_APOS)); ?>">
                                
                                <!-- Debug info -->
                                <?php if (isset($_GET['debug'])): ?>
                                <div class="alert alert-info">
                                    <strong>Debug Info:</strong><br>
                                    Import Type: <?php echo $importType; ?><br>
                                    Records Count: <?php echo count($previewData); ?><br>
                                    JSON Length: <?php echo strlen(json_encode($previewData)); ?><br>
                                    First Record: <?php echo htmlspecialchars(json_encode($previewData[0] ?? 'none')); ?>
                                </div>
                                <?php endif; ?>
                                
                                <div class="btn-group">
                                    <button type="submit" class="btn btn-success" id="importBtn">
                                        <i class="fas fa-check"></i>
                                        <span class="icon-check" style="display: none;"></span>
                                        Confirm Import
                                    </button>
                                    <a href="import_fees.php" class="btn btn-secondary">
                                        <i class="fas fa-arrow-left"></i>
                                        Back to Upload
                                    </a>
                                    <a href="import_fees.php?debug=1&import_type=<?php echo $importType; ?>" class="btn btn-outline">
                                        <i class="fas fa-bug"></i>
                                        Debug Mode
                                    </a>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Check if Font Awesome loaded, if not show emoji icons
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                const testIcon = document.querySelector('.fas.fa-bars');
                if (!testIcon || getComputedStyle(testIcon, ':before').content === 'none') {
                    document.querySelectorAll('.fas, .far').forEach(function(icon) {
                        icon.style.display = 'none';
                    });
                    document.querySelectorAll('[class*="icon-"]').forEach(function(emoji) {
                        emoji.style.display = 'inline';
                    });
                }
            }, 100);
        });

        // Sidebar toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('hidden');
            
            const isHidden = sidebar.classList.contains('hidden');
            localStorage.setItem('sidebarHidden', isHidden);
        }

        // Restore sidebar state
        document.addEventListener('DOMContentLoaded', function() {
            const sidebarHidden = localStorage.getItem('sidebarHidden');
            if (sidebarHidden === 'true') {
                document.getElementById('sidebar').classList.add('hidden');
            }
        });

        // File handling - ENHANCED FOR LARGE FILES
        function handleFileSelect(input) {
            if (!input || !input.files || !input.files.length) return;
            
            const file = input.files[0];
            const importTypeElement = document.getElementById('importType');
            const uploadBtn = document.getElementById('uploadBtn');
            const selectedFile = document.getElementById('selectedFile');
            
            if (!importTypeElement || !uploadBtn || !selectedFile) {
                console.error('Required elements not found for file handling');
                return;
            }
            
            const importType = importTypeElement.value;
            const fileSize = file.size;
            const fileSizeMB = (fileSize / 1024 / 1024).toFixed(2);
            const estimatedRows = Math.floor(fileSize / 50); // Rough estimate: 50 bytes per row
            
            if (file && importType) {
                let sizeWarning = '';
                let rowWarning = '';
                
                // File size warnings
                if (fileSize > 10 * 1024 * 1024) { // 10MB
                    sizeWarning = '<div style="color: #e53e3e; font-size: 12px; margin-top: 5px;">⚠️ File too large (max 10MB)</div>';
                } else if (fileSize > 5 * 1024 * 1024) { // 5MB
                    sizeWarning = '<div style="color: #ed8936; font-size: 12px; margin-top: 5px;">⚠️ Large file - may take longer to process</div>';
                }
                
                // Row count warnings
                if (estimatedRows > 500) {
                    rowWarning = '<div style="color: #ed8936; font-size: 12px;">📊 Estimated ' + estimatedRows + ' rows - only first 500 will be processed</div>';
                } else if (estimatedRows > 200) {
                    rowWarning = '<div style="color: #4299e1; font-size: 12px;">📊 Estimated ' + estimatedRows + ' rows - processing may take a moment</div>';
                }
                
                selectedFile.innerHTML = `
                    <div style="padding: 15px; background: #f0f4ff; border: 1px solid #667eea; border-radius: 10px;">
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                            <i class="fas fa-file-csv" style="color: #667eea; font-size: 20px;"></i>
                            <div>
                                <div style="font-weight: 600; color: #2d3748;">${file.name}</div>
                                <div style="font-size: 12px; color: #64748b;">Size: ${fileSizeMB} MB</div>
                            </div>
                        </div>
                        ${rowWarning}
                        ${sizeWarning}
                    </div>
                `;
                selectedFile.style.display = 'block';
                
                // Enable/disable button based on file size
                if (fileSize <= 10 * 1024 * 1024) {
                    uploadBtn.disabled = false;
                } else {
                    uploadBtn.disabled = true;
                }
            } else {
                selectedFile.style.display = 'none';
                uploadBtn.disabled = true;
            }
        }

        // Import type change handler
        const importTypeElement = document.getElementById('importType');
        if (importTypeElement) {
            importTypeElement.addEventListener('change', function() {
                const fileInput = document.getElementById('fileInput');
                const uploadBtn = document.getElementById('uploadBtn');
                
                if (this.value && fileInput && fileInput.files.length > 0) {
                    uploadBtn.disabled = false;
                } else if (uploadBtn) {
                    uploadBtn.disabled = true;
                }
            });
        }

        // Drag and drop functionality
        const uploadArea = document.querySelector('.file-upload-area');
        if (uploadArea) {
            uploadArea.addEventListener('dragover', function(e) {
                e.preventDefault();
                this.classList.add('dragover');
            });

            uploadArea.addEventListener('dragleave', function(e) {
                e.preventDefault();
                this.classList.remove('dragover');
            });

            uploadArea.addEventListener('drop', function(e) {
                e.preventDefault();
                this.classList.remove('dragover');
                
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    const fileInput = document.getElementById('fileInput');
                    if (fileInput) {
                        fileInput.files = files;
                        handleFileSelect(fileInput);
                    }
                }
            });
        }

        // Download template function
        function downloadTemplate(type) {
            let csvContent = '';
            let filename = '';
            
            if (type === 'business') {
                csvContent = 'business_type,category,fee_amount,is_active\n';
                csvContent += 'Restaurant,Small Scale,500.00,1\n';
                csvContent += 'Restaurant,Medium Scale,1000.00,1\n';
                csvContent += 'Shop,Small Scale,300.00,1\n';
                filename = 'business_fee_template.csv';
            } else {
                csvContent = 'structure,property_use,fee_per_room,is_active\n';
                csvContent += 'Concrete Block,Residential,50.00,1\n';
                csvContent += 'Concrete Block,Commercial,100.00,1\n';
                csvContent += 'Modern Building,Residential,75.00,1\n';
                filename = 'property_fee_template.csv';
            }
            
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }

        // Show loading state - FIXED
        function showLoading(event) {
            event.preventDefault();
            
            const loadingOverlay = document.getElementById('loadingOverlay');
            const importBtn = document.getElementById('importBtn');
            
            // Show loading overlay
            if (loadingOverlay) {
                loadingOverlay.classList.add('show');
            }
            
            // Disable button
            if (importBtn) {
                importBtn.disabled = true;
                importBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            }
            
            // Submit form after showing loading
            setTimeout(() => {
                if (event.target) {
                    event.target.submit();
                }
            }, 100);
        }

        // Mobile responsiveness
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth <= 768) {
                sidebar.classList.remove('hidden');
            }
        });

        // Form validation
        const uploadForm = document.getElementById('uploadForm');
        if (uploadForm) {
            uploadForm.addEventListener('submit', function(e) {
                const importType = document.getElementById('importType');
                const fileInput = document.getElementById('fileInput');
                
                if (!importType || !importType.value) {
                    e.preventDefault();
                    alert('Please select an import type');
                    return;
                }
                
                if (!fileInput || !fileInput.files.length) {
                    e.preventDefault();
                    alert('Please select a file to upload');
                    return;
                }
            });
        }

        // Debug: Log elements found/not found
        console.log('Elements check:', {
            uploadForm: !!document.getElementById('uploadForm'),
            importType: !!document.getElementById('importType'),
            fileInput: !!document.getElementById('fileInput'),
            uploadBtn: !!document.getElementById('uploadBtn'),
            loadingOverlay: !!document.getElementById('loadingOverlay')
        });

        // File format checker functions
        function showFileChecker() {
            const modal = document.getElementById('fileCheckerModal');
            if (modal) {
                modal.classList.add('show');
            }
        }

        function hideFileChecker() {
            const modal = document.getElementById('fileCheckerModal');
            if (modal) {
                modal.classList.remove('show');
            }
        }

        // Close modal when clicking outside
        document.addEventListener('click', function(event) {
            const modal = document.getElementById('fileCheckerModal');
            if (modal && event.target === modal) {
                hideFileChecker();
            }
        });

        // Test sample function for large files
        function testSmallSample() {
            alert('To test your large file:\n\n1. Open your CSV in Excel/text editor\n2. Copy just the header row + first 3-5 data rows\n3. Save as a new small CSV file\n4. Test import with the small file first\n\nThis helps identify format issues without processing the entire large file.');
        }

        // Enhanced error detection
        window.addEventListener('error', function(e) {
            console.error('JavaScript Error:', e.error);
        });
    </script>
</body>
</html>