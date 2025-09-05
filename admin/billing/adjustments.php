<?php
/**
 * Billing Management - Individual Bill Adjustments
 * QUICKBILL 305 - Admin Panel
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
requireLogin();
if (!hasPermission('billing.edit')) {
    setFlashMessage('error', 'Access denied. You do not have permission to adjust bills.');
    header('Location: index.php');
    exit();
}
// Check session expiration
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > 1800)) {
    // Session expired (30 minutes)
    session_unset();
    session_destroy();
    setFlashMessage('error', 'Your session has expired. Please log in again.');
    header('Location: ../../index.php');
    exit();
}

$pageTitle = 'Bill Adjustments';
$currentUser = getCurrentUser();

// Get bill ID
$billId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$billId) {
    setFlashMessage('error', 'Bill ID is required.');
    header('Location: list.php');
    exit();
}

// Initialize variables
$errors = [];
$success = false;
$bill = null;
$payerInfo = null;
$adjustmentHistory = [];

try {
    $db = new Database();
    
    // Get bill details
    $bill = $db->fetchRow("
        SELECT b.*, u.first_name as generated_by_name, u.last_name as generated_by_surname
        FROM bills b
        LEFT JOIN users u ON b.generated_by = u.user_id
        WHERE b.bill_id = ?
    ", [$billId]);
    
    if (!$bill) {
        setFlashMessage('error', 'Bill not found.');
        header('Location: list.php');
        exit();
    }
    
    // Get payer information based on bill type
    if ($bill['bill_type'] === 'Business') {
        $payerInfo = $db->fetchRow("
            SELECT b.*, z.zone_name, sz.sub_zone_name, bfs.fee_amount as annual_fee
            FROM businesses b
            LEFT JOIN zones z ON b.zone_id = z.zone_id
            LEFT JOIN sub_zones sz ON b.sub_zone_id = sz.sub_zone_id
            LEFT JOIN business_fee_structure bfs ON b.business_type = bfs.business_type AND b.category = bfs.category
            WHERE b.business_id = ? AND bfs.is_active = 1
        ", [$bill['reference_id']]);
    } else {
        $payerInfo = $db->fetchRow("
            SELECT p.*, z.zone_name, pfs.fee_per_room
            FROM properties p
            LEFT JOIN zones z ON p.zone_id = z.zone_id
            LEFT JOIN property_fee_structure pfs ON p.structure = pfs.structure AND p.property_use = pfs.property_use
            WHERE p.property_id = ? AND pfs.is_active = 1
        ", [$bill['reference_id']]);
    }
    
    if (!$payerInfo) {
        setFlashMessage('error', 'Payer information not found.');
        header('Location: list.php');
        exit();
    }
    
    // Get adjustment history for this bill
    $adjustmentHistory = $db->fetchAll("
        SELECT ba.*, u.first_name, u.last_name
        FROM bill_adjustments ba
        LEFT JOIN users u ON ba.applied_by = u.user_id
        WHERE ba.adjustment_type = 'Single' 
        AND ba.target_type = ? 
        AND ba.target_id = ?
        ORDER BY ba.applied_at DESC
    ", [$bill['bill_type'], $bill['reference_id']]);
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $adjustmentMethod = sanitizeInput($_POST['adjustment_method']);
        $adjustmentValue = floatval($_POST['adjustment_value']);
        $targetField = sanitizeInput($_POST['target_field']);
        $reason = sanitizeInput($_POST['reason']);
        
        // Validation
        if (empty($adjustmentMethod) || !in_array($adjustmentMethod, ['Fixed Amount', 'Percentage'])) {
            $errors[] = 'Please select a valid adjustment method.';
        }
        
        if ($adjustmentValue <= 0) {
            $errors[] = 'Please enter a valid adjustment value.';
        }
        
        if ($adjustmentMethod === 'Percentage' && $adjustmentValue > 100) {
            $errors[] = 'Percentage adjustment cannot exceed 100%.';
        }
        
        if (empty($targetField) || !in_array($targetField, ['old_bill', 'arrears', 'current_bill'])) {
            $errors[] = 'Please select a valid field to adjust.';
        }
        
        if (empty($reason)) {
            $errors[] = 'Please provide a reason for this adjustment.';
        }
        
        // Process adjustment if no errors
        if (empty($errors)) {
            try {
                // Start transaction
                if (method_exists($db, 'beginTransaction')) {
                    $db->beginTransaction();
                }
                
                // Get current values
                $currentValue = floatval($bill[$targetField]);
                $oldAmountPayable = floatval($bill['amount_payable']);
                
                // Calculate new value
                if ($adjustmentMethod === 'Fixed Amount') {
                    $newValue = $currentValue + $adjustmentValue;
                } else { // Percentage
                    $adjustment = ($currentValue * $adjustmentValue) / 100;
                    $newValue = $currentValue + $adjustment;
                }
                
                // Ensure new value is not negative
                $newValue = max(0, $newValue);
                $actualAdjustment = $newValue - $currentValue;
                
                // Update bill record
                $db->execute("
                    UPDATE bills 
                    SET {$targetField} = ?
                    WHERE bill_id = ?
                ", [$newValue, $billId]);
                
                // Recalculate amount payable (trigger should handle this, but let's be explicit)
                $newAmountPayable = $bill['old_bill'] + $bill['arrears'] + $bill['current_bill'] - $bill['previous_payments'];
                
                // Apply the adjustment to the correct field
                if ($targetField === 'old_bill') {
                    $newAmountPayable = $newValue + $bill['arrears'] + $bill['current_bill'] - $bill['previous_payments'];
                } elseif ($targetField === 'arrears') {
                    $newAmountPayable = $bill['old_bill'] + $newValue + $bill['current_bill'] - $bill['previous_payments'];
                } elseif ($targetField === 'current_bill') {
                    $newAmountPayable = $bill['old_bill'] + $bill['arrears'] + $newValue - $bill['previous_payments'];
                }
                
                $db->execute("
                    UPDATE bills 
                    SET amount_payable = ?
                    WHERE bill_id = ?
                ", [$newAmountPayable, $billId]);
                
                // Update the corresponding business/property record
                if ($bill['bill_type'] === 'Business') {
                    $db->execute("
                        UPDATE businesses 
                        SET {$targetField} = ?, amount_payable = ?
                        WHERE business_id = ?
                    ", [$newValue, $newAmountPayable, $bill['reference_id']]);
                } else {
                    $db->execute("
                        UPDATE properties 
                        SET {$targetField} = ?, amount_payable = ?
                        WHERE property_id = ?
                    ", [$newValue, $newAmountPayable, $bill['reference_id']]);
                }
                
                // Record the adjustment in bill_adjustments table
                $db->execute("
                    INSERT INTO bill_adjustments (
                        adjustment_type, target_type, target_id, adjustment_method, 
                        adjustment_value, old_amount, new_amount, reason, applied_by, applied_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ", [
                    'Single',
                    $bill['bill_type'],
                    $bill['reference_id'],
                    $adjustmentMethod,
                    $adjustmentValue,
                    $currentValue,
                    $newValue,
                    $reason,
                    $currentUser['user_id']
                ]);
                
                // Commit transaction
                if (method_exists($db, 'commit')) {
                    $db->commit();
                }
                
                // Log the action directly to audit_logs
                $db->execute("
                    INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ", [
                    $currentUser['user_id'],
                    'BILL_ADJUSTED',
                    'bills',
                    $billId,
                    json_encode([
                        'field' => $targetField,
                        'old_value' => $currentValue,
                        'old_amount_payable' => $oldAmountPayable
                    ]),
                    json_encode([
                        'field' => $targetField,
                        'new_value' => $newValue,
                        'new_amount_payable' => $newAmountPayable,
                        'adjustment_method' => $adjustmentMethod,
                        'adjustment_value' => $adjustmentValue,
                        'reason' => $reason
                    ]),
                    $_SERVER['REMOTE_ADDR'] ?? '::1',
                    $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
                ]);
                
                $success = true;
                setFlashMessage('success', "Bill adjusted successfully. {$targetField} changed from GHS " . number_format($currentValue, 2) . " to GHS " . number_format($newValue, 2));
                
                // Refresh bill data
                $bill = $db->fetchRow("
                    SELECT b.*, u.first_name as generated_by_name, u.last_name as generated_by_surname
                    FROM bills b
                    LEFT JOIN users u ON b.generated_by = u.user_id
                    WHERE b.bill_id = ?
                ", [$billId]);
                
                // Refresh adjustment history
                $adjustmentHistory = $db->fetchAll("
                    SELECT ba.*, u.first_name, u.last_name
                    FROM bill_adjustments ba
                    LEFT JOIN users u ON ba.applied_by = u.user_id
                    WHERE ba.adjustment_type = 'Single' 
                    AND ba.target_type = ? 
                    AND ba.target_id = ?
                    ORDER BY ba.applied_at DESC
                ", [$bill['bill_type'], $bill['reference_id']]);
                
            } catch (Exception $e) {
                // Rollback transaction
                if (method_exists($db, 'rollback')) {
                    $db->rollback();
                }
                $errors[] = 'An error occurred while applying the adjustment: ' . $e->getMessage();
                writeLog("Bill adjustment error: " . $e->getMessage(), 'ERROR');
            }
        }
    }
    
} catch (Exception $e) {
    writeLog("Bill adjustments page error: " . $e->getMessage(), 'ERROR');
    $errors[] = 'An error occurred while loading the page.';
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
    
    <!-- Icons and CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .page-header {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .page-title {
            font-size: 28px;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 10px;
        }
        
        .breadcrumb {
            color: #64748b;
            margin-bottom: 20px;
        }
        
        .breadcrumb a {
            color: #10b981;
            text-decoration: none;
        }
        
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
        
        .errors {
            background: #fee2e2;
            border: 1px solid #fca5a5;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .errors ul {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        
        .errors li {
            color: #991b1b;
            margin-bottom: 5px;
        }
        
        .errors li:before {
            content: "❌ ";
            margin-right: 5px;
        }
        
        /* Grid Layout */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }
        
        /* Cards */
        .content-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .card-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .card-icon {
            width: 35px;
            height: 35px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
        }
        
        /* Bill Header */
        .bill-header {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
        }
        
        .bill-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }
        
        .bill-content {
            position: relative;
            z-index: 2;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .bill-info {
            display: flex;
            align-items: center;
            gap: 25px;
        }
        
        .bill-icon {
            width: 80px;
            height: 80px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            border: 3px solid rgba(255,255,255,0.3);
        }
        
        .bill-details h1 {
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 8px;
        }
        
        .bill-meta {
            display: flex;
            gap: 25px;
            opacity: 0.9;
            margin-bottom: 10px;
        }
        
        .bill-status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: rgba(255,255,255,0.2);
            border-radius: 20px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 12px;
        }
        
        /* Warning Card */
        .warning-card {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: 2px solid #f59e0b;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .warning-title {
            font-weight: 600;
            color: #92400e;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .warning-text {
            color: #78350f;
            line-height: 1.6;
        }
        
        /* Form Elements */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        
        .form-label {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .form-label.required::after {
            content: " *";
            color: #ef4444;
        }
        
        .form-input {
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #10b981;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
        }
        
        .form-textarea {
            min-height: 100px;
            resize: vertical;
            font-family: inherit;
        }
        
        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .info-label {
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .info-value {
            font-size: 14px;
            font-weight: 500;
            color: #2d3748;
        }
        
        .info-value.amount {
            font-size: 18px;
            font-weight: bold;
            font-family: monospace;
            color: #f59e0b;
        }
        
        .info-value.highlight {
            color: #10b981;
            font-weight: 600;
        }
        
        /* Amount Breakdown */
        .amount-breakdown {
            background: #f8fafc;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
        }
        
        .breakdown-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .breakdown-item:last-child {
            border-bottom: none;
            font-weight: bold;
            font-size: 18px;
            color: #f59e0b;
            border-top: 2px solid #e2e8f0;
            padding-top: 15px;
            margin-top: 10px;
        }
        
        .breakdown-label {
            color: #64748b;
        }
        
        .breakdown-amount {
            font-family: monospace;
            font-weight: 600;
            color: #2d3748;
        }
        
        /* Buttons */
        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
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
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(245, 158, 11, 0.3);
        }
        
        .btn-secondary {
            background: #64748b;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #475569;
            color: white;
        }
        
        .btn-success {
            background: #10b981;
            color: white;
        }
        
        .btn-danger {
            background: #ef4444;
            color: white;
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #e2e8f0;
        }
        
        /* History Table */
        .history-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .history-table th {
            background: #f8fafc;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            color: #2d3748;
            border-bottom: 1px solid #e2e8f0;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .history-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            font-size: 14px;
        }
        
        .history-table tr:hover {
            background: #f8fafc;
        }
        
        .amount-change {
            font-family: monospace;
            font-weight: 600;
        }
        
        .amount-increase {
            color: #059669;
        }
        
        .amount-decrease {
            color: #dc2626;
        }
        
        /* Status badges */
        .type-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            display: inline-block;
            margin-bottom: 10px;
        }
        
        .type-business {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .type-property {
            background: #d1fae5;
            color: #065f46;
        }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #64748b;
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        .empty-state h3 {
            margin-bottom: 10px;
            color: #2d3748;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
            
            .bill-content {
                flex-direction: column;
                gap: 20px;
                text-align: center;
            }
            
            .bill-info {
                flex-direction: column;
                gap: 15px;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* Animations */
        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .content-card {
            animation: slideIn 0.6s ease forwards;
        }
        
        /* Calculation Preview */
        .calculation-preview {
            background: #f0f9ff;
            border: 2px solid #3b82f6;
            border-radius: 10px;
            padding: 15px;
            margin-top: 15px;
            display: none;
        }
        
        .calculation-preview.show {
            display: block;
        }
        
        .preview-title {
            font-weight: 600;
            color: #1e40af;
            margin-bottom: 10px;
            font-size: 14px;
        }
        
        .preview-calculation {
            font-family: monospace;
            font-size: 16px;
            color: #1e40af;
            background: rgba(59, 130, 246, 0.1);
            padding: 10px;
            border-radius: 6px;
            margin-bottom: 10px;
        }
        
        .preview-result {
            font-weight: bold;
            color: #1e40af;
            font-size: 16px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Page Header -->
        <div class="page-header">
            <div class="breadcrumb">
                <a href="../index.php">Dashboard</a> / 
                <a href="index.php">Billing</a> / 
                <a href="view.php?id=<?php echo $billId; ?>">Bill Details</a> / 
                Adjustments
            </div>
            <h1 class="page-title">
                <i class="fas fa-edit"></i>
                Bill Adjustments
            </h1>
            <p style="color: #64748b;">Make adjustments to individual bill amounts with proper tracking</p>
        </div>

        <!-- Flash Messages -->
        <?php if ($flashMessage): ?>
            <div class="alert alert-<?php echo $flashMessage['type']; ?>">
                <i class="fas fa-<?php echo $flashMessage['type'] === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                <div><?php echo htmlspecialchars($flashMessage['message']); ?></div>
            </div>
        <?php endif; ?>

        <!-- Errors -->
        <?php if (!empty($errors)): ?>
            <div class="errors">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Bill Header -->
        <div class="bill-header">
            <div class="bill-content">
                <div class="bill-info">
                    <div class="bill-icon">
                        <i class="fas fa-edit"></i>
                    </div>
                    <div class="bill-details">
                        <h1><?php echo htmlspecialchars($bill['bill_number']); ?></h1>
                        <div class="bill-meta">
                            <span><i class="fas fa-calendar"></i> Year <?php echo $bill['billing_year']; ?></span>
                            <span><i class="fas fa-<?php echo $bill['bill_type'] === 'Business' ? 'building' : 'home'; ?>"></i> <?php echo $bill['bill_type']; ?></span>
                            <span><i class="fas fa-money-bill-wave"></i> GHS <?php echo number_format($bill['amount_payable'], 2); ?></span>
                        </div>
                        <div class="bill-status">
                            <i class="fas fa-cog"></i>
                            Adjustment Mode
                        </div>
                    </div>
                </div>
                
                <div style="display: flex; gap: 15px;">
                    <a href="view.php?id=<?php echo $bill['bill_id']; ?>" class="btn btn-secondary">
                        <i class="fas fa-eye"></i>
                        View Bill
                    </a>
                    <a href="list.php" class="btn btn-secondary">
                        <i class="fas fa-list"></i>
                        All Bills
                    </a>
                </div>
            </div>
        </div>

        <!-- Warning Card -->
        <div class="warning-card">
            <div class="warning-title">
                <i class="fas fa-exclamation-triangle"></i>
                Important Notice
            </div>
            <div class="warning-text">
                Bill adjustments are permanent changes that affect the amount payable. All adjustments are logged and tracked for audit purposes. 
                Please ensure you have proper authorization before making any changes. The adjustment will be applied to both the bill record and the corresponding business/property record.
            </div>
        </div>

        <!-- Content Grid -->
        <div class="content-grid">
            <!-- Left Column - Adjustment Form -->
            <div>
                <!-- Current Bill Information -->
                <div class="content-card">
                    <div class="card-header">
                        <div class="card-title">
                            <div class="card-icon">
                                <i class="fas fa-info-circle"></i>
                            </div>
                            Current Bill Information
                        </div>
                    </div>
                    
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Payer Name</div>
                            <div class="info-value highlight">
                                <?php 
                                    echo htmlspecialchars($bill['bill_type'] === 'Business' ? 
                                        $payerInfo['business_name'] : $payerInfo['owner_name']); 
                                ?>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Account Number</div>
                            <div class="info-value">
                                <?php 
                                    echo htmlspecialchars($bill['bill_type'] === 'Business' ? 
                                        $payerInfo['account_number'] : $payerInfo['property_number']); 
                                ?>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Bill Type</div>
                            <div class="info-value">
                                <span class="type-badge type-<?php echo strtolower($bill['bill_type']); ?>">
                                    <?php echo htmlspecialchars($bill['bill_type']); ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Status</div>
                            <div class="info-value"><?php echo htmlspecialchars($bill['status']); ?></div>
                        </div>
                    </div>
                    
                    <div class="amount-breakdown">
                        <div class="breakdown-item">
                            <span class="breakdown-label">Old Bill</span>
                            <span class="breakdown-amount">GHS <?php echo number_format($bill['old_bill'], 2); ?></span>
                        </div>
                        
                        <div class="breakdown-item">
                            <span class="breakdown-label">Arrears</span>
                            <span class="breakdown-amount">GHS <?php echo number_format($bill['arrears'], 2); ?></span>
                        </div>
                        
                        <div class="breakdown-item">
                            <span class="breakdown-label">Current Bill</span>
                            <span class="breakdown-amount">GHS <?php echo number_format($bill['current_bill'], 2); ?></span>
                        </div>
                        
                        <div class="breakdown-item">
                            <span class="breakdown-label">Previous Payments</span>
                            <span class="breakdown-amount">- GHS <?php echo number_format($bill['previous_payments'], 2); ?></span>
                        </div>
                        
                        <div class="breakdown-item">
                            <span class="breakdown-label">Amount Payable</span>
                            <span class="breakdown-amount">GHS <?php echo number_format($bill['amount_payable'], 2); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Adjustment Form -->
                <div class="content-card">
                    <div class="card-header">
                        <div class="card-title">
                            <div class="card-icon">
                                <i class="fas fa-calculator"></i>
                            </div>
                            Make Adjustment
                        </div>
                    </div>

                    <form method="POST" id="adjustmentForm">
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label required">Field to Adjust</label>
                                <select name="target_field" class="form-input" id="targetField" required onchange="updateCalculation()">
                                    <option value="">Select field...</option>
                                    <option value="old_bill">Old Bill (GHS <?php echo number_format($bill['old_bill'], 2); ?>)</option>
                                    <option value="arrears">Arrears (GHS <?php echo number_format($bill['arrears'], 2); ?>)</option>
                                    <option value="current_bill">Current Bill (GHS <?php echo number_format($bill['current_bill'], 2); ?>)</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Adjustment Method</label>
                                <select name="adjustment_method" class="form-input" id="adjustmentMethod" required onchange="updateCalculation()">
                                    <option value="">Select method...</option>
                                    <option value="Fixed Amount">Fixed Amount (GHS)</option>
                                    <option value="Percentage">Percentage (%)</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Adjustment Value</label>
                                <input type="number" name="adjustment_value" class="form-input" id="adjustmentValue" 
                                       step="0.01" min="0" required onchange="updateCalculation()" oninput="updateCalculation()">
                            </div>

                            <div class="form-group full-width">
                                <label class="form-label required">Reason for Adjustment</label>
                                <textarea name="reason" class="form-input form-textarea" required 
                                          placeholder="Provide a detailed reason for this adjustment..."></textarea>
                            </div>
                        </div>

                        <!-- Calculation Preview -->
                        <div class="calculation-preview" id="calculationPreview">
                            <div class="preview-title">
                                <i class="fas fa-calculator"></i>
                                Adjustment Preview
                            </div>
                            <div class="preview-calculation" id="previewCalculation"></div>
                            <div class="preview-result" id="previewResult"></div>
                        </div>

                        <div class="form-actions">
                            <a href="view.php?id=<?php echo $bill['bill_id']; ?>" class="btn btn-secondary">
                                <i class="fas fa-times"></i>
                                Cancel
                            </a>
                            <button type="submit" class="btn btn-primary" id="submitBtn" disabled>
                                <i class="fas fa-save"></i>
                                Apply Adjustment
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Right Column - History -->
            <div>
                <!-- Adjustment History -->
                <div class="content-card">
                    <div class="card-header">
                        <div class="card-title">
                            <div class="card-icon">
                                <i class="fas fa-history"></i>
                            </div>
                            Adjustment History
                        </div>
                    </div>

                    <?php if (empty($adjustmentHistory)): ?>
                        <div class="empty-state">
                            <i class="fas fa-history"></i>
                            <h3>No Adjustments Made</h3>
                            <p>This bill has not been adjusted yet.</p>
                        </div>
                    <?php else: ?>
                        <table class="history-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Field</th>
                                    <th>Method</th>
                                    <th>Change</th>
                                    <th>By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($adjustmentHistory as $adjustment): ?>
                                    <tr>
                                        <td>
                                            <div style="font-size: 12px;">
                                                <?php echo date('M d, Y', strtotime($adjustment['applied_at'])); ?>
                                            </div>
                                            <div style="font-size: 11px; color: #64748b;">
                                                <?php echo date('g:i A', strtotime($adjustment['applied_at'])); ?>
                                            </div>
                                        </td>
                                        <td style="text-transform: capitalize; font-weight: 600;">
                                            <?php echo str_replace('_', ' ', $adjustment['target_type']); ?>
                                        </td>
                                        <td>
                                            <div style="font-size: 12px;">
                                                <?php echo htmlspecialchars($adjustment['adjustment_method']); ?>
                                            </div>
                                            <div style="font-size: 11px; color: #64748b;">
                                                <?php 
                                                    if ($adjustment['adjustment_method'] === 'Percentage') {
                                                        echo $adjustment['adjustment_value'] . '%';
                                                    } else {
                                                        echo 'GHS ' . number_format($adjustment['adjustment_value'], 2);
                                                    }
                                                ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="amount-change <?php echo $adjustment['new_amount'] > $adjustment['old_amount'] ? 'amount-increase' : 'amount-decrease'; ?>">
                                                GHS <?php echo number_format($adjustment['old_amount'], 2); ?>
                                                <br>
                                                <i class="fas fa-arrow-down"></i>
                                                <br>
                                                GHS <?php echo number_format($adjustment['new_amount'], 2); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600;">
                                                <?php echo htmlspecialchars(trim($adjustment['first_name'] . ' ' . $adjustment['last_name'])); ?>
                                            </div>
                                            <?php if ($adjustment['reason']): ?>
                                                <div style="font-size: 11px; color: #64748b; margin-top: 5px;" title="<?php echo htmlspecialchars($adjustment['reason']); ?>">
                                                    <?php echo htmlspecialchars(substr($adjustment['reason'], 0, 30)) . (strlen($adjustment['reason']) > 30 ? '...' : ''); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Quick Actions -->
                <div class="content-card">
                    <div class="card-header">
                        <div class="card-title">
                            <div class="card-icon">
                                <i class="fas fa-bolt"></i>
                            </div>
                            Quick Actions
                        </div>
                    </div>
                    
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <a href="view.php?id=<?php echo $bill['bill_id']; ?>" class="btn btn-primary">
                            <i class="fas fa-eye"></i>
                            View Bill Details
                        </a>
                        
                        <?php if ($bill['status'] !== 'Paid' && hasPermission('payments.create')): ?>
                            <a href="../payments/record.php?bill_id=<?php echo $bill['bill_id']; ?>" class="btn btn-success">
                                <i class="fas fa-credit-card"></i>
                                Record Payment
                            </a>
                        <?php endif; ?>
                        
                        <button class="btn btn-secondary" onclick="printBill()">
                            <i class="fas fa-print"></i>
                            Print Bill
                        </button>
                        
                        <a href="bulk_adjustments.php" class="btn btn-secondary">
                            <i class="fas fa-layer-group"></i>
                            Bulk Adjustments
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Bill data for calculations
        const billData = {
            old_bill: <?php echo $bill['old_bill']; ?>,
            arrears: <?php echo $bill['arrears']; ?>,
            current_bill: <?php echo $bill['current_bill']; ?>,
            previous_payments: <?php echo $bill['previous_payments']; ?>
        };

        function updateCalculation() {
            const targetField = document.getElementById('targetField').value;
            const adjustmentMethod = document.getElementById('adjustmentMethod').value;
            const adjustmentValue = parseFloat(document.getElementById('adjustmentValue').value) || 0;
            const preview = document.getElementById('calculationPreview');
            const calculation = document.getElementById('previewCalculation');
            const result = document.getElementById('previewResult');
            const submitBtn = document.getElementById('submitBtn');

            if (!targetField || !adjustmentMethod || adjustmentValue <= 0) {
                preview.classList.remove('show');
                submitBtn.disabled = true;
                return;
            }

            const currentValue = billData[targetField];
            let newValue, adjustmentAmount;

            if (adjustmentMethod === 'Fixed Amount') {
                adjustmentAmount = adjustmentValue;
                newValue = currentValue + adjustmentAmount;
                calculation.innerHTML = `GHS ${currentValue.toFixed(2)} + GHS ${adjustmentValue.toFixed(2)} = GHS ${newValue.toFixed(2)}`;
            } else {
                adjustmentAmount = (currentValue * adjustmentValue) / 100;
                newValue = currentValue + adjustmentAmount;
                calculation.innerHTML = `GHS ${currentValue.toFixed(2)} + (${adjustmentValue}% of GHS ${currentValue.toFixed(2)}) = GHS ${newValue.toFixed(2)}`;
            }

            // Ensure new value is not negative
            newValue = Math.max(0, newValue);

            // Calculate new amount payable
            let newAmountPayable = billData.old_bill + billData.arrears + billData.current_bill - billData.previous_payments;
            
            if (targetField === 'old_bill') {
                newAmountPayable = newValue + billData.arrears + billData.current_bill - billData.previous_payments;
            } else if (targetField === 'arrears') {
                newAmountPayable = billData.old_bill + newValue + billData.current_bill - billData.previous_payments;
            } else if (targetField === 'current_bill') {
                newAmountPayable = billData.old_bill + billData.arrears + newValue - billData.previous_payments;
            }

            const currentAmountPayable = <?php echo $bill['amount_payable']; ?>;
            const amountChange = newAmountPayable - currentAmountPayable;

            result.innerHTML = `
                <strong>New ${targetField.replace('_', ' ').toUpperCase()}:</strong> GHS ${newValue.toFixed(2)}<br>
                <strong>New Amount Payable:</strong> GHS ${newAmountPayable.toFixed(2)} 
                <span style="color: ${amountChange >= 0 ? '#059669' : '#dc2626'};">
                    (${amountChange >= 0 ? '+' : ''}GHS ${amountChange.toFixed(2)})
                </span>
            `;

            preview.classList.add('show');
            submitBtn.disabled = false;

            // Validation warnings
            if (adjustmentMethod === 'Percentage' && adjustmentValue > 100) {
                result.innerHTML += '<br><span style="color: #dc2626; font-size: 12px;">⚠️ Percentage adjustment over 100% may result in very large changes</span>';
            }

            if (newValue === 0) {
                result.innerHTML += '<br><span style="color: #dc2626; font-size: 12px;">⚠️ This adjustment will set the value to zero</span>';
            }
        }

        function printBill() {
            window.open(`bill_preview.php?id=<?php echo $bill['bill_id']; ?>&action=print`, '_blank');
        }

        // Form validation
        document.getElementById('adjustmentForm').addEventListener('submit', function(e) {
            const targetField = document.getElementById('targetField').value;
            const adjustmentMethod = document.getElementById('adjustmentMethod').value;
            const adjustmentValue = parseFloat(document.getElementById('adjustmentValue').value) || 0;
            const reason = document.querySelector('textarea[name="reason"]').value.trim();

            if (!targetField || !adjustmentMethod || adjustmentValue <= 0 || !reason) {
                e.preventDefault();
                alert('Please fill in all required fields');
                return false;
            }

            if (adjustmentMethod === 'Percentage' && adjustmentValue > 100) {
                if (!confirm('You are applying a percentage adjustment over 100%. This may result in very large changes. Are you sure you want to continue?')) {
                    e.preventDefault();
                    return false;
                }
            }

            const currentValue = billData[targetField];
            const newValue = adjustmentMethod === 'Fixed Amount' ? 
                currentValue + adjustmentValue : 
                currentValue + ((currentValue * adjustmentValue) / 100);

            if (newValue === 0) {
                if (!confirm('This adjustment will set the value to zero. Are you sure you want to continue?')) {
                    e.preventDefault();
                    return false;
                }
            }

            if (!confirm('Are you sure you want to apply this adjustment? This action cannot be undone.')) {
                e.preventDefault();
                return false;
            }

            return true;
        });

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            updateCalculation();
        });
    </script>
</body>
</html>