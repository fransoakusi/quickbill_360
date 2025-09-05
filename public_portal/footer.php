 <?php
/**
 * Public Portal Footer Template for QUICKBILL 305
 * Common footer for all public portal pages
 */

// Ensure constants are defined
if (!defined('QUICKBILL_305')) {
    die('Direct access not permitted');
}

// Get assembly name and current year
$assemblyName = getSystemSetting('assembly_name', 'Municipal Assembly');
$currentYear = date('Y');
?>

    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-container">
            <!-- Main Footer Content -->
            <div class="footer-content">
                <!-- About Section -->
                <div class="footer-section">
                    <h4 class="footer-title">
                        <i class="fas fa-building"></i>
                        <?php echo htmlspecialchars($assemblyName); ?>
                    </h4>
                    <p class="footer-text">
                        Modernizing revenue collection through secure online payment solutions. 
                        Pay your business permits and property rates conveniently from anywhere, anytime.
                    </p>
                    <div class="footer-stats">
                        <div class="footer-stat">
                            <i class="fas fa-shield-alt"></i>
                            <span>Secure Payments</span>
                        </div>
                        <div class="footer-stat">
                            <i class="fas fa-clock"></i>
                            <span>24/7 Available</span>
                        </div>
                        <div class="footer-stat">
                            <i class="fas fa-mobile-alt"></i>
                            <span>Mobile Friendly</span>
                        </div>
                    </div>
                </div>

                <!-- Quick Links -->
                <div class="footer-section">
                    <h4 class="footer-title">
                        <i class="fas fa-link"></i>
                        Quick Links
                    </h4>
                    <ul class="footer-links">
                        <li><a href="index.php"><i class="fas fa-home"></i> Home</a></li>
                        <li><a href="search_bill.php"><i class="fas fa-search"></i> Search Bill</a></li>
                        <li><a href="verify_payment.php"><i class="fas fa-check-circle"></i> Verify Payment</a></li>
                        <li><a href="#" onclick="showPaymentMethods()"><i class="fas fa-credit-card"></i> Payment Methods</a></li>
                        <li><a href="#" onclick="showFAQ()"><i class="fas fa-question-circle"></i> FAQ</a></li>
                    </ul>
                </div>

                <!-- Contact Information -->
                <div class="footer-section">
                    <h4 class="footer-title">
                        <i class="fas fa-phone"></i>
                        Contact Us
                    </h4>
                    <div class="contact-info">
                        <div class="contact-item">
                            <i class="fas fa-map-marker-alt"></i>
                            <div>
                                <strong>Address:</strong><br>
                                <?php echo htmlspecialchars($assemblyName); ?><br>
                                Ghana
                            </div>
                        </div>
                        <div class="contact-item">
                            <i class="fas fa-phone"></i>
                            <div>
                                <strong>Phone:</strong><br>
                                +233 249579191
                            </div>
                        </div>
                        <div class="contact-item">
                            <i class="fas fa-envelope"></i>
                            <div>
                                <strong>Email:</strong><br>
                                support@<?php echo strtolower(str_replace(' ', '', $assemblyName)); ?>.gov.gh
                            </div>
                        </div>
                        <div class="contact-item">
                            <i class="fas fa-clock"></i>
                            <div>
                                <strong>Hours:</strong><br>
                                Mon - Fri: 8:00 AM - 5:00 PM
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Security & Trust -->
                <div class="footer-section">
                    <h4 class="footer-title">
                        <i class="fas fa-shield-alt"></i>
                        Security & Trust
                    </h4>
                    <div class="security-badges">
                        <div class="security-badge">
                            <i class="fas fa-lock"></i>
                            <div>
                                <strong>SSL Encrypted</strong>
                                <small>256-bit encryption</small>
                            </div>
                        </div>
                        <div class="security-badge">
                            <i class="fas fa-certificate"></i>
                            <div>
                                <strong>PCI Compliant</strong>
                                <small>Secure payments</small>
                            </div>
                        </div>
                        <div class="security-badge">
                            <i class="fas fa-user-shield"></i>
                            <div>
                                <strong>Privacy Protected</strong>
                                <small>Data security</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Payment Partners -->
                    <div class="payment-partners">
                        <h5>Powered by:</h5>
                        <div class="partner-logos">
                            <img src="../assets/images/paystack-logo.png" alt="PayStack" class="partner-logo" 
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                            <div class="partner-fallback" style="display: none;">💳 PayStack</div>
                            
                            <img src="../assets/images/mtn-logo.png" alt="MTN" class="partner-logo"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                            <div class="partner-fallback" style="display: none;">📱 MTN</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer Bottom -->
            <div class="footer-bottom">
                <div class="footer-bottom-content">
                    <div class="copyright">
                        <p>&copy; <?php echo $currentYear; ?> <?php echo htmlspecialchars($assemblyName); ?>. All rights reserved.</p>
                        <p class="powered-by">Powered by <strong><?php echo APP_NAME; ?></strong> v<?php echo APP_VERSION; ?></p>
                    </div>
                    
                    <div class="footer-meta">
                        <div class="system-status">
                            <span class="status-indicator online" id="systemStatus"></span>
                            <span>System Online</span>
                        </div>
                        
                        <div class="footer-links-inline">
                            <a href="#" onclick="showPrivacyPolicy()">Privacy Policy</a>
                            <span>|</span>
                            <a href="#" onclick="showTermsOfService()">Terms of Service</a>
                            <span>|</span>
                            <a href="#" onclick="showCookiePolicy()">Cookie Policy</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </footer>

    <!-- Back to Top Button -->
    <button class="back-to-top" id="backToTop" onclick="scrollToTop()" title="Back to top">
        <i class="fas fa-chevron-up"></i>
    </button>

    <!-- Modals for Footer Links -->
    
    <!-- Payment Methods Modal -->
    <div class="modal" id="paymentMethodsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-credit-card"></i> Payment Methods</h3>
                <button class="modal-close" onclick="closeModal('paymentMethodsModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="payment-methods-grid">
                    <div class="payment-method-item">
                        <div class="payment-icon">📱</div>
                        <h4>Mobile Money</h4>
                        <p>MTN Mobile Money, Telecel Cash, AirtelTigo Money</p>
                        <small>Instant payment processing</small>
                    </div>
                    <div class="payment-method-item">
                        <div class="payment-icon">💳</div>
                        <h4>Debit/Credit Cards</h4>
                        <p>Visa, Mastercard, and other major cards</p>
                        <small>Secure online payment</small>
                    </div>
                    <div class="payment-method-item">
                        <div class="payment-icon">🏦</div>
                        <h4>Bank Transfer</h4>
                        <p>Direct bank account transfers</p>
                        <small>Available for registered accounts</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- FAQ Modal -->
    <div class="modal" id="faqModal">
        <div class="modal-content large">
            <div class="modal-header">
                <h3><i class="fas fa-question-circle"></i> Frequently Asked Questions</h3>
                <button class="modal-close" onclick="closeModal('faqModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="faq-list">
                    <div class="faq-item">
                        <h4>How do I find my bill?</h4>
                        <p>Enter your account number (found on your bill or SMS notification) and select your account type (Business or Property).</p>
                    </div>
                    <div class="faq-item">
                        <h4>What payment methods are accepted?</h4>
                        <p>We accept mobile money (MTN, Telecel, AirtelTigo), debit/credit cards, and bank transfers.</p>
                    </div>
                    <div class="faq-item">
                        <h4>Is my payment information secure?</h4>
                        <p>Yes, all payments are processed through encrypted channels with bank-level security.</p>
                    </div>
                    <div class="faq-item">
                        <h4>How quickly is my payment processed?</h4>
                        <p>Mobile money and card payments are processed instantly. Bank transfers may take 1-2 business days.</p>
                    </div>
                    <div class="faq-item">
                        <h4>Can I get a receipt for my payment?</h4>
                        <p>Yes, you can download your receipt immediately after successful payment or access it later using your payment reference.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Privacy Policy Modal -->
    <div class="modal" id="privacyModal">
        <div class="modal-content large">
            <div class="modal-header">
                <h3><i class="fas fa-shield-alt"></i> Privacy Policy</h3>
                <button class="modal-close" onclick="closeModal('privacyModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="policy-content">
                    <h4>Information We Collect</h4>
                    <p>We collect information necessary to process your bill payments, including account numbers, payment amounts, and transaction details.</p>
                    
                    <h4>How We Use Your Information</h4>
                    <p>Your information is used solely for processing payments, generating receipts, and maintaining transaction records for the assembly.</p>
                    
                    <h4>Data Security</h4>
                    <p>We implement industry-standard security measures to protect your personal and payment information.</p>
                    
                    <h4>Contact Us</h4>
                    <p>For privacy concerns, contact us at: support@<?php echo strtolower(str_replace(' ', '', $assemblyName)); ?>.gov.gh</p>
                </div>
            </div>
        </div>
    </div>

    <style>
        /* Footer Styles */
        .footer {
            background: linear-gradient(135deg, #1a202c 0%, #2d3748 100%);
            color: #e2e8f0;
            margin-top: 80px;
        }

        .footer-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 40px;
            padding: 60px 0 40px;
        }

        .footer-section h4.footer-title {
            color: #fff;
            margin-bottom: 20px;
            font-size: 1.2rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .footer-text {
            line-height: 1.6;
            margin-bottom: 20px;
            color: #cbd5e0;
        }

        .footer-stats {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .footer-stat {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #a0aec0;
            font-size: 0.9rem;
        }

        .footer-stat i {
            color: #667eea;
            width: 16px;
        }

        .footer-links {
            list-style: none;
            padding: 0;
        }

        .footer-links li {
            margin-bottom: 10px;
        }

        .footer-links a {
            color: #cbd5e0;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s;
            padding: 5px 0;
        }

        .footer-links a:hover {
            color: #667eea;
            transform: translateX(5px);
        }

        .footer-links a i {
            width: 16px;
            font-size: 0.9rem;
        }

        .contact-info {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .contact-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .contact-item i {
            color: #667eea;
            width: 16px;
            margin-top: 2px;
            flex-shrink: 0;
        }

        .contact-item div {
            color: #cbd5e0;
            font-size: 0.9rem;
            line-height: 1.4;
        }

        .contact-item strong {
            color: #fff;
        }

        .security-badges {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-bottom: 25px;
        }

        .security-badge {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .security-badge i {
            color: #48bb78;
            font-size: 1.2rem;
            width: 20px;
        }

        .security-badge strong {
            color: #fff;
            font-size: 0.9rem;
        }

        .security-badge small {
            color: #a0aec0;
            display: block;
            font-size: 0.8rem;
        }

        .payment-partners h5 {
            color: #fff;
            margin-bottom: 10px;
            font-size: 0.9rem;
        }

        .partner-logos {
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .partner-logo {
            height: 30px;
            opacity: 0.8;
            transition: opacity 0.3s;
        }

        .partner-logo:hover {
            opacity: 1;
        }

        .partner-fallback {
            font-size: 1.5rem;
            opacity: 0.8;
        }

        .footer-bottom {
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding: 25px 0;
        }

        .footer-bottom-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .copyright p {
            margin: 0;
            color: #a0aec0;
            font-size: 0.9rem;
        }

        .powered-by {
            font-size: 0.8rem !important;
            margin-top: 5px !important;
        }

        .footer-meta {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .system-status {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
        }

        .status-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        .status-indicator.online {
            background: #48bb78;
        }

        .status-indicator.offline {
            background: #f56565;
        }

        .footer-links-inline {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9rem;
        }

        .footer-links-inline a {
            color: #cbd5e0;
            text-decoration: none;
            transition: color 0.3s;
        }

        .footer-links-inline a:hover {
            color: #667eea;
        }

        .footer-links-inline span {
            color: #4a5568;
        }

        /* Back to Top Button */
        .back-to-top {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 50px;
            height: 50px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
            transition: all 0.3s;
            z-index: 1000;
        }

        .back-to-top:hover {
            background: #5a67d8;
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }

        .back-to-top.visible {
            display: flex;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 20px;
            backdrop-filter: blur(5px);
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 15px;
            max-width: 500px;
            width: 100%;
            max-height: 80vh;
            overflow-y: auto;
            animation: modalSlideIn 0.3s ease;
        }

        .modal-content.large {
            max-width: 700px;
        }

        .modal-header {
            padding: 25px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #718096;
            padding: 5px;
            border-radius: 5px;
            transition: all 0.3s;
        }

        .modal-close:hover {
            background: #f7fafc;
            color: #2d3748;
        }

        .modal-body {
            padding: 25px;
        }

        .payment-methods-grid {
            display: grid;
            gap: 20px;
        }

        .payment-method-item {
            padding: 20px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            text-align: center;
            transition: all 0.3s;
        }

        .payment-method-item:hover {
            border-color: #667eea;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.1);
        }

        .payment-icon {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .payment-method-item h4 {
            margin: 10px 0;
            color: #2d3748;
        }

        .payment-method-item p {
            color: #4a5568;
            margin-bottom: 5px;
        }

        .payment-method-item small {
            color: #718096;
            font-style: italic;
        }

        .faq-list {
            display: flex;
            flex-direction: column;
            gap: 25px;
        }

        .faq-item {
            padding-bottom: 20px;
            border-bottom: 1px solid #e2e8f0;
        }

        .faq-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .faq-item h4 {
            color: #2d3748;
            margin-bottom: 10px;
            font-size: 1.1rem;
        }

        .faq-item p {
            color: #4a5568;
            line-height: 1.6;
        }

        .policy-content h4 {
            color: #2d3748;
            margin: 20px 0 10px;
            font-size: 1.1rem;
        }

        .policy-content h4:first-child {
            margin-top: 0;
        }

        .policy-content p {
            color: #4a5568;
            line-height: 1.6;
            margin-bottom: 15px;
        }

        /* Animations */
        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        @keyframes pulse {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: 0.5;
            }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .footer-content {
                grid-template-columns: 1fr;
                gap: 30px;
                padding: 40px 0 30px;
            }

            .footer-bottom-content {
                flex-direction: column;
                text-align: center;
            }

            .footer-meta {
                flex-direction: column;
                gap: 15px;
            }

            .back-to-top {
                bottom: 20px;
                right: 20px;
                width: 45px;
                height: 45px;
            }

            .modal-content {
                margin: 10px;
            }

            .modal-header,
            .modal-body {
                padding: 20px;
            }
        }
    </style>

    <script>
        // Footer JavaScript functionality

        // Back to top button
        function scrollToTop() {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        }

        // Show/hide back to top button
        function toggleBackToTop() {
            const button = document.getElementById('backToTop');
            if (window.pageYOffset > 300) {
                button.classList.add('visible');
            } else {
                button.classList.remove('visible');
            }
        }

        // Modal functions
        function showPaymentMethods() {
            showModal('paymentMethodsModal');
        }

        function showFAQ() {
            showModal('faqModal');
        }

        function showPrivacyPolicy() {
            showModal('privacyModal');
        }

        function showTermsOfService() {
            alert('Terms of Service\n\nBy using this payment portal, you agree to:\n\n• Provide accurate payment information\n• Use the service for legitimate bill payments only\n• Accept responsibility for payment transactions\n• Comply with all applicable laws and regulations\n\nFor complete terms, contact: support@<?php echo strtolower(str_replace(' ', '', $assemblyName)); ?>.gov.gh');
        }

        function showCookiePolicy() {
            alert('Cookie Policy\n\nThis website uses cookies to:\n\n• Remember your session information\n• Improve user experience\n• Analyze website usage\n• Ensure secure transactions\n\nBy continuing to use this site, you consent to our use of cookies.');
        }

        function showModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = 'auto';
            }
        }

        // System status monitoring
        function updateSystemStatus() {
            const statusIndicator = document.getElementById('systemStatus');
            const statusText = statusIndicator.nextElementSibling;
            
            // Simple network check
            if (navigator.onLine) {
                statusIndicator.className = 'status-indicator online';
                statusText.textContent = 'System Online';
            } else {
                statusIndicator.className = 'status-indicator offline';
                statusText.textContent = 'System Offline';
            }
        }

        // Event listeners
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize back to top functionality
            window.addEventListener('scroll', toggleBackToTop);
            
            // Initialize system status
            updateSystemStatus();
            window.addEventListener('online', updateSystemStatus);
            window.addEventListener('offline', updateSystemStatus);
            
            // Close modals when clicking outside
            document.querySelectorAll('.modal').forEach(modal => {
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        closeModal(modal.id);
                    }
                });
            });
            
            // Close modals with Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    document.querySelectorAll('.modal.active').forEach(modal => {
                        closeModal(modal.id);
                    });
                }
            });
        });

        // Enhanced footer link tracking
        function trackFooterClick(linkName) {
            console.log('Footer link clicked:', linkName);
            // Add analytics tracking here if needed
        }

        // Add click tracking to footer links
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.footer-links a').forEach(link => {
                link.addEventListener('click', function() {
                    trackFooterClick(this.textContent.trim());
                });
            });
        });
    </script>

</body>
</html>
