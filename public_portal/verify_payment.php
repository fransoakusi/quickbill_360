 <?php
/**
 * Public Portal - Verify Payment for QUICKBILL 305
 * Allows users to verify payment status using payment reference
 */

// Define application constant
define('QUICKBILL_305', true);

// Include configuration files
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Start session for public portal
session_start();

// Initialize variables
$searchPerformed = false;
$paymentData = null;
$billData = null;
$paymentReference = '';
$errorMessage = '';
$assemblyName = getSystemSetting('assembly_name', 'Municipal Assembly');

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $errorMessage = 'Invalid security token. Please try again.';
    } else {
        $paymentReference = sanitizeInput($_POST['payment_reference'] ?? '');
        
        // Validate required fields
        if (empty($paymentReference)) {
            $errorMessage = 'Please enter a payment reference number.';
        } else {
            $searchPerformed = true;
            
            try {
                $db = new Database();
                
                // Search for payment
                $paymentQuery = "
                    SELECT 
                        p.payment_id,
                        p.payment_reference,
                        p.bill_id,
                        p.amount_paid,
                        p.payment_method,
                        p.payment_channel,
                        p.transaction_id,
                        p.paystack_reference,
                        p.payment_status,
                        p.payment_date,
                        p.processed_by,
                        p.notes,
                        p.receipt_url
                    FROM payments p
                    WHERE p.payment_reference = ?
                    ORDER BY p.payment_date DESC
                    LIMIT 1
                ";
                
                $paymentData = $db->fetchRow($paymentQuery, [$paymentReference]);
                
                if ($paymentData) {
                    // Get bill details
                    $billQuery = "
                        SELECT 
                            b.bill_id,
                            b.bill_number,
                            b.bill_type,
                            b.reference_id,
                            b.billing_year,
                            b.old_bill,
                            b.previous_payments,
                            b.arrears,
                            b.current_bill,
                            b.amount_payable,
                            b.status as bill_status,
                            b.generated_at,
                            CASE 
                                WHEN b.bill_type = 'Business' THEN bs.business_name
                                WHEN b.bill_type = 'Property' THEN p.owner_name
                            END as account_name,
                            CASE 
                                WHEN b.bill_type = 'Business' THEN bs.account_number
                                WHEN b.bill_type = 'Property' THEN p.property_number
                            END as account_number,
                            CASE 
                                WHEN b.bill_type = 'Business' THEN bs.owner_name
                                WHEN b.bill_type = 'Property' THEN p.owner_name
                            END as owner_name,
                            CASE 
                                WHEN b.bill_type = 'Business' THEN bs.telephone
                                WHEN b.bill_type = 'Property' THEN p.telephone
                            END as telephone
                        FROM bills b
                        LEFT JOIN businesses bs ON b.bill_type = 'Business' AND b.reference_id = bs.business_id
                        LEFT JOIN properties p ON b.bill_type = 'Property' AND b.reference_id = p.property_id
                        WHERE b.bill_id = ?
                    ";
                    
                    $billData = $db->fetchRow($billQuery, [$paymentData['bill_id']]);
                }
                
            } catch (Exception $e) {
                writeLog("Payment verification error: " . $e->getMessage(), 'ERROR');
                $errorMessage = 'An error occurred while searching for your payment. Please try again.';
            }
        }
    }
}

include 'header.php';
?>

