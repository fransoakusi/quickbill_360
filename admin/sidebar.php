 <?php
/**
 * Admin Sidebar Navigation for QUICKBILL 305
 * Contains all admin navigation links
 */

// Get current page for active menu highlighting
$currentPage = basename($_SERVER['PHP_SELF']);
$currentDir = basename(dirname($_SERVER['PHP_SELF']));

// Function to check if menu item is active
function isActiveMenu($page, $dir = '') {
    global $currentPage, $currentDir;
    
    if ($dir) {
        return $currentDir === $dir;
    }
    
    return $currentPage === $page;
}

// Function to get active class
function getActiveClass($page, $dir = '') {
    return isActiveMenu($page, $dir) ? 'active' : '';
}
?>

<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <nav class="sidebar-nav">
        <!-- Dashboard -->
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?php echo getActiveClass('index.php'); ?>" href="index.php">
                    <i class="fas fa-tachometer-alt"></i>
                    Dashboard
                </a>
            </li>
        </ul>
        
        <!-- Core Management -->
        <div class="sidebar-nav .nav-title">Core Management</div>
        <ul class="nav flex-column">
            <!-- Users -->
            <li class="nav-item">
                <a class="nav-link <?php echo getActiveClass('', 'users'); ?>" href="users/index.php">
                    <i class="fas fa-users"></i>
                    Users
                </a>
            </li>
            
            <!-- Businesses -->
            <li class="nav-item">
                <a class="nav-link <?php echo getActiveClass('', 'businesses'); ?>" href="businesses/index.php">
                    <i class="fas fa-building"></i>
                    Businesses
                </a>
            </li>
            
            <!-- Properties -->
            <li class="nav-item">
                <a class="nav-link <?php echo getActiveClass('', 'properties'); ?>" href="properties/index.php">
                    <i class="fas fa-home"></i>
                    Properties
                </a>
            </li>
            
            <!-- Zones -->
            <li class="nav-item">
                <a class="nav-link <?php echo getActiveClass('', 'zones'); ?>" href="zones/index.php">
                    <i class="fas fa-map-marked-alt"></i>
                    Zones & Areas
                </a>
            </li>
        </ul>
        
        <!-- Billing & Payments -->
        <div class="sidebar-nav .nav-title">Billing & Payments</div>
        <ul class="nav flex-column">
            <!-- Billing -->
            <li class="nav-item">
                <a class="nav-link <?php echo getActiveClass('', 'billing'); ?>" href="billing/index.php">
                    <i class="fas fa-file-invoice"></i>
                    Billing
                </a>
            </li>
            
            <!-- Payments -->
            <li class="nav-item">
                <a class="nav-link <?php echo getActiveClass('', 'payments'); ?>" href="payments/index.php">
                    <i class="fas fa-credit-card"></i>
                    Payments
                </a>
            </li>
            
            <!-- Fee Structure -->
            <li class="nav-item">
                <a class="nav-link <?php echo getActiveClass('', 'fee_structure'); ?>" href="fee_structure/business_fees.php">
                    <i class="fas fa-tags"></i>
                    Fee Structure
                </a>
            </li>
        </ul>
        
        <!-- Reports & Analytics -->
        <div class="sidebar-nav .nav-title">Reports & Analytics</div>
        <ul class="nav flex-column">
            <!-- Reports -->
            <li class="nav-item">
                <a class="nav-link <?php echo getActiveClass('', 'reports'); ?>" href="reports/index.php">
                    <i class="fas fa-chart-bar"></i>
                    Reports
                </a>
            </li>
            
            <!-- Map View -->
            <li class="nav-item">
                <a class="nav-link" href="map/index.php">
                    <i class="fas fa-map"></i>
                    Map View
                </a>
            </li>
        </ul>
        
        <!-- Communications -->
        <div class="sidebar-nav .nav-title">Communications</div>
        <ul class="nav flex-column">
            <!-- Notifications -->
            <li class="nav-item">
                <a class="nav-link <?php echo getActiveClass('', 'notifications'); ?>" href="notifications/index.php">
                    <i class="fas fa-bell"></i>
                    Notifications
                </a>
            </li>
        </ul>
        
        <!-- System -->
        <div class="sidebar-nav .nav-title">System</div>
        <ul class="nav flex-column">
            <!-- Settings -->
            <li class="nav-item">
                <a class="nav-link <?php echo getActiveClass('', 'settings'); ?>" href="settings/index.php">
                    <i class="fas fa-cog"></i>
                    Settings
                </a>
            </li>
            
            <!-- Audit Logs -->
            <li class="nav-item">
                <a class="nav-link <?php echo getActiveClass('', 'logs'); ?>" href="logs/audit_logs.php">
                    <i class="fas fa-history"></i>
                    Audit Logs
                </a>
            </li>
            
            <!-- Backup -->
            <li class="nav-item">
                <a class="nav-link" href="settings/backup.php">
                    <i class="fas fa-database"></i>
                    Backup & Restore
                </a>
            </li>
        </ul>
        
        <!-- Quick Actions -->
        <div class="sidebar-nav .nav-title">Quick Actions</div>
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link" href="businesses/add.php">
                    <i class="fas fa-plus-circle"></i>
                    Add Business
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link" href="properties/add.php">
                    <i class="fas fa-plus-circle"></i>
                    Add Property
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link" href="payments/record.php">
                    <i class="fas fa-money-bill-wave"></i>
                    Record Payment
                </a>
            </li>
        </ul>
        
        <!-- System Info -->
        <div class="p-3 mt-4" style="border-top: 1px solid rgba(255,255,255,0.1);">
            <small class="text-white-50">
                <div><strong><?php echo APP_NAME; ?></strong></div>
                <div>Version <?php echo APP_VERSION; ?></div>
                <div><?php echo date('Y'); ?> © All Rights Reserved</div>
            </small>
        </div>
    </nav>
</div>
