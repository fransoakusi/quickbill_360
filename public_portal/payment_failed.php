 <?php
/**
 * Public Portal - Payment Failed for QUICKBILL 305
 * Displays payment failure information and retry options
 */

// Define application constant
define('QUICKBILL_305', true);

// Include configuration files
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Start session for public portal
session_start();

// Get parameters from URL
$errorMessage = isset($_GET['error']) ? sanitizeInput($_GET['error']) : 'Payment processing failed';
$billId = isset($_GET['bill_id']) ? (int)$_GET['bill_id'] : 0;
$paymentReference = isset($_GET['reference']) ? sanitizeInput($_GET['reference']) : '';

// Initialize variables
$billData = null;
$assemblyName = getSystemSetting('assembly_name', 'Municipal Assembly');

// Get bill details if bill ID is provided
if ($billId > 0) {
    try {
        $db = new Database();
        
        $billQuery = "
            SELECT 
                b.bill_id,
                b.bill_number,
                b.bill_type,
                b.billing_year,
                b.amount_payable,
                CASE 
                    WHEN b.bill_type = 'Business' THEN bs.business_name
                    WHEN b.bill_type = 'Property' THEN p.owner_name
                END as account_name,
                CASE 
                    WHEN b.bill_type = 'Business' THEN bs.account_number
                    WHEN b.bill_type = 'Property' THEN p.property_number
                END as account_number
            FROM bills b
            LEFT JOIN businesses bs ON b.bill_type = 'Business' AND b.reference_id = bs.business_id
            LEFT JOIN properties p ON b.bill_type = 'Property' AND b.reference_id = p.property_id
            WHERE b.bill_id = ?
        ";
        
        $billData = $db->fetchRow($billQuery, [$billId]);
        
    } catch (Exception $e) {
        writeLog("Payment failed page error: " . $e->getMessage(), 'ERROR');
    }
}

// Determine error type and message
$errorType = 'general';
$errorTitle = 'Payment Failed';
$errorIcon = '❌';

// Categorize common error types
if (stripos($errorMessage, 'insufficient') !== false) {
    $errorType = 'insufficient_funds';
    $errorTitle = 'Insufficient Funds';
    $errorIcon = '💳';
} elseif (stripos($errorMessage, 'declined') !== false || stripos($errorMessage, 'rejected') !== false) {
    $errorType = 'declined';
    $errorTitle = 'Payment Declined';
    $errorIcon = '🚫';
} elseif (stripos($errorMessage, 'expired') !== false) {
    $errorType = 'expired';
    $errorTitle = 'Card Expired';
    $errorIcon = '⏰';
} elseif (stripos($errorMessage, 'network') !== false || stripos($errorMessage, 'connection') !== false) {
    $errorType = 'network';
    $errorTitle = 'Network Error';
    $errorIcon = '📶';
} elseif (stripos($errorMessage, 'cancelled') !== false || stripos($errorMessage, 'canceled') !== false) {
    $errorType = 'cancelled';
    $errorTitle = 'Payment Cancelled';
    $errorIcon = '🔄';
}

include 'header.php';
?>

