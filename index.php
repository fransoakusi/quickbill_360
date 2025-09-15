<?php
/**
 * Main Entry Point for QUICKBILL 305
 * Beautiful Landing Page with Real Database Statistics
 */

// Define application constant
define('QUICKBILL_305', true);

// Include configuration files first (before starting session)
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/functions.php';

// Start session after configuration
session_start();

// Include auth and security after session is started
require_once 'includes/auth.php';
require_once 'includes/security.php';

// Initialize auth and security
initAuth();
initSecurity();

// Check if user is logged in and redirect accordingly
if (isLoggedIn()) {
    // Get user role and redirect to appropriate dashboard
    $userRole = getCurrentUserRole();
    
    switch ($userRole) {
        case 'Super Admin':
        case 'Admin':
            header('Location: admin/index.php');
            break;
        case 'Officer':
            header('Location: officer/index.php');
            break;
        case 'Revenue Officer':
            header('Location: revenue_officer/index.php');
            break;
        case 'Data Collector':
            header('Location: data_collector/index.php');
            break;
        default:
            // Invalid role, logout and redirect to login
            logout();
            header('Location: auth/login.php?error=invalid_role');
            break;
    }
    exit();
}

// Get real statistics from the database
$totalBusinesses = 0;
$totalProperties = 0;
$totalRevenue = 0;
$totalPayments = 0;
$activeUsers = 0;
$pendingBills = 0;
$totalBills = 0;
$collectionRate = 0;
$defaulters = 0;
$recentPayments = [];
$monthlyGrowth = [];

try {
    $db = new Database();
    
    // Get total businesses
    try {
        $result = $db->fetchRow("SELECT COUNT(*) as count FROM businesses WHERE status = 'Active'");
        $totalBusinesses = $result['count'] ?? 0;
    } catch (Exception $e) {
        error_log("Error fetching businesses count: " . $e->getMessage());
    }
    
    // Get total properties
    try {
        $result = $db->fetchRow("SELECT COUNT(*) as count FROM properties");
        $totalProperties = $result['count'] ?? 0;
    } catch (Exception $e) {
        error_log("Error fetching properties count: " . $e->getMessage());
    }
    
    // Get total revenue from successful payments
    try {
        $result = $db->fetchRow("SELECT 
            SUM(amount_paid) as total_revenue,
            COUNT(*) as total_payments
            FROM payments 
            WHERE payment_status = 'Successful'");
        $totalRevenue = $result['total_revenue'] ?? 0;
        $totalPayments = $result['total_payments'] ?? 0;
    } catch (Exception $e) {
        error_log("Error fetching revenue: " . $e->getMessage());
    }
    
    // Get active users
    try {
        $result = $db->fetchRow("SELECT COUNT(*) as count FROM users WHERE is_active = 1");
        $activeUsers = $result['count'] ?? 0;
    } catch (Exception $e) {
        error_log("Error fetching active users: " . $e->getMessage());
    }
    
    // Get bills statistics
    try {
        $result = $db->fetchRow("SELECT 
            COUNT(*) as total_bills,
            SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending_bills,
            SUM(CASE WHEN status IN ('Paid', 'Partially Paid') THEN 1 ELSE 0 END) as paid_bills
            FROM bills");
        $totalBills = $result['total_bills'] ?? 0;
        $pendingBills = $result['pending_bills'] ?? 0;
        $paidBills = $result['paid_bills'] ?? 0;
        
        // Calculate collection rate
        if ($totalBills > 0) {
            $collectionRate = round(($paidBills / $totalBills) * 100, 1);
        }
    } catch (Exception $e) {
        error_log("Error fetching bills statistics: " . $e->getMessage());
    }
    
    // Get defaulters (businesses and properties with outstanding amounts)
    try {
        $businessDefaulters = $db->fetchRow("SELECT COUNT(*) as count FROM businesses WHERE amount_payable > 0");
        $propertyDefaulters = $db->fetchRow("SELECT COUNT(*) as count FROM properties WHERE amount_payable > 0");
        $defaulters = ($businessDefaulters['count'] ?? 0) + ($propertyDefaulters['count'] ?? 0);
    } catch (Exception $e) {
        error_log("Error fetching defaulters: " . $e->getMessage());
    }
    
    // Get recent payments for activity feed
    try {
        $recentPaymentsQuery = "SELECT 
            p.amount_paid,
            p.payment_date,
            p.payment_method,
            b.bill_type,
            CASE 
                WHEN b.bill_type = 'Business' THEN bus.business_name
                WHEN b.bill_type = 'Property' THEN prop.owner_name
                ELSE 'Unknown'
            END as payer_name
            FROM payments p
            JOIN bills b ON p.bill_id = b.bill_id
            LEFT JOIN businesses bus ON b.bill_type = 'Business' AND b.reference_id = bus.business_id
            LEFT JOIN properties prop ON b.bill_type = 'Property' AND b.reference_id = prop.property_id
            WHERE p.payment_status = 'Successful'
            ORDER BY p.payment_date DESC
            LIMIT 5";
        
        $recentPayments = $db->fetchAll($recentPaymentsQuery);
    } catch (Exception $e) {
        error_log("Error fetching recent payments: " . $e->getMessage());
        $recentPayments = [];
    }
    
    // Get monthly growth data (last 12 months)
    try {
        $monthlyQuery = "SELECT 
            DATE_FORMAT(payment_date, '%Y-%m') as month,
            SUM(amount_paid) as revenue,
            COUNT(*) as payments_count
            FROM payments 
            WHERE payment_status = 'Successful' 
            AND payment_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
            ORDER BY month DESC
            LIMIT 12";
        
        $monthlyData = $db->fetchAll($monthlyQuery);
        
        // Calculate growth percentage for current vs previous month
        if (count($monthlyData) >= 2) {
            $currentMonth = $monthlyData[0]['revenue'] ?? 0;
            $previousMonth = $monthlyData[1]['revenue'] ?? 0;
            
            if ($previousMonth > 0) {
                $monthlyGrowth['revenue'] = round((($currentMonth - $previousMonth) / $previousMonth) * 100, 1);
            } else {
                $monthlyGrowth['revenue'] = 0;
            }
        }
        
        // Business registration growth
        $businessGrowthQuery = "SELECT COUNT(*) as current_count 
            FROM businesses 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)";
        $currentMonthBusinesses = $db->fetchRow($businessGrowthQuery);
        
        $prevBusinessGrowthQuery = "SELECT COUNT(*) as prev_count 
            FROM businesses 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 2 MONTH) 
            AND created_at < DATE_SUB(NOW(), INTERVAL 1 MONTH)";
        $prevMonthBusinesses = $db->fetchRow($prevBusinessGrowthQuery);
        
        $currentBizCount = $currentMonthBusinesses['current_count'] ?? 0;
        $prevBizCount = $prevMonthBusinesses['prev_count'] ?? 0;
        
        if ($prevBizCount > 0) {
            $monthlyGrowth['businesses'] = round((($currentBizCount - $prevBizCount) / $prevBizCount) * 100, 1);
        } else {
            $monthlyGrowth['businesses'] = $currentBizCount > 0 ? 100 : 0;
        }
        
    } catch (Exception $e) {
        error_log("Error fetching monthly growth: " . $e->getMessage());
        $monthlyGrowth = ['revenue' => 0, 'businesses' => 0];
    }
    
    // Ensure all required keys exist with default values
    $monthlyGrowth = array_merge([
        'revenue' => 0,
        'businesses' => 0
    ], $monthlyGrowth ?? []);
    
} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    // Use minimal fallback values only if database is completely unavailable
    $totalBusinesses = 0;
    $totalProperties = 0;
    $totalRevenue = 0;
    $activeUsers = 0;
    $collectionRate = 0;
    $defaulters = 0;
    $monthlyGrowth = ['revenue' => 0, 'businesses' => 0];
}

