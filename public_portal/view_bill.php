<?php
/**
 * Public Portal - View Bill Details for QUICKBILL 305
 * Displays detailed bill information with outstanding balance tracking and QR code
 */

// Define application constant
define('QUICKBILL_305', true);

// Include configuration files
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Start session for public portal
session_start();

// Add missing authentication functions for public portal compatibility
if (!function_exists('isLoggedIn')) {
    function isLoggedIn() {
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }
}

if (!function_exists('getCurrentUserId')) {
    function getCurrentUserId() {
        return $_SESSION['user_id'] ?? null;
    }
}

// Public portal logging function (doesn't require authentication)
function logPublicActivity($action, $details = '') {
    try {
        $db = new Database();
        
        $sql = "INSERT INTO audit_logs (user_id, action, table_name, record_id, new_values, ip_address, user_agent, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $params = [
            null, // No user ID for public access
            'PUBLIC: ' . $action,
            'public_portal',
            null,
            json_encode($details),
            getClientIP(),
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ];
        
        $pdo = $db->getConnection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
    } catch (Exception $e) {
        writeLog("Failed to log public activity: " . $e->getMessage(), 'ERROR');
    }
}

// Calculate remaining balance for this specific bill
function calculateBillRemainingBalance($billId) {
    try {
        $db = new Database();
        
        // Get bill details first
        $billQuery = "SELECT bill_id, amount_payable FROM bills WHERE bill_id = ?";
        $billResult = $db->fetchRow($billQuery, [$billId]);
        
        if (!$billResult) {
            return [
                'total_paid' => 0,
                'remaining_balance' => 0,
                'payment_percentage' => 0,
                'is_fully_paid' => false
            ];
        }
        
        // Get total successful payments for this specific bill
        $paymentsQuery = "SELECT COALESCE(SUM(amount_paid), 0) as total_paid
                         FROM payments 
                         WHERE bill_id = ? AND payment_status = 'Successful'";
        
        $paymentsResult = $db->fetchRow($paymentsQuery, [$billId]);
        $totalPaid = $paymentsResult['total_paid'] ?? 0;
        
        // Calculate remaining balance
        $remainingBalance = max(0, $billResult['amount_payable'] - $totalPaid);
        $isFullyPaid = $remainingBalance <= 0;
        $paymentPercentage = $billResult['amount_payable'] > 0 ? 
            ($totalPaid / $billResult['amount_payable']) * 100 : 100;
        
        return [
            'total_paid' => $totalPaid,
            'remaining_balance' => $remainingBalance,
            'payment_percentage' => min($paymentPercentage, 100),
            'is_fully_paid' => $isFullyPaid
        ];
        
    } catch (Exception $e) {
        writeLog("Error calculating bill remaining balance: " . $e->getMessage(), 'ERROR');
        return [
            'total_paid' => 0,
            'remaining_balance' => $billResult['amount_payable'] ?? 0,
            'payment_percentage' => 0,
            'is_fully_paid' => false
        ];
    }
}

// Get bill ID from URL
$billId = isset($_GET['bill_id']) ? (int)$_GET['bill_id'] : 0;

if (!$billId) {
    setFlashMessage('error', 'Invalid bill ID provided.');
    header('Location: search_bill.php');
    exit();
}

// Initialize variables
$billData = null;
$accountData = null;
$paymentHistory = [];
$balanceInfo = [];
$assemblyName = getSystemSetting('assembly_name', 'Municipal Assembly');