<div class="payment-failed-page">
    <div class="container">
        <!-- Failed Header -->
        <div class="failed-header">
            <div class="failed-animation">
                <div class="error-circle">
                    <div class="error-icon"><?php echo $errorIcon; ?></div>
                </div>
            </div>
            
            <h1><?php echo htmlspecialchars($errorTitle); ?></h1>
            <p>Don't worry, no money was charged from your account</p>
        </div>

        <!-- Error Details Card -->
        <div class="error-details-card">
            <div class="error-header">
                <h3>🔍 What Happened?</h3>
            </div>
            
            <div class="error-content">
                <div class="error-message">
                    <div class="message-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="message-content">
                        <h4>Error Details</h4>
                        <p><?php echo htmlspecialchars($errorMessage); ?></p>
                        
                        <?php if ($paymentReference): ?>
                        <div class="reference-info">
                            <span class="reference-label">Reference:</span>
                            <span class="reference-value"><?php echo htmlspecialchars($paymentReference); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Bill Information -->
                <?php if ($billData): ?>
                <div class="bill-info">
                    <h5>📄 Bill Information</h5>
                    <div class="bill-details">
                        <div class="bill-row">
                            <span>Bill Number:</span>
                            <span><?php echo htmlspecialchars($billData['bill_number']); ?></span>
                        </div>
                        <div class="bill-row">
                            <span>Account:</span>
                            <span><?php echo htmlspecialchars($billData['account_number']); ?></span>
                        </div>
                        <div class="bill-row">
                            <span>Amount:</span>
                            <span>₵ <?php echo number_format($billData['amount_payable'], 2); ?></span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Solutions Card -->
        <div class="solutions-card">
            <div class="solutions-header">
                <h3>💡 How to Fix This</h3>
            </div>
            
            <div class="solutions-content">
                <?php
                // Provide specific solutions based on error type
                switch ($errorType) {
                    case 'insufficient_funds':
                        ?>
                        <div class="solution-item">
                            <div class="solution-icon">💰</div>
                            <div class="solution-content">
                                <h4>Check Your Balance</h4>
                                <p>Ensure you have sufficient funds in your account or mobile money wallet</p>
                            </div>
                        </div>
                        <div class="solution-item">
                            <div class="solution-icon">💳</div>
                            <div class="solution-content">
                                <h4>Try a Different Payment Method</h4>
                                <p>Use another card or switch to mobile money if available</p>
                            </div>
                        </div>
                        <div class="solution-item">
                            <div class="solution-icon">💵</div>
                            <div class="solution-content">
                                <h4>Make a Partial Payment</h4>
                                <p>Pay a smaller amount now and the balance later</p>
                            </div>
                        </div>
                        <?php
                        break;
                        
                    case 'declined':
                        ?>
                        <div class="solution-item">
                            <div class="solution-icon">📞</div>
                            <div class="solution-content">
                                <h4>Contact Your Bank</h4>
                                <p>Your bank may have blocked the transaction for security reasons</p>
                            </div>
                        </div>
                        <div class="solution-item">
                            <div class="solution-icon">🔍</div>
                            <div class="solution-content">
                                <h4>Check Card Details</h4>
                                <p>Verify your card number, expiry date, and CVV are correct</p>
                            </div>
                        </div>
                        <div class="solution-item">
                            <div class="solution-icon">📱</div>
                            <div class="solution-content">
                                <h4>Try Mobile Money</h4>
                                <p>Use MTN, Telecel, or AirtelTigo mobile money instead</p>
                            </div>
                        </div>
                        <?php
                        break;
                        
                    case 'expired':
                        ?>
                        <div class="solution-item">
                            <div class="solution-icon">💳</div>
                            <div class="solution-content">
                                <h4>Use a Valid Card</h4>
                                <p>Your card has expired. Please use a different card</p>
                            </div>
                        </div>
                        <div class="solution-item">
                            <div class="solution-icon">📱</div>
                            <div class="solution-content">
                                <h4>Try Mobile Money</h4>
                                <p>Mobile money is always available and doesn't expire</p>
                            </div>
                        </div>
                        <?php
                        break;
                        
                    case 'network':
                        ?>
                        <div class="solution-item">
                            <div class="solution-icon">📶</div>
                            <div class="solution-content">
                                <h4>Check Your Connection</h4>
                                <p>Ensure you have a stable internet connection</p>
                            </div>
                        </div>
                        <div class="solution-item">
                            <div class="solution-icon">🔄</div>
                            <div class="solution-content">
                                <h4>Try Again</h4>
                                <p>Wait a moment and retry your payment</p>
                            </div>
                        </div>
                        <?php
                        break;
                        
                    case 'cancelled':
                        ?>
                        <div class="solution-item">
                            <div class="solution-icon">🔄</div>
                            <div class="solution-content">
                                <h4>Start Over</h4>
                                <p>You cancelled the payment. You can try again whenever you're ready</p>
                            </div>
                        </div>
                        <?php
                        break;
                        
                    default:
                        ?>
                        <div class="solution-item">
                            <div class="solution-icon">🔄</div>
                            <div class="solution-content">
                                <h4>Try Again</h4>
                                <p>Wait a few minutes and attempt the payment again</p>
                            </div>
                        </div>
                        <div class="solution-item">
                            <div class="solution-icon">💳</div>
                            <div class="solution-content">
                                <h4>Use a Different Method</h4>
                                <p>Try a different payment method (card or mobile money)</p>
                            </div>
                        </div>
                        <div class="solution-item">
                            <div class="solution-icon">📞</div>
                            <div class="solution-content">
                                <h4>Contact Support</h4>
                                <p>If the problem persists, contact our support team</p>
                            </div>
                        </div>
                        <?php
                        break;
                }
                ?>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="failed-actions">
            <?php if ($billData): ?>
            <a href="pay_bill.php?bill_id=<?php echo $billData['bill_id']; ?>" class="btn btn-primary btn-lg">
                <i class="fas fa-redo"></i>
                Try Payment Again
            </a>
            
            <a href="view_bill.php?bill_id=<?php echo $billData['bill_id']; ?>" class="btn btn-outline">
                <i class="fas fa-eye"></i>
                View Bill Details
            </a>
            <?php else: ?>
            <a href="search_bill.php" class="btn btn-primary btn-lg">
                <i class="fas fa-search"></i>
                Find My Bill
            </a>
            <?php endif; ?>
            
            <a href="verify_payment.php" class="btn btn-secondary">
                <i class="fas fa-check-circle"></i>
                Verify Payment Status
            </a>
        </div>

        <!-- Payment Tips -->
        <div class="payment-tips">
            <h3>💡 Payment Tips</h3>
            <div class="tips-grid">
                <div class="tip-card">
                    <div class="tip-icon">🔒</div>
                    <h4>Secure Connection</h4>
                    <p>Always ensure you're on a secure internet connection when making payments</p>
                </div>
                
                <div class="tip-card">
                    <div class="tip-icon">📱</div>
                    <h4>Mobile Money</h4>
                    <p>Mobile money is often more reliable than cards for local payments</p>
                </div>
                
                <div class="tip-card">
                    <div class="tip-icon">💰</div>
                    <h4>Check Balance</h4>
                    <p>Always verify your account balance before attempting payment</p>
                </div>
                
                <div class="tip-card">
                    <div class="tip-icon">⏰</div>
                    <h4>Best Times</h4>
                    <p>Payments are usually more successful during business hours (8AM - 6PM)</p>
                </div>
            </div>
        </div>

        <!-- Support Section -->
        <div class="support-section">
            <div class="support-content">
                <h3>🤝 Need Help?</h3>
                <p>Our support team is here to help you complete your payment</p>
                
                <div class="support-options">
                    <div class="support-option">
                        <div class="support-icon">📞</div>
                        <div class="support-details">
                            <h4>Call Us</h4>
                            <p>+233 123 456 789</p>
                            <small>Monday - Friday: 8:00 AM - 5:00 PM</small>
                        </div>
                        <a href="tel:+233123456789" class="btn btn-outline btn-sm">Call Now</a>
                    </div>
                    
                    <div class="support-option">
                        <div class="support-icon">📧</div>
                        <div class="support-details">
                            <h4>Email Support</h4>
                            <p>support@<?php echo strtolower(str_replace(' ', '', $assemblyName)); ?>.gov.gh</p>
                            <small>Response within 24 hours</small>
                        </div>
                        <a href="mailto:support@<?php echo strtolower(str_replace(' ', '', $assemblyName)); ?>.gov.gh" class="btn btn-outline btn-sm">Send Email</a>
                    </div>
                    
                    <div class="support-option">
                        <div class="support-icon">🏢</div>
                        <div class="support-details">
                            <h4>Visit Office</h4>
                            <p><?php echo htmlspecialchars($assemblyName); ?></p>
                            <small>Monday - Friday: 8:00 AM - 5:00 PM</small>
                        </div>
                        <button onclick="showDirections()" class="btn btn-outline btn-sm">Get Directions</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Navigation Links -->
        <div class="navigation-links">
            <a href="index.php" class="nav-link">
                <i class="fas fa-home"></i>
                <span>Back to Home</span>
            </a>
            
            <a href="search_bill.php" class="nav-link">
                <i class="fas fa-search"></i>
                <span>Search Bills</span>
            </a>
            
            <a href="verify_payment.php" class="nav-link">
                <i class="fas fa-check-circle"></i>
                <span>Verify Payments</span>
            </a>
        </div>
    </div>
