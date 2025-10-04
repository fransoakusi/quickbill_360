<?php
/**
 * Public Portal - Search Bill for QUICKBILL 305
 * Allows users to search for their bills using account number
 * Updated with CORRECT Outstanding Balance Calculation
 * Balance is retrieved from current year's bill amount_payable field
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

// FIXED: Calculate remaining balance from current year's bill - CORRECT IMPLEMENTATION
function calculateRemainingBalance($accountId, $accountType) {
    try {
        $db = new Database();
        $currentYear = date('Y');
        
        // Get current year's bill - the amount_payable field is the correct remaining balance
        $billQuery = "SELECT bill_id, amount_payable, billing_year, status
                     FROM bills 
                     WHERE bill_type = ? AND reference_id = ? AND billing_year = ?
                     ORDER BY generated_at DESC 
                     LIMIT 1";
        
        $currentBill = $db->fetchRow($billQuery, [$accountType, $accountId, $currentYear]);
        
        if ($currentBill) {
            // The bill's amount_payable IS the remaining balance (updated by triggers when payments are made)
            $remainingBalance = floatval($currentBill['amount_payable']);
            
            // Get total payments for this specific bill to show payment progress
            $paymentsQuery = "SELECT COALESCE(SUM(p.amount_paid), 0) as bill_payments
                             FROM payments p 
                             WHERE p.bill_id = ? AND p.payment_status = 'Successful'";
            
            $paymentsResult = $db->fetchRow($paymentsQuery, [$currentBill['bill_id']]);
            $billPayments = floatval($paymentsResult['bill_payments'] ?? 0);
            
            // Get the original bill amount (before any payments)
            // This is: current_bill + arrears (which was already calculated when bill was generated)
            $originalAmount = $remainingBalance + $billPayments;
            
            // Calculate payment percentage based on the current year's bill
            $paymentPercentage = $originalAmount > 0 ? ($billPayments / $originalAmount) * 100 : 0;
            
            return [
                'has_current_bill' => true,
                'current_bill_year' => $currentBill['billing_year'],
                'bill_id' => $currentBill['bill_id'],
                'bill_status' => $currentBill['status'],
                'remaining_balance' => $remainingBalance,
                'bill_payments' => $billPayments,
                'original_bill_amount' => $originalAmount,
                'payment_percentage' => $paymentPercentage
            ];
        }
        
        // No current year bill exists
        return [
            'has_current_bill' => false,
            'current_bill_year' => $currentYear,
            'bill_id' => null,
            'bill_status' => null,
            'remaining_balance' => 0,
            'bill_payments' => 0,
            'original_bill_amount' => 0,
            'payment_percentage' => 0
        ];
        
    } catch (Exception $e) {
        writeLog("Error calculating remaining balance: " . $e->getMessage(), 'ERROR');
        return [
            'has_current_bill' => false,
            'current_bill_year' => date('Y'),
            'bill_id' => null,
            'bill_status' => null,
            'remaining_balance' => 0,
            'bill_payments' => 0,
            'original_bill_amount' => 0,
            'payment_percentage' => 0
        ];
    }
}

// Get payment history for account
function getPaymentHistory($accountId, $accountType, $limit = 5) {
    try {
        $db = new Database();
        
        $paymentsQuery = "SELECT p.*, b.bill_number, b.billing_year 
                         FROM payments p 
                         INNER JOIN bills b ON p.bill_id = b.bill_id 
                         WHERE b.bill_type = ? AND b.reference_id = ? 
                         ORDER BY p.payment_date DESC 
                         LIMIT ?";
        
        return $db->fetchAll($paymentsQuery, [$accountType, $accountId, $limit]);
        
    } catch (Exception $e) {
        writeLog("Error getting payment history: " . $e->getMessage(), 'ERROR');
        return [];
    }
}

// Initialize variables
$searchResults = null;
$searchPerformed = false;
$accountNumber = '';
$accountType = '';
$errorMessage = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $errorMessage = 'Invalid security token. Please try again.';
    } else {
        $accountNumber = sanitizeInput($_POST['account_number'] ?? '');
        $accountType = sanitizeInput($_POST['account_type'] ?? '');
        
        // Validate required fields
        if (empty($accountNumber)) {
            $errorMessage = 'Please enter your account number.';
        } elseif (empty($accountType)) {
            $errorMessage = 'Please select your account type.';
        } else {
            $searchPerformed = true;
            
            try {
                $db = new Database();
                
                // Log search attempt
                logPublicActivity(
                    "Account search attempt", 
                    [
                        'account_number' => $accountNumber,
                        'account_type' => $accountType,
                        'timestamp' => date('Y-m-d H:i:s')
                    ]
                );
                
                // Search based on account type
                if ($accountType === 'Business') {
                    // Search for business account
                    $accountQuery = "
                        SELECT 
                            b.business_id as id,
                            b.account_number,
                            b.business_name as name,
                            b.owner_name,
                            b.telephone,
                            b.business_type as type,
                            b.category,
                            b.exact_location as location,
                            b.amount_payable as account_amount_payable,
                            b.old_bill,
                            b.previous_payments,
                            b.arrears,
                            b.current_bill,
                            b.status,
                            z.zone_name,
                            sz.sub_zone_name,
                            b.created_at,
                            b.updated_at
                        FROM businesses b
                        LEFT JOIN zones z ON b.zone_id = z.zone_id
                        LEFT JOIN sub_zones sz ON b.sub_zone_id = sz.sub_zone_id
                        WHERE b.account_number = ? AND b.status = 'Active'
                    ";
                    
                    $account = $db->fetchRow($accountQuery, [$accountNumber]);
                    
                    if ($account) {
                        // Get current year bills for this business
                        $currentYear = date('Y');
                        $billsQuery = "
                            SELECT 
                                bill_id,
                                bill_number,
                                billing_year,
                                old_bill,
                                previous_payments,
                                arrears,
                                current_bill,
                                amount_payable,
                                status,
                                generated_at,
                                due_date
                            FROM bills 
                            WHERE bill_type = 'Business' 
                            AND reference_id = ? 
                            AND billing_year = ?
                            ORDER BY generated_at DESC
                        ";
                        
                        $bills = $db->fetchAll($billsQuery, [$account['id'], $currentYear]);
                        
                        // Calculate CORRECT remaining balance using the FIXED function
                        $balanceInfo = calculateRemainingBalance($account['id'], 'Business');
                        
                        // Only proceed if bills exist
                        if (!empty($bills)) {
                            // Get payment history
                            $paymentHistory = getPaymentHistory($account['id'], 'Business');
                        } else {
                            $paymentHistory = [];
                        }
                        
                        // Merge balance info with account
                        $account = array_merge($account, $balanceInfo);
                        
                        $searchResults = [
                            'account' => $account,
                            'bills' => $bills,
                            'payment_history' => $paymentHistory,
                            'account_type' => 'Business',
                            'has_bills' => !empty($bills)
                        ];
                        
                        // Log successful search
                        logPublicActivity(
                            "Business account found", 
                            [
                                'business_name' => $account['name'],
                                'account_number' => $accountNumber,
                                'bills_count' => count($bills),
                                'has_bills' => !empty($bills),
                                'current_bill_year' => $balanceInfo['current_bill_year'],
                                'remaining_balance' => $balanceInfo['remaining_balance'],
                                'payment_percentage' => $balanceInfo['payment_percentage']
                            ]
                        );
                        
                    } else {
                        $errorMessage = 'Business account not found. Please check your account number and try again.';
                        
                        // Log failed search
                        logPublicActivity(
                            "Business account not found", 
                            [
                                'account_number' => $accountNumber,
                                'search_type' => 'Business'
                            ]
                        );
                    }
                    
                } elseif ($accountType === 'Property') {
                    // Search for property account
                    $accountQuery = "
                        SELECT 
                            p.property_id as id,
                            p.property_number as account_number,
                            p.owner_name as name,
                            p.owner_name,
                            p.telephone,
                            p.structure as type,
                            p.property_use as category,
                            p.location,
                            p.number_of_rooms,
                            p.amount_payable as account_amount_payable,
                            p.old_bill,
                            p.previous_payments,
                            p.arrears,
                            p.current_bill,
                            'Active' as status,
                            z.zone_name,
                            sz.sub_zone_name,
                            p.created_at,
                            p.updated_at
                        FROM properties p
                        LEFT JOIN zones z ON p.zone_id = z.zone_id
                        LEFT JOIN sub_zones sz ON p.sub_zone_id = sz.sub_zone_id
                        WHERE p.property_number = ?
                    ";
                    
                    $account = $db->fetchRow($accountQuery, [$accountNumber]);
                    
                    if ($account) {
                        // Get current year bills for this property
                        $currentYear = date('Y');
                        $billsQuery = "
                            SELECT 
                                bill_id,
                                bill_number,
                                billing_year,
                                old_bill,
                                previous_payments,
                                arrears,
                                current_bill,
                                amount_payable,
                                status,
                                generated_at,
                                due_date
                            FROM bills 
                            WHERE bill_type = 'Property' 
                            AND reference_id = ? 
                            AND billing_year = ?
                            ORDER BY generated_at DESC
                        ";
                        
                        $bills = $db->fetchAll($billsQuery, [$account['id'], $currentYear]);
                        
                        // Calculate CORRECT remaining balance using the FIXED function
                        $balanceInfo = calculateRemainingBalance($account['id'], 'Property');
                        
                        // Only proceed if bills exist
                        if (!empty($bills)) {
                            // Get payment history
                            $paymentHistory = getPaymentHistory($account['id'], 'Property');
                        } else {
                            $paymentHistory = [];
                        }
                        
                        // Merge balance info with account
                        $account = array_merge($account, $balanceInfo);
                        
                        $searchResults = [
                            'account' => $account,
                            'bills' => $bills,
                            'payment_history' => $paymentHistory,
                            'account_type' => 'Property',
                            'has_bills' => !empty($bills)
                        ];
                        
                        // Log successful search
                        logPublicActivity(
                            "Property account found", 
                            [
                                'owner_name' => $account['name'],
                                'account_number' => $accountNumber,
                                'bills_count' => count($bills),
                                'has_bills' => !empty($bills),
                                'current_bill_year' => $balanceInfo['current_bill_year'],
                                'remaining_balance' => $balanceInfo['remaining_balance'],
                                'payment_percentage' => $balanceInfo['payment_percentage']
                            ]
                        );
                        
                    } else {
                        $errorMessage = 'Property account not found. Please check your account number and try again.';
                        
                        // Log failed search
                        logPublicActivity(
                            "Property account not found", 
                            [
                                'account_number' => $accountNumber,
                                'search_type' => 'Property'
                            ]
                        );
                    }
                }
                
            } catch (Exception $e) {
                writeLog("Search error: " . $e->getMessage(), 'ERROR');
                $errorMessage = 'An error occurred while searching for your account. Please try again.';
                
                // Log error
                logPublicActivity(
                    "Search error occurred", 
                    [
                        'error_message' => $e->getMessage(),
                        'account_number' => $accountNumber,
                        'account_type' => $accountType
                    ]
                );
            }
        }
    }
}

include 'header.php';
?>

<div class="search-page">
    <div class="container">
        <?php if (!$searchPerformed): ?>
        <!-- Search Form Section -->
        <div class="search-section">
            <div class="search-header">
                <h1>🔍 Find Your Bill</h1>
                <p>Enter your account details to view outstanding balances and pay your bills online</p>
            </div>
            
            <div class="search-form-container">
                <form action="search_bill.php" method="POST" class="search-form" id="searchForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <?php if ($errorMessage): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span><?php echo htmlspecialchars($errorMessage); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="account_number">
                                <i class="fas fa-hashtag"></i>
                                Account Number
                            </label>
                            <input 
                                type="text" 
                                id="account_number" 
                                name="account_number" 
                                value="<?php echo htmlspecialchars($accountNumber); ?>"
                                placeholder="e.g., BIZ000001 or PROP000001"
                                required
                                autocomplete="off"
                                class="form-control"
                            >
                            <small class="form-help">
                                <i class="fas fa-info-circle"></i>
                                Find your account number on your bill or SMS notification
                            </small>
                        </div>
                        
                        <div class="form-group">
                            <label for="account_type">
                                <i class="fas fa-list"></i>
                                Account Type
                            </label>
                            <select id="account_type" name="account_type" required class="form-control">
                                <option value="">Select Account Type</option>
                                <option value="Business" <?php echo $accountType === 'Business' ? 'selected' : ''; ?>>
                                    🏢 Business Permit
                                </option>
                                <option value="Property" <?php echo $accountType === 'Property' ? 'selected' : ''; ?>>
                                    🏠 Property Rates
                                </option>
                            </select>
                            <small class="form-help">
                                <i class="fas fa-info-circle"></i>
                                Choose the type of account you're looking for
                            </small>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-search"></i>
                        Search My Bill
                    </button>
                </form>
                
                <div class="search-tips">
                    <h4>💡 Search Tips</h4>
                    <ul>
                        <li><strong>Account Number:</strong> Usually starts with BIZ (Business) or PROP (Property)</li>
                        <li><strong>Case Sensitive:</strong> Enter your account number exactly as shown on your bill</li>
                        <li><strong>Outstanding Balance:</strong> View real-time remaining balance for <?php echo date('Y'); ?></li>
                        <li><strong>Need Help?</strong> Contact us if you can't find your account number</li>
                    </ul>
                </div>
            </div>
        </div>
        
        <?php else: ?>
        <!-- Search Results Section -->
        <?php if ($searchResults): ?>
        <div class="results-section">
            
            <!-- Only show balance alert if bills exist -->
            <?php if ($searchResults['has_bills']): ?>
                <!-- Outstanding Balance Alert -->
                <?php if ($searchResults['account']['remaining_balance'] > 0): ?>
                <div class="balance-alert balance-outstanding">
                    <div class="alert-icon">⚠️</div>
                    <div class="alert-content">
                        <h3><?php echo $searchResults['account']['current_bill_year']; ?> Outstanding Balance</h3>
                        <div class="alert-amount">₵ <?php echo number_format($searchResults['account']['remaining_balance'], 2); ?></div>
                        <p>Current year bill balance that needs to be paid</p>
                    </div>
                    <div class="alert-action">
                        <a href="#bills-section" class="btn btn-warning btn-pulse">
                            <i class="fas fa-credit-card"></i>
                            Pay Now
                        </a>
                    </div>
                </div>
                <?php else: ?>
                <div class="balance-alert balance-cleared">
                    <div class="alert-icon">✅</div>
                    <div class="alert-content">
                        <h3><?php echo $searchResults['account']['current_bill_year']; ?> Bill Fully Paid</h3>
                        <div class="alert-amount">₵ 0.00</div>
                        <p>Congratulations! Your <?php echo $searchResults['account']['current_bill_year']; ?> bill has been fully settled</p>
                    </div>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <!-- No Bills Alert -->
                <div class="balance-alert no-bills-alert">
                    <div class="alert-icon">📋</div>
                    <div class="alert-content">
                        <h3>Account Registered</h3>
                        <p>Your account is registered but no bills have been generated for <?php echo date('Y'); ?> yet</p>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Account Information -->
            <div class="account-info-card">
                <div class="account-header">
                    <div class="account-icon">
                        <?php echo $searchResults['account_type'] === 'Business' ? '🏢' : '🏠'; ?>
                    </div>
                    <div class="account-details">
                        <h2><?php echo htmlspecialchars($searchResults['account']['name']); ?></h2>
                        <p class="account-number">Account: <?php echo htmlspecialchars($searchResults['account']['account_number']); ?></p>
                        <div class="account-meta">
                            <span class="meta-item">
                                <i class="fas fa-user"></i>
                                <?php echo htmlspecialchars($searchResults['account']['owner_name']); ?>
                            </span>
                            <?php if (!empty($searchResults['account']['telephone'])): ?>
                            <span class="meta-item">
                                <i class="fas fa-phone"></i>
                                <?php echo htmlspecialchars($searchResults['account']['telephone']); ?>
                            </span>
                            <?php endif; ?>
                            <span class="meta-item">
                                <i class="fas fa-map-marker-alt"></i>
                                <?php echo htmlspecialchars($searchResults['account']['zone_name'] ?? 'N/A'); ?>
                            </span>
                            <?php if ($searchResults['account_type'] === 'Business'): ?>
                            <span class="meta-item">
                                <i class="fas fa-building"></i>
                                <?php echo htmlspecialchars($searchResults['account']['type']); ?>
                            </span>
                            <span class="meta-item">
                                <i class="fas fa-tag"></i>
                                <?php echo htmlspecialchars($searchResults['account']['category']); ?>
                            </span>
                            <?php else: ?>
                            <span class="meta-item">
                                <i class="fas fa-home"></i>
                                <?php echo htmlspecialchars($searchResults['account']['type']); ?> (<?php echo htmlspecialchars($searchResults['account']['category']); ?>)
                            </span>
                            <span class="meta-item">
                                <i class="fas fa-door-closed"></i>
                                <?php echo $searchResults['account']['number_of_rooms']; ?> rooms
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="account-status">
                        <?php if ($searchResults['has_bills']): ?>
                        <span class="status-badge <?php echo $searchResults['account']['remaining_balance'] > 0 ? 'outstanding' : 'paid'; ?>">
                            <?php echo $searchResults['account']['remaining_balance'] > 0 ? 'Outstanding' : 'Paid Up'; ?>
                        </span>
                        <?php else: ?>
                        <span class="status-badge active">
                            Registered
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Only show account summary if bills exist -->
                <?php if ($searchResults['has_bills']): ?>
                <div class="account-summary">
                    <div class="summary-item">
                        <div class="summary-label"><?php echo $searchResults['account']['current_bill_year']; ?> Bill Amount</div>
                        <div class="summary-value">
                            ₵ <?php echo number_format($searchResults['account']['original_bill_amount'], 2); ?>
                        </div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Amount Paid</div>
                        <div class="summary-value paid">
                            ₵ <?php echo number_format($searchResults['account']['bill_payments'], 2); ?>
                        </div>
                    </div>
                    <div class="summary-item highlight">
                        <div class="summary-label">Outstanding Balance</div>
                        <div class="summary-value <?php echo $searchResults['account']['remaining_balance'] > 0 ? 'outstanding' : 'cleared'; ?>">
                            ₵ <?php echo number_format($searchResults['account']['remaining_balance'], 2); ?>
                        </div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Payment Progress</div>
                        <div class="summary-progress">
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo min($searchResults['account']['payment_percentage'], 100); ?>%"></div>
                            </div>
                            <div class="progress-text"><?php echo number_format($searchResults['account']['payment_percentage'], 1); ?>%</div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <!-- Basic account info when no bills exist -->
                <div class="account-basic-info">
                    <div class="basic-info-grid">
                        <div class="info-item">
                            <div class="info-label">Registration Date</div>
                            <div class="info-value">
                                <i class="fas fa-calendar-plus"></i>
                                <?php echo date('M d, Y', strtotime($searchResults['account']['created_at'])); ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Account Status</div>
                            <div class="info-value">
                                <i class="fas fa-check-circle"></i>
                                Active & Registered
                            </div>
                        </div>
                        <?php if ($searchResults['account_type'] === 'Business'): ?>
                        <div class="info-item">
                            <div class="info-label">Business Type</div>
                            <div class="info-value">
                                <i class="fas fa-briefcase"></i>
                                <?php echo htmlspecialchars($searchResults['account']['type']); ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Category</div>
                            <div class="info-value">
                                <i class="fas fa-layer-group"></i>
                                <?php echo htmlspecialchars($searchResults['account']['category']); ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="info-item">
                            <div class="info-label">Property Structure</div>
                            <div class="info-value">
                                <i class="fas fa-building"></i>
                                <?php echo htmlspecialchars($searchResults['account']['type']); ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Usage Type</div>
                            <div class="info-value">
                                <i class="fas fa-home"></i>
                                <?php echo htmlspecialchars($searchResults['account']['category']); ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Number of Rooms</div>
                            <div class="info-value">
                                <i class="fas fa-door-closed"></i>
                                <?php echo $searchResults['account']['number_of_rooms']; ?> rooms
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="info-item">
                            <div class="info-label">Location</div>
                            <div class="info-value location-text">
                                <i class="fas fa-map-marker-alt"></i>
                                <?php echo htmlspecialchars($searchResults['account']['location'] ?? 'Not specified'); ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Bills Section - Only show if bills exist -->
            <?php if ($searchResults['has_bills']): ?>
            <div class="bills-section" id="bills-section">
                <div class="bills-header">
                    <h3>📄 Available Bills (<?php echo $searchResults['account']['current_bill_year']; ?>)</h3>
                    <p>Bills available for online payment</p>
                </div>
                
                <div class="bills-grid">
                    <?php foreach ($searchResults['bills'] as $bill): ?>
                    <div class="bill-card <?php echo $searchResults['account']['remaining_balance'] > 0 ? 'has-balance' : 'fully-paid'; ?>">
                        <div class="bill-header">
                            <div class="bill-number">
                                <i class="fas fa-file-invoice"></i>
                                <?php echo htmlspecialchars($bill['bill_number']); ?>
                            </div>
                            <div class="bill-status">
                                <?php if ($searchResults['account']['remaining_balance'] > 0): ?>
                                <span class="status-badge outstanding">
                                    ₵ <?php echo number_format($searchResults['account']['remaining_balance'], 2); ?> Due
                                </span>
                                <?php else: ?>
                                <span class="status-badge paid">
                                    Fully Paid
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="bill-details">
                            <div class="bill-year">
                                <i class="fas fa-calendar"></i>
                                Billing Year: <?php echo $bill['billing_year']; ?>
                            </div>
                            
                            <div class="bill-amounts">
                                <?php if ($bill['old_bill'] > 0): ?>
                                <div class="amount-row">
                                    <span>Previous Bill:</span>
                                    <span>₵ <?php echo number_format($bill['old_bill'], 2); ?></span>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($bill['previous_payments'] > 0): ?>
                                <div class="amount-row">
                                    <span>Previous Payments:</span>
                                    <span class="credit">-₵ <?php echo number_format($bill['previous_payments'], 2); ?></span>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($bill['arrears'] > 0): ?>
                                <div class="amount-row">
                                    <span>Arrears:</span>
                                    <span class="arrears">₵ <?php echo number_format($bill['arrears'], 2); ?></span>
                                </div>
                                <?php endif; ?>
                                
                                <div class="amount-row">
                                    <span>Current Bill:</span>
                                    <span>₵ <?php echo number_format($bill['current_bill'], 2); ?></span>
                                </div>
                                
                                <?php if ($searchResults['account']['bill_payments'] > 0): ?>
                                <div class="amount-row paid-amount">
                                    <span><strong>Amount Paid:</strong></span>
                                    <span class="paid"><strong>₵ <?php echo number_format($searchResults['account']['bill_payments'], 2); ?></strong></span>
                                </div>
                                <?php endif; ?>
                                
                                <div class="amount-row balance <?php echo $searchResults['account']['remaining_balance'] > 0 ? 'outstanding' : 'cleared'; ?>">
                                    <span><strong>Outstanding Balance:</strong></span>
                                    <span class="balance-amount"><strong>₵ <?php echo number_format($searchResults['account']['remaining_balance'], 2); ?></strong></span>
                                </div>
                            </div>
                            
                            <?php if ($searchResults['account']['payment_percentage'] > 0 && $searchResults['account']['payment_percentage'] < 100): ?>
                            <div class="payment-progress">
                                <div class="progress-label">Payment Progress</div>
                                <div class="progress-bar-small">
                                    <div class="progress-fill" style="width: <?php echo $searchResults['account']['payment_percentage']; ?>%"></div>
                                </div>
                                <div class="progress-percentage"><?php echo number_format($searchResults['account']['payment_percentage'], 1); ?>%</div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="bill-actions">
                            <a href="view_bill.php?bill_id=<?php echo $bill['bill_id']; ?>" class="btn btn-outline">
                                <i class="fas fa-eye"></i>
                                View Details
                            </a>
                            
                            <?php if ($searchResults['account']['remaining_balance'] > 0): ?>
                            <a href="pay_bill.php?bill_id=<?php echo $bill['bill_id']; ?>" class="btn btn-primary">
                                <i class="fas fa-credit-card"></i>
                                Pay ₵ <?php echo number_format($searchResults['account']['remaining_balance'], 2); ?>
                            </a>
                            <?php else: ?>
                            <span class="btn btn-success btn-disabled">
                                <i class="fas fa-check"></i>
                                Fully Paid
                            </span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="bill-meta">
                            <small>
                                <i class="fas fa-clock"></i>
                                Generated: <?php echo formatDate($bill['generated_at']); ?>
                            </small>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <!-- No Bills Information Section -->
            <div class="no-bills-info-section">
                <div class="no-bills-info-card">
                    <div class="no-bills-icon">📋</div>
                    <h3>No Bills Generated Yet</h3>
                    <p>Your account is registered and active, but no bills have been generated for the current year (<?php echo date('Y'); ?>).</p>
                    
                    <div class="billing-info">
                        <h4>📅 When are bills generated?</h4>
                        <ul>
                            <li>Bills are typically generated on <strong>November 1st</strong> each year</li>
                            <li>You will receive an SMS notification when your bill is ready</li>
                            <li>Bills can be viewed and paid online through this portal</li>
                        </ul>
                    </div>
                    
                    <div class="contact-info">
                        <h4>📞 Need Help?</h4>
                        <p>If you believe your bill should have been generated or have questions about your account, please contact our office.</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Recent Payment History - Only show if payments exist -->
            <?php if (!empty($searchResults['payment_history'])): ?>
            <div class="payment-history-section">
                <div class="section-header">
                    <h3>💳 Recent Payment History</h3>
                    <p>Your latest payment transactions</p>
                </div>
                
                <div class="payment-history-cards">
                    <?php foreach ($searchResults['payment_history'] as $payment): ?>
                    <div class="payment-card">
                        <div class="payment-header">
                            <div class="payment-date">
                                <i class="fas fa-calendar"></i>
                                <?php echo date('M d, Y', strtotime($payment['payment_date'])); ?>
                            </div>
                            <div class="payment-status">
                                <span class="status-badge <?php echo strtolower($payment['payment_status']); ?>">
                                    <?php echo htmlspecialchars($payment['payment_status']); ?>
                                </span>
                            </div>
                        </div>
                        <div class="payment-details">
                            <div class="payment-amount">₵ <?php echo number_format($payment['amount_paid'], 2); ?></div>
                            <div class="payment-method">
                                <i class="fas fa-credit-card"></i>
                                <?php echo htmlspecialchars($payment['payment_method']); ?>
                            </div>
                            <div class="payment-bill">
                                Bill: <?php echo htmlspecialchars($payment['bill_number']); ?>
                                (<?php echo $payment['billing_year']; ?>)
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Action Buttons -->
            <div class="action-buttons">
                <a href="search_bill.php" class="btn btn-outline">
                    <i class="fas fa-search"></i>
                    Search Another Account
                </a>
                
                <?php if ($searchResults['has_bills']): ?>
                <a href="verify_payment.php" class="btn btn-secondary">
                    <i class="fas fa-check-circle"></i>
                    Verify Payment
                </a>
                
                <?php if ($searchResults['account']['remaining_balance'] > 0): ?>
                <a href="#bills-section" class="btn btn-warning btn-scroll">
                    <i class="fas fa-credit-card"></i>
                    Make Payment
                </a>
                <?php endif; ?>
                <?php else: ?>
                <a href="#help" class="btn btn-secondary" onclick="scrollToHelp()">
                    <i class="fas fa-question-circle"></i>
                    Get Help
                </a>
                <?php endif; ?>
            </div>
        </div>
        
        <?php else: ?>
        <!-- No Results Found -->
        <div class="no-results-section">
            <div class="no-results-content">
                <div class="no-results-icon">🔍</div>
                <h2>Account Not Found</h2>
                <p><?php echo htmlspecialchars($errorMessage); ?></p>
                
                <div class="suggestions">
                    <h4>💡 Suggestions:</h4>
                    <ul>
                        <li>Double-check your account number for typos</li>
                        <li>Make sure you selected the correct account type</li>
                        <li>Contact our office if you need help finding your account number</li>
                        <li>Account numbers are case-sensitive</li>
                    </ul>
                </div>
                
                <div class="retry-actions">
                    <a href="search_bill.php" class="btn btn-primary">
                        <i class="fas fa-redo"></i>
                        Try Again
                    </a>
                    
                    <a href="#help" class="btn btn-outline" onclick="scrollToHelp()">
                        <i class="fas fa-question-circle"></i>
                        Get Help
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<style>
/* All existing styles remain exactly the same - no changes to CSS */
/* Search Page Styles */
.search-page {
    min-height: 600px;
    padding: 40px 0;
}

