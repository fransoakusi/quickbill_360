 <?php
/**
 * Public Portal - Download Receipt for QUICKBILL 305
 * Generates and downloads payment receipt as PDF or HTML
 */

// Define application constant
define('QUICKBILL_305', true);

// Include configuration files
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Start session for public portal
session_start();

// Get parameters from URL
$paymentReference = isset($_GET['reference']) ? sanitizeInput($_GET['reference']) : '';
$billId = isset($_GET['bill_id']) ? (int)$_GET['bill_id'] : 0;
$format = isset($_GET['format']) ? sanitizeInput($_GET['format']) : 'html'; // html or pdf

if (!$paymentReference || !$billId) {
    header('Location: verify_payment.php');
    exit();
}

// Initialize variables
$paymentData = null;
$billData = null;
$assemblyName = getSystemSetting('assembly_name', 'Municipal Assembly');

try {
    $db = new Database();
    
    // Get payment details
    $paymentQuery = "
        SELECT 
            p.payment_id,
            p.payment_reference,
            p.bill_id,
            p.amount_paid,
            p.payment_method,
            p.payment_channel,
            p.transaction_id,
            p.paystack_reference,
            p.payment_status,
            p.payment_date,
            p.processed_by,
            p.notes
        FROM payments p
        WHERE p.payment_reference = ? AND p.bill_id = ? AND p.payment_status = 'Successful'
        ORDER BY p.payment_date DESC
        LIMIT 1
    ";
    
    $paymentData = $db->fetchRow($paymentQuery, [$paymentReference, $billId]);
    
    if (!$paymentData) {
        header('Location: verify_payment.php');
        exit();
    }
    
    // Get bill details
    $billQuery = "
        SELECT 
            b.bill_id,
            b.bill_number,
            b.bill_type,
            b.reference_id,
            b.billing_year,
            b.old_bill,
            b.previous_payments,
            b.arrears,
            b.current_bill,
            b.amount_payable,
            b.status as bill_status,
            b.generated_at,
            CASE 
                WHEN b.bill_type = 'Business' THEN bs.business_name
                WHEN b.bill_type = 'Property' THEN p.owner_name
            END as account_name,
            CASE 
                WHEN b.bill_type = 'Business' THEN bs.account_number
                WHEN b.bill_type = 'Property' THEN p.property_number
            END as account_number,
            CASE 
                WHEN b.bill_type = 'Business' THEN bs.owner_name
                WHEN b.bill_type = 'Property' THEN p.owner_name
            END as owner_name,
            CASE 
                WHEN b.bill_type = 'Business' THEN bs.telephone
                WHEN b.bill_type = 'Property' THEN p.telephone
            END as telephone,
            CASE 
                WHEN b.bill_type = 'Business' THEN bs.exact_location
                WHEN b.bill_type = 'Property' THEN p.location
            END as location
        FROM bills b
        LEFT JOIN businesses bs ON b.bill_type = 'Business' AND b.reference_id = bs.business_id
        LEFT JOIN properties p ON b.bill_type = 'Property' AND b.reference_id = p.property_id
        WHERE b.bill_id = ?
    ";
    
    $billData = $db->fetchRow($billQuery, [$billId]);
    
    if (!$billData) {
        header('Location: verify_payment.php');
        exit();
    }
    
} catch (Exception $e) {
    writeLog("Receipt download error: " . $e->getMessage(), 'ERROR');
    header('Location: verify_payment.php');
    exit();
}

// Set appropriate headers for download
$filename = 'Receipt_' . $paymentData['payment_reference'] . '_' . date('Y-m-d');