try {
    $db = new Database();
    
    // Log bill view attempt
    logPublicActivity(
        "Bill view attempt", 
        [
            'bill_id' => $billId,
            'timestamp' => date('Y-m-d H:i:s')
        ]
    );
    
    // Get bill details
    $billQuery = "
        SELECT 
            bill_id,
            bill_number,
            bill_type,
            reference_id,
            billing_year,
            old_bill,
            previous_payments,
            arrears,
            current_bill,
            amount_payable,
            qr_code,
            status,
            generated_at,
            due_date
        FROM bills 
        WHERE bill_id = ?
    ";
    
    $billData = $db->fetchRow($billQuery, [$billId]);
    
    if (!$billData) {
        setFlashMessage('error', 'Bill not found.');
        header('Location: search_bill.php');
        exit();
    }
    
    // Calculate remaining balance for this bill
    $balanceInfo = calculateBillRemainingBalance($billId);
    
    // Get account details based on bill type
    if ($billData['bill_type'] === 'Business') {
        $accountQuery = "
            SELECT 
                business_id as id,
                account_number,
                business_name as name,
                owner_name,
                business_type as type,
                category,
                telephone,
                exact_location as location,
                status,
                z.zone_name,
                sz.sub_zone_name
            FROM businesses b
            LEFT JOIN zones z ON b.zone_id = z.zone_id
            LEFT JOIN sub_zones sz ON b.sub_zone_id = sz.sub_zone_id
            WHERE b.business_id = ?
        ";
        
        $accountData = $db->fetchRow($accountQuery, [$billData['reference_id']]);
        
    } elseif ($billData['bill_type'] === 'Property') {
        $accountQuery = "
            SELECT 
                property_id as id,
                property_number as account_number,
                owner_name as name,
                owner_name,
                structure as type,
                property_use as category,
                telephone,
                location,
                number_of_rooms,
                z.zone_name,
                sz.sub_zone_name
            FROM properties p
            LEFT JOIN zones z ON p.zone_id = z.zone_id
            LEFT JOIN sub_zones sz ON p.sub_zone_id = sz.sub_zone_id
            WHERE p.property_id = ?
        ";
        
        $accountData = $db->fetchRow($accountQuery, [$billData['reference_id']]);
    }
    
    if (!$accountData) {
        setFlashMessage('error', 'Account information not found.');
        header('Location: search_bill.php');
        exit();
    }
    
    // Get payment history for this bill
    $paymentQuery = "
        SELECT 
            payment_id,
            payment_reference,
            amount_paid,
            payment_method,
            payment_channel,
            payment_status,
            payment_date,
            notes
        FROM payments 
        WHERE bill_id = ? 
        ORDER BY payment_date DESC
    ";
    
    $paymentHistory = $db->fetchAll($paymentQuery, [$billId]);
    
    // Log successful bill view
    logPublicActivity(
        "Bill viewed successfully", 
        [
            'bill_number' => $billData['bill_number'],
            'bill_type' => $billData['bill_type'],
            'account_number' => $accountData['account_number'],
            'remaining_balance' => $balanceInfo['remaining_balance'],
            'is_fully_paid' => $balanceInfo['is_fully_paid']
        ]
    );
    
} catch (Exception $e) {
    writeLog("View bill error: " . $e->getMessage(), 'ERROR');
    setFlashMessage('error', 'An error occurred while loading bill details.');
    header('Location: search_bill.php');
    exit();
}

include 'header.php';
?>

