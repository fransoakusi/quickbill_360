 <?php
/**
 * Officer Header Template
 * Common header for all officer pages
 */

// Prevent direct access
if (!defined('QUICKBILL_305')) {
    die('Direct access not permitted');
}

// Get current user info
$currentUser = getCurrentUser();
$userDisplayName = getUserDisplayName($currentUser);
?>
    <!-- Top Navigation -->
    <div class="top-nav">
        <div class="nav-left">
            <button class="toggle-btn" onclick="toggleSidebar()" id="toggleBtn">
                <i class="fas fa-bars"></i>
                <span class="icon-menu" style="display: none;"></span>
            </button>
            
            <a href="index.php" class="brand">
                <i class="fas fa-user-tie"></i>
                <span class="icon-user" style="display: none;"></span>
                Officer Portal
            </a>
        </div>
        
        <div class="user-section">
            <div class="user-profile" onclick="toggleUserDropdown()" id="userProfile">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($currentUser['first_name'], 0, 1)); ?>
                </div>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($userDisplayName); ?></div>
                    <div class="user-role">Officer</div>
                </div>
                <i class="fas fa-chevron-down dropdown-arrow"></i>
                
                <!-- User Dropdown -->
                <div class="user-dropdown" id="userDropdown">
                    <div class="dropdown-header">
                        <div class="dropdown-avatar">
                            <?php echo strtoupper(substr($currentUser['first_name'], 0, 1)); ?>
                        </div>
                        <div class="dropdown-name"><?php echo htmlspecialchars($userDisplayName); ?></div>
                        <div class="dropdown-role">Officer</div>
                    </div>
                    <div class="dropdown-menu">
                        <a href="#" class="dropdown-item" onclick="alert('Profile management coming soon!')">
                            <i class="fas fa-user"></i>
                            <span class="icon-user" style="display: none;"></span>
                            My Profile
                        </a>
                        <a href="#" class="dropdown-item" onclick="alert('Help documentation coming soon!')">
                            <i class="fas fa-question-circle"></i>
                            <span class="icon-question" style="display: none;"></span>
                            Help & Support
                        </a>
                        <div style="height: 1px; background: #e2e8f0; margin: 10px 0;"></div>
                        <a href="../auth/logout.php" class="dropdown-item logout">
                            <i class="fas fa-sign-out-alt"></i>
                            <span class="icon-logout" style="display: none;"></span>
                            Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

<style>
/* Top Navigation Styles */
.top-nav {
    background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
    color: white;
    padding: 15px 20px;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 1000;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.nav-left {
    display: flex;
    align-items: center;
    gap: 20px;
}

.toggle-btn {
    background: rgba(255,255,255,0.2);
    border: none;
    color: white;
    font-size: 18px;
    padding: 10px;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s;
}

.toggle-btn:hover {
    background: rgba(255,255,255,0.3);
}

.brand {
    font-size: 24px;
    font-weight: bold;
    color: white;
    text-decoration: none;
}

.user-section {
    display: flex;
    align-items: center;
    gap: 15px;
    position: relative;
}

.user-profile {
    display: flex;
    align-items: center;
    gap: 12px;
    cursor: pointer;
    padding: 8px 12px;
    border-radius: 10px;
    transition: all 0.3s;
    position: relative;
}

.user-profile:hover {
    background: rgba(255,255,255,0.1);
}

.user-avatar {
    width: 45px;
    height: 45px;
    border-radius: 50%;
    background: linear-gradient(135deg, rgba(255,255,255,0.3) 0%, rgba(255,255,255,0.1) 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    border: 2px solid rgba(255,255,255,0.2);
    transition: all 0.3s;
}

.user-info {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
}

.user-name {
    font-weight: 600;
    font-size: 14px;
    color: white;
}

.user-role {
    font-size: 12px;
    opacity: 0.8;
    color: rgba(255,255,255,0.8);
}

.dropdown-arrow {
    margin-left: 8px;
    font-size: 12px;
    transition: transform 0.3s;
}

/* User Dropdown */
.user-dropdown {
    position: absolute;
    top: 100%;
    right: 0;
    background: white;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    min-width: 220px;
    opacity: 0;
    visibility: hidden;
    transform: translateY(-10px);
    transition: all 0.3s ease;
    z-index: 1001;
    overflow: hidden;
    margin-top: 10px;
}

.user-dropdown.show {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
}

.dropdown-header {
    background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
    color: white;
    padding: 20px;
    text-align: center;
}

.dropdown-avatar {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: rgba(255,255,255,0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 24px;
    margin: 0 auto 10px;
    border: 3px solid rgba(255,255,255,0.3);
}

.dropdown-name {
    font-weight: 600;
    margin-bottom: 5px;
}

.dropdown-role {
    font-size: 12px;
    opacity: 0.9;
    background: rgba(255,255,255,0.2);
    padding: 4px 12px;
    border-radius: 15px;
    display: inline-block;
}

.dropdown-menu {
    padding: 0;
}

.dropdown-item {
    display: flex;
    align-items: center;
    padding: 15px 20px;
    color: #2d3748;
    text-decoration: none;
    transition: all 0.3s;
    border-bottom: 1px solid #f7fafc;
}

.dropdown-item:hover {
    background: #f7fafc;
    color: #4299e1;
    transform: translateX(5px);
}

.dropdown-item i {
    width: 20px;
    margin-right: 12px;
    text-align: center;
}

.dropdown-item.logout {
    color: #e53e3e;
    border-top: 2px solid #fed7d7;
}

.dropdown-item.logout:hover {
    background: #fed7d7;
    color: #c53030;
}

/* Layout */
.container-fluid {
    margin-top: 80px;
    display: flex;
    min-height: calc(100vh - 80px);
}

/* Main Content */
.main-content {
    flex: 1;
    padding: 30px;
    background: #f8f9fa;
    transition: all 0.3s ease;
}

/* Responsive */
@media (max-width: 768px) {
    .user-info {
        display: none;
    }
    
    .user-profile {
        gap: 8px;
    }
    
    .brand {
        font-size: 18px;
    }
    
    .main-content {
        padding: 20px 15px;
    }
}

/* JavaScript functionality */
</style>

<script>
// User dropdown toggle
function toggleUserDropdown() {
    const dropdown = document.getElementById('userDropdown');
    const profile = document.getElementById('userProfile');
    
    dropdown.classList.toggle('show');
    profile.classList.toggle('active');
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('userDropdown');
    const profile = document.getElementById('userProfile');
    
    if (!profile.contains(event.target)) {
        dropdown.classList.remove('show');
        profile.classList.remove('active');
    }
});

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
</script>