</div>

<style>
/* Payment Failed Page Styles */
.payment-failed-page {
    padding: 40px 0;
    min-height: 700px;
    background: linear-gradient(135deg, #fff5f5 0%, #f7fafc 100%);
}

.container {
    max-width: 800px;
    margin: 0 auto;
    padding: 0 20px;
}

/* Failed Header */
.failed-header {
    text-align: center;
    margin-bottom: 40px;
}

.failed-animation {
    margin-bottom: 30px;
}

.error-circle {
    width: 120px;
    height: 120px;
    background: linear-gradient(135deg, #f56565 0%, #e53e3e 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto;
    animation: shake 0.8s ease;
    box-shadow: 0 10px 30px rgba(245, 101, 101, 0.3);
}

.error-circle .error-icon {
    color: white;
    font-size: 3rem;
    animation: bounceIn 0.5s ease 0.3s both;
}

.failed-header h1 {
    color: #c53030;
    margin-bottom: 10px;
    font-size: 2.5rem;
}

.failed-header p {
    color: #e53e3e;
    font-size: 1.1rem;
    margin-bottom: 0;
}

/* Error Details Card */
.error-details-card {
    background: white;
    border-radius: 15px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
    overflow: hidden;
    margin-bottom: 30px;
    border: 1px solid #e2e8f0;
    border-left: 5px solid #f56565;
}

.error-header {
    background: #fff5f5;
    padding: 20px 25px;
    border-bottom: 1px solid #fed7d7;
}

.error-header h3 {
    color: #c53030;
    margin: 0;
    font-size: 1.2rem;
}

.error-content {
    padding: 25px;
}

.error-message {
    display: flex;
    gap: 15px;
    margin-bottom: 25px;
    padding: 20px;
    background: #fef5e7;
    border-radius: 10px;
    border: 1px solid #fed7a1;
}

.message-icon {
    flex-shrink: 0;
}

.message-icon i {
    color: #d69e2e;
    font-size: 1.5rem;
}

.message-content h4 {
    color: #c05621;
    margin-bottom: 10px;
    font-size: 1.1rem;
}

.message-content p {
    color: #c05621;
    margin-bottom: 10px;
    line-height: 1.5;
}

.reference-info {
    display: flex;
    gap: 10px;
    margin-top: 15px;
    padding: 10px;
    background: white;
    border-radius: 6px;
    border: 1px solid #fbb6ce;
}

.reference-label {
    color: #97266d;
    font-weight: 600;
}

.reference-value {
    color: #97266d;
    font-family: monospace;
    font-weight: bold;
}

.bill-info {
    background: #f7fafc;
    padding: 20px;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
}

.bill-info h5 {
    color: #2d3748;
    margin-bottom: 15px;
    font-size: 1rem;
}

.bill-details {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.bill-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 6px 0;
    border-bottom: 1px solid #e2e8f0;
}

.bill-row:last-child {
    border-bottom: none;
}

.bill-row span:first-child {
    color: #4a5568;
    font-weight: 500;
}

.bill-row span:last-child {
    color: #2d3748;
    font-weight: 600;
}

/* Solutions Card */
.solutions-card {
    background: white;
    border-radius: 15px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
    overflow: hidden;
    margin-bottom: 30px;
    border: 1px solid #e2e8f0;
    border-left: 5px solid #48bb78;
}

.solutions-header {
    background: #f0fff4;
    padding: 20px 25px;
    border-bottom: 1px solid #9ae6b4;
}

.solutions-header h3 {
    color: #22543d;
    margin: 0;
    font-size: 1.2rem;
}

.solutions-content {
    padding: 25px;
}

.solution-item {
    display: flex;
    gap: 15px;
    margin-bottom: 20px;
    padding: 15px;
    background: #f7fafc;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
    transition: all 0.3s;
}

.solution-item:hover {
    background: #edf2f7;
    border-color: #cbd5e0;
}

.solution-item:last-child {
    margin-bottom: 0;
}

.solution-icon {
    font-size: 1.8rem;
    flex-shrink: 0;
    margin-top: 5px;
}

.solution-content h4 {
    color: #2d3748;
    margin-bottom: 8px;
    font-size: 1rem;
}

.solution-content p {
    color: #4a5568;
    margin: 0;
    line-height: 1.5;
}

/* Action Buttons */
.failed-actions {
    display: flex;
    gap: 15px;
    justify-content: center;
    margin-bottom: 40px;
    flex-wrap: wrap;
}

/* Payment Tips */
.payment-tips {
    background: white;
    border-radius: 15px;
    padding: 30px;
    margin-bottom: 30px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    border: 1px solid #e2e8f0;
}

.payment-tips h3 {
    color: #2d3748;
    margin-bottom: 25px;
    text-align: center;
    font-size: 1.3rem;
}

.tips-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 20px;
}

.tip-card {
    background: #f7fafc;
    padding: 20px;
    border-radius: 10px;
    text-align: center;
    border: 1px solid #e2e8f0;
    transition: all 0.3s;
}

.tip-card:hover {
    background: #edf2f7;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
}

.tip-icon {
    font-size: 2.5rem;
    margin-bottom: 15px;
}

.tip-card h4 {
    color: #2d3748;
    margin-bottom: 10px;
    font-size: 1rem;
}

.tip-card p {
    color: #4a5568;
    font-size: 0.9rem;
    line-height: 1.4;
}

/* Support Section */
.support-section {
    background: white;
    border-radius: 15px;
    padding: 30px;
    margin-bottom: 30px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    border: 1px solid #e2e8f0;
}

.support-content h3 {
    color: #2d3748;
    margin-bottom: 15px;
    text-align: center;
    font-size: 1.3rem;
}

.support-content > p {
    color: #4a5568;
    text-align: center;
    margin-bottom: 25px;
}

.support-options {
    display: grid;
    gap: 20px;
}

.support-option {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 20px;
    background: #f7fafc;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
    transition: all 0.3s;
}

.support-option:hover {
    background: #edf2f7;
    border-color: #cbd5e0;
}

.support-icon {
    font-size: 2rem;
    flex-shrink: 0;
}

.support-details {
    flex: 1;
}

.support-details h4 {
    color: #2d3748;
    margin-bottom: 5px;
    font-size: 1rem;
}

.support-details p {
    color: #667eea;
    margin-bottom: 3px;
    font-weight: 600;
}

.support-details small {
    color: #718096;
}

/* Navigation Links */
.navigation-links {
    display: flex;
    justify-content: center;
    gap: 30px;
    margin-top: 40px;
    flex-wrap: wrap;
}

.nav-link {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    color: #4a5568;
    text-decoration: none;
    padding: 20px;
    border-radius: 10px;
    transition: all 0.3s;
    min-width: 120px;
}

.nav-link:hover {
    color: #667eea;
    background: #f0f9ff;
    transform: translateY(-2px);
    text-decoration: none;
}

.nav-link i {
    font-size: 1.5rem;
}

.nav-link span {
    font-weight: 500;
    font-size: 0.9rem;
}

/* Button Styles */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
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

.btn-sm {
    padding: 8px 16px;
    font-size: 0.9rem;
}

.btn-lg {
    padding: 15px 30px;
    font-size: 1.1rem;
}

/* Animations */
@keyframes shake {
    0%, 100% { transform: translateX(0); }
    10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
    20%, 40%, 60%, 80% { transform: translateX(5px); }
}

@keyframes bounceIn {
    0% {
        transform: scale(0);
        opacity: 0;
    }
    50% {
        transform: scale(1.2);
        opacity: 1;
    }
    100% {
        transform: scale(1);
        opacity: 1;
    }
}

/* Responsive Design */
@media (max-width: 768px) {
    .failed-actions {
        flex-direction: column;
    }
    
    .tips-grid {
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    }
    
    .support-option {
        flex-direction: column;
        text-align: center;
    }
    
    .bill-row {
        flex-direction: column;
        align-items: flex-start;
        gap: 5px;
    }
    
    .navigation-links {
        gap: 15px;
    }
    
    .failed-header h1 {
        font-size: 2rem;
    }
}

@media (max-width: 480px) {
    .container {
        padding: 0 15px;
    }
    
    .error-details-card,
    .solutions-card,
    .payment-tips,
    .support-section {
        padding: 20px;
    }
    
    .error-circle {
        width: 80px;
        height: 80px;
    }
    
    .error-circle .error-icon {
        font-size: 2rem;
    }
    
    .failed-header h1 {
        font-size: 1.8rem;
    }
    
    .nav-link {
        min-width: 100px;
        padding: 15px;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Add entrance animations
    const cards = document.querySelectorAll('.error-details-card, .solutions-card, .payment-tips, .support-section');
    cards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        
        setTimeout(() => {
            card.style.transition = 'all 0.6s ease';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, 200 * (index + 1));
    });
    
    // Add click tracking for solutions
    document.querySelectorAll('.solution-item').forEach(item => {
        item.addEventListener('click', function() {
            this.style.background = '#e6fffa';
            this.style.borderColor = '#4fd1c7';
            
            setTimeout(() => {
                this.style.background = '#f7fafc';
                this.style.borderColor = '#e2e8f0';
            }, 1000);
        });
    });
    
    // Copy reference to clipboard when clicked
    const referenceValue = document.querySelector('.reference-value');
    if (referenceValue) {
        referenceValue.style.cursor = 'pointer';
        referenceValue.title = 'Click to copy reference';
        
        referenceValue.addEventListener('click', function() {
            navigator.clipboard.writeText(this.textContent).then(function() {
                const originalText = referenceValue.textContent;
                referenceValue.textContent = 'Copied!';
                referenceValue.style.color = '#48bb78';
                
                setTimeout(() => {
                    referenceValue.textContent = originalText;
                    referenceValue.style.color = '#97266d';
                }, 1500);
            });
        });
    }
});

