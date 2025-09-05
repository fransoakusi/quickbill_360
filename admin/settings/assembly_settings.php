 <?php
/**
 * Admin Assembly Settings - QUICKBILL 305
 * Manage assembly-specific configuration and branding
 */

// Define application constant
define('QUICKBILL_305', true);

// Include configuration files
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Start session
session_start();

// Include auth and security
require_once '../../includes/auth.php';
require_once '../../includes/security.php';

// Initialize auth and security
initAuth();
initSecurity();

// Check if user is logged in and has admin privileges
if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

if (!hasPermission('settings.edit')) {
    setFlashMessage('error', 'Access denied. Assembly settings management privileges required.');
    redirect('../admin/index.php');
}

$currentUser = getCurrentUser();
$pageTitle = 'Assembly Settings';

// Handle file upload for logo
if (isset($_FILES['assembly_logo']) && $_FILES['assembly_logo']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = UPLOADS_PATH . '/logos';
    if (!is_dir($uploadDir)) {
        createDirectory($uploadDir);
    }
    
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
    $maxSize = 2 * 1024 * 1024; // 2MB
    
    $fileType = $_FILES['assembly_logo']['type'];
    $fileSize = $_FILES['assembly_logo']['size'];
    $fileName = $_FILES['assembly_logo']['name'];
    $tempName = $_FILES['assembly_logo']['tmp_name'];
    
    if (!in_array($fileType, $allowedTypes)) {
        setFlashMessage('error', 'Invalid file type. Please upload JPG, PNG, or GIF images only.');
    } elseif ($fileSize > $maxSize) {
        setFlashMessage('error', 'File size too large. Maximum size is 2MB.');
    } else {
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $newFileName = 'assembly_logo_' . time() . '.' . $extension;
        $uploadPath = $uploadDir . '/' . $newFileName;
        
        if (move_uploaded_file($tempName, $uploadPath)) {
            // Update logo path in database
            try {
                $db = new Database();
                $logoUrl = 'uploads/logos/' . $newFileName;
                
                $checkSql = "SELECT setting_id FROM system_settings WHERE setting_key = 'assembly_logo'";
                $existing = $db->fetchRow($checkSql);
                
                if ($existing) {
                    $sql = "UPDATE system_settings SET setting_value = ?, updated_by = ?, updated_at = NOW() WHERE setting_key = 'assembly_logo'";
                    $params = [$logoUrl, $currentUser['user_id']];
                } else {
                    $sql = "INSERT INTO system_settings (setting_key, setting_value, setting_type, description, updated_by, updated_at) VALUES ('assembly_logo', ?, 'text', 'Assembly logo path', ?, NOW())";
                    $params = [$logoUrl, $currentUser['user_id']];
                }
                
                $db->execute($sql, $params);
                logUserAction('LOGO_UPLOADED', 'system_settings', null, null, ['filename' => $newFileName]);
                setFlashMessage('success', 'Assembly logo uploaded successfully.');
                
            } catch (Exception $e) {
                setFlashMessage('error', 'Failed to save logo information to database.');
            }
        } else {
            setFlashMessage('error', 'Failed to upload logo file.');
        }
    }
}