<div class="verify-payment-page">
    <div class="container">
        <?php if (!$searchPerformed): ?>
        <!-- Search Form Section -->
        <div class="verify-section">
            <div class="verify-header">
                <h1>🔍 Verify Payment</h1>
                <p>Check the status of your payment using your payment reference number</p>
            </div>
            
            <div class="verify-form-container">
                <form action="verify_payment.php" method="POST" class="verify-form" id="verifyForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <?php if ($errorMessage): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span><?php echo htmlspecialchars($errorMessage); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label for="payment_reference">
                            <i class="fas fa-receipt"></i>
                            Payment Reference Number
                        </label>
                        <input 
                            type="text" 
                            id="payment_reference" 
                            name="payment_reference" 
                            value="<?php echo htmlspecialchars($paymentReference); ?>"
                            placeholder="e.g., PAY20250722ABCD123"
                            required
                            autocomplete="off"
                            class="form-control"
                        >
                        <small class="form-help">
                            <i class="fas fa-info-circle"></i>
                            Find your payment reference in your email confirmation or SMS
                        </small>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-search"></i>
                        Verify Payment
                    </button>
                </form>
                
                <div class="reference-tips">
                    <h4>💡 Where to Find Your Payment Reference</h4>
                    <ul>
                        <li><strong>Email:</strong> Check your email confirmation for the payment reference</li>
                        <li><strong>SMS:</strong> Look for the reference number in your SMS notification</li>
                        <li><strong>Receipt:</strong> The reference is shown on your payment receipt</li>
                        <li><strong>Format:</strong> Usually starts with "PAY" followed by date and code</li>
                    </ul>
                </div>
            </div>
        </div>
        
        <?php else: ?>
        <!-- Search Results Section -->
        <?php if ($paymentData): ?>
        <div class="verification-results">
            <!-- Payment Status Card -->
            <div class="payment-status-card">
                <div class="status-header">
                    <div class="status-icon <?php echo strtolower($paymentData['payment_status']); ?>">
                        <?php
                        switch (strtolower($paymentData['payment_status'])) {
                            case 'successful':
                                echo '✅';
                                break;
                            case 'pending':
                                echo '⏳';
                                break;
                            case 'failed':
                                echo '❌';
                                break;
                            case 'cancelled':
                                echo '🔄';
                                break;
                            default:
                                echo '❓';
                                break;
                        }
                        ?>
                    </div>
                    <div class="status-details">
                        <h2>Payment <?php echo htmlspecialchars($paymentData['payment_status']); ?></h2>
                        <p class="status-message">
                            <?php
                            switch (strtolower($paymentData['payment_status'])) {
                                case 'successful':
                                    echo 'Your payment was processed successfully';
                                    break;
                                case 'pending':
                                    echo 'Your payment is being processed';
                                    break;
                                case 'failed':
                                    echo 'Your payment could not be processed';
                                    break;
                                case 'cancelled':
                                    echo 'Your payment was cancelled';
                                    break;
                                default:
                                    echo 'Payment status unknown';
                                    break;
                            }
                            ?>
                        </p>
                    </div>
                    <div class="status-amount">
                        ₵ <?php echo number_format($paymentData['amount_paid'], 2); ?>
                    </div>
                </div>
                
                <?php if (strtolower($paymentData['payment_status']) === 'successful'): ?>
                <div class="success-message">
                    <i class="fas fa-check-circle"></i>
                    <span>Payment completed successfully. Thank you!</span>
                </div>
                <?php elseif (strtolower($paymentData['payment_status']) === 'pending'): ?>
                <div class="pending-message">
                    <i class="fas fa-clock"></i>
                    <span>Payment is being processed. Please wait for confirmation.</span>
                </div>
                <?php elseif (strtolower($paymentData['payment_status']) === 'failed'): ?>
                <div class="failed-message">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>Payment failed. No money was charged from your account.</span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Payment Details Card -->
            <div class="payment-details-card">
                <div class="details-header">
                    <h3>📋 Payment Details</h3>
                    <span class="reference-badge"><?php echo htmlspecialchars($paymentData['payment_reference']); ?></span>
                </div>
                
                <div class="details-content">
                    <div class="details-grid">
                        <div class="detail-section">
                            <h4>💳 Payment Information</h4>
                            <div class="detail-rows">
                                <div class="detail-row">
                                    <span class="detail-label">Reference Number:</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($paymentData['payment_reference']); ?></span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Amount Paid:</span>
                                    <span class="detail-value amount">₵ <?php echo number_format($paymentData['amount_paid'], 2); ?></span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Payment Method:</span>
                                    <span class="detail-value">
                                        <?php 
                                        $methodIcon = $paymentData['payment_method'] === 'Mobile Money' ? '📱' : '💳';
                                        echo $methodIcon . ' ' . htmlspecialchars($paymentData['payment_method']); 
                                        ?>
                                        <?php if (!empty($paymentData['payment_channel'])): ?>
                                            - <?php echo htmlspecialchars($paymentData['payment_channel']); ?>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Payment Date:</span>
                                    <span class="detail-value"><?php echo formatDateTime($paymentData['payment_date']); ?></span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Status:</span>
                                    <span class="detail-value">
                                        <span class="status-badge <?php echo strtolower($paymentData['payment_status']); ?>">
                                            <?php echo htmlspecialchars($paymentData['payment_status']); ?>
                                        </span>
                                    </span>
                                </div>
                                <?php if (!empty($paymentData['transaction_id'])): ?>
                                <div class="detail-row">
                                    <span class="detail-label">Transaction ID:</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($paymentData['transaction_id']); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($paymentData['notes'])): ?>
                                <div class="detail-row">
                                    <span class="detail-label">Notes:</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($paymentData['notes']); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Bill Information -->
                        <?php if ($billData): ?>
                        <div class="detail-section">
                            <h4>📄 Bill Information</h4>
                            <div class="detail-rows">
                                <div class="detail-row">
                                    <span class="detail-label">Bill Number:</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($billData['bill_number']); ?></span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Account Number:</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($billData['account_number']); ?></span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Account Name:</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($billData['account_name']); ?></span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Bill Type:</span>
                                    <span class="detail-value">
                                        <?php echo $billData['bill_type'] === 'Business' ? '🏢 Business Permit' : '🏠 Property Rates'; ?>
                                    </span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Billing Year:</span>
                                    <span class="detail-value"><?php echo $billData['billing_year']; ?></span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Current Balance:</span>
                                    <span class="detail-value amount">₵ <?php echo number_format($billData['amount_payable'], 2); ?></span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Bill Status:</span>
                                    <span class="detail-value">
                                        <span class="status-badge <?php echo strtolower($billData['bill_status']); ?>">
                                            <?php echo htmlspecialchars($billData['bill_status']); ?>
                                        </span>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="verification-actions">
                <?php if (strtolower($paymentData['payment_status']) === 'successful'): ?>
                <a href="payment_success.php?reference=<?php echo urlencode($paymentData['payment_reference']); ?>&bill_id=<?php echo $paymentData['bill_id']; ?>" class="btn btn-primary">
                    <i class="fas fa-receipt"></i>
                    View Receipt
                </a>
                
                <button onclick="downloadReceipt()" class="btn btn-outline">
                    <i class="fas fa-download"></i>
                    Download Receipt
                </button>
                
                <?php if ($billData && $billData['amount_payable'] > 0): ?>
                <a href="pay_bill.php?bill_id=<?php echo $billData['bill_id']; ?>" class="btn btn-secondary">
                    <i class="fas fa-credit-card"></i>
                    Pay Remaining Balance
                </a>
                <?php endif; ?>
                
                <?php elseif (strtolower($paymentData['payment_status']) === 'failed'): ?>
                <a href="pay_bill.php?bill_id=<?php echo $paymentData['bill_id']; ?>" class="btn btn-primary">
                    <i class="fas fa-redo"></i>
                    Try Payment Again
                </a>
                
                <a href="payment_failed.php?reference=<?php echo urlencode($paymentData['payment_reference']); ?>&bill_id=<?php echo $paymentData['bill_id']; ?>" class="btn btn-outline">
                    <i class="fas fa-info-circle"></i>
                    View Error Details
                </a>
                
                <?php elseif (strtolower($paymentData['payment_status']) === 'pending'): ?>
                <button onclick="refreshStatus()" class="btn btn-primary" id="refreshBtn">
                    <i class="fas fa-sync"></i>
                    Refresh Status
                </button>
                
                <a href="pay_bill.php?bill_id=<?php echo $paymentData['bill_id']; ?>" class="btn btn-outline">
                    <i class="fas fa-credit-card"></i>
                    Make New Payment
                </a>
                <?php endif; ?>
                
                <a href="verify_payment.php" class="btn btn-secondary">
                    <i class="fas fa-search"></i>
                    Verify Another Payment
                </a>
            </div>

            <!-- Payment Timeline -->
            <?php if ($billData): ?>
            <div class="payment-timeline-card">
                <div class="timeline-header">
                    <h3>📈 Payment History</h3>
                </div>
                
                <div class="timeline-content">
                    <?php
                    // Get all payments for this bill
                    try {
                        $allPayments = $db->fetchAll("
                            SELECT 
                                payment_reference,
                                amount_paid,
                                payment_method,
                                payment_status,
                                payment_date,
                                notes
                            FROM payments 
                            WHERE bill_id = ? 
                            ORDER BY payment_date DESC
                        ", [$billData['bill_id']]);
                    } catch (Exception $e) {
                        $allPayments = [$paymentData]; // Fallback to current payment
                    }
                    ?>
                    
                    <div class="timeline">
                        <?php foreach ($allPayments as $index => $payment): ?>
                        <div class="timeline-item <?php echo $payment['payment_reference'] === $paymentData['payment_reference'] ? 'current' : ''; ?>">
                            <div class="timeline-dot <?php echo strtolower($payment['payment_status']); ?>"></div>
                            <div class="timeline-content-item">
                                <div class="timeline-header">
                                    <strong>₵ <?php echo number_format($payment['amount_paid'], 2); ?></strong>
                                    <span class="timeline-status <?php echo strtolower($payment['payment_status']); ?>">
                                        <?php echo htmlspecialchars($payment['payment_status']); ?>
                                    </span>
                                </div>
                                <div class="timeline-details">
                                    <div class="timeline-method">
                                        <?php echo htmlspecialchars($payment['payment_method']); ?>
                                    </div>
                                    <div class="timeline-date">
                                        <?php echo formatDateTime($payment['payment_date']); ?>
                                    </div>
                                    <div class="timeline-reference">
                                        Ref: <?php echo htmlspecialchars($payment['payment_reference']); ?>
                                    </div>
                                    <?php if (!empty($payment['notes'])): ?>
                                    <div class="timeline-notes">
                                        <?php echo htmlspecialchars($payment['notes']); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <?php else: ?>
        <!-- No Payment Found -->
        <div class="no-payment-section">
            <div class="no-payment-content">
                <div class="no-payment-icon">🔍</div>
                <h2>Payment Not Found</h2>
                <p>We couldn't find a payment with the reference number you provided.</p>
                
                <div class="no-payment-details">
                    <div class="detail-item">
                        <strong>Reference Searched:</strong>
                        <span><?php echo htmlspecialchars($paymentReference); ?></span>
                    </div>
                </div>
                
                <div class="no-payment-suggestions">
                    <h4>💡 Please Check:</h4>
                    <ul>
                        <li>Ensure the payment reference is typed correctly</li>
                        <li>Check for any extra spaces or special characters</li>
                        <li>Verify the reference in your email or SMS confirmation</li>
                        <li>Payment references usually start with "PAY"</li>
                        <li>If payment was recent, wait a few minutes and try again</li>
                    </ul>
                </div>
                
                <div class="no-payment-actions">
                    <a href="verify_payment.php" class="btn btn-primary">
                        <i class="fas fa-redo"></i>
                        Try Again
                    </a>
                    
                    <a href="search_bill.php" class="btn btn-outline">
                        <i class="fas fa-search"></i>
                        Find My Bill
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<style>
/* Verify Payment Page Styles */
.verify-payment-page {
    padding: 40px 0;
    min-height: 700px;
}

.container {
    max-width: 900px;
    margin: 0 auto;
    padding: 0 20px;
}

/* Verify Section */
.verify-section {
    max-width: 600px;
    margin: 0 auto;
}

.verify-header {
    text-align: center;
    margin-bottom: 40px;
}

.verify-header h1 {
    font-size: 2.5rem;
    font-weight: bold;
    color: #2d3748;
    margin-bottom: 10px;
}

.verify-header p {
    color: #718096;
    font-size: 1.1rem;
}

.verify-form-container {
    background: white;
    border-radius: 15px;
    padding: 40px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
    margin-bottom: 30px;
}

.verify-form {
    margin-bottom: 30px;
}

.form-group {
    margin-bottom: 25px;
}

.form-group label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    margin-bottom: 8px;
    color: #2d3748;
}