if ($format === 'pdf') {
    // For PDF generation, you'd typically use a library like TCPDF or mPDF
    // For now, we'll output HTML that can be printed as PDF
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
} else {
    // HTML format for printing
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.html"');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Receipt - <?php echo htmlspecialchars($paymentData['payment_reference']); ?></title>
    <style>
        /* Receipt Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Arial', sans-serif;
            line-height: 1.4;
            color: #333;
            background: white;
            padding: 20px;
        }

        .receipt-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border: 2px solid #000;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }

        /* Header */
        .receipt-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .assembly-logo {
            width: 80px;
            height: 80px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin-bottom: 15px;
            border: 3px solid rgba(255,255,255,0.3);
        }

        .assembly-name {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .receipt-title {
            font-size: 18px;
            font-weight: normal;
            opacity: 0.9;
            margin-bottom: 10px;
        }

        .receipt-date {
            font-size: 14px;
            opacity: 0.8;
        }

        /* Status Banner */
        .status-banner {
            background: #48bb78;
            color: white;
            padding: 15px;
            text-align: center;
            font-weight: bold;
            font-size: 18px;
            letter-spacing: 1px;
        }

        /* Receipt Content */
        .receipt-content {
            padding: 30px;
        }

        .section {
            margin-bottom: 25px;
            border-bottom: 1px solid #eee;
            padding-bottom: 20px;
        }

        .section:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .section-title {
            font-size: 16px;
            font-weight: bold;
            color: #333;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid #667eea;
            display: inline-block;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px dotted #ddd;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            font-weight: 600;
            color: #555;
            flex: 0 0 45%;
        }

        .info-value {
            font-weight: bold;
            color: #333;
            text-align: right;
            flex: 1;
        }

        .info-value.amount {
            color: #d69e2e;
            font-size: 18px;
        }

        .info-value.reference {
            font-family: 'Courier New', monospace;
            background: #f7fafc;
            padding: 4px 8px;
            border-radius: 4px;
            border: 1px solid #e2e8f0;
        }

        /* Amount Summary */
        .amount-summary {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #e9ecef;
            margin: 20px 0;
        }

        .amount-total {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
            border-top: 2px solid #333;
            margin-top: 15px;
            font-size: 20px;
            font-weight: bold;
        }

        .amount-total .total-label {
            color: #333;
        }

        .amount-total .total-value {
            color: #d69e2e;
        }

        /* QR Code Section */
        .qr-section {
            text-align: center;
            margin: 20px 0;
        }

        .qr-placeholder {
            width: 100px;
            height: 100px;
            border: 2px dashed #ddd;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            color: #999;
            margin-bottom: 10px;
        }

        /* Footer */
        .receipt-footer {
            background: #f8f9fa;
            padding: 20px;
            border-top: 1px solid #e9ecef;
            text-align: center;
        }

        .footer-message {
            font-size: 16px;
            font-weight: bold;
            color: #333;
            margin-bottom: 15px;
        }

        .footer-note {
            font-size: 12px;
            color: #666;
            line-height: 1.5;
            margin-bottom: 15px;
        }

        .contact-info {
            display: flex;
            justify-content: center;
            gap: 30px;
            flex-wrap: wrap;
            font-size: 12px;
            color: #555;
        }

        .contact-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        /* Security Features */
        .security-notice {
            background: #e6f3ff;
            border: 1px solid #b3d9ff;
            padding: 15px;
            border-radius: 6px;
            margin: 20px 0;
            font-size: 12px;
            color: #0066cc;
        }

        /* Print Styles */
        @media print {
            body {
                margin: 0;
                padding: 0;
            }
            
            .receipt-container {
                box-shadow: none;
                border: 1px solid #000;
                margin: 0;
                max-width: none;
                width: 100%;
            }
            
            .receipt-header {
                background: #f0f0f0 !important;
                color: #000 !important;
            }
            
            .status-banner {
                background: #f0f0f0 !important;
                color: #000 !important;
                border: 1px solid #000;
            }
            
            .section-title {
                border-bottom-color: #000 !important;
            }
            
            .amount-summary {
                background: #f9f9f9 !important;
            }
            
            .security-notice {
                background: #f0f0f0 !important;
                border: 1px solid #000 !important;
                color: #000 !important;
            }
            
            .receipt-footer {
                background: #f9f9f9 !important;
            }
        }

        /* Responsive */
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }
            
            .receipt-header {
                padding: 20px;
            }
            
            .assembly-name {
                font-size: 20px;
            }
            
            .receipt-content {
                padding: 20px;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .info-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }
            
            .info-value {
                text-align: left;
            }
            
            .contact-info {
                flex-direction: column;
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <!-- Header -->
        <div class="receipt-header">
            <div class="assembly-logo">🏛️</div>
            <div class="assembly-name"><?php echo strtoupper(htmlspecialchars($assemblyName)); ?></div>
            <div class="receipt-title">OFFICIAL PAYMENT RECEIPT</div>
            <div class="receipt-date">Generated: <?php echo date('l, F j, Y \a\t g:i A'); ?></div>
        </div>

        <!-- Status Banner -->
        <div class="status-banner">
            ✓ PAYMENT SUCCESSFUL
        </div>

        <!-- Receipt Content -->
        <div class="receipt-content">
            <!-- Payment Information -->
            <div class="section">
                <div class="section-title">💳 Payment Information</div>
                <div class="info-grid">
                    <div>
                        <div class="info-row">
                            <span class="info-label">Payment Reference:</span>
                            <span class="info-value reference"><?php echo htmlspecialchars($paymentData['payment_reference']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Amount Paid:</span>
                            <span class="info-value amount">₵ <?php echo number_format($paymentData['amount_paid'], 2); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Payment Method:</span>
                            <span class="info-value">
                                <?php 
                                echo htmlspecialchars($paymentData['payment_method']);
                                if (!empty($paymentData['payment_channel'])) {
                                    echo ' - ' . htmlspecialchars($paymentData['payment_channel']);
                                }
                                ?>
                            </span>
                        </div>
                    </div>
                    <div>
                        <div class="info-row">
                            <span class="info-label">Payment Date:</span>
                            <span class="info-value"><?php echo formatDateTime($paymentData['payment_date']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Status:</span>
                            <span class="info-value">✅ Successful</span>
                        </div>
                        <?php if (!empty($paymentData['transaction_id'])): ?>
                        <div class="info-row">
                            <span class="info-label">Transaction ID:</span>
                            <span class="info-value"><?php echo htmlspecialchars($paymentData['transaction_id']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Bill Information -->
            <div class="section">
                <div class="section-title">📄 Bill Information</div>
                <div class="info-grid">
                    <div>
                        <div class="info-row">
                            <span class="info-label">Bill Number:</span>
                            <span class="info-value"><?php echo htmlspecialchars($billData['bill_number']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Account Number:</span>
                            <span class="info-value"><?php echo htmlspecialchars($billData['account_number']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><?php echo $billData['bill_type'] === 'Business' ? 'Business Name:' : 'Property Owner:'; ?></span>
                            <span class="info-value"><?php echo htmlspecialchars($billData['account_name']); ?></span>
                        </div>
                    </div>
                    <div>
                        <div class="info-row">
                            <span class="info-label">Bill Type:</span>
                            <span class="info-value">
                                <?php echo $billData['bill_type'] === 'Business' ? '🏢 Business Permit' : '🏠 Property Rates'; ?>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Billing Year:</span>
                            <span class="info-value"><?php echo $billData['billing_year']; ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Remaining Balance:</span>
                            <span class="info-value amount">₵ <?php echo number_format($billData['amount_payable'], 2); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payer Information -->
            <div class="section">
                <div class="section-title">👤 Payer Information</div>
                <div class="info-grid">
                    <div>
                        <div class="info-row">
                            <span class="info-label">Name:</span>
                            <span class="info-value"><?php echo htmlspecialchars($billData['owner_name']); ?></span>
                        </div>
                        <?php if (!empty($billData['telephone'])): ?>
                        <div class="info-row">
                            <span class="info-label">Phone:</span>
                            <span class="info-value"><?php echo htmlspecialchars($billData['telephone']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <?php if (!empty($billData['location'])): ?>
                        <div class="info-row">
                            <span class="info-label">Location:</span>
                            <span class="info-value"><?php echo htmlspecialchars($billData['location']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Amount Summary -->
            <div class="amount-summary">
                <div class="info-row">
                    <span class="info-label">Payment Amount:</span>
                    <span class="info-value">₵ <?php echo number_format($paymentData['amount_paid'], 2); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Processing Fee:</span>
                    <span class="info-value">₵ 0.00</span>
                </div>
                <div class="amount-total">
                    <span class="total-label">TOTAL PAID:</span>
                    <span class="total-value">₵ <?php echo number_format($paymentData['amount_paid'], 2); ?></span>
                </div>
            </div>

            <!-- Notes -->
            <?php if (!empty($paymentData['notes'])): ?>
            <div class="section">
                <div class="section-title">📝 Notes</div>
                <p style="background: #f8f9fa; padding: 15px; border-radius: 6px; border: 1px solid #e9ecef;">
                    <?php echo htmlspecialchars($paymentData['notes']); ?>
                </p>
            </div>
            <?php endif; ?>

            <!-- QR Code Section -->
            <div class="qr-section">
                <div class="section-title">📱 Verification QR Code</div>
                <div class="qr-placeholder">
                    QR Code<br>
                    (Verification)
                </div>
                <p style="font-size: 12px; color: #666;">
                    Scan this QR code to verify this receipt online
                </p>
            </div>

            <!-- Security Notice -->
            <div class="security-notice">
                <strong>🔒 Security Notice:</strong> This is an official receipt from <?php echo htmlspecialchars($assemblyName); ?>. 
                For verification, visit our official website or contact our office using the details below. 
                Reference Number: <?php echo htmlspecialchars($paymentData['payment_reference']); ?>
            </div>
        </div>

        <!-- Footer -->
        <div class="receipt-footer">
            <div class="footer-message">
                Thank you for your payment!
            </div>
            
            <div class="footer-note">
                This receipt serves as proof of payment for your <?php echo strtolower($billData['bill_type']); ?> bill. 
                Please keep this receipt for your records. For any inquiries about this payment, 
                please contact us with the payment reference number.
            </div>
            
            <div class="contact-info">
                <div class="contact-item">
                    <span>📞</span>
                    <span>+233 123 456 789</span>
                </div>
                <div class="contact-item">
                    <span>📧</span>
                    <span>support@<?php echo strtolower(str_replace(' ', '', $assemblyName)); ?>.gov.gh</span>
                </div>
                <div class="contact-item">
                    <span>🌐</span>
                    <span><?php echo $_SERVER['HTTP_HOST']; ?></span>
                </div>
                <div class="contact-item">
                    <span>⏰</span>
                    <span>Mon-Fri: 8:00 AM - 5:00 PM</span>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Auto-print if this is a PDF download
        <?php if ($format === 'pdf'): ?>
        window.onload = function() {
            setTimeout(() => {
                window.print();
            }, 1000);
        };
        <?php endif; ?>

        // Track download
        if (typeof gtag !== 'undefined') {
            gtag('event', 'receipt_download', {
                'payment_reference': '<?php echo addslashes($paymentData['payment_reference']); ?>',
                'format': '<?php echo $format; ?>',
                'bill_type': '<?php echo addslashes($billData['bill_type']); ?>'
            });
        }
    </script>
</body>
</html>