// Handle assembly settings updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_FILES['assembly_logo'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Invalid security token. Please try again.');
        redirect($_SERVER['PHP_SELF']);
    }

    try {
        $db = new Database();
        $updated = 0;
        $errors = [];

        // Assembly settings to update
        $assemblySettings = [
            'assembly_name' => ['label' => 'Assembly Name', 'required' => true],
            'assembly_address' => ['label' => 'Assembly Address', 'required' => false],
            'assembly_phone' => ['label' => 'Assembly Phone', 'required' => false],
            'assembly_email' => ['label' => 'Assembly Email', 'required' => false],
            'assembly_website' => ['label' => 'Assembly Website', 'required' => false],
            'assembly_motto' => ['label' => 'Assembly Motto', 'required' => false],
            'assembly_region' => ['label' => 'Region', 'required' => false],
            'assembly_district' => ['label' => 'District', 'required' => false],
            'chief_executive' => ['label' => 'Chief Executive', 'required' => false],
            'finance_officer' => ['label' => 'Finance Officer', 'required' => false],
            'bill_footer_text' => ['label' => 'Bill Footer Text', 'required' => false],
            'receipt_footer_text' => ['label' => 'Receipt Footer Text', 'required' => false]
        ];

        $db->beginTransaction();

        foreach ($assemblySettings as $key => $config) {
            $value = sanitizeInput($_POST[$key] ?? '');
            
            // Validate required fields
            if ($config['required'] && empty($value)) {
                $errors[] = $config['label'] . ' is required';
                continue;
            }
            
            // Validate email format
            if ($key === 'assembly_email' && !empty($value) && !isValidEmail($value)) {
                $errors[] = 'Please enter a valid email address';
                continue;
            }
            
            // Validate phone format
            if ($key === 'assembly_phone' && !empty($value) && !isValidPhone($value)) {
                $errors[] = 'Please enter a valid phone number';
                continue;
            }

            // Check if setting exists, if not create it
            $existingQuery = "SELECT setting_id FROM system_settings WHERE setting_key = ?";
            $existing = $db->fetchRow($existingQuery, [$key]);

            if ($existing) {
                // Update existing setting
                $sql = "UPDATE system_settings SET setting_value = ?, updated_by = ?, updated_at = NOW() WHERE setting_key = ?";
                $params = [$value, $currentUser['user_id'], $key];
            } else {
                // Create new setting
                $sql = "INSERT INTO system_settings (setting_key, setting_value, setting_type, description, updated_by, updated_at) VALUES (?, ?, 'text', ?, ?, NOW())";
                $params = [$key, $value, $config['label'], $currentUser['user_id']];
            }

            if ($db->execute($sql, $params)) {
                $updated++;
            } else {
                $errors[] = 'Failed to update ' . $config['label'];
            }
        }

        if (empty($errors)) {
            $db->commit();
            logUserAction('ASSEMBLY_SETTINGS_UPDATED', 'system_settings', null, null, array_keys($assemblySettings));
            setFlashMessage('success', "Successfully updated $updated assembly setting(s).");
        } else {
            $db->rollback();
            foreach ($errors as $error) {
                setFlashMessage('error', $error);
            }
        }

    } catch (Exception $e) {
        if (isset($db)) {
            $db->rollback();
        }
        writeLog("Assembly settings update error: " . $e->getMessage(), 'ERROR');
        setFlashMessage('error', 'An error occurred while updating assembly settings.');
    }

    redirect($_SERVER['PHP_SELF']);
}

