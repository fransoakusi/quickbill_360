<?php
/**
 * Flash Messages Display - QUICKBILL 305
 * Common include for displaying flash messages across admin pages
 */

if (!defined('QUICKBILL_305')) {
    die('Direct access not permitted');
}

// Get flash messages
$flashMessages = getFlashMessages();

if (!empty($flashMessages)): ?>
<div class="flash-messages-container">
    <?php foreach ($flashMessages as $message): ?>
        <div class="alert alert-<?php echo $message['type'] === 'error' ? 'danger' : $message['type']; ?> alert-dismissible fade show" role="alert">
            <div class="d-flex align-items-start">
                <div class="alert-icon me-3">
                    <?php
                    $icon = '';
                    switch ($message['type']) {
                        case 'success':
                            $icon = 'fas fa-check-circle';
                            break;
                        case 'error':
                        case 'danger':
                            $icon = 'fas fa-exclamation-circle';
                            break;
                        case 'warning':
                            $icon = 'fas fa-exclamation-triangle';
                            break;
                        case 'info':
                        default:
                            $icon = 'fas fa-info-circle';
                            break;
                    }
                    ?>
                    <i class="<?php echo $icon; ?>"></i>
                </div>
                <div class="alert-content flex-grow-1">
                    <?php echo htmlspecialchars($message['message']); ?>
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endforeach; ?>
</div>

<style>
.flash-messages-container {
    margin-bottom: 1.5rem;
}

.flash-messages-container .alert {
    border: none;
    border-radius: 10px;
    margin-bottom: 1rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    animation: slideInFromTop 0.4s ease-out;
}

.flash-messages-container .alert:last-child {
    margin-bottom: 0;
}

.alert-icon {
    font-size: 1.25rem;
    margin-top: 2px;
    flex-shrink: 0;
}

.alert-content {
    font-weight: 500;
    line-height: 1.5;
}

.alert-success {
    background-color: #e8f5e8;
    color: #2e7d32;
    border-left: 4px solid #4caf50;
}

.alert-danger {
    background-color: #ffebee;
    color: #c62828;
    border-left: 4px solid #f44336;
}

.alert-warning {
    background-color: #fff3e0;
    color: #ef6c00;
    border-left: 4px solid #ff9800;
}

.alert-info {
    background-color: #e1f5fe;
    color: #0277bd;
    border-left: 4px solid #2196f3;
}

.btn-close {
    opacity: 0.6;
    font-size: 0.875rem;
}

.btn-close:hover {
    opacity: 1;
}

@keyframes slideInFromTop {
    from {
        transform: translateY(-20px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

/* Auto-hide animation */
.flash-messages-container .alert.auto-hide {
    animation: fadeOut 0.5s ease-out 4.5s forwards;
}

@keyframes fadeOut {
    from {
        opacity: 1;
        transform: translateY(0);
    }
    to {
        opacity: 0;
        transform: translateY(-10px);
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-hide success and info messages after 5 seconds
    const alerts = document.querySelectorAll('.alert-success, .alert-info');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            if (alert && alert.parentNode) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }
        }, 5000);
    });

    // Keep error and warning messages until manually dismissed
    const persistentAlerts = document.querySelectorAll('.alert-danger, .alert-warning');
    persistentAlerts.forEach(function(alert) {
        alert.classList.add('alert-permanent');
    });
});
</script>

<?php endif; ?>