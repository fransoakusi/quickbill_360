<?php
/**
 * Payment Recording Page for QUICKBILL 305
 * Revenue Officer interface for recording payments - FIXED VERSION
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

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: ../../auth/login.php');
    exit();
}

// Check if user is revenue officer or admin
$currentUser = getCurrentUser();
if (!isRevenueOfficer() && !isAdmin()) {
    setFlashMessage('error', 'Access denied. Revenue Officer privileges required.');
    header('Location: ../../auth/login.php');
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

$userDisplayName = getUserDisplayName($currentUser);

// Initialize variables
$searchTerm = '';
$searchResults = [];
$selectedAccount = null;
$currentBill = null;
$remainingBalance = 0;
$totalPaid = 0;
$paymentSummary = null;
$error = '';
$success = '';
$paymentProcessed = false;

// Database connection
try {
    $db = new Database();
} catch (Exception $e) {
    $error = 'Database connection failed. Please try again.';
}

// Handle search request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'search') {
    if (!verifyCsrfToken()) {
        $error = 'Security validation failed. Please try again.';
    } else {
        $searchTerm = sanitizeInput($_POST['search_term'] ?? '');
        
        if (empty($searchTerm)) {
            $error = 'Please enter an account number, business/property name, or phone number to search.';
        } else {
            try {
                // Search in businesses
                $businessQuery = "
                    SELECT 'business' as type, business_id as id, account_number, business_name as name, 
                           owner_name, telephone, amount_payable, exact_location as location, status
                    FROM businesses 
                    WHERE (account_number LIKE ? OR business_name LIKE ? OR owner_name LIKE ? OR telephone LIKE ?)
                    AND status = 'Active'
                    ORDER BY business_name
                ";
                
                $searchPattern = "%{$searchTerm}%";
                $businesses = $db->fetchAll($businessQuery, [$searchPattern, $searchPattern, $searchPattern, $searchPattern]);
                
                // Search in properties
                $propertyQuery = "
                    SELECT 'property' as type, property_id as id, property_number as account_number, 
                           owner_name as name, owner_name, telephone, amount_payable, location, 'Active' as status
                    FROM properties 
                    WHERE (property_number LIKE ? OR account_number LIKE ? OR owner_name LIKE ? OR telephone LIKE ?)
                    ORDER BY owner_name
                ";
                
                $properties = $db->fetchAll($propertyQuery, [$searchPattern, $searchPattern, $searchPattern, $searchPattern]);
                
                // Safely combine results
                $searchResults = [];
                if ($businesses !== false && is_array($businesses)) {
                    $searchResults = array_merge($searchResults, $businesses);
                }
                if ($properties !== false && is_array($properties)) {
                    $searchResults = array_merge($searchResults, $properties);
                }
                
                if (empty($searchResults)) {
                    $error = 'No accounts found matching your search criteria.';
                }
                
            } catch (Exception $e) {
                $error = 'Search failed. Please try again.';
            }
        }
    }
}

// Handle account selection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'select_account') {
    if (!verifyCsrfToken()) {
        $error = 'Security validation failed. Please try again.';
    } else {
        $accountType = sanitizeInput($_POST['account_type'] ?? '');
        $accountId = intval($_POST['account_id'] ?? 0);
        
        if ($accountType && $accountId) {
            try {
                if ($accountType === 'business') {
                    $selectedAccount = $db->fetchRow("
                        SELECT 'business' as type, business_id as id, account_number, business_name as name,
                               owner_name, telephone, old_bill, previous_payments, arrears, current_bill, 
                               amount_payable, exact_location as location, business_type, category
                        FROM businesses WHERE business_id = ? AND status = 'Active'
                    ", [$accountId]);
                } else {
                    $selectedAccount = $db->fetchRow("
                        SELECT 'property' as type, property_id as id, property_number as account_number, 
                               owner_name as name, owner_name, telephone, old_bill, previous_payments, 
                               arrears, current_bill, amount_payable, location, structure, property_use, number_of_rooms
                        FROM properties WHERE property_id = ?
                    ", [$accountId]);
                }
                
                if ($selectedAccount) {
                    // Get current year bill first
                    $currentYear = date('Y');
                    $currentBill = $db->fetchRow("
                        SELECT * FROM bills 
                        WHERE bill_type = ? AND reference_id = ? AND billing_year = ?
                        ORDER BY generated_at DESC LIMIT 1
                    ", [ucfirst($accountType), $accountId, $currentYear]);
                    
                    if (!$currentBill) {
                        $error = 'No bill found for this account in the current year.';
                        $selectedAccount = null;
                    } else {
                        // FIX: Use the bill's amount_payable directly - this is the correct remaining balance for the current year
                        $remainingBalance = floatval($currentBill['amount_payable']);
                        
                        // Get payment summary for this account (all years)
                        $paymentSummary = $db->fetchRow("
                            SELECT 
                                COALESCE(SUM(CASE WHEN p.payment_status = 'Successful' THEN p.amount_paid ELSE 0 END), 0) as total_paid,
                                COUNT(CASE WHEN p.payment_status = 'Successful' THEN 1 END) as successful_payments,
                                COUNT(*) as total_transactions
                            FROM payments p
                            JOIN bills b ON p.bill_id = b.bill_id
                            WHERE b.bill_type = ? AND b.reference_id = ?
                        ", [ucfirst($accountType), $accountId]);
                        
                        $totalPaid = $paymentSummary['total_paid'] ?? 0;
                    }
                } else {
                    $error = 'Account not found or inactive.';
                }
                
            } catch (Exception $e) {
                $error = 'Failed to load account details. Please try again.';
            }
        }
    }
}

// Handle payment recording
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_payment') {
    if (!verifyCsrfToken()) {
        $error = 'Security validation failed. Please try again.';
    } else {
        $billId = intval($_POST['bill_id'] ?? 0);
        $amountPaid = floatval($_POST['amount_paid'] ?? 0);
        $paymentMethod = sanitizeInput($_POST['payment_method'] ?? '');
        $paymentChannel = sanitizeInput($_POST['payment_channel'] ?? '');
        $transactionId = sanitizeInput($_POST['transaction_id'] ?? '');
        $notes = sanitizeInput($_POST['notes'] ?? '');
        
        // Validation
        if ($billId <= 0) {
            $error = 'Invalid bill selected.';
        } elseif ($amountPaid <= 0) {
            $error = 'Please enter a valid payment amount.';
        } elseif (empty($paymentMethod)) {
            $error = 'Please select a payment method.';
        } else {
            try {
                // Get bill details
                $bill = $db->fetchRow("SELECT * FROM bills WHERE bill_id = ?", [$billId]);
                
                if (!$bill) {
                    $error = 'Bill not found.';
                } else {
                    // Re-calculate remaining balance to validate
                    $currentRemainingBalance = floatval($bill['amount_payable']);
                    
                    if ($amountPaid > $currentRemainingBalance && $currentRemainingBalance > 0) {
                        $error = 'Payment amount cannot exceed the remaining balance of ₵ ' . number_format($currentRemainingBalance, 2);
                    } else {
                        // Get selected account details for audit logging
                        if ($bill['bill_type'] === 'Business') {
                            $auditAccount = $db->fetchRow("
                                SELECT business_id as id, account_number, business_name as name, owner_name
                                FROM businesses WHERE business_id = ?
                            ", [$bill['reference_id']]);
                        } else {
                            $auditAccount = $db->fetchRow("
                                SELECT property_id as id, property_number as account_number, owner_name as name, owner_name
                                FROM properties WHERE property_id = ?
                            ", [$bill['reference_id']]);
                        }
                        
                        // Generate payment reference
                        $paymentReference = 'PAY' . date('Ymd') . strtoupper(substr(uniqid(), -6));
                        
                        try {
                            // Begin transaction for data consistency
                            $db->beginTransaction();
                            
                            // Insert payment record
                            $insertSql = "
                                INSERT INTO payments (
                                    payment_reference, bill_id, amount_paid, payment_method, 
                                    payment_channel, transaction_id, payment_status, 
                                    processed_by, notes, payment_date
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                            ";
                            
                            $stmt = $db->execute($insertSql, [
                                $paymentReference,
                                $billId,
                                $amountPaid,
                                $paymentMethod,
                                $paymentChannel,
                                $transactionId,
                                'Successful',
                                $currentUser['user_id'],
                                $notes
                            ]);
                            
                            if ($stmt) {
                                // Get the inserted payment ID
                                $paymentId = $db->lastInsertId();
                                
                                // Update bill status
                                $newAmountPayable = $bill['amount_payable'] - $amountPaid;
                                $newAmountPayable = max(0, $newAmountPayable); // Ensure non-negative
                                $billStatus = $newAmountPayable <= 0 ? 'Paid' : 'Partially Paid';
                                
                                $updateBillSql = "UPDATE bills SET amount_payable = ?, status = ? WHERE bill_id = ?";
                                $db->execute($updateBillSql, [$newAmountPayable, $billStatus, $billId]);
                                
                                // Update account payable amount
                                if ($bill['bill_type'] === 'Business') {
                                    $updateAccountSql = "
                                        UPDATE businesses 
                                        SET amount_payable = amount_payable - ?, 
                                            previous_payments = previous_payments + ?
                                        WHERE business_id = ?
                                    ";
                                    $db->execute($updateAccountSql, [$amountPaid, $amountPaid, $bill['reference_id']]);
                                } else {
                                    $updateAccountSql = "
                                        UPDATE properties 
                                        SET amount_payable = amount_payable - ?, 
                                            previous_payments = previous_payments + ?
                                        WHERE property_id = ?
                                    ";
                                    $db->execute($updateAccountSql, [$amountPaid, $amountPaid, $bill['reference_id']]);
                                }
                                
                                // Enhanced audit logging for payment recording
                                try {
                                    $auditData = [
                                        'payment_reference' => $paymentReference,
                                        'bill_id' => $billId,
                                        'bill_number' => $bill['bill_number'] ?? 'N/A',
                                        'billing_year' => $bill['billing_year'] ?? date('Y'),
                                        'account_type' => $bill['bill_type'],
                                        'account_id' => $bill['reference_id'],
                                        'account_name' => $auditAccount['name'] ?? 'Unknown',
                                        'account_number' => $auditAccount['account_number'] ?? 'N/A',
                                        'account_owner' => $auditAccount['owner_name'] ?? 'Unknown',
                                        'amount_paid' => $amountPaid,
                                        'payment_method' => $paymentMethod,
                                        'payment_channel' => $paymentChannel,
                                        'transaction_id' => $transactionId,
                                        'previous_balance' => $currentRemainingBalance,
                                        'new_balance' => $newAmountPayable,
                                        'bill_amount_payable_before' => $bill['amount_payable'],
                                        'bill_amount_payable_after' => $newAmountPayable,
                                        'bill_status_updated' => $billStatus,
                                        'payment_status' => 'Successful',
                                        'processed_by_id' => $currentUser['user_id'],
                                        'processed_by_name' => $userDisplayName,
                                        'notes' => $notes,
                                        'timestamp' => date('Y-m-d H:i:s')
                                    ];
                                    
                                    $db->execute(
                                        "INSERT INTO audit_logs (user_id, action, table_name, record_id, new_values, ip_address, user_agent, created_at)
                                         VALUES (?, 'PAYMENT_RECORDED', 'payments', ?, ?, ?, ?, NOW())",
                                        [
                                            $currentUser['user_id'], 
                                            $paymentId,
                                            json_encode($auditData),
                                            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', 
                                            $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
                                        ]
                                    );
                                    
                                    // Also log bill status update if applicable
                                    if ($billStatus === 'Paid') {
                                        $billAuditData = [
                                            'bill_id' => $billId,
                                            'bill_number' => $bill['bill_number'] ?? 'N/A',
                                            'previous_status' => $bill['status'] ?? 'Pending',
                                            'new_status' => $billStatus,
                                            'final_payment_reference' => $paymentReference,
                                            'final_payment_amount' => $amountPaid,
                                            'total_amount_paid' => $bill['amount_payable'],
                                            'account_type' => $bill['bill_type'],
                                            'account_id' => $bill['reference_id'],
                                            'completed_by' => $userDisplayName,
                                            'completion_timestamp' => date('Y-m-d H:i:s')
                                        ];
                                        
                                        $db->execute(
                                            "INSERT INTO audit_logs (user_id, action, table_name, record_id, new_values, ip_address, user_agent, created_at)
                                             VALUES (?, 'BILL_FULLY_PAID', 'bills', ?, ?, ?, ?, NOW())",
                                            [
                                                $currentUser['user_id'], 
                                                $billId,
                                                json_encode($billAuditData),
                                                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', 
                                                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
                                            ]
                                        );
                                    }
                                    
                                } catch (Exception $auditError) {
                                    // Log audit failure but don't fail the payment
                                    error_log("Failed to log payment audit: " . $auditError->getMessage());
                                }
                                
                                // Commit the transaction
                                $db->commit();
                                
                                $newRemainingBalance = $newAmountPayable;
                                $success = "Payment recorded successfully! Reference: {$paymentReference}. " . 
                                         ($newRemainingBalance <= 0 ? "Account fully paid!" : "Remaining balance: ₵ " . number_format($newRemainingBalance, 2));
                                $paymentProcessed = true;
                                
                                // Clear selected account to prevent duplicate submissions
                                $selectedAccount = null;
                                $currentBill = null;
                                $remainingBalance = 0;
                                
                            } else {
                                $db->rollback();
                                $error = 'Failed to record payment. Please try again.';
                            }
                            
                        } catch (Exception $e) {
                            // Rollback transaction on error
                            if ($db->getConnection()->inTransaction()) {
                                $db->rollback();
                            }
                            $error = 'Payment recording failed: ' . $e->getMessage();
                        }
                    }
                }
                
            } catch (Exception $e) {
                $error = 'Payment recording failed: ' . $e->getMessage();
            }
        }
    }
}

// Note: formatCurrency() function is defined in includes/functions.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Record Payment - <?php echo APP_NAME; ?></title>
    
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
            color: #2d3748;
        }
        
        /* Custom Icons */
        .icon-search::before { content: "🔍"; }
        .icon-money::before { content: "💰"; }
        .icon-building::before { content: "🏢"; }
        .icon-home::before { content: "🏠"; }
        .icon-phone::before { content: "📞"; }
        .icon-location::before { content: "📍"; }
        .icon-receipt::before { content: "🧾"; }
        .icon-check::before { content: "✅"; }
        .icon-warning::before { content: "⚠️"; }
        .icon-back::before { content: "←"; }
        .icon-balance::before { content: "⚖️"; }
        .icon-cash::before { content: "💵"; }
        .icon-info::before { content: "ℹ️"; }
        
        /* Header */
        .header {
            background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%);
            color: white;
            padding: 20px 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .header-title {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .header-title h1 {
            font-size: 28px;
            font-weight: 700;
        }
        
        .header-icon {
            font-size: 32px;
            opacity: 0.9;
        }
        
        .back-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .back-btn:hover {
            background: rgba(255,255,255,0.3);
            color: white;
            text-decoration: none;
        }
        
        /* Main Content */
        .main-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px 20px;
        }
        
        /* Search Section */
        .search-section {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .search-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .search-header h2 {
            color: #2d3748;
            font-size: 24px;
            font-weight: 600;
        }
        
        .search-form {
            display: flex;
            gap: 15px;
            align-items: end;
        }
        
        .form-group {
            flex: 1;
        }
        
        .form-label {
            display: block;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s;
            background: white;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #e53e3e;
            box-shadow: 0 0 0 0.2rem rgba(229, 62, 62, 0.25);
        }
        
        .search-btn {
            background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            height: fit-content;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .search-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        /* Search Results */
        .search-results {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .results-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .results-table th {
            background: #f7fafc;
            padding: 15px 12px;
            text-align: left;
            font-weight: 600;
            color: #2d3748;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .results-table td {
            padding: 15px 12px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }
        
        .results-table tr:hover {
            background: #f7fafc;
        }
        
        .account-type-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-business {
            background: #e6fffa;
            color: #38a169;
        }
        
        .badge-property {
            background: #ebf8ff;
            color: #4299e1;
        }
        
        .select-btn {
            background: #38a169;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .select-btn:hover {
            background: #2f855a;
            transform: translateY(-1px);
        }
        
        /* Account Details */
        .account-details {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .account-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .account-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .info-label {
            font-weight: 600;
            color: #718096;
            font-size: 14px;
        }
        
        .info-value {
            font-size: 16px;
            color: #2d3748;
            font-weight: 500;
        }
        
        .amount-highlight {
            color: #e53e3e;
            font-size: 20px;
            font-weight: 700;
        }
        
        /* Balance Highlight */
        .balance-highlight {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: 2px solid #f59e0b;
            border-radius: 12px;
            padding: 25px;
            margin: 25px 0;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .balance-highlight.paid {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            border-color: #10b981;
        }
        
        .balance-highlight::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.3) 0%, transparent 70%);
            animation: shimmer 3s ease-in-out infinite;
            pointer-events: none;
        }
        
        @keyframes shimmer {
            0%, 100% { transform: rotate(0deg) translate(-50%, -50%); }
            50% { transform: rotate(180deg) translate(-50%, -50%); }
        }
        
        .balance-highlight h4 {
            margin: 0 0 15px 0;
            font-size: 20px;
            color: #92400e;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .balance-highlight.paid h4 {
            color: #065f46;
        }
        
        .balance-amount {
            font-size: 36px;
            font-weight: bold;
            color: #92400e;
            margin: 15px 0;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .balance-highlight.paid .balance-amount {
            color: #065f46;
        }
        
        .balance-subtitle {
            font-size: 14px;
            opacity: 0.8;
            color: #92400e;
        }
        
        .balance-highlight.paid .balance-subtitle {
            color: #065f46;
        }
        
        /* Balance Details Button */
        .balance-details-btn {
            background: rgba(255,255,255,0.3);
            border: 2px solid rgba(146, 64, 14, 0.3);
            color: #92400e;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 15px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .balance-highlight.paid .balance-details-btn {
            border-color: rgba(6, 95, 70, 0.3);
            color: #065f46;
        }
        
        .balance-details-btn:hover {
            background: rgba(255,255,255,0.5);
            transform: translateY(-2px);
        }
        
        /* Quick Amount Buttons */
        .quick-amounts {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 15px;
        }
        
        .quick-amount-btn {
            background: rgba(229, 62, 62, 0.1);
            border: 1px solid #e53e3e;
            color: #e53e3e;
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 14px;
        }
        
        .quick-amount-btn:hover {
            background: #e53e3e;
            color: white;
            transform: translateY(-1px);
        }
        
        /* Bill Summary */
        .bill-summary {
            background: #f7fafc;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            border-left: 4px solid #e53e3e;
        }
        
        .bill-summary h3 {
            color: #2d3748;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .bill-breakdown {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        /* Payment Form */
        .payment-form {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-control select {
            background: white;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 12px center;
            background-repeat: no-repeat;
            background-size: 16px;
            padding-right: 40px;
            appearance: none;
        }
        
        .submit-btn {
            background: linear-gradient(135deg, #38a169 0%, #2f855a 100%);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s;
            width: 100%;
            margin-top: 20px;
        }
        
        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .submit-btn:disabled {
            background: #a0aec0;
            cursor: not-allowed;
            transform: none;
        }
        
        /* Payment Summary Card */
        .payment-summary-card {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .payment-summary-card h4 {
            margin: 0 0 15px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .payment-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 15px;
        }
        
        .payment-stat {
            text-align: center;
        }
        
        .payment-stat-value {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .payment-stat-label {
            font-size: 12px;
            opacity: 0.9;
        }
        
        /* Alerts */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background: #f0fff4;
            border: 1px solid #9ae6b4;
            color: #276749;
        }
        
        .alert-danger {
            background: #fed7d7;
            border: 1px solid #fc8181;
            color: #c53030;
        }
        
        .alert-info {
            background: #e6fffa;
            border: 1px solid #81e6d9;
            color: #285e61;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .search-form {
                flex-direction: column;
                align-items: stretch;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .account-info-grid {
                grid-template-columns: 1fr;
            }
            
            .bill-breakdown {
                grid-template-columns: 1fr;
            }
            
            .results-table {
                font-size: 14px;
            }
            
            .results-table th,
            .results-table td {
                padding: 10px 8px;
            }
            
            .quick-amounts {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .payment-stats {
                grid-template-columns: 1fr;
            }
            
            .balance-amount {
                font-size: 28px;
            }
        }
        
        /* Animation */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fadeIn 0.6s ease-out;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        .pulse {
            animation: pulse 2s ease-in-out infinite;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <div class="header-content">
            <div class="header-title">
                <div class="header-icon">
                    <i class="fas fa-cash-register"></i>
                    <span class="icon-money" style="display: none;"></span>
                </div>
                <h1>Record Payment</h1>
            </div>
            <a href="../index.php" class="back-btn">
                <i class="fas fa-arrow-left"></i>
                <span class="icon-back" style="display: none;"></span>
                Back to Dashboard
            </a>
        </div>
    </div>

    <div class="main-container">
        <!-- Alerts -->
        <?php if ($error): ?>
            <div class="alert alert-danger fade-in">
                <i class="fas fa-exclamation-triangle"></i>
                <span class="icon-warning" style="display: none;"></span>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success fade-in">
                <i class="fas fa-check-circle"></i>
                <span class="icon-check" style="display: none;"></span>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- Search Section -->
        <div class="search-section fade-in">
            <div class="search-header">
                <i class="fas fa-search" style="color: #e53e3e; font-size: 24px;"></i>
                <span class="icon-search" style="display: none; font-size: 24px;"></span>
                <h2>Search Account</h2>
            </div>
            
            <form method="POST" class="search-form">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="search">
                
                <div class="form-group">
                    <label for="search_term" class="form-label">Account Number, Name, or Phone</label>
                    <input type="text" 
                           class="form-control" 
                           id="search_term" 
                           name="search_term"
                           value="<?php echo htmlspecialchars($searchTerm); ?>"
                           placeholder="Enter account number, business name, property owner, or phone number"
                           required>
                </div>
                
                <button type="submit" class="search-btn">
                    <i class="fas fa-search"></i>
                    <span class="icon-search" style="display: none;"></span>
                    Search
                </button>
            </form>
        </div>

        <!-- Search Results -->
        <?php if (!empty($searchResults)): ?>
            <div class="search-results fade-in">
                <h3>Search Results (<?php echo count($searchResults); ?> found)</h3>
                
                <table class="results-table">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Account Number</th>
                            <th>Name</th>
                            <th>Owner</th>
                            <th>Phone</th>
                            <th>Amount Due</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($searchResults as $result): ?>
                            <tr>
                                <td>
                                    <span class="account-type-badge <?php echo $result['type'] === 'business' ? 'badge-business' : 'badge-property'; ?>">
                                        <i class="fas <?php echo $result['type'] === 'business' ? 'fa-building' : 'fa-home'; ?>"></i>
                                        <span class="<?php echo $result['type'] === 'business' ? 'icon-building' : 'icon-home'; ?>" style="display: none;"></span>
                                        <?php echo ucfirst($result['type']); ?>
                                    </span>
                                </td>
                                <td><strong><?php echo htmlspecialchars($result['account_number']); ?></strong></td>
                                <td><?php echo htmlspecialchars($result['name']); ?></td>
                                <td><?php echo htmlspecialchars($result['owner_name']); ?></td>
                                <td>
                                    <i class="fas fa-phone" style="color: #718096; margin-right: 5px;"></i>
                                    <span class="icon-phone" style="display: none;"></span>
                                    <?php echo htmlspecialchars($result['telephone'] ?: 'N/A'); ?>
                                </td>
                                <td class="amount-highlight"><?php echo formatCurrency($result['amount_payable']); ?></td>
                                <td>
                                    <form method="POST" style="display: inline;">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="select_account">
                                        <input type="hidden" name="account_type" value="<?php echo $result['type']; ?>">
                                        <input type="hidden" name="account_id" value="<?php echo $result['id']; ?>">
                                        <button type="submit" class="select-btn">Select</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- Account Details & Payment Form -->
        <?php if ($selectedAccount && $currentBill): ?>
            <!-- Payment Summary Card -->
            <?php if ($paymentSummary): ?>
                <div class="payment-summary-card fade-in">
                    <h4>
                        <i class="fas fa-chart-line"></i>
                        <span class="icon-info" style="display: none;"></span>
                        Payment Summary
                    </h4>
                    <div class="payment-stats">
                        <div class="payment-stat">
                            <div class="payment-stat-value">₵ <?php echo number_format($paymentSummary['total_paid'] ?? 0, 2); ?></div>
                            <div class="payment-stat-label">Total Paid</div>
                        </div>
                        <div class="payment-stat">
                            <div class="payment-stat-value">₵ <?php echo number_format($remainingBalance, 2); ?></div>
                            <div class="payment-stat-label">Remaining</div>
                        </div>
                        <div class="payment-stat">
                            <div class="payment-stat-value"><?php echo $paymentSummary['successful_payments'] ?? 0; ?></div>
                            <div class="payment-stat-label">Payments</div>
                        </div>
                        <div class="payment-stat">
                            <div class="payment-stat-value"><?php echo number_format(($paymentSummary['total_paid'] / max($selectedAccount['amount_payable'], 1)) * 100, 1); ?>%</div>
                            <div class="payment-stat-label">Progress</div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="account-details fade-in">
                <div class="account-header">
                    <i class="fas <?php echo $selectedAccount['type'] === 'business' ? 'fa-building' : 'fa-home'; ?>" style="color: #e53e3e; font-size: 24px;"></i>
                    <span class="<?php echo $selectedAccount['type'] === 'business' ? 'icon-building' : 'icon-home'; ?>" style="display: none; font-size: 24px;"></span>
                    <h3><?php echo htmlspecialchars($selectedAccount['name']); ?></h3>
                    <span class="account-type-badge <?php echo $selectedAccount['type'] === 'business' ? 'badge-business' : 'badge-property'; ?>">
                        <?php echo ucfirst($selectedAccount['type']); ?>
                    </span>
                </div>

                <div class="account-info-grid">
                    <div class="info-item">
                        <span class="info-label">Account Number</span>
                        <span class="info-value"><?php echo htmlspecialchars($selectedAccount['account_number']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Owner Name</span>
                        <span class="info-value"><?php echo htmlspecialchars($selectedAccount['owner_name']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Phone Number</span>
                        <span class="info-value">
                            <i class="fas fa-phone" style="color: #718096; margin-right: 5px;"></i>
                            <span class="icon-phone" style="display: none;"></span>
                            <?php echo htmlspecialchars($selectedAccount['telephone'] ?: 'N/A'); ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Location</span>
                        <span class="info-value">
                            <i class="fas fa-map-marker-alt" style="color: #718096; margin-right: 5px;"></i>
                            <span class="icon-location" style="display: none;"></span>
                            <?php echo htmlspecialchars($selectedAccount['location'] ?: 'N/A'); ?>
                        </span>
                    </div>
                    <?php if ($selectedAccount['type'] === 'business'): ?>
                        <div class="info-item">
                            <span class="info-label">Business Type</span>
                            <span class="info-value"><?php echo htmlspecialchars($selectedAccount['business_type'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Category</span>
                            <span class="info-value"><?php echo htmlspecialchars($selectedAccount['category'] ?? 'N/A'); ?></span>
                        </div>
                    <?php else: ?>
                        <div class="info-item">
                            <span class="info-label">Structure</span>
                            <span class="info-value"><?php echo htmlspecialchars($selectedAccount['structure'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Property Use</span>
                            <span class="info-value"><?php echo htmlspecialchars($selectedAccount['property_use'] ?? 'N/A'); ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Remaining Balance Highlight -->
                <div class="balance-highlight <?php echo $remainingBalance <= 0 ? 'paid' : ''; ?> <?php echo $remainingBalance > 0 ? 'pulse' : ''; ?>">
                    <h4>
                        <i class="fas fa-balance-scale"></i>
                        <span class="icon-balance" style="display: none;"></span>
                        <?php echo $remainingBalance <= 0 ? 'Account Fully Paid' : 'Current Bill Balance (' . $currentBill['billing_year'] . ')'; ?>
                    </h4>
                    <div class="balance-amount">
                        ₵ <?php echo number_format($remainingBalance, 2); ?>
                    </div>
                    <div class="balance-subtitle">
                        <?php if ($remainingBalance > 0): ?>
                            Outstanding balance for <?php echo $currentBill['billing_year']; ?> bill
                        <?php else: ?>
                            ✅ <?php echo $currentBill['billing_year']; ?> bill fully settled
                        <?php endif; ?>
                    </div>
                    
                    <button onclick="showBalanceDetails()" class="balance-details-btn">
                        <i class="fas fa-info-circle"></i>
                        <span class="icon-info" style="display: none;"></span>
                        View Details
                    </button>
                    
                    <?php if ($remainingBalance > 0): ?>
                        <div class="quick-amounts">
                            <?php
                            $quickAmounts = [];
                            if ($remainingBalance >= 100) $quickAmounts[] = 100;
                            if ($remainingBalance >= 200) $quickAmounts[] = 200;
                            if ($remainingBalance >= 500) $quickAmounts[] = 500;
                            $quickAmounts[] = round($remainingBalance / 2, 2);
                            $quickAmounts[] = $remainingBalance;
                            $quickAmounts = array_unique($quickAmounts);
                            sort($quickAmounts);
                            
                            foreach ($quickAmounts as $amount):
                            ?>
                                <button type="button" class="quick-amount-btn" onclick="setAmount(<?php echo $amount; ?>)">
                                    ₵ <?php echo number_format($amount, 2); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Bill Summary -->
                <div class="bill-summary">
                    <h3>
                        <i class="fas fa-file-invoice-dollar"></i>
                        <span class="icon-receipt" style="display: none;"></span>
                        Bill Summary - <?php echo $currentBill['billing_year']; ?>
                    </h3>
                    <div class="bill-breakdown">
                        <div class="info-item">
                            <span class="info-label">Old Bill</span>
                            <span class="info-value"><?php echo formatCurrency($selectedAccount['old_bill']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Previous Payments</span>
                            <span class="info-value" style="color: #38a169;"><?php echo formatCurrency($selectedAccount['previous_payments']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Arrears</span>
                            <span class="info-value" style="color: #ed8936;"><?php echo formatCurrency($selectedAccount['arrears']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Current Bill</span>
                            <span class="info-value"><?php echo formatCurrency($selectedAccount['current_bill']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Total Amount Payable</span>
                            <span class="info-value amount-highlight"><?php echo formatCurrency($selectedAccount['amount_payable']); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payment Form -->
            <?php if ($remainingBalance > 0): ?>
                <div class="payment-form fade-in">
                    <h3 style="margin-bottom: 25px; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-credit-card" style="color: #38a169;"></i>
                        <span class="icon-money" style="display: none;"></span>
                        Record Payment
                    </h3>

                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <span class="icon-info" style="display: none;"></span>
                        Maximum payment amount: ₵ <?php echo number_format($remainingBalance, 2); ?>
                    </div>

                    <form method="POST" id="paymentForm">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="record_payment">
                        <input type="hidden" name="bill_id" value="<?php echo $currentBill['bill_id']; ?>">

                        <div class="form-row">
                            <div class="form-group">
                                <label for="amount_paid" class="form-label">Payment Amount *</label>
                                <input type="number" 
                                       class="form-control" 
                                       id="amount_paid" 
                                       name="amount_paid"
                                       step="0.01"
                                       min="0.01"
                                       max="<?php echo $remainingBalance; ?>"
                                       placeholder="Enter payment amount"
                                       required>
                            </div>
                            
                            <div class="form-group">
                                <label for="payment_method" class="form-label">Payment Method *</label>
                                <select class="form-control" id="payment_method" name="payment_method" required>
                                    <option value="">Select payment method</option>
                                    <option value="Cash">Cash</option>
                                    <option value="Mobile Money">Mobile Money</option>
                                    <option value="Bank Transfer">Bank Transfer</option>
                                    <option value="Online">Online Payment</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="payment_channel" class="form-label">Payment Channel</label>
                                <input type="text" 
                                       class="form-control" 
                                       id="payment_channel" 
                                       name="payment_channel"
                                       placeholder="e.g., MTN Mobile Money, AirtelTigo, Vodafone">
                            </div>
                            
                            <div class="form-group">
                                <label for="transaction_id" class="form-label">Transaction ID/Reference</label>
                                <input type="text" 
                                       class="form-control" 
                                       id="transaction_id" 
                                       name="transaction_id"
                                       placeholder="Enter transaction reference number">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="notes" class="form-label">Notes (Optional)</label>
                            <textarea class="form-control" 
                                      id="notes" 
                                      name="notes" 
                                      rows="3"
                                      placeholder="Add any additional notes about this payment"></textarea>
                        </div>

                        <button type="submit" class="submit-btn" id="submitBtn">
                            <i class="fas fa-check-circle"></i>
                            <span class="icon-check" style="display: none;"></span>
                            Record Payment
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <div class="alert alert-success fade-in">
                    <i class="fas fa-check-circle"></i>
                    <span class="icon-check" style="display: none;"></span>
                    This account's <?php echo $currentBill['billing_year']; ?> bill is fully paid. No outstanding balance.
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
        // Global variables
        const remainingBalance = <?php echo $remainingBalance; ?>;
        const accountName = <?php echo json_encode($selectedAccount['name'] ?? ''); ?>;
        const accountNumber = <?php echo json_encode($selectedAccount['account_number'] ?? ''); ?>;
        const billingYear = <?php echo json_encode($currentBill['billing_year'] ?? date('Y')); ?>;
        
        // Check if Font Awesome loaded, if not show emoji icons
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                const testIcon = document.querySelector('.fas.fa-search');
                if (!testIcon || getComputedStyle(testIcon, ':before').content === 'none') {
                    document.querySelectorAll('.fas, .far').forEach(function(icon) {
                        icon.style.display = 'none';
                    });
                    document.querySelectorAll('[class*="icon-"]').forEach(function(emoji) {
                        emoji.style.display = 'inline';
                    });
                }
            }, 100);

            // Auto-focus search field
            const searchField = document.getElementById('search_term');
            if (searchField && !<?php echo $selectedAccount ? 'true' : 'false'; ?>) {
                searchField.focus();
            }

            // Payment form enhancements
            const paymentForm = document.getElementById('paymentForm');
            if (paymentForm) {
                const amountField = document.getElementById('amount_paid');
                const submitBtn = document.getElementById('submitBtn');
                
                // Format amount as user types
                amountField.addEventListener('input', function() {
                    const value = parseFloat(this.value);
                    
                    if (value > remainingBalance) {
                        this.setCustomValidity(`Payment amount cannot exceed the remaining balance of ₵ ${remainingBalance.toLocaleString('en-US', {minimumFractionDigits: 2})}`);
                    } else {
                        this.setCustomValidity('');
                    }
                    
                    // Update submit button text
                    if (value > 0) {
                        const newBalance = remainingBalance - value;
                        if (newBalance <= 0) {
                            submitBtn.innerHTML = '<i class="fas fa-check-circle"></i> Complete Payment (Full Settlement)';
                        } else {
                            submitBtn.innerHTML = `<i class="fas fa-check-circle"></i> Record Payment (₵ ${newBalance.toLocaleString('en-US', {minimumFractionDigits: 2})} remaining)`;
                        }
                    } else {
                        submitBtn.innerHTML = '<i class="fas fa-check-circle"></i> Record Payment';
                    }
                });

                // Prevent form resubmission
                paymentForm.addEventListener('submit', function(e) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing Payment...';
                });
            }

            // Auto-fill payment channel based on method
            const paymentMethod = document.getElementById('payment_method');
            const paymentChannel = document.getElementById('payment_channel');
            
            if (paymentMethod && paymentChannel) {
                paymentMethod.addEventListener('change', function() {
                    const method = this.value;
                    
                    if (method === 'Mobile Money') {
                        paymentChannel.placeholder = 'e.g., MTN Mobile Money, AirtelTigo, Vodafone Cash';
                        paymentChannel.focus();
                    } else if (method === 'Bank Transfer') {
                        paymentChannel.placeholder = 'e.g., GCB Bank, Ecobank, Fidelity Bank';
                    } else if (method === 'Cash') {
                        paymentChannel.value = 'Cash Payment';
                        paymentChannel.placeholder = 'Cash Payment';
                    } else {
                        paymentChannel.placeholder = 'Enter payment channel';
                    }
                });
            }
        });

        // Show detailed balance information
        function showBalanceDetails() {
            const balanceModal = document.createElement('div');
            balanceModal.style.cssText = `
                position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(0,0,0,0.6); backdrop-filter: blur(8px); z-index: 10000;
                display: flex; align-items: center; justify-content: center;
                animation: fadeIn 0.3s ease; cursor: pointer;
            `;
            
            const balanceContent = document.createElement('div');
            balanceContent.style.cssText = `
                background: white; padding: 30px; border-radius: 15px; max-width: 600px; width: 90%;
                box-shadow: 0 25px 80px rgba(0,0,0,0.4); text-align: center;
                animation: modalSlideIn 0.4s ease; cursor: default;
            `;
            
            const amountPayable = <?php echo $selectedAccount['amount_payable'] ?? 0; ?>;
            const totalPaid = <?php echo $totalPaid; ?>;
            const paymentProgress = amountPayable > 0 ? (totalPaid / amountPayable) * 100 : 100;
            
            balanceContent.innerHTML = `
                <h3 style="margin: 0 0 20px 0; color: #2d3748; display: flex; align-items: center; gap: 10px; justify-content: center;">
                    <i class="fas fa-balance-scale" style="color: #e53e3e;"></i>
                    ⚖️ Balance Analysis - ${billingYear}
                </h3>
                <div style="background: #f8fafc; padding: 25px; border-radius: 12px; margin: 20px 0;">
                    <h4 style="margin: 0 0 15px 0; color: #e53e3e; font-size: 18px;">${accountName}</h4>
                    <p style="margin: 0 0 20px 0; color: #64748b;">Account: ${accountNumber}</p>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                        <div style="text-align: left;">
                            <strong style="color: #2d3748;">Total Amount Payable:</strong>
                            <div style="font-size: 24px; font-weight: bold; color: #e53e3e; margin-top: 5px;">
                                ₵ ${amountPayable.toLocaleString('en-US', {minimumFractionDigits: 2})}
                            </div>
                        </div>
                        <div style="text-align: left;">
                            <strong style="color: #2d3748;">Total Paid:</strong>
                            <div style="font-size: 24px; font-weight: bold; color: #059669; margin-top: 5px;">
                                ₵ ${totalPaid.toLocaleString('en-US', {minimumFractionDigits: 2})}
                            </div>
                        </div>
                    </div>
                    <div style="background: white; padding: 15px; border-radius: 8px; margin: 15px 0;">
                        <strong style="color: #2d3748;">Payment Progress:</strong>
                        <div style="background: #e2e8f0; height: 12px; border-radius: 6px; margin: 10px 0; overflow: hidden;">
                            <div style="background: linear-gradient(90deg, #10b981, #059669); height: 100%; width: ${Math.min(paymentProgress, 100)}%; transition: width 1s ease;"></div>
                        </div>
                        <div style="font-size: 14px; color: #64748b;">${paymentProgress.toFixed(1)}% Completed</div>
                    </div>
                    <div style="border: 3px solid ${remainingBalance > 0 ? '#f59e0b' : '#10b981'}; 
                                background: ${remainingBalance > 0 ? '#fef3c7' : '#d1fae5'}; 
                                padding: 20px; border-radius: 12px; margin-top: 20px;">
                        <h4 style="margin: 0 0 10px 0; color: ${remainingBalance > 0 ? '#92400e' : '#065f46'};">
                            ${remainingBalance > 0 ? '⚠️ Current Bill Balance (' + billingYear + ')' : '✅ Account Status'}
                        </h4>
                        <div style="font-size: 36px; font-weight: bold; color: ${remainingBalance > 0 ? '#92400e' : '#065f46'}; margin: 15px 0;">
                            ₵ ${remainingBalance.toLocaleString('en-US', {minimumFractionDigits: 2})}
                        </div>
                        <p style="margin: 10px 0 0 0; color: ${remainingBalance > 0 ? '#92400e' : '#065f46'};">
                            ${remainingBalance > 0 ? 'Outstanding balance for ' + billingYear + ' bill' : billingYear + ' bill fully settled'}
                        </p>
                    </div>
                </div>
                <div style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap; margin-top: 25px;">
                    <button onclick="this.closest('.balance-modal').remove()" style="
                        background: #94a3b8; color: white; padding: 12px 20px; border: none; border-radius: 8px;
                        cursor: pointer; font-weight: 600; transition: all 0.3s; display: inline-flex;
                        align-items: center; gap: 8px; font-size: 14px;">
                        <i class="fas fa-times"></i> ❌ Close
                    </button>
                </div>
            `;
            
            balanceModal.className = 'balance-modal';
            balanceModal.appendChild(balanceContent);
            document.body.appendChild(balanceModal);
            
            // Close modal when clicking backdrop
            balanceModal.addEventListener('click', function(e) {
                if (e.target === balanceModal) {
                    balanceModal.remove();
                }
            });
            
            // Add animations if not already present
            if (!document.getElementById('modalAnimations')) {
                const style = document.createElement('style');
                style.id = 'modalAnimations';
                style.textContent = `
                    @keyframes modalSlideIn {
                        from { transform: scale(0.8) translateY(-20px); opacity: 0; }
                        to { transform: scale(1) translateY(0); opacity: 1; }
                    }
                `;
                document.head.appendChild(style);
            }
        }

        // Quick amount buttons
        function setAmount(amount) {
            const amountField = document.getElementById('amount_paid');
            if (amountField) {
                amountField.value = amount.toFixed(2);
                amountField.dispatchEvent(new Event('input'));
                amountField.focus();
            }
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + Enter to submit payment form
            if (e.ctrlKey && e.key === 'Enter') {
                const paymentForm = document.getElementById('paymentForm');
                if (paymentForm && remainingBalance > 0) {
                    e.preventDefault();
                    paymentForm.submit();
                }
            }
            
            // Escape to close modals
            if (e.key === 'Escape') {
                const modals = document.querySelectorAll('.balance-modal');
                modals.forEach(modal => modal.remove());
            }
            
            // Ctrl + B for balance details
            if ((e.ctrlKey || e.metaKey) && e.key === 'b' && remainingBalance >= 0) {
                e.preventDefault();
                showBalanceDetails();
            }
        });

        console.log('✅ Payment recording page initialized successfully');
        if (remainingBalance >= 0) {
            console.log(`💰 Current Bill Balance (${billingYear}): ₵ ${remainingBalance.toLocaleString('en-US', {minimumFractionDigits: 2})}`);
        }
    </script>
</body>
</html>