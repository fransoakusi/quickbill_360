<?php
/**
 * Admin Header Template - QUICKBILL 305
 * Common header for all admin pages
 */

if (!defined('QUICKBILL_305')) {
    die('Direct access not permitted');
}

// Ensure user is logged in
if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

$currentUser = getCurrentUser();
$userDisplayName = getUserDisplayName($currentUser);
$currentUserRole = getCurrentUserRole();

// Set page title if not already set
if (!isset($pageTitle)) {
    $pageTitle = 'Admin Panel';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - <?php echo APP_NAME; ?></title>
    
    <!-- Favicons -->
    <link rel="icon" type="image/x-icon" href="../../assets/images/favicon.ico">
    <link rel="apple-touch-icon" href="../../assets/images/apple-touch-icon.png">
    
    <!-- CSS Libraries -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    
    <!-- Custom Admin CSS -->
    <style>
        :root {
            --primary-color: #667eea;
            --secondary-color: #764ba2;
            --success-color: #48bb78;
            --info-color: #4299e1;
            --warning-color: #ed8936;
            --danger-color: #f56565;
            --dark-color: #2d3748;
            --light-color: #f7fafc;
            --sidebar-width: 280px;
            --header-height: 70px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background-color: #f8f9fa;
            margin: 0;
            padding: 0;
            line-height: 1.6;
        }

        /* Top Navigation */
        .admin-navbar {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 15px 25px;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: var(--header-height);
        }

        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 15px;
            color: white;
            text-decoration: none;
            font-size: 1.25rem;
            font-weight: 600;
        }

        .navbar-brand:hover {
            color: white;
            text-decoration: none;
        }

        .navbar-brand i {
            font-size: 1.5rem;
        }

        .navbar-nav {
            display: flex;
            align-items: center;
            gap: 20px;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .nav-item {
            position: relative;
        }

        .nav-link {
            color: rgba(255,255,255,0.9);
            text-decoration: none;
            padding: 8px 12px;
            border-radius: 6px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .nav-link:hover {
            background: rgba(255,255,255,0.1);
            color: white;
            text-decoration: none;
        }

        .user-dropdown {
            position: relative;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            border: 2px solid rgba(255,255,255,0.3);
            cursor: pointer;
            transition: all 0.3s;
        }

        .user-avatar:hover {
            background: rgba(255,255,255,0.3);
            transform: scale(1.05);
        }

        /* Sidebar */
        .admin-sidebar {
            position: fixed;
            top: var(--header-height);
            left: 0;
            width: var(--sidebar-width);
            height: calc(100vh - var(--header-height));
            background: linear-gradient(180deg, var(--dark-color) 0%, #1a202c 100%);
            color: white;
            overflow-y: auto;
            z-index: 999;
            transition: transform 0.3s ease;
        }

        .sidebar-nav {
            padding: 20px 0;
        }

        .nav-section {
            margin-bottom: 25px;
        }

        .nav-section-title {
            color: #a0aec0;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            padding: 0 20px;
            margin-bottom: 10px;
            letter-spacing: 1px;
        }

        .sidebar-nav-item {
            margin-bottom: 2px;
        }

        .sidebar-nav-link {
            color: #e2e8f0;
            text-decoration: none;
            padding: 12px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.3s;
            border-left: 3px solid transparent;
        }

        .sidebar-nav-link:hover {
            background: rgba(255,255,255,0.1);
            color: white;
            border-left-color: var(--primary-color);
            text-decoration: none;
        }

        .sidebar-nav-link.active {
            background: rgba(102, 126, 234, 0.2);
            color: white;
            border-left-color: var(--primary-color);
        }

        .sidebar-nav-icon {
            width: 20px;
            text-align: center;
            flex-shrink: 0;
        }

        /* Main Content */
        .admin-content {
            margin-left: var(--sidebar-width);
            margin-top: var(--header-height);
            min-height: calc(100vh - var(--header-height));
            padding: 30px;
            transition: margin-left 0.3s ease;
        }

        /* Page Header */
        .page-header {
            margin-bottom: 30px;
        }

        .page-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-subtitle {
            color: #6c757d;
            font-size: 1rem;
            margin-bottom: 0;
        }

        .breadcrumb {
            background: none;
            padding: 0;
            margin-bottom: 15px;
            font-size: 0.875rem;
        }

        .breadcrumb-item + .breadcrumb-item::before {
            content: "›";
            color: #6c757d;
        }

        .breadcrumb-item.active {
            color: #6c757d;
        }

        .breadcrumb-item a {
            color: var(--primary-color);
            text-decoration: none;
        }

        .breadcrumb-item a:hover {
            text-decoration: underline;
        }

        /* Cards */
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 24px;
            transition: box-shadow 0.3s;
        }

        .card:hover {
            box-shadow: 0 4px 20px rgba(0,0,0,0.12);
        }

        .card-header {
            background: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
            border-radius: 12px 12px 0 0 !important;
            padding: 20px 25px;
        }

        .card-title {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-body {
            padding: 25px;
        }

        /* Form Elements */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            font-weight: 500;
            color: var(--dark-color);
            margin-bottom: 0.5rem;
        }

        .form-control {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px 16px;
            font-size: 0.875rem;
            transition: all 0.3s;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }

        .form-text {
            font-size: 0.8rem;
            color: #6c757d;
            margin-top: 0.5rem;
        }

        /* Buttons */
        .btn {
            border-radius: 8px;
            padding: 10px 20px;
            font-weight: 500;
            font-size: 0.875rem;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            text-decoration: none;
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-info {
            background: var(--info-color);
            color: white;
        }

        .btn-warning {
            background: var(--warning-color);
            color: white;
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-outline-primary {
            border: 1px solid var(--primary-color);
            color: var(--primary-color);
            background: transparent;
        }

        .btn-outline-primary:hover {
            background: var(--primary-color);
            color: white;
        }

        .btn-outline-secondary {
            border: 1px solid #6c757d;
            color: #6c757d;
            background: transparent;
        }

        .btn-outline-secondary:hover {
            background: #6c757d;
            color: white;
        }

        .btn-lg {
            padding: 12px 30px;
            font-size: 1rem;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.8rem;
        }

        .btn-block {
            width: 100%;
            justify-content: center;
        }

        /* Tables */
        .table {
            margin-bottom: 0;
        }

        .table th {
            border-top: none;
            border-bottom: 1px solid #e9ecef;
            font-weight: 600;
            color: var(--dark-color);
            font-size: 0.875rem;
            padding: 12px 15px;
        }

        .table td {
            padding: 12px 15px;
            font-size: 0.875rem;
            border-top: 1px solid #f8f9fa;
        }

        .table-striped tbody tr:nth-of-type(odd) {
            background-color: #f8f9fa;
        }

        .table-hover tbody tr:hover {
            background-color: #e9ecef;
        }

        /* Badges */
        .badge {
            font-size: 0.75rem;
            padding: 0.4rem 0.8rem;
            border-radius: 6px;
            font-weight: 500;
        }

        .badge-primary { background: var(--primary-color); }
        .badge-secondary { background: #6c757d; }
        .badge-success { background: var(--success-color); }
        .badge-info { background: var(--info-color); }
        .badge-warning { background: var(--warning-color); }
        .badge-danger { background: var(--danger-color); }

        /* Alerts */
        .alert {
            border: none;
            border-radius: 10px;
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .alert-primary { background: #e3f2fd; color: #1565c0; }
        .alert-secondary { background: #f5f5f5; color: #424242; }
        .alert-success { background: #e8f5e8; color: #2e7d32; }
        .alert-info { background: #e1f5fe; color: #0277bd; }
        .alert-warning { background: #fff3e0; color: #ef6c00; }
        .alert-danger { background: #ffebee; color: #c62828; }

        .alert-heading {
            font-weight: 600;
            margin-bottom: 8px;
        }

        .alert i {
            margin-top: 2px;
            flex-shrink: 0;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .admin-sidebar {
                transform: translateX(-100%);
            }

            .admin-sidebar.show {
                transform: translateX(0);
            }

            .admin-content {
                margin-left: 0;
            }

            .navbar-brand span {
                display: none;
            }

            .page-title {
                font-size: 1.5rem;
            }

            .card-body {
                padding: 20px;
            }
        }

        /* Custom Scrollbar */
        .admin-sidebar::-webkit-scrollbar {
            width: 6px;
        }

        .admin-sidebar::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.1);
        }

        .admin-sidebar::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.3);
            border-radius: 3px;
        }

        .admin-sidebar::-webkit-scrollbar-thumb:hover {
            background: rgba(255,255,255,0.5);
        }

        /* Loading States */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }

        .spinner-border-sm {
            width: 1rem;
            height: 1rem;
        }

        /* Utility Classes */
        .text-muted { color: #6c757d !important; }
        .text-primary { color: var(--primary-color) !important; }
        .text-success { color: var(--success-color) !important; }
        .text-info { color: var(--info-color) !important; }
        .text-warning { color: var(--warning-color) !important; }
        .text-danger { color: var(--danger-color) !important; }

        .bg-primary { background-color: var(--primary-color) !important; }
        .bg-success { background-color: var(--success-color) !important; }
        .bg-info { background-color: var(--info-color) !important; }
        .bg-warning { background-color: var(--warning-color) !important; }
        .bg-danger { background-color: var(--danger-color) !important; }

        .d-none { display: none !important; }
        .d-block { display: block !important; }
        .d-flex { display: flex !important; }
        .d-grid { display: grid !important; }

        .gap-2 { gap: 0.5rem !important; }
        .gap-3 { gap: 1rem !important; }

        .ms-2 { margin-left: 0.5rem !important; }
        .me-2 { margin-right: 0.5rem !important; }
        .mt-3 { margin-top: 1rem !important; }
        .mb-3 { margin-bottom: 1rem !important; }

        .p-3 { padding: 1rem !important; }
        .py-4 { padding-top: 1.5rem !important; padding-bottom: 1.5rem !important; }

        .text-center { text-align: center !important; }
        .text-end { text-align: right !important; }

        .w-100 { width: 100% !important; }
        .h-100 { height: 100% !important; }

        .align-items-center { align-items: center !important; }
        .justify-content-between { justify-content: space-between !important; }
        .justify-content-center { justify-content: center !important; }

        .position-relative { position: relative !important; }
        .position-fixed { position: fixed !important; }

        /* Animation */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .fade-in {
            animation: fadeIn 0.3s ease-out;
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <nav class="admin-navbar">
        <a href="../index.php" class="navbar-brand">
            <i class="fas fa-receipt"></i>
            <span><?php echo APP_NAME; ?></span>
        </a>
        
        <ul class="navbar-nav">
            <li class="nav-item">
                <a href="../notifications/index.php" class="nav-link" title="Notifications">
                    <i class="fas fa-bell"></i>
                    <span class="badge badge-danger">3</span>
                </a>
            </li>
            <li class="nav-item user-dropdown">
                <div class="nav-link" style="cursor: pointer;" onclick="toggleUserMenu()" id="userMenuToggle">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($currentUser['first_name'], 0, 1)); ?>
                    </div>
                    <span class="d-none d-md-inline"><?php echo htmlspecialchars($userDisplayName); ?></span>
                    <i class="fas fa-chevron-down"></i>
                </div>
                
                <!-- User Menu (Hidden by default) -->
                <div class="dropdown-menu dropdown-menu-end" id="userMenu" style="display: none; position: absolute; top: 100%; right: 0; background: white; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.15); min-width: 200px; z-index: 1001; margin-top: 8px;">
                    <div style="padding: 15px; border-bottom: 1px solid #e9ecef;">
                        <div style="font-weight: 600; color: #495057;"><?php echo htmlspecialchars($userDisplayName); ?></div>
                        <div style="font-size: 0.875rem; color: #6c757d;"><?php echo htmlspecialchars($currentUserRole); ?></div>
                    </div>
                    <a href="../users/view.php?id=<?php echo $currentUser['user_id']; ?>" style="display: flex; align-items: center; gap: 10px; padding: 12px 15px; color: #495057; text-decoration: none;">
                        <i class="fas fa-user"></i>
                        My Profile
                    </a>
                    <a href="../settings/index.php" style="display: flex; align-items: center; gap: 10px; padding: 12px 15px; color: #495057; text-decoration: none;">
                        <i class="fas fa-cog"></i>
                        Settings
                    </a>
                    <div style="border-top: 1px solid #e9ecef; margin: 8px 0;"></div>
                    <a href="../../auth/logout.php" style="display: flex; align-items: center; gap: 10px; padding: 12px 15px; color: #dc3545; text-decoration: none;">
                        <i class="fas fa-sign-out-alt"></i>
                        Logout
                    </a>
                </div>
            </li>
        </ul>
    </nav>

    <!-- Sidebar -->
    <nav class="admin-sidebar" id="adminSidebar">
        <div class="sidebar-nav">
            <!-- Dashboard -->
            <div class="nav-section">
                <div class="sidebar-nav-item">
                    <a href="../index.php" class="sidebar-nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'index.php' && strpos($_SERVER['REQUEST_URI'], '/admin/') !== false ? 'active' : ''; ?>">
                        <i class="fas fa-tachometer-alt sidebar-nav-icon"></i>
                        Dashboard
                    </a>
                </div>
            </div>
            
            <!-- Core Management -->
            <div class="nav-section">
                <div class="nav-section-title">Core Management</div>
                <div class="sidebar-nav-item">
                    <a href="../users/index.php" class="sidebar-nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/users/') !== false ? 'active' : ''; ?>">
                        <i class="fas fa-users sidebar-nav-icon"></i>
                        Users
                    </a>
                </div>
                <div class="sidebar-nav-item">
                    <a href="../businesses/index.php" class="sidebar-nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/businesses/') !== false ? 'active' : ''; ?>">
                        <i class="fas fa-building sidebar-nav-icon"></i>
                        Businesses
                    </a>
                </div>
                <div class="sidebar-nav-item">
                    <a href="../properties/index.php" class="sidebar-nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/properties/') !== false ? 'active' : ''; ?>">
                        <i class="fas fa-home sidebar-nav-icon"></i>
                        Properties
                    </a>
                </div>
                <div class="sidebar-nav-item">
                    <a href="../zones/index.php" class="sidebar-nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/zones/') !== false ? 'active' : ''; ?>">
                        <i class="fas fa-map-marked-alt sidebar-nav-icon"></i>
                        Zones & Areas
                    </a>
                </div>
            </div>
            
            <!-- Billing & Payments -->
            <div class="nav-section">
                <div class="nav-section-title">Billing & Payments</div>
                <div class="sidebar-nav-item">
                    <a href="../billing/index.php" class="sidebar-nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/billing/') !== false ? 'active' : ''; ?>">
                        <i class="fas fa-file-invoice sidebar-nav-icon"></i>
                        Billing
                    </a>
                </div>
                <div class="sidebar-nav-item">
                    <a href="../payments/index.php" class="sidebar-nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/payments/') !== false ? 'active' : ''; ?>">
                        <i class="fas fa-credit-card sidebar-nav-icon"></i>
                        Payments
                    </a>
                </div>
                <div class="sidebar-nav-item">
                    <a href="../fee_structure/business_fees.php" class="sidebar-nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/fee_structure/') !== false ? 'active' : ''; ?>">
                        <i class="fas fa-tags sidebar-nav-icon"></i>
                        Fee Structure
                    </a>
                </div>
            </div>
            
            <!-- Reports & System -->
            <div class="nav-section">
                <div class="nav-section-title">Reports & System</div>
                <div class="sidebar-nav-item">
                    <a href="../reports/index.php" class="sidebar-nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/reports/') !== false ? 'active' : ''; ?>">
                        <i class="fas fa-chart-bar sidebar-nav-icon"></i>
                        Reports
                    </a>
                </div>
                <div class="sidebar-nav-item">
                    <a href="../notifications/index.php" class="sidebar-nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/notifications/') !== false ? 'active' : ''; ?>">
                        <i class="fas fa-bell sidebar-nav-icon"></i>
                        Notifications
                    </a>
                </div>
                <div class="sidebar-nav-item">
                    <a href="../settings/index.php" class="sidebar-nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/settings/') !== false ? 'active' : ''; ?>">
                        <i class="fas fa-cog sidebar-nav-icon"></i>
                        Settings
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <script>
        // User menu toggle
        function toggleUserMenu() {
            const menu = document.getElementById('userMenu');
            menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
        }

        // Close user menu when clicking outside
        document.addEventListener('click', function(event) {
            const userMenu = document.getElementById('userMenu');
            const userMenuToggle = document.getElementById('userMenuToggle');
            
            if (!userMenuToggle.contains(event.target)) {
                userMenu.style.display = 'none';
            }
        });

        // Mobile sidebar toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('adminSidebar');
            sidebar.classList.toggle('show');
        }

        // Auto-hide sidebar on mobile when clicking outside
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('adminSidebar');
            const isClickInsideSidebar = sidebar.contains(event.target);
            const isClickOnToggle = event.target.closest('.navbar-toggle');
            
            if (!isClickInsideSidebar && !isClickOnToggle && window.innerWidth <= 768) {
                sidebar.classList.remove('show');
            }
        });

        // Add loading state to forms
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function() {
                    const submitBtn = form.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.classList.add('loading');
                    }
                });
            });
        });
    </script>