// Fetch current assembly settings
try {
    $db = new Database();
    $assemblySettings = [];
    $result = $db->fetchAll("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'assembly_%' OR setting_key LIKE '%_footer_text' OR setting_key LIKE 'chief_%' OR setting_key LIKE 'finance_%'");
    
    foreach ($result as $row) {
        $assemblySettings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    writeLog("Error fetching assembly settings: " . $e->getMessage(), 'ERROR');
    $assemblySettings = [];
}

include '../header.php';
?>

<div class="admin-content">
    <div class="container-fluid">
        <!-- Page Header -->
        <div class="page-header">
            <div class="row align-items-center">
                <div class="col">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="../index.php">Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="index.php">Settings</a></li>
                            <li class="breadcrumb-item active">Assembly Settings</li>
                        </ol>
                    </nav>
                    <h1 class="page-title">
                        <i class="fas fa-building"></i>
                        Assembly Settings
                    </h1>
                    <p class="page-subtitle">Configure assembly information and branding</p>
                </div>
                <div class="col-auto">
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Back to Settings
                    </a>
                </div>
            </div>
        </div>

        <!-- Flash Messages -->
        <?php include '../includes/flash_messages.php'; ?>

        <div class="row">
            <!-- Assembly Information Form -->
            <div class="col-xl-8">
                <form method="POST" action="" id="assemblySettingsForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <!-- Basic Information -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title">
                                <i class="fas fa-info-circle"></i>
                                Basic Information
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="assembly_name">Assembly Name <span class="text-danger">*</span></label>
                                        <input type="text" 
                                               class="form-control" 
                                               id="assembly_name" 
                                               name="assembly_name" 
                                               value="<?php echo htmlspecialchars($assemblySettings['assembly_name'] ?? ''); ?>"
                                               required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="assembly_motto">Assembly Motto</label>
                                        <input type="text" 
                                               class="form-control" 
                                               id="assembly_motto" 
                                               name="assembly_motto" 
                                               value="<?php echo htmlspecialchars($assemblySettings['assembly_motto'] ?? ''); ?>"
                                               placeholder="e.g., Service to the People">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="assembly_address">Assembly Address</label>
                                <textarea class="form-control" 
                                          id="assembly_address" 
                                          name="assembly_address" 
                                          rows="3"
                                          placeholder="Enter complete address"><?php echo htmlspecialchars($assemblySettings['assembly_address'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="assembly_region">Region</label>
                                        <input type="text" 
                                               class="form-control" 
                                               id="assembly_region" 
                                               name="assembly_region" 
                                               value="<?php echo htmlspecialchars($assemblySettings['assembly_region'] ?? ''); ?>"
                                               placeholder="e.g., Greater Accra">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="assembly_district">District</label>
                                        <input type="text" 
                                               class="form-control" 
                                               id="assembly_district" 
                                               name="assembly_district" 
                                               value="<?php echo htmlspecialchars($assemblySettings['assembly_district'] ?? ''); ?>"
                                               placeholder="e.g., Accra Metropolitan">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Contact Information -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title">
                                <i class="fas fa-address-book"></i>
                                Contact Information
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="assembly_phone">Phone Number</label>
                                        <input type="tel" 
                                               class="form-control" 
                                               id="assembly_phone" 
                                               name="assembly_phone" 
                                               value="<?php echo htmlspecialchars($assemblySettings['assembly_phone'] ?? ''); ?>"
                                               placeholder="e.g., +233 20 123 4567">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="assembly_email">Email Address</label>
                                        <input type="email" 
                                               class="form-control" 
                                               id="assembly_email" 
                                               name="assembly_email" 
                                               value="<?php echo htmlspecialchars($assemblySettings['assembly_email'] ?? ''); ?>"
                                               placeholder="e.g., info@assembly.gov.gh">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="assembly_website">Website</label>
                                <input type="url" 
                                       class="form-control" 
                                       id="assembly_website" 
                                       name="assembly_website" 
                                       value="<?php echo htmlspecialchars($assemblySettings['assembly_website'] ?? ''); ?>"
                                       placeholder="e.g., https://www.assembly.gov.gh">
                            </div>
                        </div>
                    </div>

                    <!-- Key Personnel -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title">
                                <i class="fas fa-users-cog"></i>
                                Key Personnel
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="chief_executive">Chief Executive</label>
                                        <input type="text" 
                                               class="form-control" 
                                               id="chief_executive" 
                                               name="chief_executive" 
                                               value="<?php echo htmlspecialchars($assemblySettings['chief_executive'] ?? ''); ?>"
                                               placeholder="Enter Chief Executive name">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="finance_officer">Finance Officer</label>
                                        <input type="text" 
                                               class="form-control" 
                                               id="finance_officer" 
                                               name="finance_officer" 
                                               value="<?php echo htmlspecialchars($assemblySettings['finance_officer'] ?? ''); ?>"
                                               placeholder="Enter Finance Officer name">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Bill and Receipt Customization -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title">
                                <i class="fas fa-file-invoice"></i>
                                Bill & Receipt Customization
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label for="bill_footer_text">Bill Footer Text</label>
                                <textarea class="form-control" 
                                          id="bill_footer_text" 
                                          name="bill_footer_text" 
                                          rows="3"
                                          placeholder="Text to appear at the bottom of bills"><?php echo htmlspecialchars($assemblySettings['bill_footer_text'] ?? ''); ?></textarea>
                                <small class="form-text text-muted">This text will appear at the bottom of all generated bills</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="receipt_footer_text">Receipt Footer Text</label>
                                <textarea class="form-control" 
                                          id="receipt_footer_text" 
                                          name="receipt_footer_text" 
                                          rows="3"
                                          placeholder="Text to appear at the bottom of receipts"><?php echo htmlspecialchars($assemblySettings['receipt_footer_text'] ?? ''); ?></textarea>
                                <small class="form-text text-muted">This text will appear at the bottom of all payment receipts</small>
                            </div>
                        </div>
                    </div>

                    <!-- Save Button -->
                    <div class="card">
                        <div class="card-body text-center">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="fas fa-save"></i>
                                Save Assembly Settings
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-lg ms-2" onclick="resetForm()">
                                <i class="fas fa-undo"></i>
                                Reset Changes
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Logo Upload and Preview -->
            <div class="col-xl-4">
                <!-- Current Logo -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">
                            <i class="fas fa-image"></i>
                            Assembly Logo
                        </h5>
                    </div>
                    <div class="card-body text-center">
                        <div class="current-logo">
                            <?php if (!empty($assemblySettings['assembly_logo'])): ?>
                                <img src="../../<?php echo htmlspecialchars($assemblySettings['assembly_logo']); ?>" 
                                     alt="Assembly Logo" 
                                     class="assembly-logo-preview"
                                     id="logoPreview">
                            <?php else: ?>
                                <div class="no-logo" id="logoPreview">
                                    <i class="fas fa-building fa-4x text-muted"></i>
                                    <p class="text-muted mt-2">No logo uploaded</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <form method="POST" enctype="multipart/form-data" id="logoUploadForm" class="mt-3">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            
                            <div class="form-group">
                                <label for="assembly_logo" class="btn btn-outline-primary">
                                    <i class="fas fa-upload"></i>
                                    Choose Logo
                                </label>
                                <input type="file" 
                                       id="assembly_logo" 
                                       name="assembly_logo" 
                                       accept="image/jpeg,image/png,image/gif"
                                       style="display: none;"
                                       onchange="previewLogo(this)">
                            </div>
                            
                            <small class="form-text text-muted">
                                Supported formats: JPG, PNG, GIF<br>
                                Maximum size: 2MB<br>
                                Recommended: 200x200 pixels
                            </small>
                            
                            <button type="submit" class="btn btn-success mt-2" id="uploadButton" style="display: none;">
                                <i class="fas fa-upload"></i>
                                Upload Logo
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Preview Card -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">
                            <i class="fas fa-eye"></i>
                            Bill Header Preview
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="bill-preview">
                            <div class="preview-header">
                                <div class="row align-items-center">
                                    <div class="col-3">
                                        <?php if (!empty($assemblySettings['assembly_logo'])): ?>
                                            <img src="../../<?php echo htmlspecialchars($assemblySettings['assembly_logo']); ?>" 
                                                 alt="Logo" 
                                                 class="preview-logo">
                                        <?php else: ?>
                                            <div class="preview-logo-placeholder">
                                                <i class="fas fa-building"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-9">
                                        <h6 class="preview-title" id="previewAssemblyName">
                                            <?php echo htmlspecialchars($assemblySettings['assembly_name'] ?? 'Assembly Name'); ?>
                                        </h6>
                                        <p class="preview-motto" id="previewMotto">
                                            <?php echo htmlspecialchars($assemblySettings['assembly_motto'] ?? 'Assembly Motto'); ?>
                                        </p>
                                        <small class="preview-contact" id="previewContact">
                                            <?php 
                                            $contact = [];
                                            if (!empty($assemblySettings['assembly_phone'])) $contact[] = $assemblySettings['assembly_phone'];
                                            if (!empty($assemblySettings['assembly_email'])) $contact[] = $assemblySettings['assembly_email'];
                                            echo htmlspecialchars(implode(' | ', $contact));
                                            ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Tips -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">
                            <i class="fas fa-lightbulb"></i>
                            Quick Tips
                        </h5>
                    </div>
                    <div class="card-body">
                        <ul class="tips-list">
                            <li><i class="fas fa-check text-success"></i> Use a high-quality logo for professional appearance</li>
                            <li><i class="fas fa-check text-success"></i> Keep assembly name concise for better display</li>
                            <li><i class="fas fa-check text-success"></i> Include official contact information</li>
                            <li><i class="fas fa-check text-success"></i> Review bill preview after making changes</li>
                            <li><i class="fas fa-check text-success"></i> Test print bills to ensure proper formatting</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.assembly-logo-preview {
    max-width: 150px;
    max-height: 150px;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    padding: 10px;
}

.no-logo {
    padding: 3rem 1rem;
    border: 2px dashed #e9ecef;
    border-radius: 8px;
}

.bill-preview {
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 1rem;
    background: #f8f9fa;
}

.preview-header {
    background: white;
    padding: 1rem;
    border-radius: 6px;
    border: 1px solid #dee2e6;
}

.preview-logo {
    width: 50px;
    height: 50px;
    object-fit: contain;
}

.preview-logo-placeholder {
    width: 50px;
    height: 50px;
    border: 1px dashed #ccc;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #6c757d;
    font-size: 1.5rem;
}

.preview-title {
    font-weight: bold;
    color: #495057;
    margin-bottom: 0.25rem;
}

.preview-motto {
    font-style: italic;
    color: #6c757d;
    margin-bottom: 0.25rem;
    font-size: 0.875rem;
}

.preview-contact {
    color: #6c757d;
    font-size: 0.75rem;
}

.tips-list {
    list-style: none;
    padding: 0;
}

.tips-list li {
    padding: 0.5rem 0;
    border-bottom: 1px solid #f8f9fa;
}

.tips-list li:last-child {
    border-bottom: none;
}

.tips-list i {
    margin-right: 0.5rem;
    width: 16px;
}

.page-header {
    margin-bottom: 2rem;
}

.card {
    margin-bottom: 1.5rem;
}
</style>

<script>
// Live preview updates
function updatePreview() {
    // Update assembly name
    const assemblyName = document.getElementById('assembly_name').value || 'Assembly Name';
    document.getElementById('previewAssemblyName').textContent = assemblyName;
    
    // Update motto
    const motto = document.getElementById('assembly_motto').value || 'Assembly Motto';
    document.getElementById('previewMotto').textContent = motto;
    
    // Update contact info
    const phone = document.getElementById('assembly_phone').value;
    const email = document.getElementById('assembly_email').value;
    const contact = [phone, email].filter(item => item).join(' | ');
    document.getElementById('previewContact').textContent = contact;
}

// Add event listeners for live preview
document.getElementById('assembly_name').addEventListener('input', updatePreview);
document.getElementById('assembly_motto').addEventListener('input', updatePreview);
document.getElementById('assembly_phone').addEventListener('input', updatePreview);
document.getElementById('assembly_email').addEventListener('input', updatePreview);

// Logo preview function
function previewLogo(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        
        reader.onload = function(e) {
            const preview = document.getElementById('logoPreview');
            preview.innerHTML = `<img src="${e.target.result}" alt="Logo Preview" class="assembly-logo-preview">`;
            
            // Show upload button
            document.getElementById('uploadButton').style.display = 'inline-block';
        };
        
        reader.readAsDataURL(input.files[0]);
    }
}

// Reset form function
function resetForm() {
    if (confirm('Are you sure you want to reset all changes?')) {
        document.getElementById('assemblySettingsForm').reset();
        updatePreview();
    }
}

// Form submission handler
document.getElementById('assemblySettingsForm').addEventListener('submit', function(e) {
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    submitBtn.disabled = true;
    
    // Re-enable after 3 seconds (fallback)
    setTimeout(() => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }, 3000);
});

// Phone number formatting
document.getElementById('assembly_phone').addEventListener('input', function(e) {
    let value = e.target.value.replace(/\D/g, '');
    
    if (value.startsWith('233')) {
        value = '+' + value;
    } else if (value.startsWith('0')) {
        value = '+233' + value.substring(1);
    }
    
    e.target.value = value;
});

// Initial preview update
updatePreview();
</script>

<?php include '../footer.php'; ?>
