<?php
/**
 * Simple Admin Dashboard for QUICKBILL 305
 * Basic version to test functionality
 */

// Define application constant
define('QUICKBILL_305', true);

// Include configuration files
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Start session
session_start();

// Include auth and security
require_once '../includes/auth.php';
require_once '../includes/security.php';

// Initialize auth and security
initAuth();
initSecurity();

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit();
}

$currentUser = getCurrentUser();
$userDisplayName = getUserDisplayName($currentUser);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - <?php echo APP_NAME; ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome Icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <style>
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: #f8f9fa;
        }
        
        .navbar-brand {
            font-weight: 600;
        }
        
        .sidebar {
            min-height: calc(100vh - 56px);
            background: linear-gradient(180deg, #1e293b 0%, #334155 100%);
            color: white;
        }
        
        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.8);
            padding: 0.75rem 1.5rem;
            border-left: 3px solid transparent;
        }
        
        .sidebar .nav-link:hover {
            color: white;
            background-color: rgba(255, 255, 255, 0.1);
            border-left-color: #2563eb;
        }
        
        .sidebar .nav-link.active {
            color: white;
            background-color: rgba(37, 99, 235, 0.2);
            border-left-color: #2563eb;
        }
        
        .main-content {
            min-height: calc(100vh - 56px);
        }
        
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
        }
        
        .stats-card {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: white;
            border-radius: 12px;
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
                <i class="fas fa-receipt me-2"></i>
                <?php echo APP_NAME; ?>
            </a>
            
            <div class="navbar-nav ms-auto">
                <div class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-1"></i>
                        <?php echo htmlspecialchars($userDisplayName); ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="../auth/logout.php">
                            <i class="fas fa-sign-out-alt me-2"></i>Logout
                        </a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 px-0">
                <div class="sidebar">
                    <nav class="nav flex-column py-3">
                        <a class="nav-link active" href="#">
                            <i class="fas fa-tachometer-alt me-2"></i>
                            Dashboard
                        </a>
                        <a class="nav-link" href="#">
                            <i class="fas fa-users me-2"></i>
                            Users
                        </a>
                        <a class="nav-link" href="#">
                            <i class="fas fa-building me-2"></i>
                            Businesses
                        </a>
                        <a class="nav-link" href="#">
                            <i class="fas fa-home me-2"></i>
                            Properties
                        </a>
                        <a class="nav-link" href="#">
                            <i class="fas fa-file-invoice me-2"></i>
                            Billing
                        </a>
                        <a class="nav-link" href="#">
                            <i class="fas fa-credit-card me-2"></i>
                            Payments
                        </a>
                        <a class="nav-link" href="#">
                            <i class="fas fa-chart-bar me-2"></i>
                            Reports
                        </a>
                        <a class="nav-link" href="#">
                            <i class="fas fa-cog me-2"></i>
                            Settings
                        </a>
                    </nav>
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-md-9 col-lg-10">
                <div class="main-content p-4">
                    <!-- Welcome Header -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h1 class="h3 mb-0">Welcome back, <?php echo htmlspecialchars($userDisplayName); ?>!</h1>
                            <p class="text-muted mb-0">Here's what's happening with your billing system today.</p>
                        </div>
                        <div>
                            <span class="badge bg-primary fs-6"><?php echo htmlspecialchars(getCurrentUserRole()); ?></span>
                        </div>
                    </div>

                    <!-- Stats Cards -->
                    <div class="row mb-4">
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card stats-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <h6 class="card-title text-uppercase">Total Revenue</h6>
                                            <h3 class="mb-0">₵ 0.00</h3>
                                        </div>
                                        <div class="align-self-center">
                                            <i class="fas fa-money-bill-wave fa-2x opacity-75"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card bg-success text-white">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <h6 class="card-title text-uppercase">Total Businesses</h6>
                                            <h3 class="mb-0">0</h3>
                                        </div>
                                        <div class="align-self-center">
                                            <i class="fas fa-building fa-2x opacity-75"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card bg-info text-white">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <h6 class="card-title text-uppercase">Total Properties</h6>
                                            <h3 class="mb-0">0</h3>
                                        </div>
                                        <div class="align-self-center">
                                            <i class="fas fa-home fa-2x opacity-75"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card bg-warning text-white">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <h6 class="card-title text-uppercase">Outstanding</h6>
                                            <h3 class="mb-0">₵ 0.00</h3>
                                        </div>
                                        <div class="align-self-center">
                                            <i class="fas fa-exclamation-triangle fa-2x opacity-75"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">Quick Actions</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-3 mb-3">
                                            <button class="btn btn-primary w-100">
                                                <i class="fas fa-plus me-2"></i>Add Business
                                            </button>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <button class="btn btn-success w-100">
                                                <i class="fas fa-plus me-2"></i>Add Property
                                            </button>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <button class="btn btn-info w-100">
                                                <i class="fas fa-money-bill me-2"></i>Record Payment
                                            </button>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <button class="btn btn-warning w-100">
                                                <i class="fas fa-file-invoice me-2"></i>Generate Bills
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- System Status -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">System Status</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row text-center">
                                        <div class="col-md-3">
                                            <i class="fas fa-database fa-3x text-success mb-2"></i>
                                            <h6>Database</h6>
                                            <span class="badge bg-success">Connected</span>
                                        </div>
                                        <div class="col-md-3">
                                            <i class="fas fa-server fa-3x text-success mb-2"></i>
                                            <h6>System</h6>
                                            <span class="badge bg-success">Running</span>
                                        </div>
                                        <div class="col-md-3">
                                            <i class="fas fa-shield-alt fa-3x text-success mb-2"></i>
                                            <h6>Security</h6>
                                            <span class="badge bg-success">Active</span>
                                        </div>
                                        <div class="col-md-3">
                                            <i class="fas fa-users fa-3x text-info mb-2"></i>
                                            <h6>Users Online</h6>
                                            <span class="badge bg-info">1</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Activity -->
                    <div class="row mt-4">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">Recent Activity</h5>
                                </div>
                                <div class="card-body">
                                    <div class="text-center py-4">
                                        <i class="fas fa-history fa-3x text-muted mb-3"></i>
                                        <p class="text-muted">No recent activity to display.</p>
                                        <small class="text-muted">Activity will appear here as you use the system.</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Success Message -->
                    <div class="alert alert-success mt-4" role="alert">
                        <h4 class="alert-heading">🎉 Admin Dashboard is Working!</h4>
                        <p>Congratulations! Your QUICKBILL 305 admin dashboard is now functional. You can now proceed with building additional features.</p>
                        <hr>
                        <p class="mb-0">Next steps: Add user management, business registration, and payment processing modules.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
</body>
</html>