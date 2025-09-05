 <?php
/**
 * Officer Sidebar Navigation
 * Common sidebar for all officer pages
 */

// Prevent direct access
if (!defined('QUICKBILL_305')) {
    die('Direct access not permitted');
}

// Get current page to highlight active nav item
$currentPage = basename($_SERVER['PHP_SELF']);
$currentDir = basename(dirname($_SERVER['PHP_SELF']));

// Helper function to check if nav item is active
function isNavActive($page, $directory = '') {
    global $currentPage, $currentDir;
    
    if (!empty($directory)) {
        return $currentDir === $directory;
    }
    
    return $currentPage === $page;
}
?>

<!-- Sidebar -->
<div class="sidebar hidden" id="sidebar">
    <div class="sidebar-content">
        <!-- Dashboard -->
        <div class="nav-section">
            <div class="nav-item">
                <a href="../index.php" class="nav-link <?php echo isNavActive('index.php') ? 'active' : ''; ?>">
                    <span class="nav-icon">
                        <i class="fas fa-tachometer-alt"></i>
                        <span class="icon-dashboard" style="display: none;"></span>
                    </span>
                    Dashboard
                </a>
            </div>
        </div>
        
        <!-- Registration -->
        <div class="nav-section">
            <div class="nav-title">Registration</div>
            <div class="nav-item">
                <a href="../businesses/add.php" class="nav-link <?php echo ($currentDir === 'businesses' && $currentPage === 'add.php') ? 'active' : ''; ?>">
                    <span class="nav-icon">
                        <i class="fas fa-plus-circle"></i>
                        <span class="icon-plus" style="display: none;"></span>
                    </span>
                    Register Business
                </a>
            </div>
            <div class="nav-item">
                <a href="../properties/add.php" class="nav-link <?php echo ($currentDir === 'properties' && $currentPage === 'add.php') ? 'active' : ''; ?>">
                    <span class="nav-icon">
                        <i class="fas fa-plus-circle"></i>
                        <span class="icon-plus" style="display: none;"></span>
                    </span>
                    Register Property
                </a>
            </div>
        </div>
        
        <!-- Management -->
        <div class="nav-section">
            <div class="nav-title">Management</div>
            <div class="nav-item">
                <a href="../businesses/index.php" class="nav-link <?php echo isNavActive('businesses', 'businesses') ? 'active' : ''; ?>">
                    <span class="nav-icon">
                        <i class="fas fa-building"></i>
                        <span class="icon-building" style="display: none;"></span>
                    </span>
                    Businesses
                </a>
            </div>
            <div class="nav-item">
                <a href="../properties/index.php" class="nav-link <?php echo isNavActive('properties', 'properties') ? 'active' : ''; ?>">
                    <span class="nav-icon">
                        <i class="fas fa-home"></i>
                        <span class="icon-home" style="display: none;"></span>
                    </span>
                    Properties
                </a>
            </div>
        </div>
        
        <!-- Payments & Bills -->
        <div class="nav-section">
            <div class="nav-title">Payments & Bills</div>
            <div class="nav-item">
                <a href="../payments/record.php" class="nav-link <?php echo ($currentDir === 'payments' && $currentPage === 'record.php') ? 'active' : ''; ?>">
                    <span class="nav-icon">
                        <i class="fas fa-cash-register"></i>
                        <span class="icon-money" style="display: none;"></span>
                    </span>
                    Record Payment
                </a>
            </div>
            <div class="nav-item">
                <a href="../payments/search.php" class="nav-link <?php echo ($currentDir === 'payments' && $currentPage === 'search.php') ? 'active' : ''; ?>">
                    <span class="nav-icon">
                        <i class="fas fa-search"></i>
                        <span class="icon-search" style="display: none;"></span>
                    </span>
                    Search Accounts
                </a>
            </div>
            <div class="nav-item">
                <a href="../billing/generate.php" class="nav-link <?php echo ($currentDir === 'billing' && $currentPage === 'generate.php') ? 'active' : ''; ?>">
                    <span class="nav-icon">
                        <i class="fas fa-file-invoice"></i>
                        <span class="icon-receipt" style="display: none;"></span>
                    </span>
                    Generate Bills
                </a>
            </div>
            <div class="nav-item">
                <a href="../billing/print.php" class="nav-link <?php echo ($currentDir === 'billing' && $currentPage === 'print.php') ? 'active' : ''; ?>">
                    <span class="nav-icon">
                        <i class="fas fa-print"></i>
                        <span class="icon-print" style="display: none;"></span>
                    </span>
                    Print Bills
                </a>
            </div>
        </div>
        
        <!-- Maps & Locations -->
        <div class="nav-section">
            <div class="nav-title">Maps & Locations</div>
            <div class="nav-item">
                <a href="../map/businesses.php" class="nav-link <?php echo ($currentDir === 'map' && $currentPage === 'businesses.php') ? 'active' : ''; ?>">
                    <span class="nav-icon">
                        <i class="fas fa-map-marked-alt"></i>
                        <span class="icon-map" style="display: none;"></span>
                    </span>
                    Business Map
                </a>
            </div>
            <div class="nav-item">
                <a href="../map/properties.php" class="nav-link <?php echo ($currentDir === 'map' && $currentPage === 'properties.php') ? 'active' : ''; ?>">
                    <span class="nav-icon">
                        <i class="fas fa-map-marker-alt"></i>
                        <span class="icon-map" style="display: none;"></span>
                    </span>
                    Property Map
                </a>
            </div>
        </div>
    </div>
