 <?php
/**
 * Public Payment Portal Homepage for QUICKBILL 305
 * Allows public users to search and pay bills online
 */

// Define application constant
define('QUICKBILL_305', true);

// Include configuration files
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Start session for public portal
session_start();

// Get assembly name from settings
$assemblyName = getSystemSetting('assembly_name', 'Municipal Assembly');
$currentYear = date('Y');

// Get some basic statistics for display (non-sensitive data)
try {
    $db = new Database();
    
    // Get total bills generated this year (for display purposes)
    $billStats = $db->fetchRow("
        SELECT 
            COUNT(*) as total_bills,
            COUNT(CASE WHEN status = 'Paid' THEN 1 END) as paid_bills
        FROM bills 
        WHERE YEAR(generated_at) = YEAR(CURDATE())
    ");
    
    $totalBills = $billStats['total_bills'] ?? 0;
    $paidBills = $billStats['paid_bills'] ?? 0;
    $paymentRate = $totalBills > 0 ? round(($paidBills / $totalBills) * 100, 1) : 0;
    
} catch (Exception $e) {
    $totalBills = 0;
    $paidBills = 0;
    $paymentRate = 0;
}

include 'header.php';
?>

<div class="hero-section">
    <div class="hero-content">
        <div class="hero-text">
            <h1 class="hero-title">💳 Pay Your Bills Online</h1>
            <p class="hero-subtitle">
                Easy and secure online payment for business permits and property rates
            </p>
            <div class="hero-stats">
                <div class="stat-item">
                    <div class="stat-number"><?php echo number_format($totalBills); ?></div>
                    <div class="stat-label">Bills Generated (<?php echo $currentYear; ?>)</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?php echo $paymentRate; ?>%</div>
                    <div class="stat-label">Payment Rate</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number">24/7</div>
                    <div class="stat-label">Available</div>
                </div>
            </div>
        </div>
        
        <div class="hero-card">
            <div class="search-card">
                <h3 class="search-title">🔍 Find Your Bill</h3>
                <p class="search-subtitle">Enter your account number to view and pay your bill</p>
                
                <form action="search_bill.php" method="POST" class="search-form" id="searchForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <div class="form-group">
                        <label for="account_number">Account Number</label>
                        <input 
                            type="text" 
                            id="account_number" 
                            name="account_number" 
                            placeholder="e.g., BIZ000001 or PROP000001"
                            required
                            autocomplete="off"
                            class="form-control"
                        >
                        <small class="form-help">
                            Find your account number on your bill or SMS notification
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label for="account_type">Account Type</label>
                        <select id="account_type" name="account_type" required class="form-control">
                            <option value="">Select Account Type</option>
                            <option value="Business">Business Permit</option>
                            <option value="Property">Property Rates</option>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-search"></i>
                        Find My Bill
                    </button>
                </form>
                
                <div class="security-notice">
                    <i class="fas fa-shield-alt"></i>
                    <span>Your payment information is secure and encrypted</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="features-section">
    <div class="container">
        <h2 class="section-title">🚀 Why Pay Online?</h2>
        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon">⚡</div>
                <h4>Instant Processing</h4>
                <p>Your payment is processed immediately and receipt is available for download</p>
            </div>
            
            <div class="feature-card">
                <div class="feature-icon">📱</div>
                <h4>Mobile Money</h4>
                <p>Pay with MTN, Telecel, or AirtelTigo mobile money accounts</p>
            </div>
            
            <div class="feature-card">
                <div class="feature-icon">🔒</div>
                <h4>Secure Payments</h4>
                <p>All transactions are encrypted and protected with bank-level security</p>
            </div>
            
            <div class="feature-card">
                <div class="feature-icon">🧾</div>
                <h4>Digital Receipt</h4>
                <p>Download your payment receipt instantly for your records</p>
            </div>
            
            <div class="feature-card">
                <div class="feature-icon">💬</div>
                <h4>SMS Notifications</h4>
                <p>Get instant SMS confirmation when your payment is successful</p>
            </div>
            
            <div class="feature-card">
                <div class="feature-icon">⏰</div>
                <h4>24/7 Available</h4>
                <p>Pay your bills anytime, anywhere - no more waiting in queues</p>
            </div>
        </div>
    </div>
</div>

<div class="payment-methods-section">
    <div class="container">
        <h2 class="section-title">💳 Accepted Payment Methods</h2>
        <div class="payment-methods">
            <div class="payment-method">
                <img src="../assets/images/mtn-logo.png" alt="MTN" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                <div class="payment-fallback" style="display: none;">📱 </div>
                <span>MTN Mobile Money</span>
            </div>
            
            <div class="payment-method">
                <img src="../assets/images/telecel-logo.png" alt="Telecel" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                <div class="payment-fallback" style="display: none;">📱 </div>
                <span>Telecel Cash</span>
            </div>
            
            <div class="payment-method">
                <img src="../assets/images/airteltigo-logo.png" alt="AirtelTigo" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                <div class="payment-fallback" style="display: none;">📱 </div>
                <span>AirtelTigo Money</span>
            </div>
            
            <div class="payment-method">
                <div class="payment-fallback">💳</div>
                <span>Visa/Mastercard</span>
            </div>
        </div>
    </div>
</div>

<div class="help-section">
    <div class="container">
        <h2 class="section-title">❓ Need Help?</h2>
        <div class="help-grid">
            <div class="help-card">
                <h4>📞 Contact Support</h4>
                <p>Call us at: <strong>+233 249579191</strong></p>
                <p>Monday - Friday: 8:00 AM - 5:00 PM</p>
            </div>
            
            <div class="help-card">
                <h4>📧 Email Support</h4>
                <p>Send us an email: <strong>support@<?php echo strtolower(str_replace(' ', '', $assemblyName)); ?>.gov.gh</strong></p>
                <p>We respond within 24 hours</p>
            </div>
            
            <div class="help-card">
                <h4>🏢 Visit Our Office</h4>
                <p><?php echo htmlspecialchars($assemblyName); ?></p>
                <p>Monday - Friday: 8:00 AM - 5:00 PM</p>
            </div>
        </div>
    </div>
</div>

<style>
/* Additional styles specific to homepage */
.hero-section {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 80px 0;
    min-height: 600px;
    display: flex;
    align-items: center;
}

.hero-content {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 20px;
    display: grid;
    grid-template-columns: 1fr 450px;
    gap: 60px;
    align-items: center;
}

.hero-title {
    font-size: 3.5rem;
    font-weight: bold;
    margin-bottom: 20px;
    line-height: 1.2;
}

.hero-subtitle {
    font-size: 1.3rem;
    margin-bottom: 40px;
    opacity: 0.9;
    line-height: 1.5;
}

.hero-stats {
    display: flex;
    gap: 30px;
    margin-top: 40px;
}

.stat-item {
    text-align: center;
}

.stat-number {
    font-size: 2rem;
    font-weight: bold;
    color: #fbbf24;
}

.stat-label {
    font-size: 0.9rem;
    opacity: 0.8;
    margin-top: 5px;
}

.hero-card {
    background: rgba(255, 255, 255, 0.1);
    backdrop-filter: blur(10px);
    border-radius: 20px;
    padding: 40px;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.search-card {
    background: white;
    border-radius: 15px;
    padding: 30px;
    color: #2d3748;
}

.search-title {
    font-size: 1.5rem;
    font-weight: bold;
    margin-bottom: 10px;
    color: #2d3748;
}

.search-subtitle {
    color: #718096;
    margin-bottom: 25px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 8px;
    color: #2d3748;
}

.form-control {
    width: 100%;
    padding: 12px 15px;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    font-size: 16px;
    transition: border-color 0.3s;
}

.form-control:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.form-help {
    display: block;
    margin-top: 5px;
    font-size: 0.85rem;
    color: #718096;
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px 24px;
    border: none;
    border-radius: 10px;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.3s;
}

.btn-primary {
    background: #667eea;
    color: white;
}

.btn-primary:hover {
    background: #5a67d8;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
}

.btn-lg {
    width: 100%;
    padding: 15px 24px;
    font-size: 1.1rem;
}

.security-notice {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 20px;
    padding: 15px;
    background: #f0f9ff;
    border: 1px solid #bae6fd;
    border-radius: 8px;
    color: #0369a1;
    font-size: 0.9rem;
}

.features-section, .payment-methods-section, .help-section {
    padding: 80px 0;
}

.container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 20px;
}

.section-title {
    text-align: center;
    font-size: 2.5rem;
    font-weight: bold;
    margin-bottom: 50px;
    color: #2d3748;
}

.features-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 30px;
}

