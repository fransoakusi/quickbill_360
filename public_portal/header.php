 <?php
/**
 * Public Portal Header Template for QUICKBILL 305
 * Common header for all public portal pages
 */

// Ensure constants are defined
if (!defined('QUICKBILL_305')) {
    die('Direct access not permitted');
}

// Get assembly name and current page
$assemblyName = getSystemSetting('assembly_name', 'Municipal Assembly');
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$pageTitle = '';

// Set page titles
switch ($currentPage) {
    case 'index':
        $pageTitle = 'Online Bill Payment Portal';
        break;
    case 'search_bill':
        $pageTitle = 'Search Bill';
        break;
    case 'view_bill':
        $pageTitle = 'View Bill Details';
        break;
    case 'pay_bill':
        $pageTitle = 'Make Payment';
        break;
    case 'payment_success':
        $pageTitle = 'Payment Successful';
        break;
    case 'payment_failed':
        $pageTitle = 'Payment Failed';
        break;
    case 'verify_payment':
        $pageTitle = 'Verify Payment';
        break;
    default:
        $pageTitle = 'Bill Payment Portal';
        break;
}

// Get any flash messages
$flashMessages = getFlashMessages();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Pay your business permits and property rates online securely and instantly with <?php echo htmlspecialchars($assemblyName); ?>">
    <meta name="keywords" content="bill payment, business permit, property rates, online payment, mobile money, <?php echo htmlspecialchars($assemblyName); ?>">
    <meta name="author" content="<?php echo htmlspecialchars($assemblyName); ?>">
    <meta name="robots" content="index, follow">
    
    <!-- Open Graph Meta Tags -->
    <meta property="og:title" content="<?php echo htmlspecialchars($pageTitle . ' - ' . $assemblyName); ?>">
    <meta property="og:description" content="Pay your bills online securely and instantly">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo BASE_URL; ?>/public_portal/">
    
    <title><?php echo htmlspecialchars($pageTitle . ' - ' . $assemblyName); ?></title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="../assets/images/favicon.ico">
    <link rel="apple-touch-icon" href="../assets/images/logo.png">
    
    <!-- External CSS Libraries -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- PayStack Inline JS -->
    <script src="https://js.paystack.co/v1/inline.js"></script>
    
    <style>
        /* CSS Reset and Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.6;
            color: #2d3748;
            background: #f8f9fa;
            overflow-x: hidden;
        }

        /* Navigation Header */
        .navbar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 0;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            box-shadow: 0 2px 20px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
        }

        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .nav-brand {
            display: flex;
            align-items: center;
            gap: 15px;
            text-decoration: none;
            color: white;
            font-weight: bold;
            font-size: 1.2rem;
        }

        .nav-brand:hover {
            color: white;
            text-decoration: none;
        }

        .nav-logo {
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .nav-text {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }

        .nav-title {
            font-size: 1.1rem;
            font-weight: 700;
        }

        .nav-subtitle {
            font-size: 0.8rem;
            opacity: 0.9;
            font-weight: 400;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 25px;
        }

        .nav-link {
            color: white;
            text-decoration: none;
            font-weight: 500;
            padding: 8px 16px;
            border-radius: 20px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            text-decoration: none;
            transform: translateY(-2px);
        }

        .nav-link.active {
            background: rgba(255, 255, 255, 0.3);
        }

        /* Mobile Menu Toggle */
        .mobile-toggle {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
        }

        /* Main Content Area */
        .main-content {
            margin-top: 80px;
            min-height: calc(100vh - 80px);
        }

        /* Flash Messages */
        .flash-messages {
            position: fixed;
            top: 100px;
            right: 20px;
            z-index: 1001;
            max-width: 400px;
        }

        .flash-message {
            margin-bottom: 10px;
            padding: 15px 20px;
            border-radius: 10px;
            color: white;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideInRight 0.3s ease;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .flash-message.success {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
        }

        .flash-message.error {
            background: linear-gradient(135deg, #f56565 0%, #e53e3e 100%);
        }

        .flash-message.warning {
            background: linear-gradient(135deg, #ed8936 0%, #dd6b20 100%);
        }

        .flash-message.info {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
        }

        .flash-close {
            background: none;
            border: none;
            color: white;
            font-size: 1.2rem;
            cursor: pointer;
            margin-left: auto;
            opacity: 0.8;
        }

        .flash-close:hover {
            opacity: 1;
        }

        /* Loading Overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.9);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            backdrop-filter: blur(5px);
        }

        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 4px solid #e2e8f0;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        .loading-text {
            margin-top: 15px;
            font-weight: 600;
            color: #667eea;
        }

        /* Utility Classes */
        .text-center { text-align: center; }
        .text-left { text-align: left; }
        .text-right { text-align: right; }

        .d-none { display: none; }
        .d-block { display: block; }
        .d-flex { display: flex; }
        .d-inline-block { display: inline-block; }

        .mt-1 { margin-top: 0.25rem; }
        .mt-2 { margin-top: 0.5rem; }
        .mt-3 { margin-top: 1rem; }
        .mt-4 { margin-top: 1.5rem; }
        .mt-5 { margin-top: 3rem; }

        .mb-1 { margin-bottom: 0.25rem; }
        .mb-2 { margin-bottom: 0.5rem; }
        .mb-3 { margin-bottom: 1rem; }
        .mb-4 { margin-bottom: 1.5rem; }
        .mb-5 { margin-bottom: 3rem; }

        .p-1 { padding: 0.25rem; }
        .p-2 { padding: 0.5rem; }
        .p-3 { padding: 1rem; }
        .p-4 { padding: 1.5rem; }
        .p-5 { padding: 3rem; }

        /* Animations */
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from {
                transform: translateY(30px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .nav-links {
                display: none;
                position: absolute;
                top: 100%;
                left: 0;
                right: 0;
                background: rgba(102, 126, 234, 0.95);
                flex-direction: column;
                padding: 20px;
                backdrop-filter: blur(10px);
            }

            .nav-links.active {
                display: flex;
            }

            .mobile-toggle {
                display: block;
            }

            .nav-text {
                display: none;
            }

            .nav-title {
                font-size: 1rem;
            }

            .flash-messages {
                right: 10px;
                left: 10px;
                max-width: none;
            }

            .nav-container {
                padding: 0 15px;
            }
        }

        @media (max-width: 480px) {
            .nav-brand {
                font-size: 1rem;
            }

            .nav-logo {
                width: 35px;
                height: 35px;
                font-size: 18px;
            }
        }

        /* Print Styles */
        @media print {
            .navbar,
            .flash-messages,
            .loading-overlay {
                display: none !important;
            }

            .main-content {
                margin-top: 0;
            }

            body {
                background: white;
            }
        }

        /* Dark mode support (future enhancement) */
        @media (prefers-color-scheme: dark) {
            /* Dark mode styles can be added here */
        }

        /* High contrast mode support */
        @media (prefers-contrast: high) {
            .navbar {
                background: #000;
            }

            .nav-link:hover {
                background: #333;
            }
        }

        /* Reduced motion support */
        @media (prefers-reduced-motion: reduce) {
            * {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }

            html {
                scroll-behavior: auto;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation Header -->
    <nav class="navbar">
        <div class="nav-container">
            <a href="index.php" class="nav-brand">
                <div class="nav-logo">
                    <i class="fas fa-receipt"></i>
                </div>
                <div class="nav-text">
                    <div class="nav-title"><?php echo APP_NAME; ?></div>
                    <div class="nav-subtitle"><?php echo htmlspecialchars($assemblyName); ?></div>
                </div>
            </a>

            <div class="nav-links" id="navLinks">
                <a href="index.php" class="nav-link <?php echo $currentPage === 'index' ? 'active' : ''; ?>">
                    <i class="fas fa-home"></i>
                    <span>Home</span>
                </a>
                <a href="search_bill.php" class="nav-link <?php echo $currentPage === 'search_bill' ? 'active' : ''; ?>">
                    <i class="fas fa-search"></i>
                    <span>Find Bill</span>
                </a>
                <a href="verify_payment.php" class="nav-link <?php echo $currentPage === 'verify_payment' ? 'active' : ''; ?>">
                    <i class="fas fa-check-circle"></i>
                    <span>Verify Payment</span>
                </a>
                <a href="#help" class="nav-link" onclick="scrollToHelp()">
                    <i class="fas fa-question-circle"></i>
                    <span>Help</span>
                </a>
            </div>

            <button class="mobile-toggle" onclick="toggleMobileMenu()" id="mobileToggle">
                <i class="fas fa-bars"></i>
            </button>
        </div>
    </nav>

    <!-- Flash Messages -->
    <?php if (!empty($flashMessages)): ?>
    <div class="flash-messages" id="flashMessages">
        <?php foreach ($flashMessages as $message): ?>
        <div class="flash-message <?php echo htmlspecialchars($message['type']); ?>" data-auto-close="5000">
            <i class="fas fa-<?php 
                echo $message['type'] === 'success' ? 'check-circle' : 
                    ($message['type'] === 'error' ? 'exclamation-triangle' : 
                    ($message['type'] === 'warning' ? 'exclamation-circle' : 'info-circle')); 
            ?>"></i>
            <span><?php echo htmlspecialchars($message['message']); ?></span>
            <button class="flash-close" onclick="removeFlashMessage(this)">&times;</button>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div style="text-align: center;">
            <div class="loading-spinner"></div>
            <div class="loading-text">Processing...</div>
        </div>
    </div>

    <!-- Main Content Area -->
    <main class="main-content">

<script>
// Global JavaScript functions for the public portal

// Toggle mobile menu
function toggleMobileMenu() {
    const navLinks = document.getElementById('navLinks');
    const mobileToggle = document.getElementById('mobileToggle');
    
    navLinks.classList.toggle('active');
    
    // Change icon
    const icon = mobileToggle.querySelector('i');
    if (navLinks.classList.contains('active')) {
        icon.className = 'fas fa-times';
    } else {
        icon.className = 'fas fa-bars';
    }
}

// Close mobile menu when clicking outside
document.addEventListener('click', function(event) {
    const navLinks = document.getElementById('navLinks');
    const mobileToggle = document.getElementById('mobileToggle');
    
    if (!mobileToggle.contains(event.target) && !navLinks.contains(event.target)) {
        navLinks.classList.remove('active');
        mobileToggle.querySelector('i').className = 'fas fa-bars';
    }
});

// Flash message management
function removeFlashMessage(button) {
    const message = button.parentElement;
    message.style.opacity = '0';
    message.style.transform = 'translateX(100%)';
    setTimeout(() => {
        message.remove();
    }, 300);
}

// Auto-close flash messages
document.addEventListener('DOMContentLoaded', function() {
    const flashMessages = document.querySelectorAll('.flash-message[data-auto-close]');
    
    flashMessages.forEach(message => {
        const autoCloseTime = parseInt(message.getAttribute('data-auto-close'));
        if (autoCloseTime > 0) {
            setTimeout(() => {
                const closeButton = message.querySelector('.flash-close');
                if (closeButton) {
                    removeFlashMessage(closeButton);
                }
            }, autoCloseTime);
        }
    });
});

// Loading overlay management
function showLoading(text = 'Processing...') {
    const overlay = document.getElementById('loadingOverlay');
    const textElement = overlay.querySelector('.loading-text');
    textElement.textContent = text;
    overlay.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function hideLoading() {
    const overlay = document.getElementById('loadingOverlay');
    overlay.style.display = 'none';
    document.body.style.overflow = 'auto';
}

// Scroll to help section
function scrollToHelp() {
    const helpSection = document.querySelector('.help-section');
    if (helpSection) {
        helpSection.scrollIntoView({ 
            behavior: 'smooth',
            block: 'start'
        });
    } else {
        // If no help section on current page, go to homepage
        window.location.href = 'index.php#help';
    }
}

// Form validation helpers
function validateForm(formId) {
    const form = document.getElementById(formId);
    if (!form) return false;
    
    const requiredFields = form.querySelectorAll('[required]');
    let isValid = true;
    
    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            field.classList.add('error');
            isValid = false;
        } else {
            field.classList.remove('error');
        }
    });
    
    return isValid;
}

// Add error styles for form validation
const style = document.createElement('style');
style.textContent = `
    .form-control.error {
        border-color: #f56565 !important;
        box-shadow: 0 0 0 3px rgba(245, 101, 101, 0.1) !important;
    }
    
    .form-control.error:focus {
        border-color: #f56565 !important;
        box-shadow: 0 0 0 3px rgba(245, 101, 101, 0.2) !important;
    }
`;
document.head.appendChild(style);

// PayStack integration helper
function initializePayStack(config) {
    if (typeof PaystackPop === 'undefined') {
        console.error('PayStack library not loaded');
        return false;
    }
    
    const handler = PaystackPop.setup({
        key: config.publicKey,
        email: config.email,
        amount: config.amount * 100, // PayStack uses kobo
        currency: 'GHS',
        ref: config.reference,
        metadata: config.metadata || {},
        callback: function(response) {
            if (config.onSuccess) {
                config.onSuccess(response);
            }
        },
        onClose: function() {
            if (config.onCancel) {
                config.onCancel();
            }
        }
    });
    
    return handler;
}

// Utility functions
function formatCurrency(amount) {
    return new Intl.NumberFormat('en-GH', {
        style: 'currency',
        currency: 'GHS',
        minimumFractionDigits: 2
    }).format(amount);
}

function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-GH', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
}

function formatDateTime(dateString) {
    const date = new Date(dateString);
    return date.toLocaleString('en-GH', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

// Network status monitoring
function checkNetworkStatus() {
    if (!navigator.onLine) {
        showNetworkError();
    }
}

function showNetworkError() {
    const errorDiv = document.createElement('div');
    errorDiv.className = 'flash-message error';
    errorDiv.innerHTML = `
        <i class="fas fa-wifi"></i>
        <span>No internet connection. Please check your network and try again.</span>
        <button class="flash-close" onclick="removeFlashMessage(this)">&times;</button>
    `;
    
    const flashContainer = document.getElementById('flashMessages');
    if (flashContainer) {
        flashContainer.appendChild(errorDiv);
    }
}

// Monitor network status
window.addEventListener('online', function() {
    console.log('Network connection restored');
});

window.addEventListener('offline', function() {
    showNetworkError();
});

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Check network status
    checkNetworkStatus();
    
    // Add smooth animations to elements
    const animatedElements = document.querySelectorAll('.feature-card, .stat-card, .help-card');
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.animation = 'slideUp 0.6s ease forwards';
            }
        });
    });
    
    animatedElements.forEach(el => {
        observer.observe(el);
    });
});
</script>
