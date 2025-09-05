<?php
/**
 * Admin Footer Template - QUICKBILL 305
 * Common footer for all admin pages
 */

if (!defined('QUICKBILL_305')) {
    die('Direct access not permitted');
}
?>

    <!-- Footer Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

    <!-- Common Admin JavaScript -->
    <script>
        $(document).ready(function() {
            // Initialize DataTables with common settings
            $('.data-table').DataTable({
                responsive: true,
                pageLength: 25,
                order: [[0, 'desc']],
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries",
                    infoEmpty: "No entries available",
                    infoFiltered: "(filtered from _MAX_ total entries)",
                    zeroRecords: "No matching records found",
                    paginate: {
                        first: "First",
                        last: "Last",
                        next: "Next",
                        previous: "Previous"
                    }
                },
                dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>t<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
                drawCallback: function() {
                    // Re-initialize tooltips after table redraw
                    $('[data-bs-toggle="tooltip"]').tooltip();
                }
            });

            // Initialize tooltips
            $('[data-bs-toggle="tooltip"]').tooltip();

            // Initialize popovers
            $('[data-bs-toggle="popover"]').popover();

            // Auto-hide alerts after 5 seconds
            $('.alert').each(function() {
                const alert = $(this);
                if (!alert.hasClass('alert-permanent')) {
                    setTimeout(function() {
                        alert.fadeOut();
                    }, 5000);
                }
            });

            // Confirm delete actions
            $('.btn-delete, .delete-btn').on('click', function(e) {
                e.preventDefault();
                const action = $(this).data('action') || 'delete this item';
                const confirmText = `Are you sure you want to ${action}? This action cannot be undone.`;
                
                if (confirm(confirmText)) {
                    if ($(this).is('a')) {
                        window.location.href = $(this).attr('href');
                    } else if ($(this).is('button') && $(this).closest('form').length) {
                        $(this).closest('form').submit();
                    }
                }
            });

            // Form validation enhancement
            $('form').on('submit', function() {
                const form = $(this);
                const submitBtn = form.find('button[type="submit"]');
                
                // Disable submit button to prevent double submission
                submitBtn.prop('disabled', true);
                
                // Add loading spinner
                const originalText = submitBtn.html();
                submitBtn.html('<i class="fas fa-spinner fa-spin"></i> Processing...');
                
                // Re-enable button after 30 seconds (fallback)
                setTimeout(function() {
                    submitBtn.prop('disabled', false);
                    submitBtn.html(originalText);
                }, 30000);
            });

            // Number formatting
            $('.format-currency').each(function() {
                const value = parseFloat($(this).text());
                if (!isNaN(value)) {
                    $(this).text('₵ ' + value.toLocaleString('en-US', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    }));
                }
            });

            // Date formatting
            $('.format-date').each(function() {
                const dateStr = $(this).text();
                const date = new Date(dateStr);
                if (!isNaN(date.getTime())) {
                    $(this).text(date.toLocaleDateString('en-US', {
                        year: 'numeric',
                        month: 'short',
                        day: 'numeric'
                    }));
                }
            });

            // Real-time search for tables
            $('.table-search').on('keyup', function() {
                const searchTerm = $(this).val().toLowerCase();
                const table = $($(this).data('target'));
                
                table.find('tbody tr').each(function() {
                    const rowText = $(this).text().toLowerCase();
                    $(this).toggle(rowText.indexOf(searchTerm) > -1);
                });
            });

            // Auto-submit forms after delay
            $('.auto-submit').on('change', function() {
                const form = $(this).closest('form');
                setTimeout(function() {
                    form.submit();
                }, 500);
            });

            // Copy to clipboard functionality
            $('.copy-btn').on('click', function(e) {
                e.preventDefault();
                const text = $(this).data('copy') || $(this).prev('input').val();
                
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(text).then(function() {
                        showToast('Copied to clipboard!', 'success');
                    });
                } else {
                    // Fallback for older browsers
                    const textArea = document.createElement("textarea");
                    textArea.value = text;
                    document.body.appendChild(textArea);
                    textArea.focus();
                    textArea.select();
                    try {
                        document.execCommand('copy');
                        showToast('Copied to clipboard!', 'success');
                    } catch (err) {
                        showToast('Failed to copy to clipboard', 'error');
                    }
                    document.body.removeChild(textArea);
                }
            });

            // AJAX form submission
            $('.ajax-form').on('submit', function(e) {
                e.preventDefault();
                const form = $(this);
                const formData = new FormData(this);
                const submitBtn = form.find('button[type="submit"]');
                const originalText = submitBtn.html();

                submitBtn.prop('disabled', true);
                submitBtn.html('<i class="fas fa-spinner fa-spin"></i> Processing...');

                $.ajax({
                    url: form.attr('action') || window.location.href,
                    type: form.attr('method') || 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        if (response.success) {
                            showToast(response.message || 'Operation completed successfully', 'success');
                            if (response.redirect) {
                                setTimeout(function() {
                                    window.location.href = response.redirect;
                                }, 1000);
                            }
                        } else {
                            showToast(response.message || 'An error occurred', 'error');
                        }
                    },
                    error: function() {
                        showToast('An error occurred while processing your request', 'error');
                    },
                    complete: function() {
                        submitBtn.prop('disabled', false);
                        submitBtn.html(originalText);
                    }
                });
            });

            // Modal management
            $('.modal-trigger').on('click', function(e) {
                e.preventDefault();
                const modalId = $(this).data('modal');
                const modal = $('#' + modalId);
                
                if (modal.length) {
                    modal.modal('show');
                }
            });

            // Print functionality
            $('.print-btn').on('click', function(e) {
                e.preventDefault();
                const printArea = $(this).data('print') || '.print-area';
                printElement(printArea);
            });

            // Filter functionality
            $('.filter-btn').on('click', function(e) {
                e.preventDefault();
                const filterValue = $(this).data('filter');
                const filterTarget = $(this).data('target') || '.filterable';
                
                if (filterValue === 'all') {
                    $(filterTarget).show();
                } else {
                    $(filterTarget).hide();
                    $(filterTarget + '[data-category="' + filterValue + '"]').show();
                }
                
                // Update active filter button
                $('.filter-btn').removeClass('active');
                $(this).addClass('active');
            });

            // Auto-save functionality
            $('.auto-save').on('change input', function() {
                const field = $(this);
                const formData = {
                    field: field.attr('name'),
                    value: field.val(),
                    csrf_token: $('input[name="csrf_token"]').val()
                };

                clearTimeout(field.data('timeout'));
                field.data('timeout', setTimeout(function() {
                    $.post(window.location.href, formData)
                        .done(function(response) {
                            if (response.success) {
                                field.addClass('is-valid').removeClass('is-invalid');
                                showToast('Auto-saved', 'success', 2000);
                            } else {
                                field.addClass('is-invalid').removeClass('is-valid');
                                showToast('Auto-save failed', 'error');
                            }
                        })
                        .fail(function() {
                            field.addClass('is-invalid').removeClass('is-valid');
                            showToast('Auto-save failed', 'error');
                        });
                }, 1000));
            });
        });

        // Utility Functions
        function showToast(message, type = 'info', duration = 3000) {
            const toastContainer = getOrCreateToastContainer();
            const toastId = 'toast-' + Date.now();
            const bgColor = {
                'success': 'bg-success',
                'error': 'bg-danger',
                'warning': 'bg-warning',
                'info': 'bg-info'
            }[type] || 'bg-info';

            const toast = $(`
                <div id="${toastId}" class="toast align-items-center text-white ${bgColor} border-0" role="alert">
                    <div class="d-flex">
                        <div class="toast-body">${message}</div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                    </div>
                </div>
            `);

            toastContainer.append(toast);
            
            const bsToast = new bootstrap.Toast(toast[0], {
                autohide: true,
                delay: duration
            });
            
            bsToast.show();

            // Remove toast element after it's hidden
            toast.on('hidden.bs.toast', function() {
                $(this).remove();
            });
        }

        function getOrCreateToastContainer() {
            let container = $('#toast-container');
            if (container.length === 0) {
                container = $('<div id="toast-container" class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1060;"></div>');
                $('body').append(container);
            }
            return container;
        }

        function printElement(selector) {
            const element = $(selector);
            if (element.length === 0) return;

            const printWindow = window.open('', '_blank');
            const title = document.title;
            
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>${title}</title>
                    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                    <style>
                        body { font-family: Arial, sans-serif; }
                        @media print {
                            .no-print { display: none !important; }
                            .page-break { page-break-before: always; }
                        }
                        .print-header {
                            text-align: center;
                            margin-bottom: 20px;
                            border-bottom: 2px solid #000;
                            padding-bottom: 10px;
                        }
                        .print-footer {
                            text-align: center;
                            margin-top: 20px;
                            border-top: 1px solid #ccc;
                            padding-top: 10px;
                            font-size: 0.8rem;
                            color: #666;
                        }
                    </style>
                </head>
                <body>
                    <div class="print-header">
                        <h2><?php echo APP_NAME; ?></h2>
                        <p>Generated on ${new Date().toLocaleDateString()}</p>
                    </div>
                    ${element.html()}
                    <div class="print-footer">
                        <p>This document was generated by <?php echo APP_NAME; ?> on ${new Date().toLocaleString()}</p>
                    </div>
                </body>
                </html>
            `);
            
            printWindow.document.close();
            printWindow.focus();
            
            setTimeout(function() {
                printWindow.print();
                printWindow.close();
            }, 250);
        }

        function confirmAction(message, callback) {
            if (confirm(message)) {
                callback();
            }
        }

        function formatCurrency(amount) {
            return '₵ ' + parseFloat(amount).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function formatDate(dateString) {
            const date = new Date(dateString);
            if (isNaN(date.getTime())) return dateString;
            
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
        }

        function formatDateTime(dateTimeString) {
            const date = new Date(dateTimeString);
            if (isNaN(date.getTime())) return dateTimeString;
            
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        // Session timeout warning
        let sessionTimeout;
        let warningTimeout;

        function resetSessionTimer() {
            clearTimeout(sessionTimeout);
            clearTimeout(warningTimeout);
            
            // Warning 5 minutes before session expires
            warningTimeout = setTimeout(function() {
                if (confirm('Your session will expire in 5 minutes. Click OK to extend your session.')) {
                    // Make a simple AJAX call to extend session
                    $.get('../../auth/extend_session.php').done(function() {
                        showToast('Session extended successfully', 'success');
                        resetSessionTimer();
                    });
                }
            }, <?php echo (SESSION_LIFETIME - 300) * 1000; ?>); // 5 minutes before expiry
            
            // Actual session timeout
            sessionTimeout = setTimeout(function() {
                alert('Your session has expired. You will be redirected to the login page.');
                window.location.href = '../../auth/login.php';
            }, <?php echo SESSION_LIFETIME * 1000; ?>);
        }

        // Start session timer
        resetSessionTimer();

        // Reset timer on user activity
        $(document).on('click keypress mousemove', function() {
            resetSessionTimer();
        });

        // Handle browser back button
        window.addEventListener('popstate', function(event) {
            // Refresh current page to ensure data consistency
            window.location.reload();
        });

        // Global error handler
        window.addEventListener('error', function(e) {
            console.error('JavaScript Error:', e.error);
            // Could send error to server for logging
        });

        // Service worker registration for offline functionality (if needed)
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('../../sw.js').catch(function(error) {
                console.log('Service Worker registration failed:', error);
            });
        }
    </script>

    <!-- Custom Page Scripts -->
    <?php if (isset($customScripts) && !empty($customScripts)): ?>
        <?php echo $customScripts; ?>
    <?php endif; ?>

    <!-- Development Scripts -->
    <?php if (ENVIRONMENT === 'development'): ?>
    <script>
        // Development helper functions
        console.log('QUICKBILL 305 - Development Mode');
        console.log('Current User:', <?php echo json_encode($currentUser ?? []); ?>);
        console.log('Page Title:', '<?php echo $pageTitle ?? 'Unknown'; ?>');
        
        // Debug information
        window.QUICKBILL_DEBUG = {
            environment: '<?php echo ENVIRONMENT; ?>',
            version: '<?php echo APP_VERSION; ?>',
            user: <?php echo json_encode($currentUser ?? []); ?>,
            timestamp: new Date().toISOString()
        };
    </script>
    <?php endif; ?>

</body>
</html>