.feature-card {
    background: white;
    padding: 30px;
    border-radius: 15px;
    text-align: center;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    transition: transform 0.3s;
}

.feature-card:hover {
    transform: translateY(-5px);
}

.feature-icon {
    font-size: 3rem;
    margin-bottom: 20px;
}

.feature-card h4 {
    font-size: 1.3rem;
    font-weight: bold;
    margin-bottom: 15px;
    color: #2d3748;
}

.feature-card p {
    color: #718096;
    line-height: 1.6;
}

.payment-methods-section {
    background: #f7fafc;
}

.payment-methods {
    display: flex;
    justify-content: center;
    gap: 40px;
    flex-wrap: wrap;
}

.payment-method {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 15px;
    padding: 20px;
    background: white;
    border-radius: 15px;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
    transition: transform 0.3s;
}

.payment-method:hover {
    transform: translateY(-3px);
}

.payment-method img {
    width: 80px;
    height: 80px;
    object-fit: contain;
}

.payment-fallback {
    font-size: 3rem;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 80px;
    height: 80px;
}

.payment-method span {
    font-weight: 600;
    color: #2d3748;
}

.help-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 30px;
}

.help-card {
    background: white;
    padding: 30px;
    border-radius: 15px;
    text-align: center;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    border-left: 5px solid #667eea;
}

.help-card h4 {
    font-size: 1.3rem;
    font-weight: bold;
    margin-bottom: 15px;
    color: #2d3748;
}

.help-card p {
    color: #718096;
    margin-bottom: 10px;
    line-height: 1.6;
}

.help-card strong {
    color: #667eea;
}

/* Responsive */
@media (max-width: 768px) {
    .hero-content {
        grid-template-columns: 1fr;
        gap: 40px;
    }
    
    .hero-title {
        font-size: 2.5rem;
    }
    
    .hero-stats {
        justify-content: center;
    }
    
    .section-title {
        font-size: 2rem;
    }
    
    .payment-methods {
        gap: 20px;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('searchForm');
    const accountInput = document.getElementById('account_number');
    const typeSelect = document.getElementById('account_type');
    
    // Auto-detect account type based on account number
    accountInput.addEventListener('input', function() {
        const value = this.value.toUpperCase();
        
        if (value.startsWith('BIZ')) {
            typeSelect.value = 'Business';
        } else if (value.startsWith('PROP')) {
            typeSelect.value = 'Property';
        }
    });
    
    // Form submission with loading state
    form.addEventListener('submit', function(e) {
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Searching...';
        submitBtn.disabled = true;
        
        // Re-enable button after 10 seconds (in case of issues)
        setTimeout(() => {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }, 10000);
    });
    
    // Add smooth scroll for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });
});
</script>

<?php include 'footer.php'; ?>
