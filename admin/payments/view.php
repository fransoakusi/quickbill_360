 <?php
/**
 * Payment View Page
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
    setFlashMessage('error', 'Access denied. You do not have permission to view payments.');
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

// Get payment ID
$paymentId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$paymentId) {
    setFlashMessage('error', 'Payment ID is required.');
    header('Location: index.php');
    exit();
}

$pageTitle = 'Payment Details';
$currentUser = getCurrentUser();
$payment = null;

try {
    $db = new Database();
    
    // Get payment details with related data
    $payment = $db->fetchRow("
        SELECT 
            p.*,
            b.bill_number,
            b.bill_type,
            b.billing_year,
            b.old_bill,
            b.previous_payments as bill_previous_payments,
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
            END as location,
            u.username as processed_by_username,
            CONCAT(u.first_name, ' ', u.last_name) as processed_by_name
        FROM payments p
        INNER JOIN bills b ON p.bill_id = b.bill_id
        LEFT JOIN businesses bs ON b.bill_type = 'Business' AND b.reference_id = bs.business_id
        LEFT JOIN properties pr ON b.bill_type = 'Property' AND b.reference_id = pr.property_id
        LEFT JOIN users u ON p.processed_by = u.user_id
        WHERE p.payment_id = ?
    ", [$paymentId]);
    
    if (!$payment) {
        setFlashMessage('error', 'Payment not found.');
        header('Location: index.php');
        exit();
    }
    
} catch (Exception $e) {
    writeLog("Payment view error: " . $e->getMessage(), 'ERROR');
    setFlashMessage('error', 'An error occurred while loading payment details.');
    header('Location: index.php');
    exit();
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
            max-width: 1000px;
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
            color: #3b82f6;
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
        
        .payment-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .payment-header {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 30px;
            text-align: center;
        }
        
        .payment-reference {
            font-size: 32px;
            font-weight: bold;
            font-family: monospace;
            margin-bottom: 10px;
        }
        
        .payment-amount {
            font-size: 24px;
            margin-bottom: 5px;
        }
        
        .payment-status {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            display: inline-block;
            margin-top: 10px;
        }
        
        .status-successful {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }
        
        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }
        
        .status-failed {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .info-section {
            background: #f8fafc;
            border-radius: 10px;
            padding: 20px;
        }
        
        .info-section h4 {
            color: #2d3748;
            margin-bottom: 15px;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .info-item:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-weight: 600;
            color: #64748b;
            font-size: 14px;
        }
        
        .info-value {
            color: #2d3748;
            font-weight: 500;
        }
        
        .amount-value {
            font-family: monospace;
            font-weight: bold;
            color: #059669;
        }
        
        .bill-type {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .bill-type.business {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .bill-type.property {
            background: #d1fae5;
            color: #065f46;
        }
        
        .payment-method {
            padding: 6px 12px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .method-mobile {
            background: #fef3c7;
            color: #92400e;
        }
        
        .method-cash {
            background: #d1fae5;
            color: #065f46;
        }
        
        .method-bank {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .method-online {
            background: #e0e7ff;
            color: #3730a3;
        }
        
        .actions {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #e2e8f0;
        }
        
        .btn {
            padding: 12px 24px;
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
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.3);
            color: white;
        }
        
        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.3);
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
        
        @media (max-width: 768px) {
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .actions {
                flex-direction: column;
                align-items: center;
            }
            
            .btn {
                width: 100%;
                max-width: 300px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Page Header -->
        <div class="page-header">
            <div class="breadcrumb">
                <a href="../index.php">Dashboard</a> / 
                <a href="index.php">Payments</a> / 
                Payment Details
            </div>
            <h1 class="page-title">
                <i class="fas fa-receipt"></i>
                Payment Details
            </h1>
            <p style="color: #64748b;">View payment transaction details and generate receipt</p>
        </div>

        <!-- Flash Messages -->
        <?php if ($flashMessage): ?>
            <div class="alert alert-<?php echo $flashMessage['type']; ?>">
                <i class="fas fa-<?php echo $flashMessage['type'] === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                <div><?php echo htmlspecialchars($flashMessage['message']); ?></div>
            </div>
        <?php endif; ?>

        <!-- Payment Details Card -->
        <div class="payment-card">
            <!-- Payment Header -->
            <div class="payment-header">
                <div class="payment-reference"><?php echo htmlspecialchars($payment['payment_reference']); ?></div>
                <div class="payment-amount">GHS <?php echo number_format($payment['amount_paid'], 2); ?></div>
                <div class="payment-status status-<?php echo strtolower($payment['payment_status']); ?>">
                    <?php echo htmlspecialchars($payment['payment_status']); ?>
                </div>
            </div>

            <!-- Payment Information Grid -->
            <div class="info-grid">
                <!-- Payment Details -->
                <div class="info-section">
                    <h4>
                        <i class="fas fa-credit-card"></i>
                        Payment Information
                    </h4>
                    
                    <div class="info-item">
                        <span class="info-label">Payment Date</span>
                        <span class="info-value"><?php echo date('M d, Y \a\t g:i A', strtotime($payment['payment_date'])); ?></span>
                    </div>
                    
                    <div class="info-item">
                        <span class="info-label">Payment Method</span>
                        <span class="payment-method method-<?php echo strtolower(str_replace(' ', '-', $payment['payment_method'])); ?>">
                            <?php echo htmlspecialchars($payment['payment_method']); ?>
                        </span>
                    </div>
                    
                    <?php if (!empty($payment['payment_channel'])): ?>
                    <div class="info-item">
                        <span class="info-label">Payment Channel</span>
                        <span class="info-value"><?php echo htmlspecialchars($payment['payment_channel']); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($payment['transaction_id'])): ?>
                    <div class="info-item">
                        <span class="info-label">Transaction ID</span>
                        <span class="info-value" style="font-family: monospace;"><?php echo htmlspecialchars($payment['transaction_id']); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="info-item">
                        <span class="info-label">Processed By</span>
                        <span class="info-value"><?php echo htmlspecialchars($payment['processed_by_name'] ?? 'System'); ?></span>
                    </div>
                </div>

                <!-- Payer Details -->
                <div class="info-section">
                    <h4>
                        <i class="fas fa-user"></i>
                        Payer Information
                    </h4>
                    
                    <div class="info-item">
                        <span class="info-label">Payer Name</span>
                        <span class="info-value"><?php echo htmlspecialchars($payment['payer_name']); ?></span>
                    </div>
                    
                    <div class="info-item">
                        <span class="info-label">Account Number</span>
                        <span class="info-value" style="font-family: monospace;"><?php echo htmlspecialchars($payment['account_number']); ?></span>
                    </div>
                    
                    <?php if (!empty($payment['telephone'])): ?>
                    <div class="info-item">
                        <span class="info-label">Phone Number</span>
                        <span class="info-value"><?php echo htmlspecialchars($payment['telephone']); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($payment['location'])): ?>
                    <div class="info-item">
                        <span class="info-label">Location</span>
                        <span class="info-value"><?php echo htmlspecialchars($payment['location']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Bill Information -->
            <div class="info-section">
                <h4>
                    <i class="fas fa-file-invoice"></i>
                    Bill Information
                </h4>
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                    <div class="info-item">
                        <span class="info-label">Bill Number</span>
                        <span class="info-value" style="font-family: monospace;"><?php echo htmlspecialchars($payment['bill_number']); ?></span>
                    </div>
                    
                    <div class="info-item">
                        <span class="info-label">Bill Type</span>
                        <span class="bill-type <?php echo strtolower($payment['bill_type']); ?>">
                            <?php echo htmlspecialchars($payment['bill_type']); ?>
                        </span>
                    </div>
                    
                    <div class="info-item">
                        <span class="info-label">Billing Year</span>
                        <span class="info-value"><?php echo htmlspecialchars($payment['billing_year']); ?></span>
                    </div>
                    
                    <div class="info-item">
                        <span class="info-label">Old Bill</span>
                        <span class="amount-value">GHS <?php echo number_format($payment['old_bill'], 2); ?></span>
                    </div>
                    
                    <div class="info-item">
                        <span class="info-label">Arrears</span>
                        <span class="amount-value">GHS <?php echo number_format($payment['arrears'], 2); ?></span>
                    </div>
                    
                    <div class="info-item">
                        <span class="info-label">Current Bill</span>
                        <span class="amount-value">GHS <?php echo number_format($payment['current_bill'], 2); ?></span>
                    </div>
                    
                    <div class="info-item">
                        <span class="info-label">Previous Payments</span>
                        <span class="amount-value">GHS <?php echo number_format($payment['bill_previous_payments'], 2); ?></span>
                    </div>
                    
                    <div class="info-item">
                        <span class="info-label">Remaining Balance</span>
                        <span class="amount-value">GHS <?php echo number_format($payment['bill_amount_payable'], 2); ?></span>
                    </div>
                </div>
            </div>

            <!-- Notes -->
            <?php if (!empty($payment['notes'])): ?>
            <div class="info-section">
                <h4>
                    <i class="fas fa-sticky-note"></i>
                    Notes
                </h4>
                <p style="color: #2d3748; line-height: 1.6;"><?php echo nl2br(htmlspecialchars($payment['notes'])); ?></p>
            </div>
            <?php endif; ?>

            <!-- Action Buttons -->
            <div class="actions">
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i>
                    Back to Payments
                </a>
                
                <?php if ($payment['payment_status'] === 'Successful'): ?>
                    <a href="receipts.php?payment_id=<?php echo $payment['payment_id']; ?>" 
                       class="btn btn-success" target="_blank">
                        <i class="fas fa-receipt"></i>
                        Generate Receipt
                    </a>
                <?php endif; ?>
                
                <a href="record.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i>
                    Record New Payment
                </a>
            </div>
        </div>
    </div>
</body>
</html>