.form-control {
    width: 100%;
    padding: 15px;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    font-size: 16px;
    transition: all 0.3s;
    font-family: monospace;
}

.form-control:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.form-help {
    display: flex;
    align-items: center;
    gap: 5px;
    margin-top: 8px;
    font-size: 0.9rem;
    color: #718096;
}

.alert {
    padding: 15px 20px;
    border-radius: 10px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.alert-error {
    background: #fed7d7;
    color: #c53030;
    border: 1px solid #feb2b2;
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px 24px;
    border: none;
    border-radius: 10px;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.3s;
    font-size: 1rem;
}

.btn-primary {
    background: #667eea;
    color: white;
}

.btn-primary:hover {
    background: #5a67d8;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
    color: white;
    text-decoration: none;
}

.btn-outline {
    background: transparent;
    color: #667eea;
    border: 2px solid #667eea;
}

.btn-outline:hover {
    background: #667eea;
    color: white;
    text-decoration: none;
}

.btn-secondary {
    background: #4a5568;
    color: white;
}

.btn-secondary:hover {
    background: #2d3748;
    color: white;
    text-decoration: none;
}

.btn-lg {
    width: 100%;
    padding: 15px 24px;
    font-size: 1.1rem;
}

.reference-tips {
    background: #f0f9ff;
    border: 1px solid #bae6fd;
    border-radius: 10px;
    padding: 20px;
}

.reference-tips h4 {
    color: #0369a1;
    margin-bottom: 15px;
}

.reference-tips ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

.reference-tips li {
    margin-bottom: 10px;
    color: #0369a1;
    font-size: 0.9rem;
    padding-left: 15px;
    position: relative;
}

.reference-tips li:before {
    content: "•";
    position: absolute;
    left: 0;
    color: #0369a1;
    font-weight: bold;
}

/* Payment Status Card */
.payment-status-card {
    background: white;
    border-radius: 15px;
    padding: 30px;
    margin-bottom: 25px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
    border: 1px solid #e2e8f0;
}

.status-header {
    display: flex;
    align-items: center;
    gap: 20px;
    margin-bottom: 20px;
}

.status-icon {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.5rem;
    flex-shrink: 0;
}

.status-icon.successful {
    background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
    color: white;
}

.status-icon.pending {
    background: linear-gradient(135deg, #ed8936 0%, #dd6b20 100%);
    color: white;
}

.status-icon.failed {
    background: linear-gradient(135deg, #f56565 0%, #e53e3e 100%);
    color: white;
}

.status-icon.cancelled {
    background: linear-gradient(135deg, #4a5568 0%, #2d3748 100%);
    color: white;
}

.status-details {
    flex: 1;
}

.status-details h2 {
    color: #2d3748;
    margin-bottom: 8px;
    font-size: 1.8rem;
}

.status-message {
    color: #4a5568;
    font-size: 1.1rem;
    margin: 0;
}

.status-amount {
    font-size: 2.5rem;
    font-weight: bold;
    color: #667eea;
    text-align: right;
}

.success-message,
.pending-message,
.failed-message {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 15px;
    border-radius: 8px;
    font-weight: 500;
}

.success-message {
    background: #f0fff4;
    color: #22543d;
    border: 1px solid #9ae6b4;
}

.pending-message {
    background: #fffaf0;
    color: #c05621;
    border: 1px solid #fbd38d;
}

.failed-message {
    background: #fff5f5;
    color: #c53030;
    border: 1px solid #fed7d7;
}

/* Payment Details Card */
.payment-details-card {
    background: white;
    border-radius: 15px;
    padding: 30px;
    margin-bottom: 25px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
    border: 1px solid #e2e8f0;
}

.details-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
    padding-bottom: 15px;
    border-bottom: 1px solid #e2e8f0;
}

.details-header h3 {
    color: #2d3748;
    margin: 0;
    font-size: 1.3rem;
}

.reference-badge {
    background: #667eea;
    color: white;
    padding: 8px 12px;
    border-radius: 20px;
    font-size: 0.9rem;
    font-weight: 600;
    font-family: monospace;
}

.details-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 30px;
}

.detail-section {
    background: #f7fafc;
    padding: 25px;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
}

.detail-section h4 {
    color: #2d3748;
    margin-bottom: 20px;
    font-size: 1.1rem;
    display: flex;
    align-items: center;
    gap: 8px;
}

.detail-rows {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.detail-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid #e2e8f0;
}

.detail-row:last-child {
    border-bottom: none;
}

.detail-label {
    color: #4a5568;
    font-weight: 500;
    flex: 0 0 45%;
}

.detail-value {
    color: #2d3748;
    font-weight: 600;
    text-align: right;
    flex: 1;
}

.detail-value.amount {
    color: #d69e2e;
    font-size: 1.1rem;
}

.status-badge {
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.status-badge.successful {
    background: #c6f6d5;
    color: #22543d;
}

.status-badge.pending {
    background: #feebc8;
    color: #c05621;
}

.status-badge.failed {
    background: #fed7d7;
    color: #c53030;
}

.status-badge.paid {
    background: #c6f6d5;
    color: #22543d;
}

.status-badge.partially {
    background: #feebc8;
    color: #c05621;
}

/* Verification Actions */
.verification-actions {
    display: flex;
    gap: 15px;
    justify-content: center;
    margin-bottom: 30px;
    flex-wrap: wrap;
}

/* Payment Timeline */
.payment-timeline-card {
    background: white;
    border-radius: 15px;
    padding: 30px;
    margin-bottom: 25px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
    border: 1px solid #e2e8f0;
}

.timeline-header {
    margin-bottom: 25px;
    padding-bottom: 15px;
    border-bottom: 1px solid #e2e8f0;
}

.timeline-header h3 {
    color: #2d3748;
    margin: 0;
    font-size: 1.3rem;
}

.timeline {
    position: relative;
    padding-left: 30px;
}

.timeline-item {
    position: relative;
    margin-bottom: 25px;
    padding-bottom: 20px;
}

.timeline-item:not(:last-child):before {
    content: '';
    position: absolute;
    left: -23px;
    top: 30px;
    bottom: -5px;
    width: 2px;
    background: #e2e8f0;
}

.timeline-item.current {
    background: #f0f9ff;
    padding: 15px;
    border-radius: 10px;
    border: 2px solid #bae6fd;
}

.timeline-dot {
    position: absolute;
    left: -30px;
    top: 5px;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    border: 3px solid white;
    box-shadow: 0 0 0 2px #e2e8f0;
}

.timeline-dot.successful {
    background: #48bb78;
    box-shadow: 0 0 0 2px #48bb78;
}

.timeline-dot.pending {
    background: #ed8936;
    box-shadow: 0 0 0 2px #ed8936;
}

.timeline-dot.failed {
    background: #f56565;
    box-shadow: 0 0 0 2px #f56565;
}

.timeline-content-item {
    margin-left: 10px;
}

.timeline-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.timeline-status {
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
}

.timeline-status.successful {
    background: #c6f6d5;
    color: #22543d;
}

.timeline-status.pending {
    background: #feebc8;
    color: #c05621;
}

.timeline-status.failed {
    background: #fed7d7;
    color: #c53030;
}

.timeline-details {
    color: #4a5568;
    font-size: 0.9rem;
}

.timeline-method,
.timeline-date,
.timeline-reference,
.timeline-notes {
    margin-bottom: 3px;
}

.timeline-reference {
    font-family: monospace;
    font-size: 0.8rem;
    color: #667eea;
}

.timeline-notes {
    font-style: italic;
    color: #718096;
}

/* No Payment Section */
.no-payment-section {
    text-align: center;
    padding: 60px 20px;
    animation: slideUp 0.6s ease;
}

.no-payment-content {
    max-width: 500px;
    margin: 0 auto;
    background: white;
    padding: 40px;
    border-radius: 15px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
}

.no-payment-icon {
    font-size: 4rem;
    margin-bottom: 20px;
}

.no-payment-content h2 {
    color: #2d3748;
    margin-bottom: 15px;
}

.no-payment-content p {
    color: #4a5568;
    margin-bottom: 25px;
}

.no-payment-details {
    background: #f7fafc;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 25px;
    border: 1px solid #e2e8f0;
}

.detail-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.detail-item span {
    font-family: monospace;
    color: #667eea;
    font-weight: bold;
}

.no-payment-suggestions {
    text-align: left;
    margin-bottom: 30px;
    background: #f0f9ff;
    padding: 20px;
    border-radius: 10px;
    border: 1px solid #bae6fd;
}

.no-payment-suggestions h4 {
    color: #0369a1;
    margin-bottom: 15px;
}

.no-payment-suggestions ul {
    color: #0369a1;
    line-height: 1.6;
    margin: 0;
}

.no-payment-suggestions li {
    margin-bottom: 8px;
}

.no-payment-actions {
    display: flex;
    gap: 15px;
    justify-content: center;
}

/* Animations */
@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Responsive Design */
@media (max-width: 768px) {
    .details-grid {
        grid-template-columns: 1fr;
    }
    
    .detail-row {
        flex-direction: column;
        align-items: flex-start;
        gap: 5px;
    }
    
    .detail-value {
        text-align: left;
    }
    
    .status-header {
        flex-direction: column;
        text-align: center;
        gap: 15px;
    }
    
    .status-amount {
        text-align: center;
    }
    
    .details-header {
        flex-direction: column;
        gap: 15px;
        text-align: center;
    }
    
    .verification-actions,
    .no-payment-actions {
        flex-direction: column;
    }
    
    .timeline {
        padding-left: 20px;
    }
    
    .timeline-dot {
        left: -20px;
    }
    
    .timeline-item:not(:last-child):before {
        left: -13px;
    }
}

@media (max-width: 480px) {
    .container {
        padding: 0 15px;
    }
    
    .verify-form-container,
    .payment-status-card,
    .payment-details-card,
    .payment-timeline-card {
        padding: 20px;
    }
    
    .verify-header h1 {
        font-size: 2rem;
    }
    
    .status-amount {
        font-size: 2rem;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('verifyForm');
    const paymentInput = document.getElementById('payment_reference');
    
    // Auto-uppercase and format payment reference
    if (paymentInput) {
        paymentInput.addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });
        
        // Auto-focus on input
        if (!paymentInput.value) {
            paymentInput.focus();
        }
    }
    
    // Form submission with loading state
    if (form) {
        form.addEventListener('submit', function(e) {
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';
            submitBtn.disabled = true;
            
            // Show loading overlay
            showLoading('Verifying your payment...');
            
            // Re-enable button after 10 seconds (in case of issues)
            setTimeout(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
                hideLoading();
            }, 10000);
        });
    }
    
    // Add copy functionality for reference numbers
    document.querySelectorAll('.detail-value, .reference-badge, .timeline-reference').forEach(element => {
        if (element.textContent.includes('PAY')) {
            element.style.cursor = 'pointer';
            element.title = 'Click to copy';
            
            element.addEventListener('click', function() {
                const text = this.textContent.replace('Ref: ', '').trim();
                navigator.clipboard.writeText(text).then(function() {
                    const originalText = element.textContent;
                    element.textContent = 'Copied!';
                    element.style.color = '#48bb78';
                    
                    setTimeout(() => {
                        element.textContent = originalText;
                        element.style.color = '';
                    }, 1500);
                });
            });
        }
    });
    
    // Auto-refresh for pending payments
    <?php if ($paymentData && strtolower($paymentData['payment_status']) === 'pending'): ?>
    let refreshCount = 0;
    const maxRefreshes = 10;
    
    const autoRefresh = setInterval(() => {
        refreshCount++;
        if (refreshCount >= maxRefreshes) {
            clearInterval(autoRefresh);
            return;
        }
        
        // Auto-refresh every 30 seconds for pending payments
        setTimeout(() => {
            window.location.reload();
        }, 30000);
    }, 30000);
    <?php endif; ?>
});

// Refresh payment status
function refreshStatus() {
    const btn = document.getElementById('refreshBtn');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking...';
    btn.disabled = true;
    
    setTimeout(() => {
        window.location.reload();
    }, 2000);
}

// Download receipt
function downloadReceipt() {
    const reference = '<?php echo addslashes($paymentData['payment_reference'] ?? ''); ?>';
    const billId = <?php echo $paymentData['bill_id'] ?? 'null'; ?>;
    
    if (reference && billId) {
        window.open(`download_receipt.php?reference=${encodeURIComponent(reference)}&bill_id=${billId}`, '_blank');
    }
}
</script>

<?php include 'footer.php'; ?>
