<?php
/**
 * Restriction Warning Component - QUICKBILL 305
 * Include this file in all user dashboards to show restriction warnings
 * Path: includes/restriction_warning.php
 */

// Only run if QUICKBILL_305 is defined
if (!defined('QUICKBILL_305')) {
    return;
}

// Check if required functions exist (ensure auth.php is loaded)
if (!function_exists('isLoggedIn') || !function_exists('isSuperAdmin') || !class_exists('Database')) {
    return;
}

// Only show to logged in users
if (!isLoggedIn()) {
    return;
}

// *** SUPER ADMIN EXEMPTION - Never show restrictions to Super Admin ***
if (isSuperAdmin()) {
    return; // Super Admin bypasses all restrictions completely
}

// Don't show restriction warnings to Super Admin on the restrictions page itself
$currentPage = basename($_SERVER['PHP_SELF']);
if ($currentPage === 'restrictions.php') {
    return;
}

try {
    $db = new Database();
    
    // Get current restriction info with enhanced date calculations
    $restrictionInfo = $db->fetchRow("
        SELECT sr.*, ss.setting_value as system_restricted,
               DATEDIFF(sr.restriction_start_date, CURDATE()) as days_until_start,
               DATEDIFF(sr.restriction_end_date, CURDATE()) as days_until_end,
               DATEDIFF(CURDATE(), sr.restriction_start_date) as days_since_start,
               CASE 
                   WHEN CURDATE() < sr.restriction_start_date THEN 'before_start'
                   WHEN CURDATE() >= sr.restriction_start_date AND CURDATE() <= sr.restriction_end_date THEN 'active_period'
                   WHEN CURDATE() > sr.restriction_end_date THEN 'expired'
               END as restriction_phase,
               u.first_name, u.last_name
        FROM system_restrictions sr
        LEFT JOIN system_settings ss ON ss.setting_key = 'system_restricted'
        LEFT JOIN users u ON sr.created_by = u.user_id
        WHERE sr.is_active = 1
        ORDER BY sr.created_at DESC
        LIMIT 1
    ");
    
    if (!$restrictionInfo) {
        return; // No active restrictions
    }
    
    $isSystemRestricted = ($restrictionInfo['system_restricted'] === 'true');
    $daysUntilStart = intval($restrictionInfo['days_until_start']);
    $daysUntilEnd = intval($restrictionInfo['days_until_end']);
    $daysSinceStart = intval($restrictionInfo['days_since_start']);
    $warningDays = intval($restrictionInfo['warning_days']);
    $restrictionPhase = $restrictionInfo['restriction_phase'];
    
    $startDate = date('F j, Y', strtotime($restrictionInfo['restriction_start_date']));
    $endDate = date('F j, Y', strtotime($restrictionInfo['restriction_end_date']));
    $startDateTime = date('F j, Y \a\t g:i A', strtotime($restrictionInfo['restriction_start_date'] . ' 00:00:00'));
    $endDateTime = date('F j, Y \a\t g:i A', strtotime($restrictionInfo['restriction_end_date'] . ' 23:59:59'));
    
    // Check if system is currently restricted (only if we're in the active period AND restriction is enforced)
    if ($restrictionPhase === 'active_period' && $isSystemRestricted && !isSuperAdmin()) {
        // Show blocking message and prevent access
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>System Restricted - <?php echo APP_NAME; ?></title>
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                
                body {
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                    background: linear-gradient(135deg, #e53e3e 0%, #c53030 50%, #9b2c2c 100%);
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    color: white;
                    overflow: hidden;
                }
                
                body::before {
                    content: '';
                    position: absolute;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 1000"><defs><radialGradient id="grad1" cx="20%" cy="20%"><stop offset="0%" stop-color="rgba(255,255,255,0.1)"/><stop offset="100%" stop-color="rgba(255,255,255,0)"/></radialGradient></defs><circle cx="200" cy="200" r="150" fill="url(%23grad1)"/><circle cx="800" cy="800" r="200" fill="url(%23grad1)"/></svg>');
                    opacity: 0.4;
                    animation: float 25s ease-in-out infinite;
                }
                
                @keyframes float {
                    0%, 100% { transform: translateY(0px) rotate(0deg); }
                    33% { transform: translateY(-15px) rotate(2deg); }
                    66% { transform: translateY(10px) rotate(-1deg); }
                }
                
                .restriction-container {
                    background: rgba(255, 255, 255, 0.95);
                    backdrop-filter: blur(20px);
                    border-radius: 24px;
                    padding: 40px;
                    max-width: 500px;
                    width: 90%;
                    text-align: center;
                    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
                    border: 1px solid rgba(255, 255, 255, 0.2);
                    position: relative;
                    z-index: 2;
                    animation: slideInUp 0.8s ease-out;
                    color: #2d3748;
                }
                
                @keyframes slideInUp {
                    from {
                        opacity: 0;
                        transform: translateY(50px);
                    }
                    to {
                        opacity: 1;
                        transform: translateY(0);
                    }
                }
                
                .lock-icon {
                    width: 80px;
                    height: 80px;
                    background: linear-gradient(135deg, #e53e3e, #c53030);
                    border-radius: 20px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    margin: 0 auto 20px;
                    font-size: 40px;
                    color: white;
                    animation: pulse 2s infinite;
                }
                
                @keyframes pulse {
                    0%, 100% { transform: scale(1); }
                    50% { transform: scale(1.05); }
                }
                
                .restriction-title {
                    font-size: 32px;
                    font-weight: bold;
                    color: #e53e3e;
                    margin-bottom: 15px;
                }
                
                .restriction-message {
                    font-size: 16px;
                    color: #4a5568;
                    margin-bottom: 25px;
                    line-height: 1.6;
                }
                
                .restriction-details {
                    background: #f7fafc;
                    border-radius: 12px;
                    padding: 20px;
                    margin-bottom: 25px;
                    border-left: 4px solid #e53e3e;
                }
                
                .restriction-details h4 {
                    color: #2d3748;
                    margin-bottom: 10px;
                    font-size: 18px;
                }
                
                .restriction-details p {
                    color: #4a5568;
                    margin-bottom: 8px;
                }
                
                .logout-btn {
                    background: linear-gradient(135deg, #e53e3e, #c53030);
                    color: white;
                    padding: 12px 30px;
                    border: none;
                    border-radius: 10px;
                    font-size: 16px;
                    font-weight: 600;
                    cursor: pointer;
                    transition: all 0.3s;
                    text-decoration: none;
                    display: inline-block;
                }
                
                .logout-btn:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 8px 25px rgba(229, 62, 62, 0.3);
                    color: white;
                    text-decoration: none;
                }
                
                .contact-info {
                    margin-top: 20px;
                    font-size: 14px;
                    color: #718096;
                }
            </style>
        </head>
        <body>
            <div class="restriction-container">
                <div class="lock-icon">🔒</div>
                <h1 class="restriction-title">System Restricted.</h1>
                <p class="restriction-message">
                    The system is currently under restriction. Access has been temporarily suspended for all users.Contact KabTech Consulting :0545041428
                </p>
                
                <div class="restriction-details">
                    <h4>Restriction Details</h4>
                    <p><strong>Started:</strong> <?php echo $startDateTime; ?></p>
                    <p><strong>Ends:</strong> <?php echo $endDateTime; ?></p>
                    <p><strong>Status:</strong> Active Restriction</p>
                    <p><strong>Access Level:</strong> Super Admin Only.</p>
                
                </div>
                
                <a href="../auth/logout.php" class="logout-btn">
                    🚪 Logout
                </a>
                
                <p class="contact-info">
                    Please contact your system administrator for more information.
                </p>
            </div>
        </body>
        </html>
        <?php
        exit(); // Stop execution to prevent access
    }
    
    // Determine warning display logic
    $showWarning = false;
    $warningType = '';
    $warningMessage = '';
    $warningClass = '';
    
    if ($restrictionPhase === 'before_start') {
        // Before start date - show countdown warnings
        if ($daysUntilStart <= $warningDays) {
            $showWarning = true;
            if ($daysUntilStart <= 1) {
                $warningType = 'critical';
                $warningClass = 'restriction-warning-critical';
                if ($daysUntilStart === 0) {
                    $warningMessage = "🚨 CRITICAL: System restriction starts TODAY at midnight ({$startDate})";
                } else {
                    $warningMessage = "🚨 CRITICAL: System restriction starts TOMORROW ({$startDate})";
                }
            } elseif ($daysUntilStart <= 3) {
                $warningType = 'urgent';
                $warningClass = 'restriction-warning-urgent';
                $warningMessage = "⚠️ URGENT: System restriction starts in {$daysUntilStart} days on {$startDate}";
            } elseif ($daysUntilStart <= 7) {
                $warningType = 'warning';
                $warningClass = 'restriction-warning-warning';
                $warningMessage = "⚠️ WARNING: System restriction starts in {$daysUntilStart} days on {$startDate}";
            } else {
                $warningType = 'notice';
                $warningClass = 'restriction-warning-notice';
                $warningMessage = "📢 NOTICE: System restriction scheduled in {$daysUntilStart} days on {$startDate}";
            }
        }
    } elseif ($restrictionPhase === 'active_period' && !$isSystemRestricted) {
        // In restriction period but not yet enforced
        $showWarning = true;
        $warningType = 'pending';
        $warningClass = 'restriction-warning-pending';
        $warningMessage = "⏳ RESTRICTION PERIOD: System is in restriction period but not yet enforced. Ends {$endDate}";
    } elseif ($restrictionPhase === 'expired') {
        // Restriction has expired but is still marked as active
        $showWarning = true;
        $warningType = 'expired';
        $warningClass = 'restriction-warning-expired';
        $warningMessage = "📋 INFO: Restriction period ended on {$endDate}. Contact admin to clear.";
    }
    
    if ($showWarning) {
        ?>
        <style>
            .restriction-warning {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                z-index: 9999;
                padding: 15px 20px;
                text-align: center;
                font-weight: 600;
                font-size: 14px;
                backdrop-filter: blur(10px);
                border-bottom: 3px solid;
                animation: slideDownWarning 0.5s ease-out;
                cursor: pointer;
                box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            }
            
            .restriction-warning-critical {
                background: linear-gradient(135deg, rgba(229, 62, 62, 0.95), rgba(197, 48, 48, 0.95));
                color: white;
                border-bottom-color: #c53030;
                animation: pulseRed 1.5s infinite;
            }
            
            .restriction-warning-urgent {
                background: linear-gradient(135deg, rgba(237, 137, 54, 0.95), rgba(221, 107, 32, 0.95));
                color: white;
                border-bottom-color: #dd6b20;
            }
            
            .restriction-warning-warning {
                background: linear-gradient(135deg, rgba(245, 158, 11, 0.95), rgba(217, 119, 6, 0.95));
                color: white;
                border-bottom-color: #d97706;
            }
            
            .restriction-warning-notice {
                background: linear-gradient(135deg, rgba(66, 153, 225, 0.95), rgba(49, 130, 206, 0.95));
                color: white;
                border-bottom-color: #3182ce;
            }
            
            .restriction-warning-pending {
                background: linear-gradient(135deg, rgba(128, 90, 213, 0.95), rgba(102, 126, 234, 0.95));
                color: white;
                border-bottom-color: #667eea;
                animation: pulseBlue 2s infinite;
            }
            
            .restriction-warning-expired {
                background: linear-gradient(135deg, rgba(113, 128, 150, 0.95), rgba(74, 85, 104, 0.95));
                color: white;
                border-bottom-color: #718096;
            }
            
            @keyframes slideDownWarning {
                from {
                    transform: translateY(-100%);
                    opacity: 0;
                }
                to {
                    transform: translateY(0);
                    opacity: 1;
                }
            }
            
            @keyframes pulseRed {
                0%, 100% { background: linear-gradient(135deg, rgba(229, 62, 62, 0.95), rgba(197, 48, 48, 0.95)); }
                50% { background: linear-gradient(135deg, rgba(229, 62, 62, 1), rgba(197, 48, 48, 1)); }
            }
            
            @keyframes pulseBlue {
                0%, 100% { background: linear-gradient(135deg, rgba(128, 90, 213, 0.95), rgba(102, 126, 234, 0.95)); }
                50% { background: linear-gradient(135deg, rgba(128, 90, 213, 1), rgba(102, 126, 234, 1)); }
            }
            
            .restriction-warning:hover {
                transform: translateY(-2px);
                box-shadow: 0 8px 30px rgba(0,0,0,0.2);
            }
            
            .restriction-warning-details {
                font-size: 12px;
                opacity: 0.9;
                margin-top: 5px;
                font-weight: normal;
            }
            
            .countdown-display {
                font-size: 16px;
                font-weight: bold;
                margin-top: 5px;
                display: flex;
                justify-content: center;
                gap: 15px;
                flex-wrap: wrap;
            }
            
            .countdown-unit {
                display: flex;
                flex-direction: column;
                align-items: center;
                min-width: 50px;
            }
            
            .countdown-number {
                font-size: 20px;
                font-weight: bold;
            }
            
            .countdown-label {
                font-size: 10px;
                opacity: 0.8;
                text-transform: uppercase;
            }
            
            .restriction-warning-close {
                position: absolute;
                top: 50%;
                right: 20px;
                transform: translateY(-50%);
                background: rgba(255,255,255,0.2);
                border: none;
                color: white;
                width: 25px;
                height: 25px;
                border-radius: 50%;
                cursor: pointer;
                font-size: 14px;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: all 0.3s;
            }
            
            .restriction-warning-close:hover {
                background: rgba(255,255,255,0.3);
                transform: translateY(-50%) scale(1.1);
            }
            
            /* Adjust body padding when warning is shown */
            body.warning-shown {
                padding-top: 90px;
            }
            
            .container.warning-shown {
                margin-top: 170px;
            }
            
            /* Mobile adjustments */
            @media (max-width: 768px) {
                .restriction-warning {
                    font-size: 13px;
                    padding: 12px 15px;
                }
                
                .restriction-warning-details {
                    font-size: 11px;
                }
                
                .countdown-display {
                    font-size: 14px;
                    gap: 10px;
                }
                
                .countdown-number {
                    font-size: 18px;
                }
                
                body.warning-shown {
                    padding-top: 80px;
                }
                
                .container.warning-shown {
                    margin-top: 160px;
                }
            }
        </style>
        
        <div class="restriction-warning <?php echo $warningClass; ?>" id="restrictionWarning" onclick="toggleWarningDetails()">
            <div class="restriction-warning-message">
                <?php echo $warningMessage; ?>
            </div>
            
            <?php if ($restrictionPhase === 'before_start' && $daysUntilStart <= 30): ?>
                <div class="countdown-display" id="countdownDisplay">
                    <!-- Countdown will be populated by JavaScript -->
                </div>
            <?php endif; ?>
            
            <div class="restriction-warning-details" id="warningDetails" style="display: none;">
                <?php if ($restrictionPhase === 'before_start'): ?>
                    Restriction starts: <?php echo $startDateTime; ?> | 
                    Ends: <?php echo $endDateTime; ?> | 
                    Warning period: <?php echo $warningDays; ?> days |
                <?php elseif ($restrictionPhase === 'active_period'): ?>
                    Started: <?php echo $startDateTime; ?> | 
                    Ends: <?php echo $endDateTime; ?> |
                <?php endif; ?>
                Created by: <?php echo htmlspecialchars(($restrictionInfo['first_name'] ?? '') . ' ' . ($restrictionInfo['last_name'] ?? '')); ?>
                <?php if (isSuperAdmin()): ?>
                    | <a href="../admin/settings/restrictions.php" style="color: white; text-decoration: underline;">Manage Restrictions</a>
                <?php endif; ?>
            </div>
            
            <button class="restriction-warning-close" onclick="event.stopPropagation(); hideWarning()" title="Hide warning">
                ✕
            </button>
        </div>
        
        <script>
            // Countdown timer variables
            const restrictionStartDate = new Date('<?php echo $restrictionInfo['restriction_start_date']; ?>T00:00:00');
            
            // Adjust page layout for warning
            document.addEventListener('DOMContentLoaded', function() {
                document.body.classList.add('warning-shown');
                const container = document.querySelector('.container');
                if (container) {
                    container.classList.add('warning-shown');
                }
                
                // Start countdown timer if applicable
                <?php if ($restrictionPhase === 'before_start' && $daysUntilStart <= 30): ?>
                updateCountdown();
                setInterval(updateCountdown, 1000); // Update every second
                <?php endif; ?>
                
                // Auto-hide warning after 15 seconds for notice level
                <?php if ($warningType === 'notice'): ?>
                setTimeout(function() {
                    if (!sessionStorage.getItem('restrictionWarningDismissed')) {
                        hideWarning();
                    }
                }, 15000);
                <?php endif; ?>
            });
            
            function updateCountdown() {
                const now = new Date();
                const timeLeft = restrictionStartDate - now;
                
                if (timeLeft <= 0) {
                    // Restriction has started, reload page to trigger restriction
                    location.reload();
                    return;
                }
                
                const days = Math.floor(timeLeft / (1000 * 60 * 60 * 24));
                const hours = Math.floor((timeLeft % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                const minutes = Math.floor((timeLeft % (1000 * 60 * 60)) / (1000 * 60));
                const seconds = Math.floor((timeLeft % (1000 * 60)) / 1000);
                
                const countdownDisplay = document.getElementById('countdownDisplay');
                if (countdownDisplay) {
                    countdownDisplay.innerHTML = `
                        <div class="countdown-unit">
                            <div class="countdown-number">${days}</div>
                            <div class="countdown-label">Days</div>
                        </div>
                        <div class="countdown-unit">
                            <div class="countdown-number">${hours.toString().padStart(2, '0')}</div>
                            <div class="countdown-label">Hours</div>
                        </div>
                        <div class="countdown-unit">
                            <div class="countdown-number">${minutes.toString().padStart(2, '0')}</div>
                            <div class="countdown-label">Minutes</div>
                        </div>
                        <div class="countdown-unit">
                            <div class="countdown-number">${seconds.toString().padStart(2, '0')}</div>
                            <div class="countdown-label">Seconds</div>
                        </div>
                    `;
                }
            }
            
            function toggleWarningDetails() {
                const details = document.getElementById('warningDetails');
                if (details.style.display === 'none') {
                    details.style.display = 'block';
                } else {
                    details.style.display = 'none';
                }
            }
            
            function hideWarning() {
                const warning = document.getElementById('restrictionWarning');
                warning.style.animation = 'slideDownWarning 0.3s ease-out reverse';
                
                setTimeout(function() {
                    warning.style.display = 'none';
                    document.body.classList.remove('warning-shown');
                    const container = document.querySelector('.container');
                    if (container) {
                        container.classList.remove('warning-shown');
                    }
                }, 300);
                
                // Store in session that user has dismissed warning for this session
                sessionStorage.setItem('restrictionWarningDismissed', 'true');
            }
            
            // Check if warning was already dismissed
            if (sessionStorage.getItem('restrictionWarningDismissed') === 'true') {
                // Only auto-hide for notice level warnings
                <?php if ($warningType === 'notice'): ?>
                setTimeout(() => hideWarning(), 100);
                <?php endif; ?>
            }
        </script>
        <?php
    }
    
} catch (Exception $e) {
    // Silently log error but don't disrupt page loading
    writeLog("Restriction warning error: " . $e->getMessage(), 'ERROR');
}
?>