.container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 20px;
}

/* Search Section */
.search-section {
    max-width: 600px;
    margin: 0 auto;
}

.search-header {
    text-align: center;
    margin-bottom: 40px;
}

.search-header h1 {
    font-size: 2.5rem;
    font-weight: bold;
    color: #2d3748;
    margin-bottom: 10px;
}

.search-header p {
    color: #718096;
    font-size: 1.1rem;
}

.search-form-container {
    background: white;
    border-radius: 15px;
    padding: 40px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
    margin-bottom: 30px;
}

.form-row {
    display: grid;
    gap: 20px;
    margin-bottom: 25px;
}

.form-group {
    margin-bottom: 20px;
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
    padding: 12px 15px;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    font-size: 16px;
    transition: all 0.3s;
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
    margin-top: 5px;
    font-size: 0.85rem;
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

.balance-alert.no-bills-alert {
    border-left: 5px solid #667eea;
    background: linear-gradient(135deg, #f0f9ff 0%, #dbeafe 100%);
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

.btn-success {
    background: #10b981;
    color: white;
}

.btn-success:hover {
    background: #059669;
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

.btn-disabled {
    background: #e2e8f0;
    color: #a0aec0;
    cursor: not-allowed;
}

.btn-lg {
    width: 100%;
    padding: 15px 24px;
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

.search-tips {
    background: #f0f9ff;
    border: 1px solid #bae6fd;
    border-radius: 10px;
    padding: 20px;
    margin-top: 30px;
}

.search-tips h4 {
    color: #0369a1;
    margin-bottom: 15px;
    font-size: 1.1rem;
}

.search-tips ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

.search-tips li {
    margin-bottom: 8px;
    color: #0369a1;
    font-size: 0.9rem;
}

/* Results Section */
.results-section {
    animation: slideUp 0.6s ease;
}

.account-info-card {
    background: white;
    border-radius: 15px;
    padding: 30px;
    margin-bottom: 30px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
    border-left: 5px solid #667eea;
}

.account-header {
    display: flex;
    align-items: flex-start;
    gap: 20px;
    margin-bottom: 25px;
}

.account-icon {
    font-size: 3rem;
    background: #f0f9ff;
    padding: 15px;
    border-radius: 15px;
    border: 2px solid #bae6fd;
}

.account-details {
    flex: 1;
}

.account-details h2 {
    color: #2d3748;
    margin-bottom: 5px;
    font-size: 1.5rem;
}

.account-number {
    color: #667eea;
    font-weight: 600;
    margin-bottom: 15px;
    font-size: 1.1rem;
}

.account-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
}

.meta-item {
    display: flex;
    align-items: center;
    gap: 5px;
    color: #4a5568;
    font-size: 0.9rem;
}

.meta-item i {
    color: #667eea;
    width: 16px;
}

.account-status {
    flex-shrink: 0;
}

.status-badge {
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-badge.outstanding {
    background: #fef3c7;
    color: #92400e;
}

.status-badge.paid {
    background: #d1fae5;
    color: #065f46;
}

.status-badge.active {
    background: #c6f6d5;
    color: #22543d;
}

.status-badge.pending {
    background: #fed7d7;
    color: #c53030;
}

.status-badge.partially {
    background: #feebc8;
    color: #c05621;
}

.status-badge.successful {
    background: #d1fae5;
    color: #065f46;
}

.account-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    padding-top: 20px;
    border-top: 1px solid #e2e8f0;
}

.summary-item {
    padding: 20px;
    background: #f7fafc;
    border-radius: 12px;
    text-align: center;
}

.summary-item.highlight {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    border: 2px solid #f59e0b;
}

.summary-label {
    color: #718096;
    font-size: 0.9rem;
    margin-bottom: 8px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.summary-value {
    font-weight: bold;
    color: #2d3748;
    font-size: 1.3rem;
}

.summary-value.outstanding {
    color: #d69e2e;
    font-size: 1.5rem;
}

.summary-value.cleared {
    color: #10b981;
    font-size: 1.5rem;
}

.summary-value.paid {
    color: #10b981;
}

.summary-progress {
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

/* Account Basic Info (when no bills exist) */
.account-basic-info {
    padding-top: 20px;
    border-top: 1px solid #e2e8f0;
}

.basic-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.info-item {
    padding: 20px;
    background: #f7fafc;
    border-radius: 12px;
    text-align: center;
}

.info-label {
    color: #718096;
    font-size: 0.85rem;
    margin-bottom: 8px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.info-value {
    color: #2d3748;
    font-weight: 600;
    font-size: 1rem;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.info-value i {
    color: #667eea;
}

.location-text {
    font-size: 0.9rem;
    text-align: left;
    justify-content: flex-start;
}

/* No Bills Info Section */
.no-bills-info-section {
    margin-bottom: 30px;
}

.no-bills-info-card {
    background: white;
    border-radius: 15px;
    padding: 40px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
    text-align: center;
    border: 2px solid #bae6fd;
}

.no-bills-icon {
    font-size: 4rem;
    margin-bottom: 20px;
}

.no-bills-info-card h3 {
    color: #2d3748;
    margin-bottom: 15px;
    font-size: 1.5rem;
}

.no-bills-info-card > p {
    color: #4a5568;
    margin-bottom: 30px;
    font-size: 1.1rem;
}

.billing-info,
.contact-info {
    background: #f0f9ff;
    border-radius: 10px;
    padding: 25px;
    margin-bottom: 20px;
    text-align: left;
}

.billing-info h4,
.contact-info h4 {
    color: #0369a1;
    margin-bottom: 15px;
    font-size: 1.1rem;
}

.billing-info ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

.billing-info li {
    color: #0369a1;
    margin-bottom: 8px;
    padding-left: 20px;
    position: relative;
    font-size: 0.95rem;
}

.billing-info li:before {
    content: "•";
    position: absolute;
    left: 0;
    color: #0369a1;
    font-weight: bold;
}

.contact-info p {
    color: #0369a1;
    margin: 0;
    font-size: 0.95rem;
}

/* Bills Section */
.bills-section {
    margin-bottom: 30px;
}

.bills-header {
    margin-bottom: 25px;
}

.bills-header h3 {
    color: #2d3748;
    margin-bottom: 5px;
    font-size: 1.5rem;
}

.bills-header p {
    color: #718096;
}

.bills-grid {
    display: grid;
    gap: 20px;
}

.bill-card {
    background: white;
    border-radius: 15px;
    padding: 25px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    border: 2px solid #e2e8f0;
    transition: all 0.3s;
}

.bill-card.has-balance {
    border-color: #f59e0b;
    background: linear-gradient(135deg, #fffbf5 0%, #ffffff 100%);
}

.bill-card.fully-paid {
    border-color: #10b981;
    background: linear-gradient(135deg, #f0fdf4 0%, #ffffff 100%);
}

.bill-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
}

.bill-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.bill-number {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    color: #2d3748;
}

.bill-number i {
    color: #667eea;
}

.bill-details {
    margin-bottom: 20px;
}

.bill-year {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #4a5568;
    margin-bottom: 15px;
    font-size: 0.9rem;
}

.bill-year i {
    color: #667eea;
}

.bill-amounts {
    background: #f7fafc;
    padding: 18px;
    border-radius: 10px;
    margin-bottom: 15px;
}

.amount-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 10px;
    font-size: 0.95rem;
}

.amount-row:last-child {
    margin-bottom: 0;
}

.amount-row.total {
    padding-top: 12px;
    border-top: 2px solid #e2e8f0;
    font-size: 1rem;
}

.amount-row.paid-amount {
    border-top: 1px solid #d1fae5;
    padding-top: 8px;
}

.amount-row.balance {
    border-top: 2px solid #e2e8f0;
    padding-top: 12px;
    font-size: 1.1rem;
}

.amount-row.balance.outstanding {
    background: #fef3c7;
    margin: 8px -18px 0;
    padding: 12px 18px;
    border-radius: 8px;
}

.amount-row.balance.cleared {
    background: #d1fae5;
    margin: 8px -18px 0;
    padding: 12px 18px;
    border-radius: 8px;
}

.amount-row .credit {
    color: #10b981;
    font-weight: 600;
}

.amount-row .arrears {
    color: #e53e3e;
    font-weight: 600;
}

.amount-row .paid {
    color: #10b981;
    font-weight: 600;
}

.balance-amount {
    font-weight: bold;
}

.outstanding .balance-amount {
    color: #d69e2e;
}

.cleared .balance-amount {
    color: #10b981;
}

.payment-progress {
    background: #f1f5f9;
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 15px;
}

.progress-label {
    font-size: 0.85rem;
    color: #4a5568;
    margin-bottom: 8px;
    font-weight: 600;
}

.progress-bar-small {
    height: 8px;
    background: #e2e8f0;
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 5px;
}

.progress-percentage {
    font-size: 0.8rem;
    color: #4a5568;
    text-align: right;
    font-weight: 600;
}

.bill-actions {
    display: flex;
    gap: 12px;
    margin-bottom: 15px;
}

.bill-actions .btn {
    flex: 1;
    justify-content: center;
}

.bill-meta {
    text-align: center;
    padding-top: 15px;
    border-top: 1px solid #e2e8f0;
}

.bill-meta small {
    color: #718096;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
}

/* Payment History Section */
.payment-history-section {
    margin-bottom: 30px;
}

.section-header {
    margin-bottom: 25px;
}

.section-header h3 {
    color: #2d3748;
    margin-bottom: 5px;
    font-size: 1.5rem;
}

.section-header p {
    color: #718096;
}

.payment-history-cards {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 15px;
}

.payment-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
    border: 1px solid #e2e8f0;
    transition: all 0.3s;
}

.payment-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.15);
}

.payment-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.payment-date {
    display: flex;
    align-items: center;
    gap: 6px;
    color: #4a5568;
    font-size: 0.9rem;
    font-weight: 600;
}

.payment-date i {
    color: #667eea;
}

.payment-details {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.payment-amount {
    font-size: 1.4rem;
    font-weight: bold;
    color: #10b981;
}

.payment-method,
.payment-bill {
    display: flex;
    align-items: center;
    gap: 6px;
    color: #4a5568;
    font-size: 0.85rem;
}

.payment-method i {
    color: #667eea;
}

/* No Results Section */
.no-results-section {
    text-align: center;
    padding: 60px 20px;
    animation: slideUp 0.6s ease;
}

.no-results-content {
    max-width: 500px;
    margin: 0 auto;
    background: white;
    padding: 40px;
    border-radius: 15px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
}

.no-results-icon {
    font-size: 4rem;
    margin-bottom: 20px;
}

.no-results-content h2 {
    color: #2d3748;
    margin-bottom: 15px;
}

.no-results-content p {
    color: #4a5568;
    margin-bottom: 25px;
}

.suggestions {
    text-align: left;
    margin-bottom: 30px;
    padding: 20px;
    background: #f0f9ff;
    border-radius: 10px;
    border: 1px solid #bae6fd;
}

.suggestions h4 {
    color: #0369a1;
    margin-bottom: 15px;
}

.suggestions ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

.suggestions li {
    margin-bottom: 8px;
    color: #0369a1;
    font-size: 0.9rem;
    padding-left: 15px;
    position: relative;
}

.suggestions li:before {
    content: "•";
    position: absolute;
    left: 0;
    color: #0369a1;
}

.retry-actions {
    display: flex;
    gap: 15px;
    justify-content: center;
}

.action-buttons {
    display: flex;
    gap: 15px;
    justify-content: center;
    margin-top: 30px;
    flex-wrap: wrap;
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
    .search-form-container {
        padding: 25px;
    }
    
    .balance-alert {
        flex-direction: column;
        text-align: center;
        gap: 15px;
    }
    
    .account-header {
        flex-direction: column;
        text-align: center;
    }
    
    .account-meta {
        flex-direction: column;
        gap: 10px;
    }
    
    .account-summary,
    .basic-info-grid {
        grid-template-columns: 1fr;
    }
    
    .bill-actions {
        flex-direction: column;
    }
    
    .action-buttons,
    .retry-actions {
        flex-direction: column;
    }
    
    .search-header h1 {
        font-size: 2rem;
    }
    
    .alert-amount {
        font-size: 2rem;
    }
    
    .payment-history-cards {
        grid-template-columns: 1fr;
    }
    
    .summary-progress {
        flex-direction: column;
        gap: 8px;
    }
}
</style>

<script>
// All JavaScript remains exactly the same - no changes needed
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('searchForm');
    const accountInput = document.getElementById('account_number');
    const typeSelect = document.getElementById('account_type');
    
    // Auto-detect account type based on account number
    if (accountInput && typeSelect) {
        accountInput.addEventListener('input', function() {
            const value = this.value.toUpperCase();
            
            if (value.startsWith('BIZ')) {
                typeSelect.value = 'Business';
            } else if (value.startsWith('PROP')) {
                typeSelect.value = 'Property';
            }
        });
    }
    
    // Form submission with loading state
    if (form) {
        form.addEventListener('submit', function(e) {
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Searching...';
            submitBtn.disabled = true;
            
            // Show loading overlay
            showLoading('Searching for your account...');
            
            // Re-enable button after 15 seconds (in case of issues)
            setTimeout(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
                hideLoading();
            }, 15000);
        });
    }
    
    // Add hover effects to bill cards
    const billCards = document.querySelectorAll('.bill-card');
    billCards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            const hasBalance = this.classList.contains('has-balance');
            this.style.borderColor = hasBalance ? '#d97706' : '#059669';
        });
        
        card.addEventListener('mouseleave', function() {
            const hasBalance = this.classList.contains('has-balance');
            this.style.borderColor = hasBalance ? '#f59e0b' : '#10b981';
        });
    });
    
    // Smooth scrolling for internal links
    const scrollLinks = document.querySelectorAll('.btn-scroll');
    scrollLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({ 
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });
    
    // Outstanding balance notification - only if bills exist
    const balanceAlert = document.querySelector('.balance-alert.balance-outstanding');
    if (balanceAlert) {
        // Add animation class after a delay
        setTimeout(() => {
            balanceAlert.style.animation = 'slideUp 0.6s ease, pulse 2s infinite 1s';
        }, 1000);
    }
    
    // Auto-focus on account number input
    if (accountInput && !accountInput.value) {
        accountInput.focus();
    }
    
    // Initialize progress bar animations
    initializeProgressBars();
    
    // Add copy functionality to account numbers
    addCopyFunctionality();
    
    // Handle no bills scenario
    handleNoBillsScenario();
});

