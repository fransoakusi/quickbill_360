<?php
/**
 * Billing Management - Bulk Bill Adjustments
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
    setFlashMessage('error', 'Access denied. You do not have permission to perform bulk adjustments.');
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

$pageTitle = 'Bulk Bill Adjustments';
$currentUser = getCurrentUser();

// Initialize variables
$errors = [];
$success = false;
$adjustmentResults = [];
$zones = [];
$businessTypes = [];
$previewBills = [];
$showPreview = false;

// Get filter parameters
$filterType = sanitizeInput($_POST['filter_type'] ?? '');
$filterZone = intval($_POST['filter_zone'] ?? 0);
$filterBusinessType = sanitizeInput($_POST['filter_business_type'] ?? '');
$filterStatus = sanitizeInput($_POST['filter_status'] ?? '');
$filterYear = intval($_POST['filter_year'] ?? 0);

try {
    $db = new Database();
    
    // Get zones for filter
    $zones = $db->fetchAll("SELECT * FROM zones ORDER BY zone_name");
    
    // Get business types for filter
    $businessTypes = $db->fetchAll("
        SELECT DISTINCT business_type 
        FROM business_fee_structure 
        WHERE is_active = 1 
        ORDER BY business_type
    ");
    
    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = sanitizeInput($_POST['action'] ?? '');
        
        if ($action === 'preview') {
            // Show preview of bills that will be affected
            $whereConditions = [];
            $params = [];
            
            // Build filter conditions
            if (!empty($filterType)) {
                $whereConditions[] = "b.bill_type = ?";
                $params[] = $filterType;
            }
            
            if ($filterZone > 0) {
                $whereConditions[] = "(
                    (b.bill_type = 'Business' AND bs.zone_id = ?) OR
                    (b.bill_type = 'Property' AND pr.zone_id = ?)
                )";
                $params[] = $filterZone;
                $params[] = $filterZone;
            }
            
            if (!empty($filterBusinessType)) {
                $whereConditions[] = "b.bill_type = 'Business' AND bs.business_type = ?";
                $params[] = $filterBusinessType;
            }
            
            if (!empty($filterStatus)) {
                $whereConditions[] = "b.status = ?";
                $params[] = $filterStatus;
            }
            
            if ($filterYear > 0) {
                $whereConditions[] = "b.billing_year = ?";
                $params[] = $filterYear;
            }
            
            $whereClause = empty($whereConditions) ? '' : 'WHERE ' . implode(' AND ', $whereConditions);
            
            // Get bills that match the criteria
            $previewQuery = "
                SELECT b.bill_id, b.bill_number, b.bill_type, b.billing_year, b.status,
                       b.old_bill, b.arrears, b.current_bill, b.amount_payable,
                       CASE 
                           WHEN b.bill_type = 'Business' THEN bs.business_name
                           WHEN b.bill_type = 'Property' THEN pr.owner_name
                       END as payer_name,
                       CASE 
                           WHEN b.bill_type = 'Business' THEN bs.account_number
                           WHEN b.bill_type = 'Property' THEN pr.property_number
                       END as account_number,
                       CASE 
                           WHEN b.bill_type = 'Business' THEN z1.zone_name
                           WHEN b.bill_type = 'Property' THEN z2.zone_name
                       END as zone_name
                FROM bills b
                LEFT JOIN businesses bs ON b.bill_type = 'Business' AND b.reference_id = bs.business_id
                LEFT JOIN properties pr ON b.bill_type = 'Property' AND b.reference_id = pr.property_id
                LEFT JOIN zones z1 ON bs.zone_id = z1.zone_id
                LEFT JOIN zones z2 ON pr.zone_id = z2.zone_id
                {$whereClause}
                ORDER BY b.bill_type, b.bill_number
                LIMIT 100
            ";
            
            $previewBills = $db->fetchAll($previewQuery, $params);
            $showPreview = true;
            
        } elseif ($action === 'apply') {
            // Apply bulk adjustments
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
                $errors[] = 'Please provide a reason for this bulk adjustment.';
            }
            
            // Validate filters - at least one filter must be applied
            if (empty($filterType) && $filterZone <= 0 && empty($filterBusinessType) && empty($filterStatus) && $filterYear <= 0) {
                $errors[] = 'Please apply at least one filter to limit the scope of bulk adjustments.';
            }
            
            // Process bulk adjustment if no errors
            if (empty($errors)) {
                try {
                    // Start transaction
                    if (method_exists($db, 'beginTransaction')) {
                        $db->beginTransaction();
                    }
                    
                    // Build filter conditions (same as preview)
                    $whereConditions = [];
                    $params = [];
                    
                    if (!empty($filterType)) {
                        $whereConditions[] = "b.bill_type = ?";
                        $params[] = $filterType;
                    }
                    
                    if ($filterZone > 0) {
                        $whereConditions[] = "(
                            (b.bill_type = 'Business' AND bs.zone_id = ?) OR
                            (b.bill_type = 'Property' AND pr.zone_id = ?)
                        )";
                        $params[] = $filterZone;
                        $params[] = $filterZone;
                    }
                    
                    if (!empty($filterBusinessType)) {
                        $whereConditions[] = "b.bill_type = 'Business' AND bs.business_type = ?";
                        $params[] = $filterBusinessType;
                    }
                    
                    if (!empty($filterStatus)) {
                        $whereConditions[] = "b.status = ?";
                        $params[] = $filterStatus;
                    }
                    
                    if ($filterYear > 0) {
                        $whereConditions[] = "b.billing_year = ?";
                        $params[] = $filterYear;
                    }
                    
                    $whereClause = empty($whereConditions) ? '' : 'WHERE ' . implode(' AND ', $whereConditions);
                    
                    // Get bills to adjust
                    $billsQuery = "
                        SELECT b.bill_id, b.bill_type, b.reference_id, b.{$targetField} as current_value,
                               b.old_bill, b.arrears, b.current_bill, b.previous_payments, b.amount_payable
                        FROM bills b
                        LEFT JOIN businesses bs ON b.bill_type = 'Business' AND b.reference_id = bs.business_id
                        LEFT JOIN properties pr ON b.bill_type = 'Property' AND b.reference_id = pr.property_id
                        LEFT JOIN zones z1 ON bs.zone_id = z1.zone_id
                        LEFT JOIN zones z2 ON pr.zone_id = z2.zone_id
                        {$whereClause}
                    ";
                    
                    $billsToAdjust = $db->fetchAll($billsQuery, $params);
                    
                    $adjustedCount = 0;
                    $totalAdjustment = 0;
                    
                    foreach ($billsToAdjust as $bill) {
                        $currentValue = floatval($bill['current_value']);
                        
                        // Calculate new value
                        if ($adjustmentMethod === 'Fixed Amount') {
                            $newValue = $currentValue + $adjustmentValue;
                            $actualAdjustment = $adjustmentValue;
                        } else { // Percentage
                            $actualAdjustment = ($currentValue * $adjustmentValue) / 100;
                            $newValue = $currentValue + $actualAdjustment;
                        }
                        
                        // Ensure new value is not negative
                        $newValue = max(0, $newValue);
                        $actualAdjustment = $newValue - $currentValue;
                        
                        // Update bill record
                        $db->execute("
                            UPDATE bills 
                            SET {$targetField} = ?
                            WHERE bill_id = ?
                        ", [$newValue, $bill['bill_id']]);
                        
                        // Recalculate amount payable
                        $newAmountPayable = $bill['old_bill'] + $bill['arrears'] + $bill['current_bill'] - $bill['previous_payments'];
                        
                        // Apply the adjustment to the correct field for amount payable calculation
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
                        ", [$newAmountPayable, $bill['bill_id']]);
                        
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
                            'Bulk',
                            $bill['bill_type'],
                            $bill['reference_id'],
                            $adjustmentMethod,
                            $adjustmentValue,
                            $currentValue,
                            $newValue,
                            $reason,
                            $currentUser['user_id']
                        ]);
                        
                        $adjustedCount++;
                        $totalAdjustment += $actualAdjustment;
                    }
                    
                    // Commit transaction
                    if (method_exists($db, 'commit')) {
                        $db->commit();
                    }
                    
                    // Log the bulk action
                    $db->execute("
                        INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ", [
                        $currentUser['user_id'],
                        'BULK_BILL_ADJUSTMENT',
                        'bills',
                        null,
                        null,
                        json_encode([
                            'field' => $targetField,
                            'adjustment_method' => $adjustmentMethod,
                            'adjustment_value' => $adjustmentValue,
                            'affected_bills' => $adjustedCount,
                            'total_adjustment' => $totalAdjustment,
                            'filters' => [
                                'type' => $filterType,
                                'zone' => $filterZone,
                                'business_type' => $filterBusinessType,
                                'status' => $filterStatus,
                                'year' => $filterYear
                            ],
                            'reason' => $reason
                        ]),
                        $_SERVER['REMOTE_ADDR'] ?? '::1',
                        $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
                    ]);
                    
                    $adjustmentResults = [
                        'adjusted_count' => $adjustedCount,
                        'total_adjustment' => $totalAdjustment,
                        'field' => $targetField,
                        'method' => $adjustmentMethod,
                        'value' => $adjustmentValue
                    ];
                    
                    $success = true;
                    setFlashMessage('success', "Bulk adjustment applied successfully! {$adjustedCount} bills were adjusted.");
                    
                } catch (Exception $e) {
                    // Rollback transaction
                    if (method_exists($db, 'rollback')) {
                        $db->rollback();
                    }
                    $errors[] = 'An error occurred while applying bulk adjustments: ' . $e->getMessage();
                    writeLog("Bulk adjustment error: " . $e->getMessage(), 'ERROR');
                }
            }
        }
    }
    
} catch (Exception $e) {
    writeLog("Bulk adjustments page error: " . $e->getMessage(), 'ERROR');
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
        
        /* Warning Card */
        .warning-card {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: 2px solid #f59e0b;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
        }
        
        .warning-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(245, 158, 11, 0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }
        
        .warning-content {
            position: relative;
            z-index: 2;
        }
        
        .warning-title {
            font-weight: 600;
            color: #92400e;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 18px;
        }
        
        .warning-text {
            color: #78350f;
            line-height: 1.6;
            margin-bottom: 15px;
        }
        
        .warning-list {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        
        .warning-list li {
            color: #78350f;
            margin-bottom: 8px;
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }
        
        .warning-list li:before {
            content: "⚠️";
            flex-shrink: 0;
        }
        
        /* Content Cards */
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
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
        }
        
        /* Form Elements */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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
            border-color: #dc2626;
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }
        
        .form-textarea {
            min-height: 100px;
            resize: vertical;
            font-family: inherit;
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
        
        .btn-danger {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            color: white;
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(220, 38, 38, 0.3);
        }
        
        .btn-primary {
            background: #3b82f6;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2563eb;
            color: white;
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
        
        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #e2e8f0;
        }
        
        /* Results Card */
        .results-card {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            border: 2px solid #10b981;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            text-align: center;
        }
        
        .results-icon {
            width: 80px;
            height: 80px;
            background: #10b981;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            color: white;
            font-size: 36px;
        }
        
        .results-title {
            font-size: 24px;
            font-weight: bold;
            color: #065f46;
            margin-bottom: 15px;
        }
        
        .results-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        
        .stat-item {
            background: rgba(255, 255, 255, 0.8);
            padding: 20px;
            border-radius: 10px;
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #065f46;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 12px;
            color: #047857;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        /* Preview Table */
        .preview-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-bottom: 30px;
        }
        
        .preview-header {
            background: #f8fafc;
            padding: 20px 25px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .preview-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .table-container {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .preview-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .preview-table th {
            background: #f8fafc;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            color: #2d3748;
            border-bottom: 1px solid #e2e8f0;
            font-size: 12px;
            text-transform: uppercase;
            position: sticky;
            top: 0;
            z-index: 1;
        }
        
        .preview-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            font-size: 14px;
        }
        
        .preview-table tr:hover {
            background: #f8fafc;
        }
        
        /* Status badges */
        .status-badge {
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-paid { background: #d1fae5; color: #065f46; }
        .status-partially-paid { background: #dbeafe; color: #1e40af; }
        .status-overdue { background: #fee2e2; color: #991b1b; }
        
        .type-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .type-business { background: #dbeafe; color: #1e40af; }
        .type-property { background: #d1fae5; color: #065f46; }
        
        .amount {
            font-weight: bold;
            font-family: monospace;
            color: #dc2626;
        }
        
        .bill-number {
            font-family: monospace;
            font-weight: 600;
            color: #2d3748;
        }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #64748b;
        }
        
        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .empty-state h3 {
            margin-bottom: 10px;
            color: #2d3748;
            font-size: 24px;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .results-stats {
                grid-template-columns: repeat(2, 1fr);
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
    </style>
</head>
<body>
    <div class="container">
        <!-- Page Header -->
        <div class="page-header">
            <div class="breadcrumb">
                <a href="../index.php">Dashboard</a> / 
                <a href="index.php">Billing</a> / 
                Bulk Adjustments
            </div>
            <h1 class="page-title">
                <i class="fas fa-layer-group"></i>
                Bulk Bill Adjustments
            </h1>
            <p style="color: #64748b;">Apply adjustments to multiple bills at once based on filters</p>
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

        <!-- Warning Card -->
        <div class="warning-card">
            <div class="warning-content">
                <div class="warning-title">
                    <i class="fas fa-exclamation-triangle"></i>
                    Critical Warning - Bulk Operations
                </div>
                <div class="warning-text">
                    Bulk adjustments affect multiple bills simultaneously and cannot be undone. These changes will permanently modify bill amounts and payment obligations.
                </div>
                <ul class="warning-list">
                    <li>Always preview changes before applying them</li>
                    <li>Use specific filters to limit the scope of changes</li>
                    <li>Ensure you have proper authorization for bulk modifications</li>
                    <li>All changes are logged and tracked for audit purposes</li>
                    <li>Consider the impact on payment schedules and customer notifications</li>
                </ul>
            </div>
        </div>

        <!-- Success Results -->
        <?php if ($success && !empty($adjustmentResults)): ?>
            <div class="results-card">
                <div class="results-icon">
                    <i class="fas fa-check"></i>
                </div>
                <div class="results-title">Bulk Adjustment Applied Successfully!</div>
                <div class="results-stats">
                    <div class="stat-item">
                        <div class="stat-number"><?php echo number_format($adjustmentResults['adjusted_count']); ?></div>
                        <div class="stat-label">Bills Adjusted</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo ucfirst(str_replace('_', ' ', $adjustmentResults['field'])); ?></div>
                        <div class="stat-label">Field Modified</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $adjustmentResults['method']; ?></div>
                        <div class="stat-label">Adjustment Method</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number">
                            <?php 
                                if ($adjustmentResults['method'] === 'Percentage') {
                                    echo $adjustmentResults['value'] . '%';
                                } else {
                                    echo 'GHS ' . number_format($adjustmentResults['value'], 2);
                                }
                            ?>
                        </div>
                        <div class="stat-label">Adjustment Value</div>
                    </div>
                </div>
                <div style="margin-top: 20px;">
                    <a href="list.php" class="btn btn-success">
                        <i class="fas fa-list"></i>
                        View Adjusted Bills
                    </a>
                    <a href="bulk_adjustments.php" class="btn btn-secondary">
                        <i class="fas fa-plus"></i>
                        New Bulk Adjustment
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <!-- Filter & Adjustment Form -->
        <div class="content-card">
            <div class="card-header">
                <div class="card-title">
                    <div class="card-icon">
                        <i class="fas fa-filter"></i>
                    </div>
                    Bulk Adjustment Criteria
                </div>
            </div>

            <form method="POST" id="bulkAdjustmentForm">
                <!-- Filters Section -->
                <h3 style="margin-bottom: 15px; color: #2d3748;">
                    <i class="fas fa-funnel-dollar"></i>
                    Target Filters (Required)
                </h3>
                <p style="margin-bottom: 20px; color: #64748b; font-size: 14px;">
                    Select criteria to identify which bills will be affected. At least one filter must be applied.
                </p>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Bill Type</label>
                        <select name="filter_type" class="form-input">
                            <option value="">All Types</option>
                            <option value="Business" <?php echo $filterType === 'Business' ? 'selected' : ''; ?>>Business Bills</option>
                            <option value="Property" <?php echo $filterType === 'Property' ? 'selected' : ''; ?>>Property Bills</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Zone</label>
                        <select name="filter_zone" class="form-input">
                            <option value="">All Zones</option>
                            <?php foreach ($zones as $zone): ?>
                                <option value="<?php echo $zone['zone_id']; ?>" 
                                        <?php echo $filterZone == $zone['zone_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($zone['zone_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Business Type</label>
                        <select name="filter_business_type" class="form-input">
                            <option value="">All Business Types</option>
                            <?php foreach ($businessTypes as $type): ?>
                                <option value="<?php echo htmlspecialchars($type['business_type']); ?>" 
                                        <?php echo $filterBusinessType === $type['business_type'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($type['business_type']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="filter_status" class="form-input">
                            <option value="">All Status</option>
                            <option value="Pending" <?php echo $filterStatus === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="Paid" <?php echo $filterStatus === 'Paid' ? 'selected' : ''; ?>>Paid</option>
                            <option value="Partially Paid" <?php echo $filterStatus === 'Partially Paid' ? 'selected' : ''; ?>>Partially Paid</option>
                            <option value="Overdue" <?php echo $filterStatus === 'Overdue' ? 'selected' : ''; ?>>Overdue</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Billing Year</label>
                        <select name="filter_year" class="form-input">
                            <option value="">All Years</option>
                            <?php for ($year = date('Y'); $year >= 2020; $year--): ?>
                                <option value="<?php echo $year; ?>" 
                                        <?php echo $filterYear == $year ? 'selected' : ''; ?>>
                                    <?php echo $year; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <!-- Adjustment Details Section -->
                <h3 style="margin: 30px 0 15px; color: #2d3748;">
                    <i class="fas fa-edit"></i>
                    Adjustment Details
                </h3>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label required">Field to Adjust</label>
                        <select name="target_field" class="form-input" required>
                            <option value="">Select field...</option>
                            <option value="old_bill">Old Bill</option>
                            <option value="arrears">Arrears</option>
                            <option value="current_bill">Current Bill</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Adjustment Method</label>
                        <select name="adjustment_method" class="form-input" required>
                            <option value="">Select method...</option>
                            <option value="Fixed Amount">Fixed Amount (GHS)</option>
                            <option value="Percentage">Percentage (%)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Adjustment Value</label>
                        <input type="number" name="adjustment_value" class="form-input" 
                               step="0.01" min="0" required 
                               placeholder="Enter amount or percentage">
                    </div>

                    <div class="form-group full-width">
                        <label class="form-label required">Reason for Bulk Adjustment</label>
                        <textarea name="reason" class="form-input form-textarea" required 
                                  placeholder="Provide a detailed reason for this bulk adjustment. This will be recorded for all affected bills."></textarea>
                    </div>
                </div>

                <div class="form-actions">
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i>
                        Cancel
                    </a>
                    <button type="submit" name="action" value="preview" class="btn btn-primary">
                        <i class="fas fa-search"></i>
                        Preview Changes
                    </button>
                    <?php if ($showPreview && !empty($previewBills)): ?>
                        <button type="submit" name="action" value="apply" class="btn btn-danger" 
                                onclick="return confirmBulkAdjustment()">
                            <i class="fas fa-bolt"></i>
                            Apply Bulk Adjustment
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Preview Section -->
        <?php if ($showPreview): ?>
            <div class="preview-card">
                <div class="preview-header">
                    <div class="preview-title">
                        <i class="fas fa-eye"></i>
                        Bills Preview (<?php echo count($previewBills); ?> bills will be affected)
                    </div>
                </div>

                <div class="table-container">
                    <?php if (empty($previewBills)): ?>
                        <div class="empty-state">
                            <i class="fas fa-search"></i>
                            <h3>No Bills Found</h3>
                            <p>No bills match your selected criteria. Please adjust your filters and try again.</p>
                        </div>
                    <?php else: ?>
                        <?php if (count($previewBills) >= 100): ?>
                            <div style="background: #fef3c7; border: 1px solid #f59e0b; padding: 15px; margin: 20px; border-radius: 8px; text-align: center;">
                                <i class="fas fa-info-circle"></i>
                                <strong>Preview Limited:</strong> Showing first 100 bills. Total matching bills may be higher.
                            </div>
                        <?php endif; ?>
                        
                        <table class="preview-table">
                            <thead>
                                <tr>
                                    <th>Bill Number</th>
                                    <th>Type</th>
                                    <th>Payer</th>
                                    <th>Year</th>
                                    <th>Status</th>
                                    <th>Current Amount</th>
                                    <th>Zone</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($previewBills as $bill): ?>
                                    <tr>
                                        <td>
                                            <span class="bill-number"><?php echo htmlspecialchars($bill['bill_number']); ?></span>
                                        </td>
                                        <td>
                                            <span class="type-badge type-<?php echo strtolower($bill['bill_type']); ?>">
                                                <?php echo htmlspecialchars($bill['bill_type']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600;">
                                                <?php echo htmlspecialchars($bill['payer_name'] ?: 'Unknown'); ?>
                                            </div>
                                            <div style="font-size: 12px; color: #64748b;">
                                                <?php echo htmlspecialchars($bill['account_number'] ?: 'N/A'); ?>
                                            </div>
                                        </td>
                                        <td><?php echo $bill['billing_year']; ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $bill['status'])); ?>">
                                                <?php echo htmlspecialchars($bill['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="amount">GHS <?php echo number_format($bill['amount_payable'], 2); ?></span>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($bill['zone_name'] ?: 'Unassigned'); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function confirmBulkAdjustment() {
            const adjustmentMethod = document.querySelector('select[name="adjustment_method"]').value;
            const adjustmentValue = document.querySelector('input[name="adjustment_value"]').value;
            const targetField = document.querySelector('select[name="target_field"]').value;
            const billCount = <?php echo count($previewBills); ?>;
            
            const fieldName = targetField.replace('_', ' ').toUpperCase();
            const methodText = adjustmentMethod === 'Percentage' ? adjustmentValue + '%' : 'GHS ' + adjustmentValue;
            
            const message = `Are you absolutely sure you want to apply this bulk adjustment?
            
This will:
• Modify ${billCount} bill(s)
• Adjust the ${fieldName} field
• Apply a ${methodText} ${adjustmentMethod.toLowerCase()} adjustment

This action CANNOT be undone!

Type "CONFIRM" to proceed:`;
            
            const confirmation = prompt(message);
            return confirmation === 'CONFIRM';
        }
        
        // Auto-update business type filter based on bill type
        document.addEventListener('DOMContentLoaded', function() {
            const billTypeFilter = document.querySelector('select[name="filter_type"]');
            const businessTypeFilter = document.querySelector('select[name="filter_business_type"]');
            
            function updateBusinessTypeFilter() {
                if (billTypeFilter.value === 'Property') {
                    businessTypeFilter.disabled = true;
                    businessTypeFilter.value = '';
                } else {
                    businessTypeFilter.disabled = false;
                }
            }
            
            billTypeFilter.addEventListener('change', updateBusinessTypeFilter);
            updateBusinessTypeFilter(); // Initial check
        });
    </script>
</body>
</html>