// Format numbers for display
$formattedRevenue = number_format($totalRevenue, 2);
$revenueDisplay = $totalRevenue >= 1000 ? '₵' . number_format($totalRevenue/1000, 0) . 'K' : '₵' . number_format($totalRevenue, 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> - Modern Assembly Revenue Management</title>
    
    <!-- Multiple Icon Sources -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://use.fontawesome.com/releases/v6.4.0/css/all.css">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        :root {
            --primary-purple: #667eea;
            --secondary-purple: #764ba2;
            --accent-blue: #4299e1;
            --success-green: #48bb78;
            --warning-orange: #ed8936;
            --danger-red: #e53e3e;
            --dark-text: #2d3748;
            --light-gray: #f8f9fa;
            --medium-gray: #718096;
            --white: #ffffff;
            --shadow-light: 0 4px 20px rgba(0, 0, 0, 0.08);
            --shadow-medium: 0 8px 40px rgba(0, 0, 0, 0.12);
            --shadow-heavy: 0 16px 60px rgba(0, 0, 0, 0.15);
            --border-radius: 20px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            line-height: 1.6;
            color: var(--dark-text);
            overflow-x: hidden;
            background: var(--white);
        }
        
        /* Custom Icons (fallback) */
        .icon-dashboard::before { content: "📊"; }
        .icon-users::before { content: "👥"; }
        .icon-building::before { content: "🏢"; }
        .icon-home::before { content: "🏠"; }
        .icon-map::before { content: "🗺️"; }
        .icon-invoice::before { content: "📄"; }
        .icon-money::before { content: "💰"; }
        .icon-mobile::before { content: "📱"; }
        .icon-chart::before { content: "📈"; }
        .icon-qr::before { content: "📱"; }
        .icon-receipt::before { content: "🧾"; }
        .icon-shield::before { content: "🛡️"; }
        .icon-bell::before { content: "🔔"; }
        .icon-cog::before { content: "⚙️"; }
        .icon-login::before { content: "🚪"; }
        .icon-rocket::before { content: "🚀"; }
        .icon-menu::before { content: "☰"; }
        .icon-close::before { content: "✕"; }
        .icon-book::before { content: "📚"; }
        .icon-manual::before { content: "📖"; }
        
        /* Navigation */
        .navbar {
            position: fixed;
            top: 0;
            width: 100%;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            z-index: 1000;
            padding: 1rem 0;
            transition: var(--transition);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .navbar.scrolled {
            box-shadow: var(--shadow-light);
            background: rgba(255, 255, 255, 0.98);
        }
        
        .nav-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--primary-purple);
            text-decoration: none;
            transition: var(--transition);
            z-index: 1001;
        }
        
        .logo:hover {
            transform: scale(1.05);
            color: var(--primary-purple);
            text-decoration: none;
        }
        
        .logo-icon {
            font-size: 2rem;
            background: linear-gradient(135deg, var(--primary-purple), var(--secondary-purple));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .nav-links {
            display: flex;
            gap: 2rem;
            align-items: center;
        }
        
        .nav-link {
            color: var(--dark-text);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.95rem;
            transition: var(--transition);
            position: relative;
            padding: 0.5rem 0;
        }
        
        .nav-link::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 2px;
            background: linear-gradient(90deg, var(--primary-purple), var(--secondary-purple));
            transition: width 0.3s ease;
        }
        
        .nav-link:hover::after {
            width: 100%;
        }
        
        .nav-link:hover {
            color: var(--primary-purple);
            text-decoration: none;
        }

        /* Mobile Menu */
        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--primary-purple);
            cursor: pointer;
            z-index: 1001;
            position: relative;
        }

        .mobile-menu {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100vh;
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 2rem;
            transform: translateX(-100%);
            transition: transform 0.3s ease;
        }

        .mobile-menu.active {
            transform: translateX(0);
        }

        .mobile-nav-link {
            color: var(--dark-text);
            text-decoration: none;
            font-weight: 600;
            font-size: 1.2rem;
            padding: 1rem 2rem;
            border-radius: 12px;
            transition: var(--transition);
            text-align: center;
            min-width: 200px;
        }

        .mobile-nav-link:hover {
            background: rgba(102, 126, 234, 0.1);
            color: var(--primary-purple);
            text-decoration: none;
        }
        
        /* Hero Section */
        .hero {
            background: linear-gradient(135deg, var(--primary-purple) 0%, var(--secondary-purple) 30%, var(--accent-blue) 70%, var(--success-green) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            position: relative;
            overflow: hidden;
            padding-top: 100px;
        }
        
        .hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 1000"><defs><radialGradient id="grad1" cx="20%" cy="20%"><stop offset="0%" stop-color="rgba(255,255,255,0.1)"/><stop offset="100%" stop-color="rgba(255,255,255,0)"/></radialGradient><radialGradient id="grad2" cx="80%" cy="80%"><stop offset="0%" stop-color="rgba(255,255,255,0.15)"/><stop offset="100%" stop-color="rgba(255,255,255,0)"/></radialGradient></defs><circle cx="200" cy="200" r="150" fill="url(%23grad1)"/><circle cx="800" cy="800" r="200" fill="url(%23grad2)"/></svg>');
            opacity: 0.4;
            animation: float 25s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            33% { transform: translateY(-15px) rotate(2deg); }
            66% { transform: translateY(10px) rotate(-1deg); }
        }
        
        .hero-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 2rem;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6rem;
            align-items: center;
            position: relative;
            z-index: 2;
        }
        
        .hero-content {
            animation: slideInFromLeft 1s ease-out;
        }
        
        @keyframes slideInFromLeft {
            from {
                opacity: 0;
                transform: translateX(-50px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        .hero-badge {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            color: var(--white);
            padding: 0.5rem 1.5rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 2rem;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        .hero-title {
            font-size: 4rem;
            font-weight: 900;
            color: var(--white);
            margin-bottom: 1.5rem;
            line-height: 1.1;
            letter-spacing: -0.02em;
        }
        
        .hero-title .highlight {
            background: linear-gradient(90deg, #fff, rgba(255,255,255,0.8));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .hero-subtitle {
            font-size: 1.25rem;
            color: rgba(255, 255, 255, 0.9);
            margin-bottom: 3rem;
            font-weight: 400;
            line-height: 1.6;
            max-width: 90%;
        }
        
        .hero-buttons {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 3rem;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem 2rem;
            border-radius: 60px;
            text-decoration: none;
            font-weight: 600;
            font-size: 1rem;
            transition: var(--transition);
            border: none;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            white-space: nowrap;
        }
        
        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.6s;
        }
        
        .btn:hover::before {
            left: 100%;
        }
        
        .btn-primary {
            background: var(--white);
            color: var(--primary-purple);
            box-shadow: var(--shadow-medium);
        }
        
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-heavy);
            background: #f8f9ff;
            color: var(--primary-purple);
            text-decoration: none;
        }
        
        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            color: var(--white);
            border: 2px solid rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
        }
        
        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-3px);
            border-color: rgba(255, 255, 255, 0.3);
            color: var(--white);
            text-decoration: none;
        }

        .btn-tertiary {
            background: rgba(255, 255, 255, 0.15);
            color: var(--white);
            border: 2px solid rgba(255, 255, 255, 0.25);
            backdrop-filter: blur(10px);
        }
        
        .btn-tertiary:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-3px);
            border-color: rgba(255, 255, 255, 0.4);
            color: var(--white);
            text-decoration: none;
        }
        
        .hero-stats {
            display: flex;
            gap: 2rem;
            margin-top: 2rem;
        }
        
        .hero-stat {
            text-align: center;
        }
        
        .hero-stat-number {
            font-size: 2rem;
            font-weight: 800;
            color: var(--white);
            display: block;
        }
        
        .hero-stat-label {
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.8);
            font-weight: 500;
        }
        
        /* Dashboard Mockup */
        .hero-visual {
            position: relative;
            animation: slideInFromRight 1s ease-out;
            perspective: 1000px;
        }
        
        @keyframes slideInFromRight {
            from {
                opacity: 0;
                transform: translateX(50px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        .dashboard-mockup {
            position: relative;
            background: var(--white);
            border-radius: 24px;
            box-shadow: 0 25px 100px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            transform: rotateY(-5deg) rotateX(10deg);
            transition: transform 0.6s ease;
        }
        
        .dashboard-mockup:hover {
            transform: rotateY(0deg) rotateX(0deg) scale(1.02);
        }
        
        .dashboard-header {
            background: linear-gradient(135deg, var(--primary-purple), var(--secondary-purple));
            padding: 1.5rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .dashboard-header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .dashboard-logo {
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-size: 1.2rem;
        }
        
        .dashboard-title {
            color: var(--white);
            font-weight: 700;
            font-size: 1.1rem;
        }
        
        .dashboard-nav {
            display: flex;
            gap: 1rem;
        }
        
        .dashboard-nav-item {
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.85rem;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .dashboard-nav-item.active {
            background: rgba(255, 255, 255, 0.2);
            color: var(--white);
        }
        
        .dashboard-content {
            padding: 2rem;
            background: #fafbfc;
        }
        
        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: var(--white);
            padding: 1.5rem;
            border-radius: 16px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--primary-purple), var(--secondary-purple));
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
        }
        
        .stat-card:nth-child(1)::before { background: linear-gradient(90deg, var(--success-green), #38a169); }
        .stat-card:nth-child(2)::before { background: linear-gradient(90deg, var(--accent-blue), #3182ce); }
        .stat-card:nth-child(3)::before { background: linear-gradient(90deg, var(--warning-orange), #dd6b20); }
        .stat-card:nth-child(4)::before { background: linear-gradient(90deg, var(--danger-red), #c53030); }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 800;
            color: var(--dark-text);
            margin-bottom: 0.25rem;
        }
        
        .stat-label {
            font-size: 0.8rem;
            color: var(--medium-gray);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stat-change {
            font-size: 0.75rem;
            margin-top: 0.25rem;
            font-weight: 600;
        }
        
        .stat-change.positive {
            color: var(--success-green);
        }
        
        .stat-change.negative {
            color: var(--danger-red);
        }
        
        .stat-change.neutral {
            color: var(--medium-gray);
        }
        
        .dashboard-main {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
        }
        
        .chart-card {
            background: var(--white);
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }
        
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .chart-title {
            font-weight: 700;
            color: var(--dark-text);
            font-size: 1.1rem;
        }
        
        .chart-period {
            font-size: 0.8rem;
            color: var(--medium-gray);
            background: var(--light-gray);
            padding: 0.4rem 0.8rem;
            border-radius: 6px;
        }
        
        .chart-area {
            height: 200px;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(116, 75, 162, 0.1));
            border-radius: 12px;
            position: relative;
            overflow: hidden;
        }
        
        .chart-line {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            /* Height will be set via inline style in HTML to avoid PHP in CSS */
            background: linear-gradient(135deg, var(--primary-purple), var(--secondary-purple));
            border-radius: 12px 12px 0 0;
            opacity: 0.8;
            animation: chartGrow 2s ease-out;
        }
        
        @keyframes chartGrow {
            from { height: 0%; }
            to { /* Height will be set via inline style in HTML */ }
        }
        
        .activity-feed {
            background: var(--white);
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }
        
        .activity-header {
            font-weight: 700;
            color: var(--dark-text);
            margin-bottom: 1rem;
            font-size: 1.1rem;
        }
        
        .activity-item {
            display: flex;
            gap: 1rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            animation: fadeInUp 0.6s ease forwards;
            opacity: 0;
        }
        
        .activity-item:nth-child(2) { animation-delay: 0.1s; }
        .activity-item:nth-child(3) { animation-delay: 0.2s; }
        .activity-item:nth-child(4) { animation-delay: 0.3s; }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 35px;
            height: 35px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            color: var(--white);
            flex-shrink: 0;
        }
        
        .activity-icon.success { background: var(--success-green); }
        .activity-icon.info { background: var(--accent-blue); }
        .activity-icon.warning { background: var(--warning-orange); }
        
        .activity-content {
            flex: 1;
        }
        
        .activity-text {
            font-size: 0.85rem;
            color: var(--dark-text);
            font-weight: 500;
            margin-bottom: 0.25rem;
        }
        
        .activity-time {
            font-size: 0.75rem;
            color: var(--medium-gray);
        }
        
        /* Features Section */
        .features-section {
            padding: 8rem 0;
            background: var(--white);
        }
        
        .features-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
        }
        
        .section-header {
            text-align: center;
            margin-bottom: 5rem;
        }
        
        .section-badge {
            display: inline-block;
            background: rgba(102, 126, 234, 0.1);
            color: var(--primary-purple);
            padding: 0.5rem 1.5rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
        }
        
        .section-title {
            font-size: 3rem;
            font-weight: 800;
            color: var(--dark-text);
            margin-bottom: 1rem;
            letter-spacing: -0.02em;
        }
        
        .section-subtitle {
            font-size: 1.2rem;
            color: var(--medium-gray);
            max-width: 600px;
            margin: 0 auto;
            line-height: 1.6;
        }
        
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 3rem;
        }
        
        .feature-card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 2.5rem;
            box-shadow: var(--shadow-light);
            transition: var(--transition);
            border: 1px solid rgba(0, 0, 0, 0.05);
            position: relative;
            overflow: hidden;
        }
        
        .feature-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-purple), var(--secondary-purple));
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }
        
        .feature-card:hover::before {
            transform: scaleX(1);
        }
        
        .feature-card:hover {
            transform: translateY(-10px);
            box-shadow: var(--shadow-heavy);
        }
        
        .feature-icon {
            font-size: 3rem;
            background: linear-gradient(135deg, var(--primary-purple), var(--secondary-purple));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 1.5rem;
            display: block;
        }
        
        .feature-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--dark-text);
        }
        
        .feature-description {
            color: var(--medium-gray);
            line-height: 1.6;
            margin-bottom: 1.5rem;
        }
        
        .feature-list {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        
        .feature-list li {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            margin: 0.75rem 0;
            color: var(--medium-gray);
            font-size: 0.95rem;
            line-height: 1.5;
        }
        
        .feature-list li::before {
            content: '✓';
            color: var(--success-green);
            font-weight: bold;
            font-size: 1.1rem;
            margin-top: 0.1rem;
            flex-shrink: 0;
        }
        
        /* CTA Section */
        .cta-section {
            padding: 8rem 0;
            background: linear-gradient(135deg, var(--primary-purple) 0%, var(--secondary-purple) 30%, var(--accent-blue) 70%, var(--success-green) 100%);
            position: relative;
            overflow: hidden;
        }
        
        .cta-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 1000"><circle cx="100" cy="100" r="80" fill="rgba(255,255,255,0.1)"/><circle cx="900" cy="200" r="120" fill="rgba(255,255,255,0.1)"/><circle cx="200" cy="800" r="100" fill="rgba(255,255,255,0.1)"/></svg>');
            animation: float 20s ease-in-out infinite reverse;
        }
        
        .cta-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 0 2rem;
            text-align: center;
            position: relative;
            z-index: 2;
        }
        
        .cta-badge {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            color: var(--white);
            padding: 0.5rem 1.5rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 2rem;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .cta-title {
            font-size: 3rem;
            font-weight: 800;
            color: var(--white);
            margin-bottom: 1.5rem;
            letter-spacing: -0.02em;
        }
        
        .cta-subtitle {
            font-size: 1.25rem;
            color: rgba(255, 255, 255, 0.9);
            margin-bottom: 3rem;
            line-height: 1.6;
        }
        
        /* Footer */
        .footer {
            padding: 3rem 0;
            background: var(--dark-text);
            color: var(--white);
        }
        
        .footer-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
            text-align: center;
        }
        
        .footer-text {
            opacity: 0.9;
            margin-bottom: 1rem;
            font-size: 1.1rem;
        }
        
        .footer-tech {
            opacity: 0.6;
            font-size: 0.9rem;
            color: var(--medium-gray);
        }
        
        /* Animations */
        .fade-in {
            opacity: 0;
            transform: translateY(30px);
            transition: all 0.6s ease;
        }
        
        .fade-in.visible {
            opacity: 1;
            transform: translateY(0);
        }
        
        /* Enhanced Responsive Design */
        
        /* Tablet Styles (768px - 1024px) */
        @media (max-width: 1024px) {
            .hero-container {
                gap: 4rem;
            }
            
            .hero-title {
                font-size: 3.5rem;
            }
            
            .section-title {
                font-size: 2.5rem;
            }
            
            .cta-title {
                font-size: 2.5rem;
            }
            
            .dashboard-stats {
                grid-template-columns: repeat(2, 1fr);
                gap: 1.5rem;
            }
            
            .features-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 2.5rem;
            }
            
            .dashboard-nav {
                gap: 0.5rem;
            }
            
            .dashboard-nav-item {
                font-size: 0.8rem;
                padding: 0.4rem 0.8rem;
            }

            .hero-buttons {
                justify-content: flex-start;
                flex-wrap: wrap;
            }
        }

        /* Mobile Styles (Below 768px) */
        @media (max-width: 768px) {
            .nav-links {
                display: none;
            }

            .mobile-menu-toggle {
                display: block;
            }
            
            .hero-container {
                grid-template-columns: 1fr;
                text-align: center;
                gap: 3rem;
                padding: 0 1.5rem;
            }
            
            .hero-title {
                font-size: 2.8rem;
                line-height: 1.2;
            }
            
            .hero-subtitle {
                font-size: 1.1rem;
                max-width: 100%;
            }
            
            .hero-buttons {
                justify-content: center;
                gap: 0.75rem;
                flex-direction: column;
                align-items: stretch;
            }
            
            .btn {
                padding: 1rem 1.5rem;
                font-size: 0.95rem;
                justify-content: center;
            }
            
            .hero-stats {
                justify-content: center;
                gap: 1.5rem;
                flex-wrap: wrap;
            }
            
            .hero-stat-number {
                font-size: 1.8rem;
            }
            
            .hero-stat-label {
                font-size: 0.8rem;
            }
            
            .section-title {
                font-size: 2.2rem;
                line-height: 1.2;
            }
            
            .section-subtitle {
                font-size: 1.1rem;
            }
            
            .cta-title {
                font-size: 2.2rem;
                line-height: 1.2;
            }
            
            .cta-subtitle {
                font-size: 1.1rem;
            }
            
            .nav-container {
                padding: 0 1.5rem;
            }
            
            .logo {
                font-size: 1.3rem;
            }
            
            .logo-icon {
                font-size: 1.8rem;
            }
            
            .dashboard-mockup {
                transform: none;
                border-radius: 16px;
            }
            
            .dashboard-mockup:hover {
                transform: scale(1.02);
            }
            
            .dashboard-header {
                padding: 1rem 1.5rem;
            }
            
            .dashboard-title {
                font-size: 1rem;
            }
            
            .dashboard-nav {
                display: none;
            }
            
            .dashboard-content {
                padding: 1.5rem;
            }
            
            .dashboard-stats {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.75rem;
            }
            
            .stat-card {
                padding: 1rem;
            }
            
            .stat-value {
                font-size: 1.5rem;
            }
            
            .stat-label {
                font-size: 0.7rem;
            }
            
            .stat-change {
                font-size: 0.7rem;
            }
            
            .dashboard-main {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .chart-card,
            .activity-feed {
                padding: 1rem;
            }
            
            .chart-area {
                height: 150px;
            }
            
            .activity-text {
                font-size: 0.8rem;
            }
            
            .activity-time {
                font-size: 0.7rem;
            }
            
            .features-section {
                padding: 5rem 0;
            }
            
            .features-container {
                padding: 0 1.5rem;
            }
            
            .section-header {
                margin-bottom: 3rem;
            }
            
            .features-grid {
                grid-template-columns: 1fr;
                gap: 2rem;
            }
            
            .feature-card {
                padding: 2rem;
            }
            
            .feature-icon {
                font-size: 2.5rem;
            }
            
            .feature-title {
                font-size: 1.3rem;
            }
            
            .feature-description {
                font-size: 0.95rem;
            }
            
            .feature-list li {
                font-size: 0.9rem;
            }
            
            .cta-section {
                padding: 5rem 0;
            }
            
            .cta-container {
                padding: 0 1.5rem;
            }
            
            .footer-container {
                padding: 0 1.5rem;
            }
        }
        
        /* Small Mobile Styles (Below 480px) */
        @media (max-width: 480px) {
            .nav-container {
                padding: 0 1rem;
            }
            
            .hero-container {
                padding: 0 1rem;
            }
            
            .hero-title {
                font-size: 2.2rem;
            }
            
            .hero-subtitle {
                font-size: 1rem;
            }
            
            .hero-badge {
                font-size: 0.8rem;
                padding: 0.4rem 1rem;
            }
            
            .hero-stats {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
                display: grid;
            }
            
            .dashboard-stats {
                grid-template-columns: 1fr;
                gap: 0.5rem;
            }
            
            .hero-buttons {
                gap: 0.5rem;
            }
            
            .btn {
                width: 100%;
                padding: 1rem;
            }
            
            .section-title {
                font-size: 1.8rem;
            }
            
            .cta-title {
                font-size: 1.8rem;
            }
            
            .section-subtitle,
            .cta-subtitle {
                font-size: 1rem;
            }
            
            .features-container,
            .cta-container,
            .footer-container {
                padding: 0 1rem;
            }
            
            .feature-card {
                padding: 1.5rem;
            }
            
            .logo {
                font-size: 1.2rem;
            }
            
            .dashboard-content {
                padding: 1rem;
            }
            
            .chart-card,
            .activity-feed {
                padding: 1rem;
            }
            
            .activity-icon {
                width: 30px;
                height: 30px;
                font-size: 0.8rem;
            }
        }

        /* Landscape Mobile Optimization */
        @media (max-height: 500px) and (orientation: landscape) {
            .hero {
                min-height: auto;
                padding: 6rem 0 4rem;
            }
            
            .hero-title {
                font-size: 2rem;
                margin-bottom: 1rem;
            }
            
            .hero-subtitle {
                font-size: 1rem;
                margin-bottom: 1.5rem;
            }
            
            .hero-badge {
                margin-bottom: 1rem;
            }
            
            .hero-buttons {
                margin-bottom: 1.5rem;
            }
            
            .hero-stats {
                margin-top: 1rem;
            }
        }

        /* High DPI Display Optimization */
        @media (-webkit-min-device-pixel-ratio: 2), (min-resolution: 192dpi) {
            .dashboard-mockup {
                box-shadow: 0 25px 100px rgba(0, 0, 0, 0.25);
            }
            
            .btn {
                box-shadow: var(--shadow-medium);
            }
            
            .feature-card {
                box-shadow: var(--shadow-light);
            }
        }

        /* Reduced Motion Preferences */
        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
            
            .hero::before {
                animation: none;
            }
            
            .chart-line {
                animation: none;
            }
            
            .activity-item {
                animation: none;
                opacity: 1;
            }
        }

        /* Dark mode support (if preferred) */
        @media (prefers-color-scheme: dark) {
            .dashboard-content {
                background: #f5f5f5;
            }
        }

        /* Enhanced Focus States for Accessibility */
        .btn:focus,
        .nav-link:focus,
        .mobile-nav-link:focus,
        .mobile-menu-toggle:focus {
            outline: 2px solid var(--primary-purple);
            outline-offset: 2px;
        }

        /* Improved Touch Targets for Mobile */
        @media (max-width: 768px) {
            .btn,
            .nav-link,
            .mobile-nav-link,
            .mobile-menu-toggle {
                min-height: 44px;
                min-width: 44px;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar" id="navbar">
        <div class="nav-container">
            <a href="#" class="logo">
                <div class="logo-icon">
                    <i class="fas fa-receipt"></i>
                    <span class="icon-receipt" style="display: none;"></span>
                </div>
                <?php echo APP_NAME; ?>
            </a>
            
            <!-- Desktop Navigation -->
            <div class="nav-links">
                <a href="#home" class="nav-link">Home</a>
                <a href="#features" class="nav-link">Features</a>
                <a href="#about" class="nav-link">About Us</a>
                <a href="#status" class="nav-link">System Status</a>
                <a href="auth/login.php" class="btn btn-primary">
                    <i class="fas fa-sign-in-alt"></i>
                    <span class="icon-login" style="display: none;"></span>
                    Get Started
                </a>
            </div>

            <!-- Mobile Menu Toggle -->
            <button class="mobile-menu-toggle" id="mobileMenuToggle">
                <i class="fas fa-bars" id="menuIcon"></i>
                <span class="icon-menu" style="display: none;" id="menuIconFallback"></span>
            </button>
        </div>

        <!-- Mobile Menu -->
        <div class="mobile-menu" id="mobileMenu">
            <a href="#home" class="mobile-nav-link">Home</a>
            <a href="#features" class="mobile-nav-link">Features</a>
            <a href="#about" class="mobile-nav-link">About Us</a>
            <a href="#status" class="mobile-nav-link">System Status</a>
            <a href="auth/login.php" class="btn btn-primary">
                <i class="fas fa-sign-in-alt"></i>
                <span class="icon-login" style="display: none;"></span>
                Get Started
            </a>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero" id="home">
        <div class="hero-container">
            <div class="hero-content">
                <div class="hero-badge">
                    🎯 MODERN ASSEMBLY REVENUE MANAGEMENT
                </div>
                <h1 class="hero-title">
                    Your Fast Track to<br>
                    <span class="highlight">Revenue Excellence</span>
                </h1>
                <p class="hero-subtitle">
                    At QUICKBILL 360, we're committed to empowering assembly management with 
                    comprehensive billing solutions, automated payment systems, and real-time analytics 
                    for efficient revenue collection.
                </p>
                <div class="hero-buttons">
                    <a href="auth/login.php" class="btn btn-primary">
                        <i class="fas fa-rocket"></i>
                        <span class="icon-rocket" style="display: none;"></span>
                        Start Your Journey
                    </a>
                    <a href="#features" class="btn btn-secondary">
                        <i class="fas fa-play"></i>
                        Watch Demo
                    </a>
                    <a href="user-manual.html" class="btn btn-tertiary" target="_blank">
                        <i class="fas fa-book"></i>
                        <span class="icon-manual" style="display: none;"></span>
                        User Manual
                    </a>
                </div>
                
                <div class="hero-stats">
                    <div class="hero-stat">
                        <span class="hero-stat-number" data-target="<?php echo $totalBusinesses; ?>"><?php echo number_format($totalBusinesses); ?>+</span>
                        <span class="hero-stat-label">Registered Businesses</span>
                    </div>
                    <div class="hero-stat">
                        <span class="hero-stat-number" data-target="<?php echo $totalProperties; ?>"><?php echo number_format($totalProperties); ?>+</span>
                        <span class="hero-stat-label">Properties Managed</span>
                    </div>
                    <div class="hero-stat">
                        <span class="hero-stat-number" data-target="<?php echo $totalRevenue; ?>"><?php echo $revenueDisplay; ?>+</span>
                        <span class="hero-stat-label">Revenue Collected</span>
                    </div>
                </div>
            </div>
            
            <div class="hero-visual">
                <div class="dashboard-mockup">
                    <!-- Dashboard Header -->
                    <div class="dashboard-header">
                        <div class="dashboard-header-left">
                            <div class="dashboard-logo">
                                <i class="fas fa-receipt"></i>
                                <span class="icon-receipt" style="display: none;"></span>
                            </div>
                            <div class="dashboard-title"><?php echo APP_NAME; ?></div>
                        </div>
                        <div class="dashboard-nav">
                            <div class="dashboard-nav-item active">Dashboard</div>
                            <div class="dashboard-nav-item">Billing</div>
                            <div class="dashboard-nav-item">Payments</div>
                            <div class="dashboard-nav-item">Reports</div>
                        </div>
                    </div>
                    
                    <!-- Dashboard Content -->
                    <div class="dashboard-content">
                        <!-- Stats Cards -->
                        <div class="dashboard-stats">
                            <div class="stat-card">
                                <div class="stat-value"><?php echo number_format($totalBusinesses); ?></div>
                                <div class="stat-label">Total Businesses</div>
                                <div class="stat-change <?php echo ($monthlyGrowth['businesses'] ?? 0) > 0 ? 'positive' : (($monthlyGrowth['businesses'] ?? 0) < 0 ? 'negative' : 'neutral'); ?>">
                                    <?php 
                                    $bizGrowth = $monthlyGrowth['businesses'] ?? 0;
                                    echo $bizGrowth > 0 ? '↗ +' : ($bizGrowth < 0 ? '↘ ' : '→ ');
                                    echo abs($bizGrowth) . '% this month'; 
                                    ?>
                                </div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value">₵<?php echo number_format($totalRevenue, 0); ?></div>
                                <div class="stat-label">Revenue Collected</div>
                                <div class="stat-change <?php echo ($monthlyGrowth['revenue'] ?? 0) > 0 ? 'positive' : (($monthlyGrowth['revenue'] ?? 0) < 0 ? 'negative' : 'neutral'); ?>">
                                    <?php 
                                    $revGrowth = $monthlyGrowth['revenue'] ?? 0;
                                    echo $revGrowth > 0 ? '↗ +' : ($revGrowth < 0 ? '↘ ' : '→ ');
                                    echo abs($revGrowth) . '% this month'; 
                                    ?>
                                </div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value"><?php echo number_format($totalProperties); ?></div>
                                <div class="stat-label">Properties</div>
                                <div class="stat-change positive">→ Stable count</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value"><?php echo $collectionRate; ?>%</div>
                                <div class="stat-label">Collection Rate</div>
                                <div class="stat-change <?php echo $collectionRate >= 70 ? 'positive' : ($collectionRate >= 50 ? 'neutral' : 'negative'); ?>">
                                    <?php echo $collectionRate >= 70 ? '↗ Excellent' : ($collectionRate >= 50 ? '→ Good' : '↘ Needs attention'); ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Main Content -->
                        <div class="dashboard-main">
                            <div class="chart-card">
                                <div class="chart-header">
                                    <div class="chart-title">Collection Rate Overview</div>
                                    <div class="chart-period">Current Status: <?php echo $collectionRate; ?>%</div>
                                </div>
                                <div class="chart-area">
                                    <div class="chart-line" style="height: <?php echo min(80, max(20, ($collectionRate ?? 60))); ?>%;"></div>
                                </div>
                            </div>
                            
                            <div class="activity-feed">
                                <div class="activity-header">Recent Activities</div>
                                <?php if (!empty($recentPayments)): ?>
                                    <?php foreach (array_slice($recentPayments, 0, 3) as $payment): ?>
                                        <div class="activity-item">
                                            <div class="activity-icon success">
                                                <i class="fas fa-check"></i>
                                            </div>
                                            <div class="activity-content">
                                                <div class="activity-text">
                                                    Payment of ₵<?php echo number_format($payment['amount_paid'], 2); ?> from 
                                                    <?php echo htmlspecialchars($payment['payer_name']); ?>
                                                </div>
                                                <div class="activity-time">
                                                    <?php 
                                                    $paymentDate = new DateTime($payment['payment_date']);
                                                    $now = new DateTime();
                                                    $diff = $now->diff($paymentDate);
                                                    
                                                    if ($diff->days == 0) {
                                                        if ($diff->h == 0) {
                                                            echo $diff->i . ' minutes ago';
                                                        } else {
                                                            echo $diff->h . ' hours ago';
                                                        }
                                                    } else {
                                                        echo $diff->days . ' days ago';
                                                    }
                                                    ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="activity-item">
                                        <div class="activity-icon info">
                                            <i class="fas fa-info-circle"></i>
                                        </div>
                                        <div class="activity-content">
                                            <div class="activity-text">System ready for revenue collection</div>
                                            <div class="activity-time">Start collecting payments today</div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($totalBills > 0): ?>
                                    <div class="activity-item">
                                        <div class="activity-icon info">
                                            <i class="fas fa-file-invoice"></i>
                                            <span class="icon-invoice" style="display: none;"></span>
                                        </div>
                                        <div class="activity-content">
                                            <div class="activity-text"><?php echo number_format($totalBills); ?> bills generated</div>
                                            <div class="activity-time"><?php echo number_format($pendingBills); ?> pending collection</div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($defaulters > 0): ?>
                                    <div class="activity-item">
                                        <div class="activity-icon warning">
                                            <i class="fas fa-exclamation-triangle"></i>
                                        </div>
                                        <div class="activity-content">
                                            <div class="activity-text"><?php echo number_format($defaulters); ?> accounts with outstanding bills</div>
                                            <div class="activity-time">Follow-up required</div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="features-section" id="features">
        <div class="features-container">
            <div class="section-header fade-in">
                <div class="section-badge">✨ FEATURES</div>
                <h2 class="section-title">Powerful Revenue Management Tools</h2>
                <p class="section-subtitle">
                    Everything you need to manage assembly revenue efficiently with modern technology and automation
                </p>
            </div>
            
            <div class="features-grid">
                <div class="feature-card fade-in">
                    <i class="fas fa-building feature-icon"></i>
                    <span class="icon-building feature-icon" style="display: none;"></span>
                    <h3 class="feature-title">Business & Property Management</h3>
                    <p class="feature-description">
                        Comprehensive registration and management system with GPS location tracking and detailed profile management.
                    </p>
                    <ul class="feature-list">
                        <li>GPS location capture with Google Maps</li>
                        <li>Dynamic billing based on business types</li>
                        <li>Property structure classification</li>
                        <li>Zone and sub-zone management</li>
                    </ul>
                </div>

                <div class="feature-card fade-in">
                    <i class="fas fa-file-invoice feature-icon"></i>
                    <span class="icon-invoice feature-icon" style="display: none;"></span>
                    <h3 class="feature-title">Automated Billing System</h3>
                    <p class="feature-description">
                        Smart bill generation with QR codes, automatic calculations, and bulk printing capabilities for efficient revenue collection.
                    </p>
                    <ul class="feature-list">
                        <li>Auto-generated account numbers</li>
                        <li>QR codes on every bill</li>
                        <li>Bulk bill printing by zone</li>
                        <li>Fee structure management</li>
                    </ul>
                </div>

                <div class="feature-card fade-in">
                    <i class="fas fa-mobile-alt feature-icon"></i>
                    <span class="icon-mobile feature-icon" style="display: none;"></span>
                    <h3 class="feature-title">Mobile Money Integration</h3>
                    <p class="feature-description">
                        Seamless payment processing through MTN, Telecel, and AirtelTigo with instant receipt generation and SMS notifications.
                    </p>
                    <ul class="feature-list">
                        <li>PayStack integration for mobile money</li>
                        <li>Instant downloadable receipts</li>
                        <li>SMS payment confirmations</li>
                        <li>Public payment portal</li>
                    </ul>
                </div>

                <div class="feature-card fade-in">
                    <i class="fas fa-users-cog feature-icon"></i>
                    <span class="icon-users feature-icon" style="display: none;"></span>
                    <h3 class="feature-title">Multi-Role User Management</h3>
                    <p class="feature-description">
                        Five distinct user roles with specific permissions: Super Admin, Admin, Officer, Revenue Officer, and Data Collector.
                    </p>
                    <ul class="feature-list">
                        <li>Role-based access control</li>
                        <li>Audit logging for all activities</li>
                        <li>Password reset requirements</li>
                        <li>System restriction controls</li>
                    </ul>
                </div>

                <div class="feature-card fade-in">
                    <i class="fas fa-chart-bar feature-icon"></i>
                    <span class="icon-chart feature-icon" style="display: none;"></span>
                    <h3 class="feature-title">Analytics & Reports</h3>
                    <p class="feature-description">
                        Comprehensive reporting system with revenue analytics, defaulter management, and performance insights.
                    </p>
                    <ul class="feature-list">
                        <li>Real-time revenue analytics</li>
                        <li>Defaulter identification system</li>
                        <li>Custom report generation</li>
                        <li>Performance tracking</li>
                    </ul>
                </div>

                <div class="feature-card fade-in">
                    <i class="fas fa-shield-alt feature-icon"></i>
                    <span class="icon-shield feature-icon" style="display: none;"></span>
                    <h3 class="feature-title">Security & Backup</h3>
                    <p class="feature-description">
                        Advanced security features with automated backups, bill adjustments, and system restriction controls.
                    </p>
                    <ul class="feature-list">
                        <li>Automated backup and restore</li>
                        <li>Bulk and single bill adjustments</li>
                        <li>System restriction timers</li>
                        <li>Secure authentication</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="cta-section">
        <div class="cta-container">
            <div class="cta-badge">
                🚀 GET STARTED
            </div>
            <h2 class="cta-title">Ready to Transform Revenue Management?</h2>
            <p class="cta-subtitle">
                Join progressive assemblies already using QUICKBILL 360 to streamline their revenue collection and improve efficiency. Start your digital transformation today.
            </p>
            
            <div class="hero-buttons">
                <a href="auth/login.php" class="btn btn-primary">
                    <i class="fas fa-rocket"></i>
                    <span class="icon-rocket" style="display: none;"></span>
                    Access Dashboard
                </a>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-container">
            <p class="footer-text">Built with ❤️ for modern assembly revenue management 💼</p>
            <p class="footer-tech">
                &copy; <?php echo date('Y'); ?> <?php echo APP_NAME; ?>. Empowering Assembly Revenue Excellence.
            </p>
        </div>
    </footer>

    <script>
        // Global variables for real data
        const realData = {
            totalBusinesses: <?php echo $totalBusinesses; ?>,
            totalProperties: <?php echo $totalProperties; ?>,
            totalRevenue: <?php echo $totalRevenue; ?>,
            totalPayments: <?php echo $totalPayments; ?>,
            collectionRate: <?php echo $collectionRate; ?>,
            defaulters: <?php echo $defaulters; ?>,
            monthlyGrowth: <?php echo json_encode($monthlyGrowth ?? ['revenue' => 0, 'businesses' => 0]); ?>
        };

        // Check if Font Awesome loaded, if not show emoji icons
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                const testIcon = document.querySelector('.fas.fa-bars');
                if (!testIcon || getComputedStyle(testIcon, ':before').content === 'none') {
                    document.querySelectorAll('.fas, .far').forEach(function(icon) {
                        icon.style.display = 'none';
                    });
                    document.querySelectorAll('[class*="icon-"]').forEach(function(emoji) {
                        emoji.style.display = 'inline';
                    });
                }
            }, 100);
        });

        // Mobile Menu Functionality
        document.addEventListener('DOMContentLoaded', function() {
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');
            const mobileMenu = document.getElementById('mobileMenu');
            const menuIcon = document.getElementById('menuIcon');
            let isMenuOpen = false;

            mobileMenuToggle.addEventListener('click', function() {
                isMenuOpen = !isMenuOpen;
                mobileMenu.classList.toggle('active');
                
                // Update icon
                if (isMenuOpen) {
                    menuIcon.className = 'fas fa-times';
                } else {
                    menuIcon.className = 'fas fa-bars';
                }
            });

            // Close menu when clicking on mobile nav links
            document.querySelectorAll('.mobile-nav-link').forEach(link => {
                link.addEventListener('click', function() {
                    isMenuOpen = false;
                    mobileMenu.classList.remove('active');
                    menuIcon.className = 'fas fa-bars';
                });
            });

            // Close menu when clicking outside
            document.addEventListener('click', function(e) {
                if (isMenuOpen && !mobileMenuToggle.contains(e.target) && !mobileMenu.contains(e.target)) {
                    isMenuOpen = false;
                    mobileMenu.classList.remove('active');
                    menuIcon.className = 'fas fa-bars';
                }
            });
        });

        // Enhanced navbar scroll effect
        window.addEventListener('scroll', function() {
            const navbar = document.getElementById('navbar');
            if (window.scrollY > 50) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        });

        // Intersection Observer for fade-in animations
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver(function(entries) {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                    // Add staggered animation for feature cards
                    if (entry.target.classList.contains('feature-card')) {
                        const delay = Array.from(entry.target.parentNode.children).indexOf(entry.target) * 100;
                        entry.target.style.transitionDelay = delay + 'ms';
                    }
                }
            });
        }, observerOptions);

        // Observe all fade-in elements
        document.querySelectorAll('.fade-in').forEach(el => {
            observer.observe(el);
        });

        // Enhanced smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
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

        // Enhanced loading states for buttons
        document.querySelectorAll('.btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                if (this.href && !this.href.includes('#') && !this.href.includes('javascript:')) {
                    const originalText = this.innerHTML;
                    this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
                    this.style.pointerEvents = 'none';
                    this.style.opacity = '0.8';
                    
                    // Reset after 3 seconds if page doesn't load
                    setTimeout(() => {
                        this.innerHTML = originalText;
                        this.style.pointerEvents = 'auto';
                        this.style.opacity = '1';
                    }, 3000);
                }
            });
        });

        // Dashboard mockup hover effects
        const dashboardMockup = document.querySelector('.dashboard-mockup');
        if (dashboardMockup) {
            dashboardMockup.addEventListener('mouseenter', function() {
                this.style.transform = 'rotateY(0deg) rotateX(0deg) scale(1.02)';
            });
            
            dashboardMockup.addEventListener('mouseleave', function() {
                this.style.transform = 'rotateY(-5deg) rotateX(10deg) scale(1)';
            });
        }

        // Animated counter for hero stats using real data
        function animateCounters() {
            const counters = document.querySelectorAll('.hero-stat-number');
            
            counters.forEach((counter, index) => {
                const text = counter.textContent;
                const targetAttr = counter.getAttribute('data-target');
                const target = parseInt(targetAttr) || parseInt(text.replace(/[^\d]/g, ''));
                
                if (target === 0) return;
                
                let current = 0;
                const increment = Math.max(1, Math.ceil(target / 50));
                const timer = setInterval(() => {
                    current += increment;
                    if (current >= target) {
                        // Set final formatted value
                        counter.textContent = text;
                        clearInterval(timer);
                    } else {
                        // Show animated count
                        const prefix = text.includes('₵') ? '₵' : '';
                        const suffix = text.includes('+') ? '+' : (text.includes('K') ? 'K+' : '');
                        
                        if (text.includes('K')) {
                            counter.textContent = prefix + Math.floor(current/1000) + suffix;
                        } else {
                            counter.textContent = prefix + Math.floor(current).toLocaleString() + suffix;
                        }
                    }
                }, 40);
            });
        }

        // Trigger counter animation when hero section is visible
        const heroObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    animateCounters();
                    heroObserver.unobserve(entry.target);
                }
            });
        });

        const heroSection = document.querySelector('.hero');
        if (heroSection) {
            heroObserver.observe(heroSection);
        }

        // Add parallax effect to hero background (disabled on mobile for performance)
        if (window.innerWidth > 768) {
            window.addEventListener('scroll', () => {
                const scrolled = window.pageYOffset;
                const hero = document.querySelector('.hero');
                if (hero && scrolled < window.innerHeight) {
                    hero.style.transform = `translateY(${scrolled * 0.5}px)`;
                }
            });
        }

        // Add floating animation to feature cards on load
        function addFloatingAnimation() {
            const cards = document.querySelectorAll('.feature-card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.2}s`;
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-15px) scale(1.02)';
                });
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                });
            });
        }

        // Initialize animations after a delay
        setTimeout(addFloatingAnimation, 1000);

        // Add pulse effect to important elements
        const pulseElements = document.querySelectorAll('.hero-badge, .cta-badge');
        pulseElements.forEach(element => {
            element.addEventListener('mouseenter', function() {
                this.style.animation = 'pulse 0.6s ease-in-out';
            });
            element.addEventListener('animationend', function() {
                this.style.animation = 'pulse 2s infinite';
            });
        });

        // Handle orientation change for mobile devices
        window.addEventListener('orientationchange', function() {
            setTimeout(function() {
                window.scrollTo(0, 0);
            }, 100);
        });

        // Optimize for touch devices
        if ('ontouchstart' in window) {
            document.body.classList.add('touch-device');
            
            // Remove hover effects on touch devices
            const hoverElements = document.querySelectorAll('.feature-card, .btn, .dashboard-mockup');
            hoverElements.forEach(element => {
                element.addEventListener('touchstart', function() {
                    this.classList.add('touch-active');
                });
                element.addEventListener('touchend', function() {
                    setTimeout(() => {
                        this.classList.remove('touch-active');
                    }, 300);
                });
            });
        }

        // Performance optimization: Disable animations on low-end devices
        if (navigator.hardwareConcurrency && navigator.hardwareConcurrency <= 2) {
            document.documentElement.style.setProperty('--transition', 'none');
        }

        // Intersection Observer for performance optimization
        const mediaQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
        if (mediaQuery.matches) {
            // Disable animations for users who prefer reduced motion
            document.documentElement.style.setProperty('--transition', 'none');
        }

        // Log real data for debugging (remove in production)
        console.log('QuickBill 360 Real Statistics:', realData);
    </script>
</body>
</html>