<div class="bill-details-page">
    <div class="container">
        <!-- Outstanding Balance Alert -->
        <?php if ($balanceInfo['remaining_balance'] > 0): ?>
        <div class="balance-alert balance-outstanding">
            <div class="alert-icon">⚠️</div>
            <div class="alert-content">
                <h3>Outstanding Balance</h3>
                <div class="alert-amount">₵ <?php echo number_format($balanceInfo['remaining_balance'], 2); ?></div>
                <p>This bill has an outstanding balance that needs to be paid</p>
            </div>
            <div class="alert-action">
                <a href="pay_bill.php?bill_id=<?php echo $billData['bill_id']; ?>" class="btn btn-warning btn-pulse">
                    <i class="fas fa-credit-card"></i>
                    Pay Now
                </a>
            </div>
        </div>
        <?php else: ?>
        <div class="balance-alert balance-cleared">
            <div class="alert-icon">✅</div>
            <div class="alert-content">
                <h3>Bill Fully Paid</h3>
                <div class="alert-amount">₵ 0.00</div>
                <p>This bill has been paid in full. Thank you!</p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Bill Header -->
        <div class="bill-header-section">
            <div class="bill-header-content">
                <div class="bill-title">
                    <h1>📄 Bill Details</h1>
                    <p>Complete information for your <?php echo strtolower($billData['bill_type']); ?> bill</p>
                </div>
                
                <div class="bill-actions">
                    <button onclick="window.print()" class="btn btn-outline">
                        <i class="fas fa-print"></i>
                        Print Bill
                    </button>
                    
                    <?php if ($balanceInfo['remaining_balance'] > 0): ?>
                    <a href="pay_bill.php?bill_id=<?php echo $billData['bill_id']; ?>" class="btn btn-primary">
                        <i class="fas fa-credit-card"></i>
                        Pay ₵ <?php echo number_format($balanceInfo['remaining_balance'], 2); ?>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Bill Information Card -->
        <div class="bill-info-card">
            <!-- Assembly Header -->
            <div class="assembly-header">
                <div class="assembly-logo">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <div class="assembly-info">
                    <h2><?php echo htmlspecialchars($assemblyName); ?></h2>
                    <p class="assembly-subtitle">Official Bill Statement</p>
                    <p class="assembly-contact">Ghana | Tel: +233 123 456 789</p>
                </div>
                <div class="bill-qr">
                    <?php if (!empty($billData['qr_code'])): ?>
                        <img src="data:image/png;base64,<?php echo $billData['qr_code']; ?>" alt="Bill QR Code" class="qr-code">
                    <?php else: ?>
                        <div class="qr-placeholder">
                            <i class="fas fa-qrcode"></i>
                            <small>QR Code</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Bill and Account Details -->
            <div class="bill-account-section">
                <div class="bill-details-grid">
                    <!-- Bill Information -->
                    <div class="detail-section">
                        <h3 class="section-title">
                            <i class="fas fa-file-invoice"></i>
                            Bill Information
                        </h3>
                        <div class="detail-rows">
                            <div class="detail-row">
                                <span class="detail-label">Bill Number:</span>
                                <span class="detail-value"><?php echo htmlspecialchars($billData['bill_number']); ?></span>
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
                                <span class="detail-label">Generated Date:</span>
                                <span class="detail-value"><?php echo formatDate($billData['generated_at']); ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Payment Status:</span>
                                <span class="detail-value">
                                    <span class="status-badge <?php echo $balanceInfo['is_fully_paid'] ? 'paid' : 'outstanding'; ?>">
                                        <?php echo $balanceInfo['is_fully_paid'] ? 'Fully Paid' : 'Outstanding'; ?>
                                    </span>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Account Information -->
                    <div class="detail-section">
                        <h3 class="section-title">
                            <i class="fas fa-user"></i>
                            Account Information
                        </h3>
                        <div class="detail-rows">
                            <div class="detail-row">
                                <span class="detail-label">Account Number:</span>
                                <span class="detail-value account-number"><?php echo htmlspecialchars($accountData['account_number']); ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label"><?php echo $billData['bill_type'] === 'Business' ? 'Business Name:' : 'Owner Name:'; ?></span>
                                <span class="detail-value"><?php echo htmlspecialchars($accountData['name']); ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Owner Name:</span>
                                <span class="detail-value"><?php echo htmlspecialchars($accountData['owner_name']); ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label"><?php echo $billData['bill_type'] === 'Business' ? 'Business Type:' : 'Structure:'; ?></span>
                                <span class="detail-value"><?php echo htmlspecialchars($accountData['type']); ?></span>
                            </div>
                            <?php if (!empty($accountData['category'])): ?>
                            <div class="detail-row">
                                <span class="detail-label"><?php echo $billData['bill_type'] === 'Business' ? 'Category:' : 'Property Use:'; ?></span>
                                <span class="detail-value"><?php echo htmlspecialchars($accountData['category']); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($accountData['telephone'])): ?>
                            <div class="detail-row">
                                <span class="detail-label">Telephone:</span>
                                <span class="detail-value"><?php echo htmlspecialchars($accountData['telephone']); ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="detail-row">
                                <span class="detail-label">Location:</span>
                                <span class="detail-value"><?php echo htmlspecialchars($accountData['location'] ?? 'N/A'); ?></span>
                            </div>
                            <?php if (!empty($accountData['zone_name'])): ?>
                            <div class="detail-row">
                                <span class="detail-label">Zone:</span>
                                <span class="detail-value"><?php echo htmlspecialchars($accountData['zone_name']); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if (isset($accountData['number_of_rooms'])): ?>
                            <div class="detail-row">
                                <span class="detail-label">Number of Rooms:</span>
                                <span class="detail-value"><?php echo $accountData['number_of_rooms']; ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payment Progress Section -->
            <?php if ($balanceInfo['total_paid'] > 0 || $balanceInfo['payment_percentage'] > 0): ?>
            <div class="payment-progress-section">
                <h3 class="section-title">
                    <i class="fas fa-chart-line"></i>
                    Payment Progress
                </h3>
                
                <div class="progress-summary">
                    <div class="progress-item">
                        <div class="progress-label">Total Payable</div>
                        <div class="progress-value">₵ <?php echo number_format($billData['amount_payable'], 2); ?></div>
                    </div>
                    <div class="progress-item">
                        <div class="progress-label">Amount Paid</div>
                        <div class="progress-value paid">₵ <?php echo number_format($balanceInfo['total_paid'], 2); ?></div>
                    </div>
                    <div class="progress-item highlight">
                        <div class="progress-label">Outstanding Balance</div>
                        <div class="progress-value <?php echo $balanceInfo['remaining_balance'] > 0 ? 'outstanding' : 'cleared'; ?>">
                            ₵ <?php echo number_format($balanceInfo['remaining_balance'], 2); ?>
                        </div>
                    </div>
                    <div class="progress-item">
                        <div class="progress-label">Payment Progress</div>
                        <div class="progress-visual">
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo min($balanceInfo['payment_percentage'], 100); ?>%"></div>
                            </div>
                            <div class="progress-text"><?php echo number_format($balanceInfo['payment_percentage'], 1); ?>%</div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Bill Amount Breakdown -->
            <div class="amount-breakdown-section">
                <h3 class="section-title">
                    <i class="fas fa-calculator"></i>
                    Amount Breakdown
                </h3>
                
                <div class="amount-table">
                    <div class="amount-table-header">
                        <span>Description</span>
                        <span>Amount (₵)</span>
                    </div>
                    
                    <?php if ($billData['old_bill'] > 0): ?>
                    <div class="amount-row">
                        <span class="amount-description">
                            <i class="fas fa-history"></i>
                            Previous Bill Balance
                        </span>
                        <span class="amount-value"><?php echo number_format($billData['old_bill'], 2); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($billData['arrears'] > 0): ?>
                    <div class="amount-row arrears">
                        <span class="amount-description">
                            <i class="fas fa-exclamation-triangle"></i>
                            Arrears/Penalties
                        </span>
                        <span class="amount-value"><?php echo number_format($billData['arrears'], 2); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="amount-row">
                        <span class="amount-description">
                            <i class="fas fa-file-invoice-dollar"></i>
                            Current Bill (<?php echo $billData['billing_year']; ?>)
                        </span>
                        <span class="amount-value"><?php echo number_format($billData['current_bill'], 2); ?></span>
                    </div>
                    
                    <?php if ($billData['previous_payments'] > 0): ?>
                    <div class="amount-row credit">
                        <span class="amount-description">
                            <i class="fas fa-check-circle"></i>
                            Previous Payments (at Bill Generation)
                        </span>
                        <span class="amount-value">-<?php echo number_format($billData['previous_payments'], 2); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="amount-row total">
                        <span class="amount-description">
                            <i class="fas fa-money-bill-wave"></i>
                            <strong>Total Amount Payable</strong>
                        </span>
                        <span class="amount-value">
                            <strong>₵ <?php echo number_format($billData['amount_payable'], 2); ?></strong>
                        </span>
                    </div>

                    <?php if ($balanceInfo['total_paid'] > 0): ?>
                    <div class="amount-row paid-amount">
                        <span class="amount-description">
                            <i class="fas fa-credit-card"></i>
                            <strong>Payments Made</strong>
                        </span>
                        <span class="amount-value paid">
                            <strong>-₵ <?php echo number_format($balanceInfo['total_paid'], 2); ?></strong>
                        </span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="amount-row balance <?php echo $balanceInfo['remaining_balance'] > 0 ? 'outstanding' : 'cleared'; ?>">
                        <span class="amount-description">
                            <i class="fas fa-balance-scale"></i>
                            <strong>Outstanding Balance</strong>
                        </span>
                        <span class="amount-value">
                            <strong>₵ <?php echo number_format($balanceInfo['remaining_balance'], 2); ?></strong>
                        </span>
                    </div>
                </div>
                
                <?php if ($balanceInfo['remaining_balance'] > 0): ?>
                <div class="payment-call-to-action">
                    <div class="payment-cta-content">
                        <h4>💳 Ready to Pay?</h4>
                        <p>Pay securely online using mobile money or card payments</p>
                        <a href="pay_bill.php?bill_id=<?php echo $billData['bill_id']; ?>" class="btn btn-primary btn-lg">
                            <i class="fas fa-credit-card"></i>
                            Pay ₵ <?php echo number_format($balanceInfo['remaining_balance'], 2); ?> Now
                        </a>
                    </div>
                </div>
                <?php else: ?>
                <div class="payment-status-paid">
                    <div class="paid-icon">✅</div>
                    <h4>Bill Fully Paid</h4>
                    <p>This bill has been paid in full. Thank you!</p>
                    <small>Total paid: ₵ <?php echo number_format($balanceInfo['total_paid'], 2); ?></small>
                </div>
                <?php endif; ?>
            </div>

            <!-- Payment History -->
            <?php if (!empty($paymentHistory)): ?>
            <div class="payment-history-section">
                <h3 class="section-title">
                    <i class="fas fa-history"></i>
                    Payment History
                </h3>
                
                <div class="payment-history-summary">
                    <div class="history-stats">
                        <div class="stat-item">
                            <div class="stat-number"><?php echo count($paymentHistory); ?></div>
                            <div class="stat-label">Total Transactions</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number">₵ <?php echo number_format($balanceInfo['total_paid'], 2); ?></div>
                            <div class="stat-label">Total Paid</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number"><?php echo number_format($balanceInfo['payment_percentage'], 1); ?>%</div>
                            <div class="stat-label">Progress</div>
                        </div>
                    </div>
                </div>
                
                <div class="payment-history-table">
                    <?php foreach ($paymentHistory as $payment): ?>
                    <div class="payment-row">
                        <div class="payment-info">
                            <div class="payment-reference">
                                <i class="fas fa-receipt"></i>
                                <?php echo htmlspecialchars($payment['payment_reference']); ?>
                            </div>
                            <div class="payment-method">
                                <i class="fas fa-<?php echo $payment['payment_method'] === 'Mobile Money' ? 'mobile-alt' : 'credit-card'; ?>"></i>
                                <?php echo htmlspecialchars($payment['payment_method']); ?>
                                <?php if (!empty($payment['payment_channel'])): ?>
                                    - <?php echo htmlspecialchars($payment['payment_channel']); ?>
                                <?php endif; ?>
                            </div>
                            <div class="payment-date">
                                <i class="fas fa-calendar"></i>
                                <?php echo formatDateTime($payment['payment_date']); ?>
                            </div>
                            <?php if (!empty($payment['notes'])): ?>
                            <div class="payment-notes">
                                <i class="fas fa-sticky-note"></i>
                                <?php echo htmlspecialchars($payment['notes']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="payment-amount">
                            <span class="amount">₵ <?php echo number_format($payment['amount_paid'], 2); ?></span>
                            <span class="status-badge <?php echo strtolower($payment['payment_status']); ?>">
                                <?php echo htmlspecialchars($payment['payment_status']); ?>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Bill Footer -->
            <div class="bill-footer">
                <div class="footer-note">
                    <p><strong>Note:</strong> This is an official bill statement from <?php echo htmlspecialchars($assemblyName); ?>. 
                    For inquiries, please contact our office during business hours.</p>
                    <p><strong>Payment Methods:</strong> Mobile Money (MTN, Telecel, AirtelTigo), Debit/Credit Cards, Bank Transfer</p>
                    <?php if ($balanceInfo['remaining_balance'] > 0): ?>
                    <p><strong>Outstanding Balance:</strong> ₵ <?php echo number_format($balanceInfo['remaining_balance'], 2); ?> remains to be paid on this bill.</p>
                    <?php endif; ?>
                </div>
                
                <div class="footer-contact">
                    <div class="contact-item">
                        <i class="fas fa-phone"></i>
                        <span>+233 123 456 789</span>
                    </div>
                    <div class="contact-item">
                        <i class="fas fa-envelope"></i>
                        <span>support@<?php echo strtolower(str_replace(' ', '', $assemblyName)); ?>.gov.gh</span>
                    </div>
                    <div class="contact-item">
                        <i class="fas fa-clock"></i>
                        <span>Mon - Fri: 8:00 AM - 5:00 PM</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Navigation Actions -->
        <div class="navigation-actions">
            <a href="search_bill.php" class="btn btn-outline">
                <i class="fas fa-search"></i>
                Search Another Bill
            </a>
            
            <a href="verify_payment.php" class="btn btn-secondary">
                <i class="fas fa-check-circle"></i>
                Verify Payment
            </a>
            
            <?php if ($balanceInfo['remaining_balance'] > 0): ?>
            <a href="pay_bill.php?bill_id=<?php echo $billData['bill_id']; ?>" class="btn btn-primary">
                <i class="fas fa-credit-card"></i>
                Pay Outstanding Balance
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
/* Bill Details Page Styles */
.bill-details-page {
    padding: 40px 0;
    min-height: 600px;
}

.container {
    max-width: 900px;
    margin: 0 auto;
    padding: 0 20px;
}

/* Outstanding Balance Alert */
.balance-alert {
    background: white;
    border-radius: 15px;
    padding: 30px;
    margin-bottom: 30px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
    display: flex;
    align-items: center;
    gap: 25px;
    animation: slideUp 0.6s ease;
}

.balance-alert.balance-outstanding {
    border-left: 5px solid #f59e0b;
    background: linear-gradient(135deg, #fffbf5 0%, #fef7e0 100%);
}

.balance-alert.balance-cleared {
    border-left: 5px solid #10b981;
    background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
}

.alert-icon {
    font-size: 3rem;
    flex-shrink: 0;
}

.alert-content {
    flex: 1;
}

.alert-content h3 {
    color: #2d3748;
    margin-bottom: 8px;
    font-size: 1.5rem;
}

.alert-amount {
    font-size: 2.5rem;
    font-weight: bold;
    margin: 10px 0;
}

.balance-outstanding .alert-amount {
    color: #d69e2e;
}

.balance-cleared .alert-amount {
    color: #10b981;
}

.alert-content p {
    color: #4a5568;
    margin: 0;
}

.alert-action {
    flex-shrink: 0;
}

/* Bill Header */
.bill-header-section {
    margin-bottom: 30px;
}

.bill-header-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: white;
    padding: 30px;
    border-radius: 15px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
}

.bill-title h1 {
    color: #2d3748;
    margin-bottom: 5px;
    font-size: 2rem;
}

.bill-title p {
    color: #718096;
    margin: 0;
}

.bill-actions {
    display: flex;
    gap: 15px;
}

/* Bill Information Card */
.bill-info-card {
    background: white;
    border-radius: 15px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
    overflow: hidden;
    margin-bottom: 30px;
    animation: slideUp 0.8s ease;
}

/* Assembly Header */
.assembly-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 30px;
    display: flex;
    align-items: center;
    gap: 20px;
}