</div>

<style>
/* Sidebar Styles */
.sidebar {
    width: 280px;
    background: linear-gradient(180deg, #2d3748 0%, #1a202c 100%);
    color: white;
    transition: all 0.3s ease;
    overflow: hidden;
}

.sidebar.hidden {
    width: 0;
    min-width: 0;
}

.sidebar-content {
    width: 280px;
    padding: 20px 0;
}

.nav-section {
    margin-bottom: 30px;
}

.nav-title {
    color: #a0aec0;
    font-size: 12px;
    font-weight: bold;
    text-transform: uppercase;
    padding: 0 20px;
    margin-bottom: 10px;
    letter-spacing: 1px;
}

.nav-item {
    margin-bottom: 2px;
}

.nav-link {
    color: #e2e8f0;
    text-decoration: none;
    padding: 12px 20px;
    display: block;
    transition: all 0.3s;
    border-left: 3px solid transparent;
}

.nav-link:hover {
    background: rgba(255,255,255,0.1);
    color: white;
    border-left-color: #4299e1;
    text-decoration: none;
}

.nav-link.active {
    background: rgba(66, 153, 225, 0.3);
    color: white;
    border-left-color: #4299e1;
}

.nav-icon {
    display: inline-block;
    width: 20px;
    margin-right: 12px;
    text-align: center;
}

/* Custom Icons (fallback if Font Awesome fails) */
.icon-dashboard::before { content: "⚡"; }
.icon-building::before { content: "🏢"; }
.icon-home::before { content: "🏠"; }
.icon-money::before { content: "💰"; }
.icon-receipt::before { content: "🧾"; }
.icon-map::before { content: "🗺️"; }
.icon-plus::before { content: "➕"; }
.icon-search::before { content: "🔍"; }
.icon-print::before { content: "🖨️"; }

/* Responsive */
@media (max-width: 768px) {
    .sidebar {
        position: fixed;
        height: 100%;
        z-index: 999;
        transform: translateX(-100%);
        width: 280px;
    }
    
    .sidebar.show {
        transform: translateX(0);
    }
    
    .sidebar.hidden {
        transform: translateX(-100%);
        width: 280px;
    }
    
    .main-content {
        margin-left: 0;
    }
}

/* Animations */
@keyframes slideIn {
    from { transform: translateX(-20px); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

.nav-link {
    animation: slideIn 0.6s ease-out;
}
</style>

<script>
// Sidebar toggle functionality
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const isMobile = window.innerWidth <= 768;
    
    if (isMobile) {
        sidebar.classList.toggle('show');
        sidebar.classList.toggle('hidden');
    } else {
        sidebar.classList.toggle('hidden');
    }
    
    const isHidden = sidebar.classList.contains('hidden');
    localStorage.setItem('sidebarHidden', isHidden);
}

// Restore sidebar state
document.addEventListener('DOMContentLoaded', function() {
    const sidebarHidden = localStorage.getItem('sidebarHidden');
    const sidebar = document.getElementById('sidebar');
    const isMobile = window.innerWidth <= 768;
    
    if (isMobile) {
        sidebar.classList.add('hidden');
        sidebar.classList.remove('show');
    } else if (sidebarHidden === 'true') {
        sidebar.classList.add('hidden');
    }
});

// Close sidebar when clicking outside in mobile view
document.addEventListener('click', function(event) {
    const sidebar = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('toggleBtn');
    const isMobile = window.innerWidth <= 768;
    
    if (isMobile && !sidebar.contains(event.target) && !toggleBtn.contains(event.target)) {
        sidebar.classList.remove('show');
        sidebar.classList.add('hidden');
        localStorage.setItem('sidebarHidden', true);
    }
});

// Handle window resize
window.addEventListener('resize', function() {
    const sidebar = document.getElementById('sidebar');
    const isMobile = window.innerWidth <= 768;
    
    if (!isMobile) {
        sidebar.classList.remove('show');
        
        // Restore desktop state
        const sidebarHidden = localStorage.getItem('sidebarHidden');
        if (sidebarHidden === 'true') {
            sidebar.classList.add('hidden');
        } else {
            sidebar.classList.remove('hidden');
        }
    } else {
        // Mobile state
        if (!sidebar.classList.contains('show')) {
            sidebar.classList.add('hidden');
        }
    }
});
</script>
