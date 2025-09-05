<?php
/**
 * Zone Management - Edit Sub-Zone
 * QUICKBILL 305 - Admin Panel
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

// Check authentication and permissions
requireLogin();
if (!hasPermission('zones.edit')) {
    setFlashMessage('error', 'Access denied. You do not have permission to edit sub-zones.');
    header('Location: index.php');
    exit();
}

$pageTitle = 'Edit Sub-Zone';
$currentUser = getCurrentUser();
$userDisplayName = getUserDisplayName($currentUser);

// Get sub-zone ID and zone ID from URL
$subZoneId = intval($_GET['id'] ?? 0);
$zoneId = intval($_GET['zone_id'] ?? 0);

if (!$subZoneId || !$zoneId) {
    setFlashMessage('error', 'Invalid sub-zone or zone ID.');
    header('Location: index.php');
    exit();
}

// Initialize variables
$errors = [];
$subZone = null;
$zone = null;
$formData = [
    'sub_zone_name' => '',
    'sub_zone_code' => '',
    'description' => ''
];

try {
    $db = new Database();
    
    // Get sub-zone information
    $subZone = $db->fetchRow("
        SELECT * FROM sub_zones 
        WHERE sub_zone_id = ? AND zone_id = ?
    ", [$subZoneId, $zoneId]);
    
    if (!$subZone) {
        setFlashMessage('error', 'Sub-zone not found.');
        header('Location: sub_zones.php?zone_id=' . $zoneId);
        exit();
    }
    
    // Get parent zone information
    $zone = $db->fetchRow("SELECT * FROM zones WHERE zone_id = ?", [$zoneId]);
    
    if (!$zone) {
        setFlashMessage('error', 'Parent zone not found.');
        header('Location: index.php');
        exit();
    }
    
    // Populate form data
    $formData = [
        'sub_zone_name' => $subZone['sub_zone_name'],
        'sub_zone_code' => $subZone['sub_zone_code'],
        'description' => $subZone['description']
    ];
    
    // Get existing sub-zones for reference (excluding current one)
    $existingSubZones = $db->fetchAll("
        SELECT sub_zone_name, sub_zone_code 
        FROM sub_zones 
        WHERE zone_id = ? AND sub_zone_id != ? 
        ORDER BY sub_zone_name
    ", [$zoneId, $subZoneId]);
    
    // Get business count for this sub-zone
    $businessCountResult = $db->fetchRow("
        SELECT COUNT(*) as count FROM businesses WHERE sub_zone_id = ?
    ", [$subZoneId]);
    $businessCount = $businessCountResult['count'] ?? 0;
    
    // Process form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Validate and sanitize input
        $formData['sub_zone_name'] = sanitizeInput($_POST['sub_zone_name'] ?? '');
        $formData['sub_zone_code'] = sanitizeInput($_POST['sub_zone_code'] ?? '');
        $formData['description'] = sanitizeInput($_POST['description'] ?? '');
        
        // Validation
        if (empty($formData['sub_zone_name'])) {
            $errors[] = 'Sub-zone name is required.';
        } elseif (strlen($formData['sub_zone_name']) < 2) {
            $errors[] = 'Sub-zone name must be at least 2 characters long.';
        } elseif (strlen($formData['sub_zone_name']) > 100) {
            $errors[] = 'Sub-zone name cannot exceed 100 characters.';
        }
        
        if (!empty($formData['sub_zone_code'])) {
            if (strlen($formData['sub_zone_code']) > 20) {
                $errors[] = 'Sub-zone code cannot exceed 20 characters.';
            }
            if (!preg_match('/^[A-Z0-9]+$/', $formData['sub_zone_code'])) {
                $errors[] = 'Sub-zone code should only contain uppercase letters and numbers.';
            }
        }
        
        if (!empty($formData['description']) && strlen($formData['description']) > 500) {
            $errors[] = 'Description cannot exceed 500 characters.';
        }
        
        // Check if sub-zone name already exists in this zone (excluding current)
        if (empty($errors)) {
            $existingSubZone = $db->fetchRow("
                SELECT sub_zone_id FROM sub_zones 
                WHERE zone_id = ? AND sub_zone_name = ? AND sub_zone_id != ?
            ", [$zoneId, $formData['sub_zone_name'], $subZoneId]);
            
            if ($existingSubZone) {
                $errors[] = 'A sub-zone with this name already exists in this zone.';
            }
        }
        
        // Check if sub-zone code already exists (excluding current, if provided)
        if (empty($errors) && !empty($formData['sub_zone_code'])) {
            $existingCode = $db->fetchRow("
                SELECT sub_zone_id FROM sub_zones 
                WHERE sub_zone_code = ? AND sub_zone_id != ?
            ", [$formData['sub_zone_code'], $subZoneId]);
            
            if ($existingCode) {
                $errors[] = 'A sub-zone with this code already exists.';
            }
        }
        
        // If no errors, update the sub-zone
        if (empty($errors)) {
            try {
                $db->beginTransaction();
                
                // Generate sub-zone code if not provided
                if (empty($formData['sub_zone_code'])) {
                    $subZoneName = strtoupper($formData['sub_zone_name']);
                    $zonePart = $zone['zone_code'] ? strtoupper(substr($zone['zone_code'], 0, 2)) : strtoupper(substr($zone['zone_name'], 0, 2));
                    
                    $words = explode(' ', $subZoneName);
                    if (count($words) >= 2) {
                        // Use first letter of first two words
                        $subPart = substr($words[0], 0, 1) . substr($words[1], 0, 1);
                    } else {
                        // Use first two letters of sub-zone name
                        $subPart = substr($subZoneName, 0, 2);
                    }
                    
                    $generatedCode = $zonePart . $subPart;
                    
                    // Add number suffix if code exists (excluding current)
                    $counter = 1;
                    $baseCode = $generatedCode;
                    while (true) {
                        $existingCode = $db->fetchRow("
                            SELECT sub_zone_id FROM sub_zones 
                            WHERE sub_zone_code = ? AND sub_zone_id != ?
                        ", [$generatedCode, $subZoneId]);
                        
                        if (!$existingCode) {
                            break;
                        }
                        
                        $counter++;
                        $generatedCode = $baseCode . str_pad($counter, 2, '0', STR_PAD_LEFT);
                        
                        if ($counter > 99) {
                            $generatedCode = $baseCode . time(); // Fallback
                            break;
                        }
                    }
                    
                    $formData['sub_zone_code'] = $generatedCode;
                }
                
                // Update sub-zone
                $query = "UPDATE sub_zones 
                          SET sub_zone_name = ?, sub_zone_code = ?, description = ?, updated_at = NOW()
                          WHERE sub_zone_id = ?";
                
                $params = [
                    $formData['sub_zone_name'],
                    $formData['sub_zone_code'],
                    !empty($formData['description']) ? $formData['description'] : null,
                    $subZoneId
                ];
                
                // Execute update
                $db->execute($query, $params);
                
                // Log the action
                writeLog("Sub-zone updated: {$formData['sub_zone_name']} (ID: $subZoneId) in zone {$zone['zone_name']} by user {$currentUser['username']}", 'INFO');
                
                $db->commit();
                
                setFlashMessage('success', 'Sub-zone updated successfully!');
                header('Location: sub_zones.php?zone_id=' . $zoneId);
                exit();
                
            } catch (Exception $e) {
                $db->rollback();
                writeLog("Error updating sub-zone: " . $e->getMessage(), 'ERROR');
                $errors[] = 'An error occurred while updating the sub-zone. Please try again.';
            }
        }
    }
    
} catch (Exception $e) {
    writeLog("Sub-zone edit page error: " . $e->getMessage(), 'ERROR');
    $errors[] = 'An error occurred while loading the page. Please try again.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - <?php echo APP_NAME; ?></title>
    
    <!-- Multiple Icon Sources -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://use.fontawesome.com/releases/v6.4.0/css/all.css">
    
    <!-- Bootstrap for backup -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
            overflow-x: hidden;
        }
        
        /* Custom Icons (fallback if Font Awesome fails) */
        .icon-dashboard::before { content: "📊"; }
        .icon-users::before { content: "👥"; }
        .icon-building::before { content: "🏢"; }
        .icon-home::before { content: "🏠"; }
        .icon-map::before { content: "🗺️"; }
        .icon-invoice::before { content: "📄"; }
        .icon-credit::before { content: "💳"; }
        .icon-tags::before { content: "🏷️"; }
        .icon-chart::before { content: "📈"; }
        .icon-bell::before { content: "🔔"; }
        .icon-cog::before { content: "⚙️"; }
        .icon-receipt::before { content: "🧾"; }
        .icon-menu::before { content: "☰"; }
        .icon-logout::before { content: "🚪"; }
        .icon-user::before { content: "👤"; }
        .icon-plus::before { content: "➕"; }
        .icon-save::before { content: "💾"; }
        .icon-layer::before { content: "🏷️"; }
        .icon-info::before { content: "ℹ️"; }
        .icon-code::before { content: "🔢"; }
        .icon-text::before { content: "📝"; }
        .icon-edit::before { content: "✏️"; }
        
        /* Top Navigation - Same as previous pages */
        .top-nav {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
        
        /* User Dropdown - Same as previous pages */
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
            color: #667eea;
            transform: translateX(5px);
        }
        
        .dropdown-item:last-child {
            border-bottom: none;
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
        .container {
            margin-top: 80px;
            display: flex;
            min-height: calc(100vh - 80px);
        }
        
        /* Sidebar - Same as previous pages */
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
            border-left-color: #667eea;
        }
        
        .nav-link.active {
            background: rgba(102, 126, 234, 0.3);
            color: white;
            border-left-color: #667eea;
        }
        
        .nav-icon {
            display: inline-block;
            width: 20px;
            margin-right: 12px;
            text-align: center;
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            padding: 30px;
            background: #f8f9fa;
            transition: all 0.3s ease;
        }
        
        /* Breadcrumb */
        .breadcrumb-nav {
            background: white;
            border-radius: 15px;
            padding: 20px 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
            color: #64748b;
        }
        
        .breadcrumb a {
            color: #667eea;
            text-decoration: none;
        }
        
        .breadcrumb-current {
            color: #2d3748;
            font-weight: 600;
        }
        
        /* Zone Info Header */
        .zone-info-header {
            background: linear-gradient(135deg, #f0f4ff 0%, #e0e7ff 100%);
            border: 1px solid #c7d2fe;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
        }
        
        .zone-info-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .zone-info-content {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .zone-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 24px;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }
        
        .zone-details h3 {
            font-size: 20px;
            font-weight: bold;
            color: #2d3748;
            margin: 0 0 5px 0;
        }
        
        .zone-code {
            font-size: 14px;
            color: #667eea;
            font-weight: 600;
            font-family: monospace;
            background: rgba(102, 126, 234, 0.1);
            padding: 4px 8px;
            border-radius: 6px;
            display: inline-block;
            margin-bottom: 5px;
        }
        
        .zone-description {
            color: #64748b;
            font-size: 14px;
        }
        
        /* Page Header */
        .page-header {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .page-title {
            font-size: 28px;
            font-weight: bold;
            color: #2d3748;
            margin: 0;
        }
        
        .back-btn {
            background: #64748b;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }
        
        .back-btn:hover {
            background: #475569;
            transform: translateY(-2px);
            color: white;
        }
        
        /* Business Usage Warning */
        .usage-warning {
            background: linear-gradient(135deg, #fef3cd 0%, #fde68a 100%);
            border: 1px solid #f59e0b;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .warning-icon {
            width: 50px;
            height: 50px;
            background: #f59e0b;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            flex-shrink: 0;
        }
        
        .warning-content {
            flex: 1;
        }
        
        .warning-title {
            font-size: 16px;
            font-weight: bold;
            color: #92400e;
            margin-bottom: 5px;
        }
        
        .warning-text {
            color: #a16207;
            font-size: 14px;
            line-height: 1.5;
        }
        
        /* Form Styles */
        .form-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .form-section {
            margin-bottom: 40px;
        }
        
        .form-section:last-child {
            margin-bottom: 0;
        }
        
        .section-title {
            font-size: 20px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-icon {
            width: 35px;
            height: 35px;
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-row.single {
            grid-template-columns: 1fr;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-label {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 8px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .required {
            color: #e53e3e;
        }
        
        .form-control {
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
            background: white;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #4299e1;
            box-shadow: 0 0 0 3px rgba(66, 153, 225, 0.1);
        }
        
        .form-control:invalid {
            border-color: #e53e3e;
        }
        
        .form-control.error {
            border-color: #e53e3e;
            background: #fef2f2;
        }
        
        .form-help {
            font-size: 12px;
            color: #64748b;
            margin-top: 5px;
        }
        
        .textarea-control {
            min-height: 120px;
            resize: vertical;
            font-family: inherit;
        }
        
        .code-preview {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
            padding: 15px 20px;
            border-radius: 10px;
            text-align: center;
            margin-top: 10px;
            font-family: monospace;
            font-size: 16px;
            font-weight: bold;
            letter-spacing: 2px;
            display: none;
            animation: slideDown 0.3s ease;
        }
        
        .code-preview.show {
            display: block;
        }
        
        /* Character Counter */
        .char-counter {
            text-align: right;
            font-size: 12px;
            color: #64748b;
            margin-top: 5px;
        }
        
        .char-counter.warning {
            color: #ed8936;
        }
        
        .char-counter.danger {
            color: #e53e3e;
        }
        
        /* Existing Sub-Zones Reference */
        .reference-card {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .reference-title {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .reference-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
        }
        
        .reference-item {
            background: white;
            padding: 12px 15px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .reference-name {
            font-weight: 500;
            color: #2d3748;
            font-size: 14px;
        }
        
        .reference-code {
            font-size: 12px;
            font-family: monospace;
            background: #f1f5f9;
            color: #64748b;
            padding: 2px 6px;
            border-radius: 4px;
        }
        
        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background: #d1fae5;
            border: 1px solid #9ae6b4;
            color: #065f46;
        }
        
        .alert-error {
            background: #fee2e2;
            border: 1px solid #fca5a5;
            color: #991b1b;
        }
        
        /* Buttons */
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            justify-content: center;
            font-size: 14px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(66, 153, 225, 0.3);
            color: white;
        }
        
        .btn-secondary {
            background: #64748b;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #475569;
            color: white;
        }
        
        .btn-success {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(72, 187, 120, 0.3);
            color: white;
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            padding-top: 20px;
            border-top: 2px solid #e2e8f0;
            margin-top: 30px;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                height: 100%;
                z-index: 999;
                transform: translateX(-100%);
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
            
            .form-row {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .header-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .zone-info-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .reference-list {
                grid-template-columns: 1fr;
            }
            
            .usage-warning {
                flex-direction: column;
                text-align: center;
            }
        }
        
        /* Animations */
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <div class="top-nav">
        <div class="nav-left">
            <button class="toggle-btn" onclick="toggleSidebar()" id="toggleBtn">
                <i class="fas fa-bars"></i>
                <span class="icon-menu" style="display: none;"></span>
            </button>
            
            <a href="../index.php" class="brand">
                <i class="fas fa-receipt"></i>
                <span class="icon-receipt" style="display: none;"></span>
                <?php echo APP_NAME; ?>
            </a>
        </div>
        
        <div class="user-section">
            <!-- Notification Bell -->
            <div style="position: relative; margin-right: 10px;">
                <button style="
                    background: rgba(255,255,255,0.2);
                    border: none;
                    color: white;
                    font-size: 18px;
                    padding: 10px;
                    border-radius: 50%;
                    cursor: pointer;
                    transition: all 0.3s;
                " onmouseover="this.style.background='rgba(255,255,255,0.3)'" 
                   onmouseout="this.style.background='rgba(255,255,255,0.2)'"
                   onclick="showComingSoon('Notifications')">
                    <i class="fas fa-bell"></i>
                    <span class="icon-bell" style="display: none;"></span>
                </button>
                <span class="notification-badge" style="
                    position: absolute;
                    top: -2px;
                    right: -2px;
                    background: #ef4444;
                    color: white;
                    border-radius: 50%;
                    width: 20px;
                    height: 20px;
                    font-size: 11px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-weight: bold;
                    animation: pulse 2s infinite;
                ">3</span>
            </div>
            
            <div class="user-profile" onclick="toggleUserDropdown()" id="userProfile">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($currentUser['first_name'], 0, 1)); ?>
                </div>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($userDisplayName); ?></div>
                    <div class="user-role"><?php echo htmlspecialchars(getCurrentUserRole()); ?></div>
                </div>
                <i class="fas fa-chevron-down dropdown-arrow"></i>
                
                <!-- User Dropdown -->
                <div class="user-dropdown" id="userDropdown">
                    <div class="dropdown-header">
                        <div class="dropdown-avatar">
                            <?php echo strtoupper(substr($currentUser['first_name'], 0, 1)); ?>
                        </div>
                        <div class="dropdown-name"><?php echo htmlspecialchars($userDisplayName); ?></div>
                        <div class="dropdown-role"><?php echo htmlspecialchars(getCurrentUserRole()); ?></div>
                    </div>
                    <div class="dropdown-menu">
                        <a href="#" class="dropdown-item" onclick="showComingSoon('User Profile')">
                            <i class="fas fa-user"></i>
                            <span class="icon-user" style="display: none;"></span>
                            My Profile
                        </a>
                        <a href="#" class="dropdown-item" onclick="showComingSoon('Account Settings')">
                            <i class="fas fa-cog"></i>
                            <span class="icon-cog" style="display: none;"></span>
                            Account Settings
                        </a>
                        <a href="#" class="dropdown-item" onclick="showComingSoon('Activity Log')">
                            <i class="fas fa-history"></i>
                            <span class="icon-chart" style="display: none;"></span>
                            Activity Log
                        </a>
                        <a href="#" class="dropdown-item" onclick="showComingSoon('Help & Support')">
                            <i class="fas fa-question-circle"></i>
                            <span class="icon-bell" style="display: none;"></span>
                            Help & Support
                        </a>
                        <div style="height: 1px; background: #e2e8f0; margin: 10px 0;"></div>
                        <a href="../../auth/logout.php" class="dropdown-item logout">
                            <i class="fas fa-sign-out-alt"></i>
                            <span class="icon-logout" style="display: none;"></span>
                            Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-content">
                <!-- Dashboard -->
                <div class="nav-section">
                    <div class="nav-item">
                        <a href="../index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-tachometer-alt"></i>
                                <span class="icon-dashboard" style="display: none;"></span>
                            </span>
                            Dashboard
                        </a>
                    </div>
                </div>
                
                <!-- Core Management -->
                <div class="nav-section">
                    <div class="nav-title">Core Management</div>
                    <div class="nav-item">
                        <a href="../users/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-users"></i>
                                <span class="icon-users" style="display: none;"></span>
                            </span>
                            Users
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../businesses/index.php" class="nav-link">
                            <span class="nav-icon">
                                <i class="fas fa-building"></i>
                                <span class="icon-building" style="display: none;"></span>
                            </span>
                            Businesses
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="#" class="nav-link" onclick="showComingSoon('Properties')">
                            <span class="nav-icon">
                                <i class="fas fa-home"></i>
                                <span class="icon-home" style="display: none;"></span>
                            </span>
                            Properties
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="index.php" class="nav-link active">
                            <span class="nav-icon">
                                <i class="fas fa-map-marked-alt"></i>
                                <span class="icon-map" style="display: none;"></span>
                            </span>
                            Zones & Areas
                        </a>
                    </div>
                </div>
                
                <!-- Billing & Payments -->
                <div class="nav-section">
                    <div class="nav-title">Billing & Payments</div>
                    <div class="nav-item">
                        <a href="#" class="nav-link" onclick="showComingSoon('Billing')">
                            <span class="nav-icon">
                                <i class="fas fa-file-invoice"></i>
                                <span class="icon-invoice" style="display: none;"></span>
                            </span>
                            Billing
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="#" class="nav-link" onclick="showComingSoon('Payments')">
                            <span class="nav-icon">
                                <i class="fas fa-credit-card"></i>
                                <span class="icon-credit" style="display: none;"></span>
                            </span>
                            Payments
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="#" class="nav-link" onclick="showComingSoon('Fee Structure')">
                            <span class="nav-icon">
                                <i class="fas fa-tags"></i>
                                <span class="icon-tags" style="display: none;"></span>
                            </span>
                            Fee Structure
                        </a>
                    </div>
                </div>
                
                <!-- Reports & System -->
                <div class="nav-section">
                    <div class="nav-title">Reports & System</div>
                    <div class="nav-item">
                        <a href="#" class="nav-link" onclick="showComingSoon('Reports')">
                            <span class="nav-icon">
                                <i class="fas fa-chart-bar"></i>
                                <span class="icon-chart" style="display: none;"></span>
                            </span>
                            Reports
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="#" class="nav-link" onclick="showComingSoon('Notifications')">
                            <span class="nav-icon">
                                <i class="fas fa-bell"></i>
                                <span class="icon-bell" style="display: none;"></span>
                            </span>
                            Notifications
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="#" class="nav-link" onclick="showComingSoon('Settings')">
                            <span class="nav-icon">
                                <i class="fas fa-cog"></i>
                                <span class="icon-cog" style="display: none;"></span>
                            </span>
                            Settings
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Breadcrumb -->
            <div class="breadcrumb-nav">
                <div class="breadcrumb">
                    <a href="../index.php">Dashboard</a>
                    <span>/</span>
                    <a href="index.php">Zone Management</a>
                    <span>/</span>
                    <a href="view.php?id=<?php echo $zoneId; ?>"><?php echo htmlspecialchars($zone['zone_name']); ?></a>
                    <span>/</span>
                    <a href="sub_zones.php?zone_id=<?php echo $zoneId; ?>">Sub-Zones</a>
                    <span>/</span>
                    <span class="breadcrumb-current">Edit Sub-Zone</span>
                </div>
            </div>

            <!-- Flash Messages -->
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <strong>Please fix the following errors:</strong>
                        <ul style="margin: 10px 0 0 0; padding-left: 20px;">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Zone Information Header -->
            <div class="zone-info-header">
                <div class="zone-info-content">
                    <div class="zone-avatar">
                        <i class="fas fa-map-marked-alt"></i>
                        <span class="icon-map" style="display: none;"></span>
                    </div>
                    <div class="zone-details">
                        <h3>Parent Zone: <?php echo htmlspecialchars($zone['zone_name']); ?></h3>
                        <?php if ($zone['zone_code']): ?>
                            <div class="zone-code"><?php echo htmlspecialchars($zone['zone_code']); ?></div>
                        <?php endif; ?>
                        <?php if ($zone['description']): ?>
                            <div class="zone-description"><?php echo htmlspecialchars($zone['description']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <div class="header-content">
                    <div>
                        <h1 class="page-title">
                            <i class="fas fa-edit"></i>
                            <span class="icon-edit" style="display: none;"></span>
                            Edit Sub-Zone
                        </h1>
                        <p style="color: #64748b; margin: 5px 0 0 0;">Update sub-zone information for "<?php echo htmlspecialchars($subZone['sub_zone_name']); ?>"</p>
                    </div>
                    <a href="sub_zones.php?zone_id=<?php echo $zoneId; ?>" class="back-btn">
                        <i class="fas fa-arrow-left"></i>
                        Back to Sub-Zones
                    </a>
                </div>
            </div>

            <!-- Business Usage Warning -->
            <?php if ($businessCount > 0): ?>
                <div class="usage-warning">
                    <div class="warning-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="warning-content">
                        <div class="warning-title">Sub-Zone In Use</div>
                        <div class="warning-text">
                            This sub-zone is currently assigned to <?php echo $businessCount; ?> business<?php echo $businessCount !== 1 ? 'es' : ''; ?>. 
                            Changes to the sub-zone name or code will be reflected in all assigned businesses. Please proceed with caution.
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Sub-Zone Edit Form -->
            <form method="POST" action="" id="subZoneForm">
                <!-- Basic Sub-Zone Information -->
                <div class="form-card">
                    <div class="form-section">
                        <h3 class="section-title">
                            <div class="section-icon">
                                <i class="fas fa-layer-group"></i>
                                <span class="icon-layer" style="display: none;"></span>
                            </div>
                            Sub-Zone Information
                        </h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-layer-group"></i>
                                    <span class="icon-layer" style="display: none;"></span>
                                    Sub-Zone Name <span class="required">*</span>
                                </label>
                                <input type="text" name="sub_zone_name" id="subZoneName" class="form-control" 
                                       value="<?php echo htmlspecialchars($formData['sub_zone_name']); ?>" 
                                       placeholder="Enter sub-zone name (e.g., Market Area, Government Section)" 
                                       maxlength="100" required>
                                <div class="form-help">Specific name for this sub-area within the zone</div>
                                <div class="char-counter" id="nameCounter">0 / 100</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-code"></i>
                                    <span class="icon-code" style="display: none;"></span>
                                    Sub-Zone Code
                                </label>
                                <input type="text" name="sub_zone_code" id="subZoneCode" class="form-control" 
                                       value="<?php echo htmlspecialchars($formData['sub_zone_code']); ?>" 
                                       placeholder="Enter sub-zone code (e.g., MA01, GS02)" 
                                       maxlength="20" 
                                       style="text-transform: uppercase;">
                                <div class="form-help">Unique code for quick identification (auto-generated if empty)</div>
                                <div class="char-counter" id="codeCounter">0 / 20</div>
                                <div class="code-preview" id="codePreview"></div>
                            </div>
                        </div>
                        
                        <div class="form-row single">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-align-left"></i>
                                    <span class="icon-text" style="display: none;"></span>
                                    Description
                                </label>
                                <textarea name="description" id="subZoneDescription" class="form-control textarea-control" 
                                          placeholder="Enter a detailed description of the sub-zone, its boundaries, landmarks, or other relevant information..."
                                          maxlength="500"><?php echo htmlspecialchars($formData['description']); ?></textarea>
                                <div class="form-help">Optional description to help identify and understand the sub-zone</div>
                                <div class="char-counter" id="descCounter">0 / 500</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Other Sub-Zones Reference -->
                <?php if (!empty($existingSubZones)): ?>
                    <div class="form-card">
                        <div class="reference-card">
                            <div class="reference-title">
                                <i class="fas fa-list"></i>
                                Other Sub-Zones in this Zone
                            </div>
                            <div class="reference-list">
                                <?php foreach ($existingSubZones as $existing): ?>
                                    <div class="reference-item">
                                        <span class="reference-name"><?php echo htmlspecialchars($existing['sub_zone_name']); ?></span>
                                        <?php if ($existing['sub_zone_code']): ?>
                                            <span class="reference-code"><?php echo htmlspecialchars($existing['sub_zone_code']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Form Actions -->
                <div class="form-card">
                    <div class="form-actions">
                        <a href="sub_zones.php?zone_id=<?php echo $zoneId; ?>" class="btn btn-secondary">
                            <i class="fas fa-times"></i>
                            Cancel
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i>
                            <span class="icon-save" style="display: none;"></span>
                            Update Sub-Zone
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
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
            
            // Initialize form
            initializeForm();
        });

        // Initialize form functionality
        function initializeForm() {
            // Set up character counters
            setupCharacterCounter('subZoneName', 'nameCounter', 100);
            setupCharacterCounter('subZoneCode', 'codeCounter', 20);
            setupCharacterCounter('subZoneDescription', 'descCounter', 500);
            
            // Set up sub-zone code auto-generation and formatting
            setupSubZoneCodeHandling();
            
            // Set up form validation
            setupFormValidation();
        }

        // Character counter functionality
        function setupCharacterCounter(inputId, counterId, maxLength) {
            const input = document.getElementById(inputId);
            const counter = document.getElementById(counterId);
            
            if (!input || !counter) return;
            
            function updateCounter() {
                const length = input.value.length;
                counter.textContent = `${length} / ${maxLength}`;
                
                // Update counter styling based on length
                counter.className = 'char-counter';
                if (length > maxLength * 0.8) {
                    counter.classList.add('warning');
                }
                if (length > maxLength * 0.95) {
                    counter.classList.remove('warning');
                    counter.classList.add('danger');
                }
            }
            
            input.addEventListener('input', updateCounter);
            updateCounter(); // Initial count
        }

        // Sub-zone code handling
        function setupSubZoneCodeHandling() {
            const subZoneCodeInput = document.getElementById('subZoneCode');
            const subZoneNameInput = document.getElementById('subZoneName');
            const codePreview = document.getElementById('codePreview');
            
            // Format sub-zone code to uppercase
            subZoneCodeInput.addEventListener('input', function() {
                this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
                updateCodePreview();
            });
            
            // Auto-generate code suggestion when sub-zone name changes (only if code is empty)
            subZoneNameInput.addEventListener('input', function() {
                if (!subZoneCodeInput.value) {
                    generateCodeSuggestion();
                }
                updateCodePreview();
            });
            
            function generateCodeSuggestion() {
                const subZoneName = subZoneNameInput.value.trim().toUpperCase();
                if (!subZoneName) return;
                
                // Get zone prefix from parent zone
                const zoneCode = '<?php echo $zone['zone_code'] ? strtoupper(substr($zone['zone_code'], 0, 2)) : strtoupper(substr($zone['zone_name'], 0, 2)); ?>';
                
                let suggestion = zoneCode;
                const words = subZoneName.split(/\s+/);
                
                if (words.length >= 2) {
                    // Use first letter of first two words
                    suggestion += words[0].charAt(0) + words[1].charAt(0);
                } else {
                    // Use first two letters of sub-zone name
                    suggestion += subZoneName.substring(0, 2);
                }
                
                // Add number suffix
                suggestion += '01';
                
                // Show as placeholder
                subZoneCodeInput.placeholder = suggestion;
            }
            
            function updateCodePreview() {
                const code = subZoneCodeInput.value || subZoneCodeInput.placeholder;
                if (code) {
                    codePreview.textContent = `Sub-Zone Code: ${code}`;
                    codePreview.classList.add('show');
                } else {
                    codePreview.classList.remove('show');
                }
            }
            
            // Initial update
            updateCodePreview();
        }

        // Form validation
        function setupFormValidation() {
            const form = document.getElementById('subZoneForm');
            
            form.addEventListener('submit', function(e) {
                const subZoneName = document.getElementById('subZoneName').value.trim();
                const subZoneCode = document.getElementById('subZoneCode').value.trim();
                
                let isValid = true;
                const errors = [];
                
                // Validate sub-zone name
                if (!subZoneName) {
                    errors.push('Sub-zone name is required.');
                    isValid = false;
                } else if (subZoneName.length < 2) {
                    errors.push('Sub-zone name must be at least 2 characters long.');
                    isValid = false;
                }
                
                // Validate sub-zone code format
                if (subZoneCode && !/^[A-Z0-9]+$/.test(subZoneCode)) {
                    errors.push('Sub-zone code should only contain uppercase letters and numbers.');
                    isValid = false;
                }
                
                if (!isValid) {
                    e.preventDefault();
                    showValidationErrors(errors);
                    return false;
                }
            });
        }

        // Show validation errors
        function showValidationErrors(errors) {
            const errorHtml = errors.map(error => `<li>${error}</li>`).join('');
            const alertHtml = `
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <strong>Please fix the following errors:</strong>
                        <ul style="margin: 10px 0 0 0; padding-left: 20px;">
                            ${errorHtml}
                        </ul>
                    </div>
                </div>
            `;
            
            // Remove existing alerts
            const existingAlerts = document.querySelectorAll('.alert');
            existingAlerts.forEach(alert => alert.remove());
            
            // Insert new alert at the top of main content
            const mainContent = document.querySelector('.main-content');
            const breadcrumb = document.querySelector('.breadcrumb-nav');
            breadcrumb.insertAdjacentHTML('afterend', alertHtml);
            
            // Scroll to top to show errors
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // Sidebar toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('hidden');
            
            const isHidden = sidebar.classList.contains('hidden');
            localStorage.setItem('sidebarHidden', isHidden);
        }

        // Restore sidebar state
        document.addEventListener('DOMContentLoaded', function() {
            const sidebarHidden = localStorage.getItem('sidebarHidden');
            if (sidebarHidden === 'true') {
                document.getElementById('sidebar').classList.add('hidden');
            }
        });

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

        // Coming soon modal
        function showComingSoon(feature) {
            const backdrop = document.createElement('div');
            backdrop.style.cssText = `
                position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(0,0,0,0.6); backdrop-filter: blur(8px); z-index: 10000;
                display: flex; align-items: center; justify-content: center;
                animation: fadeIn 0.3s ease; cursor: pointer;
            `;
            
            const modal = document.createElement('div');
            modal.style.cssText = `
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white; padding: 50px 40px; border-radius: 25px; text-align: center;
                box-shadow: 0 25px 80px rgba(0,0,0,0.4); max-width: 450px; width: 90%;
                animation: modalSlideIn 0.4s ease; cursor: default; position: relative; overflow: hidden;
            `;
            
            modal.innerHTML = `
                <div style="position: absolute; top: -50%; right: -50%; width: 200%; height: 200%;
                    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
                    animation: rotate 20s linear infinite; pointer-events: none;"></div>
                
                <div style="position: relative; z-index: 2;">
                    <div style="width: 80px; height: 80px; background: rgba(255,255,255,0.2);
                        border-radius: 50%; display: flex; align-items: center; justify-content: center;
                        margin: 0 auto 25px; animation: bounce 2s ease-in-out infinite;">
                        <i class="fas fa-rocket" style="font-size: 2.5rem; color: white;"></i>
                        <span style="font-size: 2.5rem; display: none;">🚀</span>
                    </div>
                    
                    <h3 style="margin: 0 0 15px 0; font-weight: 700; font-size: 1.8rem;
                        text-shadow: 0 2px 4px rgba(0,0,0,0.3);">${feature}</h3>
                    
                    <p style="margin: 0 0 30px 0; opacity: 0.9; font-size: 1.1rem; line-height: 1.6;">
                        This amazing feature is coming soon! 🎉<br>We're working hard to bring you the best experience.</p>
                    
                    <button onclick="closeModal()" style="background: rgba(255,255,255,0.2);
                        border: 2px solid rgba(255,255,255,0.3); color: white; padding: 12px 30px;
                        border-radius: 25px; cursor: pointer; font-weight: 600; font-size: 1rem;
                        transition: all 0.3s ease; backdrop-filter: blur(10px);">
                        Awesome! Let's Go 🚀
                    </button>
                    
                    <div style="margin-top: 20px; font-size: 0.9rem; opacity: 0.7;">
                        Click anywhere outside to close</div>
                </div>
            `;
            
            backdrop.appendChild(modal);
            document.body.appendChild(backdrop);
            
            if (!document.getElementById('modalAnimations')) {
                const style = document.createElement('style');
                style.id = 'modalAnimations';
                style.textContent = `
                    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
                    @keyframes modalSlideIn { from { transform: translateY(-30px) scale(0.9); opacity: 0; } 
                        to { transform: translateY(0) scale(1); opacity: 1; } }
                    @keyframes bounce { 0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
                        40% { transform: translateY(-10px); } 60% { transform: translateY(-5px); } }
                    @keyframes rotate { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
                    @keyframes fadeOut { from { opacity: 1; } to { opacity: 0; } }
                    @keyframes modalSlideOut { from { transform: translateY(0) scale(1); opacity: 1; }
                        to { transform: translateY(-30px) scale(0.9); opacity: 0; } }
                `;
                document.head.appendChild(style);
            }
            
            window.closeModal = function() {
                backdrop.style.animation = 'fadeOut 0.3s ease forwards';
                modal.style.animation = 'modalSlideOut 0.3s ease forwards';
                setTimeout(() => backdrop.remove(), 300);
            };
            
            backdrop.addEventListener('click', function(e) {
                if (e.target === backdrop) closeModal();
            });
        }

        // Mobile responsiveness
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth <= 768) {
                sidebar.classList.remove('hidden');
            }
        });
    </script>
</body>
</html>