function initializeProgressBars() {
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
}

function addCopyFunctionality() {
    const accountNumbers = document.querySelectorAll('.account-number');
    accountNumbers.forEach(element => {
        element.style.cursor = 'pointer';
        element.title = 'Click to copy account number';
        
        element.addEventListener('click', function() {
            const accountText = this.textContent.replace('Account: ', '');
            navigator.clipboard.writeText(accountText).then(() => {
                showNotification('Account number copied to clipboard!', 'success');
            });
        });
    });
}

function handleNoBillsScenario() {
    const noBillsAlert = document.querySelector('.no-bills-alert');
    const noBillsInfo = document.querySelector('.no-bills-info-section');
    
    if (noBillsAlert && noBillsInfo) {
        // Add special styling for no bills scenario
        noBillsAlert.style.animation = 'slideUp 0.6s ease';
        noBillsInfo.style.animation = 'slideUp 0.8s ease';
        
        // Show helpful information
        console.log('Account found but no bills generated - showing informational content');
    }
}

function scrollToHelp() {
    // Implement scroll to help section functionality
    window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
}

// Loading overlay functions
function showLoading(message) {
    // Remove existing loading overlay
    const existingOverlay = document.getElementById('loadingOverlay');
    if (existingOverlay) {
        existingOverlay.remove();
    }
    
    const overlay = document.createElement('div');
    overlay.id = 'loadingOverlay';
    overlay.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.8);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 10000;
        color: white;
        font-size: 1.2rem;
        flex-direction: column;
        gap: 20px;
    `;
    
    overlay.innerHTML = `
        <div style="font-size: 3rem;">
            <i class="fas fa-spinner fa-spin"></i>
        </div>
        <div>${message}</div>
        <div style="font-size: 0.9rem; opacity: 0.8;">
            This may take a few moments...
        </div>
    `;
    
    document.body.appendChild(overlay);
}

function hideLoading() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) {
        overlay.style.opacity = '0';
        setTimeout(() => {
            overlay.remove();
        }, 300);
    }
}

// Notification system
function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${type === 'success' ? '#10b981' : type === 'error' ? '#e53e3e' : '#667eea'};
        color: white;
        padding: 15px 20px;
        border-radius: 10px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        z-index: 10001;
        animation: slideInRight 0.3s ease, slideOutRight 0.3s ease 2.7s forwards;
        font-weight: 600;
        max-width: 300px;
    `;
    
    notification.textContent = message;
    document.body.appendChild(notification);
    
    // Add animations if not already present
    if (!document.getElementById('notificationAnimations')) {
        const style = document.createElement('style');
        style.id = 'notificationAnimations';
        style.textContent = `
            @keyframes slideInRight { 
                from { transform: translateX(100%); opacity: 0; } 
                to { transform: translateX(0); opacity: 1; } 
            }
            @keyframes slideOutRight { 
                from { transform: translateX(0); opacity: 1; } 
                to { transform: translateX(100%); opacity: 0; } 
            }
        `;
        document.head.appendChild(style);
    }
    
    setTimeout(() => {
        if (notification.parentNode) {
            notification.remove();
        }
    }, 3000);
}

// Enhanced balance checking for accounts with bills
function checkBalanceUpdates() {
    // This could be extended to periodically check for balance updates
    const outstandingElements = document.querySelectorAll('.summary-value.outstanding, .balance-amount');
    
    outstandingElements.forEach(element => {
        const amount = parseFloat(element.textContent.replace(/[₵,]/g, ''));
        if (amount > 0) {
            element.style.animation = 'pulse 3s infinite';
        }
    });
}

// Initialize balance checking only if bills exist
const hasBills = document.querySelector('.bills-section');
if (hasBills) {
    setTimeout(checkBalanceUpdates, 2000);
}

console.log('✅ FIXED: Bill search page with correct balance calculation from current year bill initialized successfully');
</script>

<?php include 'footer.php'; ?>