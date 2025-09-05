<?php
/**
 * Daily Summary Page for QUICKBILL 305
 * Revenue Officer interface for daily collection reporting
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

// Get selected date (default to today)
$selectedDate = isset($_GET['date']) ? sanitizeInput($_GET['date']) : date('Y-m-d');
$selectedMonth = isset($_GET['month']) ? sanitizeInput($_GET['month']) : date('Y-m');

// Validate date
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = date('Y-m-d');
}

// Database connection
try {
    $db = new Database();
} catch (Exception $e) {
    $error = 'Database connection failed. Please try again.';
}

// Initialize summary data with proper defaults
$dailySummary = [
    'total_payments' => 0,
    'total_amount' => 0.00,
    'cash_payments' => 0,
    'cash_amount' => 0.00,
    'mobile_money_payments' => 0,
    'mobile_money_amount' => 0.00,
    'bank_transfer_payments' => 0,
    'bank_transfer_amount' => 0.00,
    'online_payments' => 0,
    'online_amount' => 0.00,
    'business_payments' => 0,
    'business_amount' => 0.00,
    'property_payments' => 0,
    'property_amount' => 0.00,
    'my_payments' => 0,
    'my_amount' => 0.00
];

$monthlyComparison = [];
$paymentDetails = [];
$hourlyBreakdown = [];

// Helper function to safely cast numeric values
function safeNumeric($value, $default = 0) {
    if ($value === null || $value === '') {
        return $default;
    }
    return is_numeric($value) ? $value : $default;
}

// Helper function to safely format currency
function safeCurrency($value) {
    return formatCurrency(safeNumeric($value, 0));
}

// Helper function to safely format numbers
function safeNumberFormat($value) {
    return number_format((int)safeNumeric($value, 0));
}

try {
    // Daily summary query
    $dailyQuery = "
        SELECT 
            COUNT(*) as total_payments,
            COALESCE(SUM(amount_paid), 0) as total_amount,
            SUM(CASE WHEN payment_method = 'Cash' THEN 1 ELSE 0 END) as cash_payments,
            COALESCE(SUM(CASE WHEN payment_method = 'Cash' THEN amount_paid ELSE 0 END), 0) as cash_amount,
            SUM(CASE WHEN payment_method = 'Mobile Money' THEN 1 ELSE 0 END) as mobile_money_payments,
            COALESCE(SUM(CASE WHEN payment_method = 'Mobile Money' THEN amount_paid ELSE 0 END), 0) as mobile_money_amount,
            SUM(CASE WHEN payment_method = 'Bank Transfer' THEN 1 ELSE 0 END) as bank_transfer_payments,
            COALESCE(SUM(CASE WHEN payment_method = 'Bank Transfer' THEN amount_paid ELSE 0 END), 0) as bank_transfer_amount,
            SUM(CASE WHEN payment_method = 'Online' THEN 1 ELSE 0 END) as online_payments,
            COALESCE(SUM(CASE WHEN payment_method = 'Online' THEN amount_paid ELSE 0 END), 0) as online_amount
        FROM payments 
        WHERE DATE(payment_date) = ? AND payment_status = 'Successful'
    ";
    
    $result = $db->fetchRow($dailyQuery, [$selectedDate]);
    if ($result) {
        // Safely merge results with proper type casting
        foreach ($result as $key => $value) {
            $dailySummary[$key] = safeNumeric($value, 0);
        }
    }
    
    // Business vs Property breakdown
    $typeQuery = "
        SELECT 
            b.bill_type,
            COUNT(*) as payment_count,
            COALESCE(SUM(p.amount_paid), 0) as total_amount
        FROM payments p
        JOIN bills b ON p.bill_id = b.bill_id
        WHERE DATE(p.payment_date) = ? AND p.payment_status = 'Successful'
        GROUP BY b.bill_type
    ";
    
    $typeResults = $db->fetchAll($typeQuery, [$selectedDate]);
    if ($typeResults) {
        foreach ($typeResults as $type) {
            if ($type['bill_type'] === 'Business') {
                $dailySummary['business_payments'] = safeNumeric($type['payment_count'], 0);
                $dailySummary['business_amount'] = safeNumeric($type['total_amount'], 0);
            } else {
                $dailySummary['property_payments'] = safeNumeric($type['payment_count'], 0);
                $dailySummary['property_amount'] = safeNumeric($type['total_amount'], 0);
            }
        }
    }
    
    // My payments (current user)
    $myQuery = "
        SELECT 
            COUNT(*) as my_payments,
            COALESCE(SUM(amount_paid), 0) as my_amount
        FROM payments 
        WHERE DATE(payment_date) = ? AND payment_status = 'Successful' AND processed_by = ?
    ";
    
    $myResult = $db->fetchRow($myQuery, [$selectedDate, $currentUser['user_id']]);
    if ($myResult) {
        $dailySummary['my_payments'] = safeNumeric($myResult['my_payments'], 0);
        $dailySummary['my_amount'] = safeNumeric($myResult['my_amount'], 0);
    }
    
    // Monthly comparison (last 30 days)
    $monthlyQuery = "
        SELECT 
            DATE(payment_date) as payment_date,
            COUNT(*) as daily_payments,
            COALESCE(SUM(amount_paid), 0) as daily_amount
        FROM payments 
        WHERE DATE(payment_date) >= DATE_SUB(?, INTERVAL 29 DAY) 
        AND DATE(payment_date) <= ?
        AND payment_status = 'Successful'
        GROUP BY DATE(payment_date)
        ORDER BY payment_date
    ";
    
    $monthlyResults = $db->fetchAll($monthlyQuery, [$selectedDate, $selectedDate]);
    if ($monthlyResults) {
        $monthlyComparison = $monthlyResults;
    }
    
    // Payment details for selected date
    $detailsQuery = "
        SELECT 
            p.payment_reference,
            p.amount_paid,
            p.payment_method,
            p.payment_channel,
            p.payment_date,
            b.bill_number,
            b.bill_type,
            CASE 
                WHEN b.bill_type = 'Business' THEN bs.business_name
                WHEN b.bill_type = 'Property' THEN pr.owner_name
            END as payer_name,
            CASE 
                WHEN b.bill_type = 'Business' THEN bs.account_number
                WHEN b.bill_type = 'Property' THEN pr.property_number
            END as account_number,
            u.first_name,
            u.last_name
        FROM payments p
        JOIN bills b ON p.bill_id = b.bill_id
        LEFT JOIN businesses bs ON b.bill_type = 'Business' AND b.reference_id = bs.business_id
        LEFT JOIN properties pr ON b.bill_type = 'Property' AND b.reference_id = pr.property_id
        LEFT JOIN users u ON p.processed_by = u.user_id
        WHERE DATE(p.payment_date) = ? AND p.payment_status = 'Successful'
        ORDER BY p.payment_date DESC
    ";
    
    $paymentDetails = $db->fetchAll($detailsQuery, [$selectedDate]);
    if ($paymentDetails === false) {
        $paymentDetails = [];
    }
    
    // Hourly breakdown
    $hourlyQuery = "
        SELECT 
            HOUR(payment_date) as payment_hour,
            COUNT(*) as hourly_payments,
            COALESCE(SUM(amount_paid), 0) as hourly_amount
        FROM payments 
        WHERE DATE(payment_date) = ? AND payment_status = 'Successful'
        GROUP BY HOUR(payment_date)
        ORDER BY payment_hour
    ";
    
    $hourlyResults = $db->fetchAll($hourlyQuery, [$selectedDate]);
    if ($hourlyResults) {
        $hourlyBreakdown = $hourlyResults;
    }
    
} catch (Exception $e) {
    $error = 'Failed to load summary data. Please try again.';
}

// Calculate percentages with safe division
$my_percentage = $dailySummary['total_payments'] > 0 ? 
    round(($dailySummary['my_payments'] / $dailySummary['total_payments']) * 100, 1) : 0;

$business_percentage = $dailySummary['total_payments'] > 0 ? 
    round(($dailySummary['business_payments'] / $dailySummary['total_payments']) * 100, 1) : 0;

$property_percentage = $dailySummary['total_payments'] > 0 ? 
    round(($dailySummary['property_payments'] / $dailySummary['total_payments']) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Summary - <?php echo APP_NAME; ?></title>
    
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
        .icon-chart::before { content: "📊"; }
        .icon-money::before { content: "💰"; }
        .icon-calendar::before { content: "📅"; }
        .icon-clock::before { content: "🕐"; }
        .icon-building::before { content: "🏢"; }
        .icon-home::before { content: "🏠"; }
        .icon-user::before { content: "👤"; }
        .icon-back::before { content: "←"; }
        .icon-download::before { content: "💾"; }
        .icon-percentage::before { content: "📈"; }
        
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
        
        .header-actions {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        .date-picker {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            padding: 8px 12px;
            border-radius: 8px;
            font-weight: 600;
        }
        
        .date-picker:focus {
            outline: none;
            background: rgba(255,255,255,0.3);
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
        
        /* Summary Cards */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .summary-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: transform 0.3s;
            position: relative;
            overflow: hidden;
        }
        
        .summary-card:hover {
            transform: translateY(-5px);
        }
        
        .summary-card.primary {
            background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%);
            color: white;
        }
        
        .summary-card.success {
            background: linear-gradient(135deg, #38a169 0%, #2f855a 100%);
            color: white;
        }
        
        .summary-card.info {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
        }
        
        .summary-card.warning {
            background: linear-gradient(135deg, #ed8936 0%, #dd6b20 100%);
            color: white;
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .card-title {
            font-size: 14px;
            opacity: 0.9;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .card-icon {
            font-size: 28px;
            opacity: 0.8;
        }
        
        .card-value {
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .card-subtitle {
            font-size: 14px;
            opacity: 0.8;
        }
        
        /* Performance Chart */
        .chart-section {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .section-title {
            font-size: 20px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .chart-container {
            height: 300px;
            display: flex;
            align-items: end;
            justify-content: space-between;
            padding: 20px 0;
            border-bottom: 2px solid #e2e8f0;
            position: relative;
        }
        
        .chart-bar {
            background: linear-gradient(to top, #e53e3e, #fc8181);
            border-radius: 4px 4px 0 0;
            min-width: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .chart-bar:hover {
            transform: scale(1.05);
            filter: brightness(1.1);
        }
        
        .chart-value {
            position: absolute;
            top: -25px;
            background: #2d3748;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .chart-bar:hover .chart-value {
            opacity: 1;
        }
        
        .chart-label {
            margin-top: 10px;
            font-size: 12px;
            color: #718096;
            text-align: center;
        }
        
        /* Payment Methods */
        .methods-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .method-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid #e2e8f0;
        }
        
        .method-card.cash { border-left-color: #38a169; }
        .method-card.mobile { border-left-color: #4299e1; }
        .method-card.bank { border-left-color: #9f7aea; }
        .method-card.online { border-left-color: #ed8936; }
        
        .method-icon {
            font-size: 32px;
            margin-bottom: 15px;
            color: #718096;
        }
        
        .method-card.cash .method-icon { color: #38a169; }
        .method-card.mobile .method-icon { color: #4299e1; }
        .method-card.bank .method-icon { color: #9f7aea; }
        .method-card.online .method-icon { color: #ed8936; }
        
        .method-name {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 10px;
        }
        
        .method-amount {
            font-size: 18px;
            font-weight: 700;
            color: #e53e3e;
            margin-bottom: 5px;
        }
        
        .method-count {
            font-size: 14px;
            color: #718096;
        }
        
        /* Payment Details Table */
        .details-section {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .details-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .details-table th {
            background: #f7fafc;
            padding: 15px 12px;
            text-align: left;
            font-weight: 600;
            color: #2d3748;
            border-bottom: 2px solid #e2e8f0;
            font-size: 14px;
        }
        
        .details-table td {
            padding: 15px 12px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
            font-size: 14px;
        }
        
        .details-table tr:hover {
            background: #f7fafc;
        }
        
        .payment-ref {
            font-family: monospace;
            background: #e2e8f0;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
        }
        
        .account-type-badge {
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
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
        
        .method-badge {
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .method-cash { background: #c6f6d5; color: #276749; }
        .method-mobile { background: #bee3f8; color: #2c5282; }
        .method-bank { background: #e9d8fd; color: #553c9a; }
        .method-online { background: #fbd38d; color: #c05621; }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #718096;
        }
        
        .empty-icon {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .summary-grid {
                grid-template-columns: 1fr;
            }
            
            .methods-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .header-content {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .header-actions {
                width: 100%;
                justify-content: center;
            }
            
            .details-table {
                font-size: 12px;
            }
            
            .details-table th,
            .details-table td {
                padding: 10px 8px;
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
        
        @keyframes slideIn {
            from { transform: translateX(-100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        .slide-in {
            animation: slideIn 0.8s ease-out;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <div class="header-content">
            <div class="header-title">
                <div class="header-icon">
                    <i class="fas fa-chart-line"></i>
                    <span class="icon-chart" style="display: none;"></span>
                </div>
                <div>
                    <h1>Daily Summary</h1>
                    <div style="font-size: 16px; opacity: 0.9;"><?php echo date('F j, Y', strtotime($selectedDate)); ?></div>
                </div>
            </div>
            <div class="header-actions">
                <input type="date" 
                       class="date-picker" 
                       value="<?php echo $selectedDate; ?>" 
                       onchange="changeDate(this.value)">
                <a href="../index.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i>
                    <span class="icon-back" style="display: none;"></span>
                    Dashboard
                </a>
            </div>
        </div>
    </div>

    <div class="main-container">
        <!-- Summary Cards -->
        <div class="summary-grid fade-in">
            <div class="summary-card primary">
                <div class="card-header">
                    <div class="card-title">Total Collections</div>
                    <div class="card-icon">
                        <i class="fas fa-money-bill-wave"></i>
                        <span class="icon-money" style="display: none;"></span>
                    </div>
                </div>
                <div class="card-value"><?php echo safeCurrency($dailySummary['total_amount']); ?></div>
                <div class="card-subtitle"><?php echo safeNumberFormat($dailySummary['total_payments']); ?> total payments</div>
            </div>

            <div class="summary-card success">
                <div class="card-header">
                    <div class="card-title">My Collections</div>
                    <div class="card-icon">
                        <i class="fas fa-user-check"></i>
                        <span class="icon-user" style="display: none;"></span>
                    </div>
                </div>
                <div class="card-value"><?php echo safeCurrency($dailySummary['my_amount']); ?></div>
                <div class="card-subtitle"><?php echo safeNumberFormat($dailySummary['my_payments']); ?> payments (<?php echo $my_percentage; ?>%)</div>
            </div>

            <div class="summary-card info">
                <div class="card-header">
                    <div class="card-title">Business Collections</div>
                    <div class="card-icon">
                        <i class="fas fa-building"></i>
                        <span class="icon-building" style="display: none;"></span>
                    </div>
                </div>
                <div class="card-value"><?php echo safeCurrency($dailySummary['business_amount']); ?></div>
                <div class="card-subtitle"><?php echo safeNumberFormat($dailySummary['business_payments']); ?> payments (<?php echo $business_percentage; ?>%)</div>
            </div>

            <div class="summary-card warning">
                <div class="card-header">
                    <div class="card-title">Property Collections</div>
                    <div class="card-icon">
                        <i class="fas fa-home"></i>
                        <span class="icon-home" style="display: none;"></span>
                    </div>
                </div>
                <div class="card-value"><?php echo safeCurrency($dailySummary['property_amount']); ?></div>
                <div class="card-subtitle"><?php echo safeNumberFormat($dailySummary['property_payments']); ?> payments (<?php echo $property_percentage; ?>%)</div>
            </div>
        </div>

        <!-- Payment Methods Breakdown -->
        <div class="chart-section fade-in">
            <h3 class="section-title">
                <i class="fas fa-credit-card"></i>
                <span class="icon-money" style="display: none;"></span>
                Payment Methods Breakdown
            </h3>
            <div class="methods-grid">
                <div class="method-card cash">
                    <div class="method-icon">
                        <i class="fas fa-money-bill"></i>
                        <span class="icon-money" style="display: none;"></span>
                    </div>
                    <div class="method-name">Cash</div>
                    <div class="method-amount"><?php echo safeCurrency($dailySummary['cash_amount']); ?></div>
                    <div class="method-count"><?php echo safeNumberFormat($dailySummary['cash_payments']); ?> payments</div>
                </div>

                <div class="method-card mobile">
                    <div class="method-icon">
                        <i class="fas fa-mobile-alt"></i>
                        <span class="icon-phone" style="display: none;"></span>
                    </div>
                    <div class="method-name">Mobile Money</div>
                    <div class="method-amount"><?php echo safeCurrency($dailySummary['mobile_money_amount']); ?></div>
                    <div class="method-count"><?php echo safeNumberFormat($dailySummary['mobile_money_payments']); ?> payments</div>
                </div>

                <div class="method-card bank">
                    <div class="method-icon">
                        <i class="fas fa-university"></i>
                        <span class="icon-building" style="display: none;"></span>
                    </div>
                    <div class="method-name">Bank Transfer</div>
                    <div class="method-amount"><?php echo safeCurrency($dailySummary['bank_transfer_amount']); ?></div>
                    <div class="method-count"><?php echo safeNumberFormat($dailySummary['bank_transfer_payments']); ?> payments</div>
                </div>

                <div class="method-card online">
                    <div class="method-icon">
                        <i class="fas fa-globe"></i>
                        <span class="icon-chart" style="display: none;"></span>
                    </div>
                    <div class="method-name">Online</div>
                    <div class="method-amount"><?php echo safeCurrency($dailySummary['online_amount']); ?></div>
                    <div class="method-count"><?php echo safeNumberFormat($dailySummary['online_payments']); ?> payments</div>
                </div>
            </div>
        </div>

        <!-- Hourly Performance Chart -->
        <?php if (!empty($hourlyBreakdown)): ?>
        <div class="chart-section fade-in">
            <h3 class="section-title">
                <i class="fas fa-clock"></i>
                <span class="icon-clock" style="display: none;"></span>
                Hourly Collection Performance
            </h3>
            <div class="chart-container">
                <?php 
                $maxAmount = max(array_column($hourlyBreakdown, 'hourly_amount'));
                foreach ($hourlyBreakdown as $hour): 
                    $height = $maxAmount > 0 ? (safeNumeric($hour['hourly_amount']) / $maxAmount) * 250 : 0;
                ?>
                    <div class="chart-bar" style="height: <?php echo $height; ?>px;">
                        <div class="chart-value"><?php echo safeCurrency($hour['hourly_amount']); ?></div>
                        <div class="chart-label"><?php echo str_pad(safeNumeric($hour['payment_hour']), 2, '0', STR_PAD_LEFT); ?>:00</div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Payment Details -->
        <div class="details-section fade-in">
            <h3 class="section-title">
                <i class="fas fa-list"></i>
                <span class="icon-chart" style="display: none;"></span>
                Payment Details (<?php echo count($paymentDetails); ?> payments)
            </h3>
            
            <?php if (!empty($paymentDetails)): ?>
                <div style="overflow-x: auto;">
                    <table class="details-table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Reference</th>
                                <th>Account</th>
                                <th>Payer</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Processed By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($paymentDetails as $payment): ?>
                                <tr>
                                    <td><?php echo date('H:i', strtotime($payment['payment_date'])); ?></td>
                                    <td><span class="payment-ref"><?php echo htmlspecialchars($payment['payment_reference']); ?></span></td>
                                    <td><strong><?php echo htmlspecialchars($payment['account_number']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($payment['payer_name']); ?></td>
                                    <td>
                                        <span class="account-type-badge <?php echo $payment['bill_type'] === 'Business' ? 'badge-business' : 'badge-property'; ?>">
                                            <?php echo $payment['bill_type']; ?>
                                        </span>
                                    </td>
                                    <td><strong><?php echo safeCurrency($payment['amount_paid']); ?></strong></td>
                                    <td>
                                        <span class="method-badge method-<?php echo strtolower(str_replace(' ', '', $payment['payment_method'])); ?>">
                                            <?php echo htmlspecialchars($payment['payment_method']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="fas fa-inbox"></i>
                        <span class="icon-chart" style="display: none;"></span>
                    </div>
                    <h3>No payments recorded</h3>
                    <p>No payments were processed on <?php echo date('F j, Y', strtotime($selectedDate)); ?>.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Check if Font Awesome loaded, if not show emoji icons
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                const testIcon = document.querySelector('.fas.fa-chart-line');
                if (!testIcon || getComputedStyle(testIcon, ':before').content === 'none') {
                    document.querySelectorAll('.fas, .far').forEach(function(icon) {
                        icon.style.display = 'none';
                    });
                    document.querySelectorAll('[class*="icon-"]').forEach(function(emoji) {
                        emoji.style.display = 'inline';
                    });
                }
            }, 100);

            // Animate summary cards
            const cards = document.querySelectorAll('.summary-card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
                card.classList.add('slide-in');
            });
        });

        // Change date and reload page
        function changeDate(date) {
            window.location.href = `?date=${date}`;
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Left arrow for previous day
            if (e.key === 'ArrowLeft' && e.ctrlKey) {
                e.preventDefault();
                const currentDate = new Date('<?php echo $selectedDate; ?>');
                currentDate.setDate(currentDate.getDate() - 1);
                const newDate = currentDate.toISOString().split('T')[0];
                changeDate(newDate);
            }
            
            // Right arrow for next day
            if (e.key === 'ArrowRight' && e.ctrlKey) {
                e.preventDefault();
                const currentDate = new Date('<?php echo $selectedDate; ?>');
                currentDate.setDate(currentDate.getDate() + 1);
                const newDate = currentDate.toISOString().split('T')[0];
                changeDate(newDate);
            }
            
            // T for today
            if (e.key === 't' || e.key === 'T') {
                e.preventDefault();
                const today = new Date().toISOString().split('T')[0];
                changeDate(today);
            }
        });

        // Add hover effects to chart bars
        document.querySelectorAll('.chart-bar').forEach(bar => {
            bar.addEventListener('mouseenter', function() {
                this.style.transform = 'scale(1.05)';
                this.style.filter = 'brightness(1.1)';
            });
            
            bar.addEventListener('mouseleave', function() {
                this.style.transform = 'scale(1)';
                this.style.filter = 'brightness(1)';
            });
        });

        // Add printing functionality
        function printReport() {
            window.print();
        }

        // Add export functionality (placeholder)
        function exportReport() {
            alert('Export functionality would be implemented here');
        }
    </script>
</body>
</html>