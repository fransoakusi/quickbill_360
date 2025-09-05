<?php
/**
 * Payment Receipt Generator
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
if (!hasPermission('payments.view')) {
    setFlashMessage('error', 'Access denied. You do not have permission to view payment receipts.');
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
// Get payment ID from URL
$paymentId = isset($_GET['payment_id']) ? intval($_GET['payment_id']) : 0;

if (!$paymentId) {
    setFlashMessage('error', 'Invalid payment ID.');
    header('Location: index.php');
    exit();
}

try {
    $db = new Database();
    
    // Get payment details with related information
    $payment = $db->fetchRow("
        SELECT 
            p.*,
            b.bill_number,
            b.bill_type,
            b.billing_year,
            b.old_bill,
            b.previous_payments,
            b.arrears,
            b.current_bill,
            b.amount_payable as bill_amount_payable,
            CASE 
                WHEN b.bill_type = 'Business' THEN bs.business_name
                WHEN b.bill_type = 'Property' THEN pr.owner_name
            END as payer_name,
            CASE 
                WHEN b.bill_type = 'Business' THEN bs.account_number
                WHEN b.bill_type = 'Property' THEN pr.property_number
            END as account_number,
            CASE 
                WHEN b.bill_type = 'Business' THEN bs.telephone
                WHEN b.bill_type = 'Property' THEN pr.telephone
            END as telephone,
            CASE 
                WHEN b.bill_type = 'Business' THEN bs.exact_location
                WHEN b.bill_type = 'Property' THEN pr.location
            END as payer_location,
            CASE 
                WHEN b.bill_type = 'Business' THEN CONCAT(bs.business_type, ' - ', bs.category)
                WHEN b.bill_type = 'Property' THEN CONCAT(pr.structure, ' (', pr.number_of_rooms, ' rooms)')
            END as additional_info,
            CASE 
                WHEN b.bill_type = 'Business' THEN z1.zone_name
                WHEN b.bill_type = 'Property' THEN z2.zone_name
            END as zone_name,
            u.username as processed_by_username,
            CONCAT(u.first_name, ' ', u.last_name) as processed_by_name
        FROM payments p
        INNER JOIN bills b ON p.bill_id = b.bill_id
        LEFT JOIN businesses bs ON b.bill_type = 'Business' AND b.reference_id = bs.business_id
        LEFT JOIN properties pr ON b.bill_type = 'Property' AND b.reference_id = pr.property_id
        LEFT JOIN zones z1 ON b.bill_type = 'Business' AND bs.zone_id = z1.zone_id
        LEFT JOIN zones z2 ON b.bill_type = 'Property' AND pr.zone_id = z2.zone_id
        LEFT JOIN users u ON p.processed_by = u.user_id
        WHERE p.payment_id = ?
    ", [$paymentId]);
    
    if (!$payment) {
        setFlashMessage('error', 'Payment not found.');
        header('Location: index.php');
        exit();
    }
    
    // Get system settings for assembly information
    $assemblyName = $db->fetchRow("SELECT setting_value FROM system_settings WHERE setting_key = 'assembly_name'");
    $assemblyName = $assemblyName ? $assemblyName['setting_value'] : 'District Assembly';
    
    // Calculate remaining balance
    $remainingBalance = $payment['bill_amount_payable'] - $payment['amount_paid'];
    
    // Generate receipt number if not exists
    $receiptNumber = 'RCP' . date('Y') . str_pad($payment['payment_id'], 6, '0', STR_PAD_LEFT);
    
} catch (Exception $e) {
    writeLog("Receipt generation error: " . $e->getMessage(), 'ERROR');
    setFlashMessage('error', 'An error occurred while generating the receipt.');
    header('Location: index.php');
    exit();
}

// Set print-friendly headers
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Receipt - <?php echo htmlspecialchars($payment['payment_reference']); ?></title>
    
    <style>
        /* Reset and base styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Arial', 'Helvetica', sans-serif;
            line-height: 1.3;
            color: #333;
            background: #f5f5f5;
            padding: 10px;
            font-size: 13px;
        }
        
        /* Receipt container */
        .receipt-container {
            max-width: 750px;
            margin: 0 auto;
            background: white;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            border-radius: 6px;
            overflow: hidden;
        }
        
        /* Header section */
        .receipt-header {
            background: linear-gradient(135deg, #2d3748 0%, #4a5568 100%);
            color: white;
            padding: 10px 20px;
            text-align: center;
            position: relative;
        }
        
        .receipt-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
        }
        
        .assembly-logo {
            width: 40px;
            height: 40px;
            margin: 0 auto 5px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: bold;
            border: 2px solid rgba(255,255,255,0.2);
        }
        
        .assembly-name {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 2px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .receipt-title {
            font-size: 12px;
            opacity: 0.9;
            font-weight: normal;
        }
        
        /* Receipt info bar */
        .receipt-info {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            padding: 8px 20px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .info-item {
            text-align: left;
        }
        
        .info-item:last-child {
            text-align: right;
        }
        
        .info-label {
            font-size: 9px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
        }
        
        .info-value {
            font-size: 12px;
            font-weight: bold;
            color: #2d3748;
            font-family: monospace;
        }
        
        .receipt-number {
            color: #3b82f6;
        }
        
        .payment-status {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .status-successful {
            background: #d1fae5;
            color: #065f46;
        }
        
        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }
        
        .status-failed {
            background: #fee2e2;
            color: #991b1b;
        }
        
        /* Main content */
        .receipt-content {
            padding: 10px 20px;
            position: relative;
            z-index: 1;
        }
        
        /* Payment details section */
        .payment-section {
            margin-bottom: 10px;
        }
        
        .section-title {
            font-size: 15px;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 8px;
            padding-bottom: 4px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .section-icon {
            width: 18px;
            height: 18px;
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 10px;
        }
        
        /* Details grid */
        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 10px;
        }
        
        .detail-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .detail-label {
            font-weight: 600;
            color: #64748b;
            font-size: 12px;
        }
        
        .detail-value {
            font-weight: bold;
            color: #2d3748;
            text-align: right;
            font-size: 12px;
        }
        
        .amount-value {
            color: #059669;
            font-family: monospace;
            font-size: 14px;
        }
        
        /* Payer information */
        .payer-card {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 10px;
        }
        
        .payer-name {
            font-size: 16px;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 8px;
        }
        
        .payer-details {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 10px;
        }
        
        .payer-detail {
            display: flex;
            flex-direction: column;
        }
        
        .payer-label {
            font-size: 10px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
        }
        
        .payer-value {
            font-weight: 600;
            color: #2d3748;
            font-size: 12px;
        }
        
        /* Bill breakdown */
        .bill-breakdown {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            overflow: hidden;
            margin-bottom: 10px;
        }
        
        .breakdown-header {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            padding: 8px 12px;
            font-weight: bold;
            color: #2d3748;
            border-bottom: 1px solid #e2e8f0;
            font-size: 13px;
        }
        
        .breakdown-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 12px;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .breakdown-item:last-child {
            border-bottom: none;
        }
        
        .breakdown-label {
            color: #64748b;
            font-weight: 600;
            font-size: 12px;
        }
        
        .breakdown-value {
            font-weight: bold;
            color: #2d3748;
            font-family: monospace;
            font-size: 12px;
        }
        
        .total-row {
            background: linear-gradient(135deg, #f0f9f4 0%, #dcfce7 100%);
            border-top: 2px solid #10b981;
        }
        
        .total-row .breakdown-label {
            color: #065f46;
            font-weight: bold;
        }
        
        .total-row .breakdown-value {
            color: #065f46;
            font-size: 14px;
        }
        
        .payment-row {
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
        }
        
        .payment-row .breakdown-label {
            color: #1e40af;
            font-weight: bold;
        }
        
        .payment-row .breakdown-value {
            color: #1e40af;
            font-size: 13px;
        }
        
        .balance-row {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
        }
        
        .balance-row .breakdown-label {
            color: #92400e;
            font-weight: bold;
        }
        
        .balance-row .breakdown-value {
            color: #92400e;
            font-size: 13px;
        }
        
        /* Footer */
        .receipt-footer {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            padding: 8px 20px;
            border-top: 1px solid #e2e8f0;
            text-align: center;
        }
        
        .footer-note {
            color: #64748b;
            font-size: 10px;
            margin-bottom: 6px;
            line-height: 1.3;
        }
        
        .verification-code {
            font-family: monospace;
            font-weight: bold;
            color: #2d3748;
            background: white;
            padding: 3px 6px;
            border-radius: 3px;
            border: 1px solid #e2e8f0;
            display: inline-block;
            margin-bottom: 6px;
            font-size: 9px;
        }
        
        .timestamp {
            font-size: 9px;
            color: #64748b;
            margin-bottom: 3px;
        }
        
        .generated-by {
            font-size: 9px;
            color: #64748b;
        }
        
        /* Print button */
        .print-controls {
            text-align: center;
            margin-bottom: 15px;
        }
        
        .btn {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            justify-content: center;
            font-size: 12px;
            transition: all 0.3s;
            margin: 0 3px;
        }
        
        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
        }
        
        .btn-secondary {
            background: #64748b;
        }
        
        .btn-secondary:hover {
            background: #475569;
            box-shadow: 0 4px 15px rgba(100, 116, 139, 0.3);
        }
        
        /* Print styles */
        @media print {
            @page {
                size: A4;
                margin: 0.4in;
            }
            
            body {
                background: white !important;
                padding: 0 !important;
                font-size: 12px !important;
            }
            
            .print-controls {
                display: none !important;
            }
            
            .security-watermark {
                display: none !important;
                visibility: hidden !important;
                opacity: 0 !important;
                height: 0 !important;
                width: 0 !important;
                position: absolute !important;
                z-index: -1 !important;
            }
            
            .receipt-container {
                box-shadow: none !important;
                border-radius: 0 !important;
                max-width: 100% !important;
                margin: 0 !important;
                position: relative !important;
                overflow: visible !important;
            }
            
            .receipt-content {
                position: static !important;
                z-index: auto !important;
            }
            
            /* Ensure all content flows normally */
            .receipt-header,
            .receipt-info,
            .receipt-content,
            .receipt-footer {
                position: static !important;
                z-index: auto !important;
            }
            
            /* Slightly more compact for print */
            .receipt-header {
                padding: 12px 18px !important;
            }
            
            .assembly-logo {
                width: 45px !important;
                height: 45px !important;
                font-size: 20px !important;
                margin-bottom: 6px !important;
            }
            
            .assembly-name {
                font-size: 16px !important;
                margin-bottom: 3px !important;
            }
            
            .receipt-title {
                font-size: 12px !important;
            }
            
            .receipt-info {
                padding: 6px 18px !important;
                grid-template-columns: 1fr 1fr !important;
            }
            
            .receipt-content {
                padding: 12px 18px !important;
            }
            
            .payment-section {
                margin-bottom: 12px !important;
            }
            
            .payer-card {
                padding: 10px !important;
                margin-bottom: 8px !important;
            }
            
            .receipt-footer {
                padding: 10px 18px !important;
            }
        }
        
        /* Responsive design */
        @media (max-width: 768px) {
            body {
                padding: 5px;
            }
            
            .receipt-info {
                grid-template-columns: 1fr;
                gap: 8px;
                padding: 8px 15px;
            }
            
            .info-item,
            .info-item:last-child {
                text-align: left;
            }
            
            .details-grid {
                grid-template-columns: 1fr;
                gap: 5px;
            }
            
            .payer-details {
                grid-template-columns: 1fr 1fr;
                gap: 8px;
            }
            
            .receipt-header,
            .receipt-content {
                padding: 8px 15px;
            }
            
            .assembly-name {
                font-size: 14px;
            }
            
            .receipt-title {
                font-size: 11px;
            }
        }
        
        /* Security watermark - only for screen view */
        .security-watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 80px;
            color: rgba(59, 130, 246, 0.04);
            font-weight: bold;
            pointer-events: none;
            z-index: 0;
        }
    </style>
</head>
<body>
    <!-- Print Controls -->
    <div class="print-controls">
        <button onclick="window.print()" class="btn">
            🖨️ Print Receipt
        </button>
        <a href="index.php" class="btn btn-secondary">
            ← Back to Payments
        </a>
        <a href="view.php?id=<?php echo $payment['payment_id']; ?>" class="btn btn-secondary">
            👁️ View Details
        </a>
    </div>

    <!-- Receipt Container -->
    <div class="receipt-container">
        <!-- Security Watermark -->
        <div class="security-watermark">PAID</div>
        
        <!-- Header -->
        <div class="receipt-header">
            <div class="assembly-logo">
                <?php echo strtoupper(substr($assemblyName, 0, 1)); ?>
            </div>
            <div class="assembly-name"><?php echo htmlspecialchars($assemblyName); ?></div>
            <div class="receipt-title">Official Payment Receipt</div>
        </div>

        <!-- Receipt Info Bar -->
        <div class="receipt-info">
            <div class="info-item">
                <div class="info-label">Payment Reference</div>
                <div class="info-value"><?php echo htmlspecialchars($payment['payment_reference']); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Receipt Number</div>
                <div class="info-value receipt-number"><?php echo htmlspecialchars($receiptNumber); ?></div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="receipt-content">
            <!-- Payer Information -->
            <div class="payment-section">
                <div class="section-title">
                    <div class="section-icon">👤</div>
                    Payer Information
                </div>
                
                <div class="payer-card">
                    <div class="payer-name"><?php echo htmlspecialchars($payment['payer_name']); ?></div>
                    <div class="payer-details">
                        <div class="payer-detail">
                            <div class="payer-label">Account Number</div>
                            <div class="payer-value"><?php echo htmlspecialchars($payment['account_number']); ?></div>
                        </div>
                        <div class="payer-detail">
                            <div class="payer-label">Account Type</div>
                            <div class="payer-value"><?php echo htmlspecialchars($payment['bill_type']); ?></div>
                        </div>
                        <?php if (!empty($payment['telephone'])): ?>
                        <div class="payer-detail">
                            <div class="payer-label">Phone Number</div>
                            <div class="payer-value"><?php echo htmlspecialchars($payment['telephone']); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($payment['zone_name'])): ?>
                        <div class="payer-detail">
                            <div class="payer-label">Zone</div>
                            <div class="payer-value"><?php echo htmlspecialchars($payment['zone_name']); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($payment['additional_info'])): ?>
                        <div class="payer-detail">
                            <div class="payer-label">Details</div>
                            <div class="payer-value"><?php echo htmlspecialchars($payment['additional_info']); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($payment['payer_location'])): ?>
                        <div class="payer-detail" style="grid-column: 1 / -1;">
                            <div class="payer-label">Location</div>
                            <div class="payer-value"><?php echo htmlspecialchars($payment['payer_location']); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Bill Information -->
            <div class="payment-section">
                <div class="section-title">
                    <div class="section-icon">📄</div>
                    Bill Information
                </div>
                
                <div class="details-grid">
                    <div class="detail-item">
                        <span class="detail-label">Bill Number:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($payment['bill_number']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Billing Year:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($payment['billing_year']); ?></span>
                    </div>
                </div>
                
                <!-- Bill Breakdown -->
                <div class="bill-breakdown">
                    <div class="breakdown-header">Bill Breakdown</div>
                    
                    <?php if ($payment['old_bill'] > 0): ?>
                    <div class="breakdown-item">
                        <span class="breakdown-label">Previous Balance</span>
                        <span class="breakdown-value">GHS <?php echo number_format($payment['old_bill'], 2); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($payment['arrears'] > 0): ?>
                    <div class="breakdown-item">
                        <span class="breakdown-label">Arrears</span>
                        <span class="breakdown-value">GHS <?php echo number_format($payment['arrears'], 2); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="breakdown-item">
                        <span class="breakdown-label">Current Bill (<?php echo $payment['billing_year']; ?>)</span>
                        <span class="breakdown-value">GHS <?php echo number_format($payment['current_bill'], 2); ?></span>
                    </div>
                    
                    <?php if ($payment['previous_payments'] > 0): ?>
                    <div class="breakdown-item">
                        <span class="breakdown-label">Previous Payments</span>
                        <span class="breakdown-value">- GHS <?php echo number_format($payment['previous_payments'], 2); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="breakdown-item total-row">
                        <span class="breakdown-label">Total Amount Due</span>
                        <span class="breakdown-value">GHS <?php echo number_format($payment['bill_amount_payable'], 2); ?></span>
                    </div>
                    
                    <div class="breakdown-item payment-row">
                        <span class="breakdown-label">Amount Paid</span>
                        <span class="breakdown-value">GHS <?php echo number_format($payment['amount_paid'], 2); ?></span>
                    </div>
                    
                    <div class="breakdown-item balance-row">
                        <span class="breakdown-label">
                            <?php echo $remainingBalance > 0 ? 'Remaining Balance' : 'Overpayment'; ?>
                        </span>
                        <span class="breakdown-value">
                            GHS <?php echo number_format(abs($remainingBalance), 2); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Payment Details -->
            <div class="payment-section">
                <div class="section-title">
                    <div class="section-icon">💳</div>
                    Payment Details
                </div>
                
                <div class="details-grid">
                    <div class="detail-item">
                        <span class="detail-label">Amount Paid:</span>
                        <span class="detail-value amount-value">GHS <?php echo number_format($payment['amount_paid'], 2); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Payment Method:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($payment['payment_method']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Payment Date:</span>
                        <span class="detail-value"><?php echo date('M d, Y g:i A', strtotime($payment['payment_date'])); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Processed By:</span>
                        <span class="detail-value">
                            <?php echo $payment['processed_by_name'] ? htmlspecialchars($payment['processed_by_name']) : 'System'; ?>
                        </span>
                    </div>
                    <?php if (!empty($payment['payment_channel'])): ?>
                    <div class="detail-item">
                        <span class="detail-label">Payment Channel:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($payment['payment_channel']); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($payment['transaction_id'])): ?>
                    <div class="detail-item">
                        <span class="detail-label">Transaction ID:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($payment['transaction_id']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="receipt-footer">
            <div class="footer-note">
                This is an official receipt for payment made to <?php echo htmlspecialchars($assemblyName); ?>. 
                Please keep this receipt for your records. For any inquiries, please contact our office with the receipt number above.
            </div>
            
            <div class="verification-code">
                Verification Code: <?php echo strtoupper(md5($payment['payment_reference'] . $payment['payment_date'])); ?>
            </div>
            
            <div class="timestamp">
                Receipt generated on: <?php echo date('F d, Y \a\t g:i A'); ?>
            </div>
            
            <div class="generated-by">
                Generated by: <?php echo APP_NAME; ?> | Powered by QuickBill 305
            </div>
        </div>
    </div>

    <script>
        // Auto-print if requested
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('auto_print') === '1') {
            window.onload = function() {
                setTimeout(function() {
                    window.print();
                }, 500);
            };
        }
        
        // Add print shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                window.print();
            }
        });
    </script>
</body>
</html>