// Show directions to office
function showDirections() {
    const assemblyName = '<?php echo addslashes($assemblyName); ?>';
    const googleMapsUrl = `https://www.google.com/maps/search/${encodeURIComponent(assemblyName + ' Ghana')}`;
    window.open(googleMapsUrl, '_blank');
}

// Auto-retry functionality for network errors
<?php if ($errorType === 'network'): ?>
let retryCount = 0;
const maxRetries = 3;

function autoRetry() {
    if (retryCount < maxRetries) {
        retryCount++;
        setTimeout(() => {
            const retryBtn = document.querySelector('.btn-primary');
            if (retryBtn && retryBtn.href) {
                retryBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Auto-retrying...';
                setTimeout(() => {
                    window.location.href = retryBtn.href;
                }, 1000);
            }
        }, 5000 * retryCount); // Increasing delay
    }
}

// Start auto-retry for network errors
setTimeout(autoRetry, 3000);
<?php endif; ?>

// Track failed payment for analytics
if (typeof gtag !== 'undefined') {
    gtag('event', 'payment_failed', {
        'error_type': '<?php echo $errorType; ?>',
        'bill_id': <?php echo $billId ?: 'null'; ?>,
        'error_message': '<?php echo addslashes($errorMessage); ?>'
    });
}
</script>

<?php include 'footer.php'; ?>