.assembly-logo {
    font-size: 3rem;
    background: rgba(255, 255, 255, 0.2);
    padding: 20px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.assembly-info {
    flex: 1;
}

.assembly-info h2 {
    margin: 0 0 8px 0;
    font-size: 1.8rem;
    font-weight: bold;
}

.assembly-subtitle {
    margin: 0 0 5px 0;
    opacity: 0.9;
    font-size: 1.1rem;
}

.assembly-contact {
    margin: 0;
    opacity: 0.8;
    font-size: 0.9rem;
}

.bill-qr {
    flex-shrink: 0;
}

.qr-code {
    width: 100px;
    height: 100px;
    background: white;
    border-radius: 8px;
    padding: 8px;
}

.qr-placeholder {
    width: 100px;
    height: 100px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 8px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: white;
}

.qr-placeholder i {
    font-size: 2rem;
    margin-bottom: 5px;
}

.qr-placeholder small {
    font-size: 0.8rem;
    opacity: 0.8;
}

/* Bill and Account Section */
.bill-account-section {
    padding: 30px;
    border-bottom: 1px solid #e2e8f0;
}

.bill-details-grid {
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

.section-title {
    display: flex;
    align-items: center;
    gap: 10px;
    color: #2d3748;
    margin-bottom: 20px;
    font-size: 1.2rem;
    font-weight: 600;
    padding-bottom: 10px;
    border-bottom: 2px solid #667eea;
}

.section-title i {
    color: #667eea;
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
    padding: 8px 0;
    border-bottom: 1px solid #e2e8f0;
}

.detail-row:last-child {
    border-bottom: none;
}

.detail-label {
    color: #4a5568;
    font-weight: 500;
    flex: 0 0 40%;
}

.detail-value {
    color: #2d3748;
    font-weight: 600;
    text-align: right;
    flex: 1;
}

.detail-value.account-number {
    color: #667eea;
    font-family: monospace;
    font-size: 1.1rem;
    cursor: pointer;
}

.status-badge {
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-badge.paid {
    background: #c6f6d5;
    color: #22543d;
}

.status-badge.outstanding {
    background: #feebc8;
    color: #c05621;
}

.status-badge.partially {
    background: #feebc8;
    color: #c05621;
}

.status-badge.successful {
    background: #c6f6d5;
    color: #22543d;
}

.status-badge.failed {
    background: #fed7d7;
    color: #c53030;
}

/* Payment Progress Section */
.payment-progress-section {
    padding: 30px;
    border-bottom: 1px solid #e2e8f0;
    background: linear-gradient(135deg, #f8fafc 0%, #edf2f7 100%);
}

.progress-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
}

.progress-item {
    background: white;
    padding: 20px;
    border-radius: 12px;
    text-align: center;
    border: 1px solid #e2e8f0;
}

.progress-item.highlight {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    border: 2px solid #f59e0b;
}

.progress-label {
    color: #718096;
    font-size: 0.9rem;
    margin-bottom: 8px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.progress-value {
    font-weight: bold;
    color: #2d3748;
    font-size: 1.3rem;
}

.progress-value.outstanding {
    color: #d69e2e;
    font-size: 1.5rem;
}

.progress-value.cleared {
    color: #10b981;
    font-size: 1.5rem;
}

.progress-value.paid {
    color: #10b981;
}

.progress-visual {
    display: flex;
    align-items: center;
    gap: 10px;
}

.progress-bar {
    flex: 1;
    height: 12px;
    background: #e2e8f0;
    border-radius: 6px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #10b981, #059669);
    border-radius: 6px;
    transition: width 1s ease;
}

.progress-text {
    font-size: 0.9rem;
    font-weight: 600;
    color: #4a5568;
}

/* Amount Breakdown */
.amount-breakdown-section {
    padding: 30px;
    border-bottom: 1px solid #e2e8f0;
}

.amount-table {
    background: #f7fafc;
    border-radius: 10px;
    overflow: hidden;
    border: 1px solid #e2e8f0;
    margin-bottom: 25px;
}

.amount-table-header {
    background: #667eea;
    color: white;
    padding: 15px 20px;
    display: flex;
    justify-content: space-between;
    font-weight: 600;
}

.amount-row {
    padding: 15px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #e2e8f0;
}

.amount-row:last-child {
    border-bottom: none;
}

.amount-row.total {
    background: #edf2f7;
    font-weight: bold;
    font-size: 1.1rem;
}

.amount-row.credit {
    background: #f0fff4;
}

.amount-row.credit .amount-value {
    color: #38a169;
}

.amount-row.arrears {
    background: #fff5f5;
}

.amount-row.arrears .amount-value {
    color: #e53e3e;
}

.amount-row.paid-amount {
    background: #e6fffa;
    border-top: 2px solid #38a169;
}

.amount-row.paid-amount .amount-value {
    color: #38a169;
}

.amount-row.balance {
    border-top: 3px solid #e2e8f0;
    font-size: 1.2rem;
    font-weight: bold;
}

.amount-row.balance.outstanding {
    background: #fef3c7;
    border-top-color: #f59e0b;
}

.amount-row.balance.outstanding .amount-value {
    color: #d69e2e;
}

.amount-row.balance.cleared {
    background: #d1fae5;
    border-top-color: #10b981;
}

.amount-row.balance.cleared .amount-value {
    color: #10b981;
}

.amount-description {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #2d3748;
}

.amount-description i {
    color: #667eea;
    width: 16px;
}

.amount-value {
    font-weight: 600;
    color: #2d3748;
    font-family: monospace;
}

/* Payment Call to Action */
.payment-call-to-action {
    background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
    color: white;
    padding: 25px;
    border-radius: 10px;
    text-align: center;
}

.payment-cta-content h4 {
    margin-bottom: 8px;
    font-size: 1.3rem;
}

.payment-cta-content p {
    margin-bottom: 20px;
    opacity: 0.9;
}

.payment-status-paid {
    background: #f0fff4;
    border: 2px solid #9ae6b4;
    color: #22543d;
    padding: 25px;
    border-radius: 10px;
    text-align: center;
}

.paid-icon {
    font-size: 3rem;
    margin-bottom: 15px;
}

.payment-status-paid h4 {
    margin-bottom: 8px;
    color: #22543d;
}

.payment-status-paid p {
    margin: 0 0 10px 0;
    color: #2f855a;
}

.payment-status-paid small {
    color: #38a169;
    font-weight: 600;
}

/* Payment History */
.payment-history-section {
    padding: 30px;
    border-bottom: 1px solid #e2e8f0;
}

.payment-history-summary {
    margin-bottom: 25px;
    padding: 20px;
    background: #f7fafc;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
}

.history-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 20px;
}

.stat-item {
    text-align: center;
    padding: 15px;
    background: white;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
}

.stat-number {
    font-size: 1.5rem;
    font-weight: bold;
    color: #2d3748;
    margin-bottom: 5px;
}

.stat-label {
    color: #718096;
    font-size: 0.9rem;
    font-weight: 500;
}

.payment-history-table {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.payment-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px;
    background: #f7fafc;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
    transition: all 0.3s;
}

.payment-row:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    border-color: #667eea;
}

.payment-info {
    flex: 1;
}

.payment-reference {
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 5px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.payment-method,
.payment-date,
.payment-notes {
    font-size: 0.9rem;
    color: #4a5568;
    margin-bottom: 3px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.payment-method i,
.payment-date i,
.payment-notes i {
    color: #667eea;
    width: 14px;
}

.payment-amount {
    text-align: right;
}

.payment-amount .amount {
    display: block;
    font-weight: bold;
    color: #2d3748;
    font-size: 1.1rem;
    margin-bottom: 5px;
}

/* Bill Footer */
.bill-footer {
    padding: 30px;
    background: #f7fafc;
}

.footer-note {
    margin-bottom: 20px;
    padding-bottom: 20px;
    border-bottom: 1px solid #e2e8f0;
}

.footer-note p {
    color: #4a5568;
    margin-bottom: 8px;
    font-size: 0.9rem;
    line-height: 1.5;
}

.footer-contact {
    display: flex;
    gap: 30px;
    flex-wrap: wrap;
}

.contact-item {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #4a5568;
    font-size: 0.9rem;
}

.contact-item i {
    color: #667eea;
    width: 16px;
}

/* Navigation Actions */
.navigation-actions {
    display: flex;
    gap: 15px;
    justify-content: center;
    flex-wrap: wrap;
    margin-top: 20px;
}

/* Button Styles */
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

.btn-warning {
    background: #f59e0b;
    color: white;
}

.btn-warning:hover {
    background: #d97706;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(245, 158, 11, 0.3);
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
    padding: 15px 30px;
    font-size: 1.1rem;
}

.btn-pulse {
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.05); }
    100% { transform: scale(1); }
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

/* Print Styles */
@media print {
    .balance-alert,
    .bill-header-section,
    .navigation-actions,
    .payment-call-to-action {
        display: none !important;
    }
    
    .bill-info-card {
        box-shadow: none;
        border: 1px solid #000;
    }
    
    .assembly-header {
        background: #f8f9fa !important;
        color: #000 !important;
        border-bottom: 2px solid #000;
    }
    
    .section-title {
        color: #000 !important;
    }
    
    .amount-table-header {
        background: #f8f9fa !important;
        color: #000 !important;
    }
    
    body {
        background: white !important;
    }
}

/* Responsive Design */
@media (max-width: 768px) {
    .balance-alert {
        flex-direction: column;
        text-align: center;
        gap: 15px;
    }
    
    .bill-header-content {
        flex-direction: column;
        gap: 20px;
        text-align: center;
    }
    
    .assembly-header {
        flex-direction: column;
        text-align: center;
        gap: 15px;
    }
    
    .bill-details-grid,
    .progress-summary {
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
    
    .amount-table-header,
    .amount-row {
        flex-direction: column;
        gap: 5px;
        text-align: center;
    }
    
    .payment-row {
        flex-direction: column;
        gap: 15px;
        text-align: center;
    }
    
    .footer-contact {
        flex-direction: column;
        gap: 15px;
    }
    
    .navigation-actions {
        flex-direction: column;
    }
    
    .progress-visual {
        flex-direction: column;
        gap: 8px;
    }
    
    .history-stats {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 480px) {
    .container {
        padding: 0 15px;
    }
    
    .bill-info-card,
    .detail-section {
        padding: 20px;
    }
    
    .assembly-header,
    .payment-progress-section {
        padding: 20px;
    }
    
    .bill-title h1 {
        font-size: 1.5rem;
    }
    
    .alert-amount {
        font-size: 2rem;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Add copy functionality for account number
    const accountNumber = document.querySelector('.account-number');
    if (accountNumber) {
        accountNumber.title = 'Click to copy account number';
        
        accountNumber.addEventListener('click', function() {
            navigator.clipboard.writeText(this.textContent).then(function() {
                // Show temporary feedback
                const originalText = accountNumber.textContent;
                const originalColor = accountNumber.style.color;
                
                accountNumber.textContent = 'Copied!';
                accountNumber.style.color = '#48bb78';
                
                setTimeout(() => {
                    accountNumber.textContent = originalText;
                    accountNumber.style.color = originalColor;
                }, 1000);
            });
        });
    }
    
    // Initialize progress bar animations
    const progressBars = document.querySelectorAll('.progress-fill');
    
    // Animate progress bars when they come into view
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const progressBar = entry.target;
                const width = progressBar.style.width;
                progressBar.style.width = '0%';
                setTimeout(() => {
                    progressBar.style.width = width;
                }, 100);
            }
        });
    });
    
    progressBars.forEach(bar => {
        observer.observe(bar);
    });
    
    // Add print optimization
    window.addEventListener('beforeprint', function() {
        document.title = 'Bill_<?php echo htmlspecialchars($billData["bill_number"]); ?>';
    });
    
    // Add hover effects to payment rows
    const paymentRows = document.querySelectorAll('.payment-row');
    paymentRows.forEach(row => {
        row.addEventListener('mouseenter', function() {
            this.style.borderColor = '#667eea';
        });
        
        row.addEventListener('mouseleave', function() {
            this.style.borderColor = '#e2e8f0';
        });
    });
    
    // Outstanding balance pulsing effect
    const outstandingAlert = document.querySelector('.balance-alert.balance-outstanding');
    if (outstandingAlert) {
        // Add pulse animation after a delay
        setTimeout(() => {
            outstandingAlert.style.animation = 'slideUp 0.6s ease, pulse 3s infinite 2s';
        }, 1000);
    }
    
    // Auto-scroll to amount breakdown on mobile with hash
    if (window.innerWidth <= 768 && window.location.hash === '#amount') {
        setTimeout(() => {
            document.querySelector('.amount-breakdown-section').scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }, 500);
    }
    
    // Add notification for outstanding balance
    const remainingBalance = <?php echo $balanceInfo['remaining_balance']; ?>;
    const paymentPercentage = <?php echo $balanceInfo['payment_percentage']; ?>;
    
    if (remainingBalance > 0) {
        console.log('📋 Outstanding Balance: ₵' + remainingBalance.toLocaleString(undefined, {minimumFractionDigits: 2}));
        console.log('💳 Payment Progress: ' + paymentPercentage.toFixed(1) + '%');
    } else {
        console.log('✅ Bill fully paid!');
    }
    
    // Track bill view for analytics
    setTimeout(() => {
        // This could be extended to send analytics data
        console.log('📊 Bill view tracked:', {
            billId: <?php echo $billId; ?>,
            billNumber: '<?php echo htmlspecialchars($billData["bill_number"]); ?>',
            outstandingBalance: remainingBalance,
            paymentProgress: paymentPercentage
        });
    }, 2000);
});
</script>

<?php include 'footer.php'; ?>