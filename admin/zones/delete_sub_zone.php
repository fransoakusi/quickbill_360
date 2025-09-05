<?php
/**
 * Sub-Zone Management - Delete Sub-Zone
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
if (!hasPermission('zones.delete')) {
    setFlashMessage('error', 'Access denied. You do not have permission to delete sub-zones.');
    header('Location: index.php');
    exit();
}

// Validate sub-zone ID parameter
$subZoneId = intval($_GET['id'] ?? 0);
$zoneId = intval($_GET['zone_id'] ?? 0); // For redirect back to sub-zones list

if ($subZoneId <= 0) {
    setFlashMessage('error', 'Invalid sub-zone ID provided.');
    $redirectUrl = $zoneId > 0 ? "sub_zones.php?zone_id={$zoneId}" : 'index.php';
    header("Location: {$redirectUrl}");
    exit();
}

try {
    $db = new Database();
    
    // Get sub-zone details first
    $subZone = $db->fetchRow("
        SELECT sz.*, z.zone_name 
        FROM sub_zones sz 
        LEFT JOIN zones z ON sz.zone_id = z.zone_id 
        WHERE sz.sub_zone_id = ?
    ", [$subZoneId]);
    
    if (!$subZone) {
        setFlashMessage('error', 'Sub-zone not found.');
        $redirectUrl = $zoneId > 0 ? "sub_zones.php?zone_id={$zoneId}" : 'index.php';
        header("Location: {$redirectUrl}");
        exit();
    }
    
    // Use the actual zone_id from the sub-zone record if not provided
    if ($zoneId <= 0) {
        $zoneId = $subZone['zone_id'];
    }
    
    // Check for dependent records
    $dependencies = [];
    
    // Check for businesses
    $businesses = $db->fetchRow("SELECT COUNT(*) as count FROM businesses WHERE sub_zone_id = ?", [$subZoneId]);
    if ($businesses['count'] > 0) {
        $dependencies[] = $businesses['count'] . ' business(es)';
    }
    
    // Check for properties
    $properties = $db->fetchRow("SELECT COUNT(*) as count FROM properties WHERE sub_zone_id = ?", [$subZoneId]);
    if ($properties['count'] > 0) {
        $dependencies[] = $properties['count'] . ' property/properties';
    }
    
    // If there are dependencies, prevent deletion
    if (!empty($dependencies)) {
        $dependencyList = implode(', ', $dependencies);
        setFlashMessage('error', "Cannot delete sub-zone '{$subZone['sub_zone_name']}'. It is currently assigned to: {$dependencyList}. Please reassign or remove these records first.");
        header("Location: sub_zones.php?zone_id={$zoneId}");
        exit();
    }
    
    // Store sub-zone data for audit log
    $oldSubZoneData = [
        'sub_zone_id' => $subZone['sub_zone_id'],
        'zone_id' => $subZone['zone_id'],
        'sub_zone_name' => $subZone['sub_zone_name'],
        'sub_zone_code' => $subZone['sub_zone_code'],
        'description' => $subZone['description'],
        'created_by' => $subZone['created_by'],
        'created_at' => $subZone['created_at']
    ];
    
    // Begin transaction
    if (method_exists($db, 'beginTransaction')) {
        $db->beginTransaction();
    } else {
        $db->execute("START TRANSACTION");
    }
    
    try {
        // Delete the sub-zone
        $deleteResult = $db->execute("DELETE FROM sub_zones WHERE sub_zone_id = ?", [$subZoneId]);
        
        if (!$deleteResult) {
            throw new Exception("Failed to delete sub-zone from database.");
        }
        
        // Commit transaction
        if (method_exists($db, 'commit')) {
            $db->commit();
        } else {
            $db->execute("COMMIT");
        }
        
        // Log the deletion action
        logUserAction(
            'DELETE_SUB_ZONE',
            'sub_zones',
            $subZoneId,
            $oldSubZoneData,
            null
        );
        
        // Set success message
        $zoneName = $subZone['zone_name'] ? " in {$subZone['zone_name']}" : '';
        setFlashMessage('success', "Sub-zone '{$subZone['sub_zone_name']}'{$zoneName} has been successfully deleted.");
        
        // Redirect back to sub-zones list or zones list
        $redirectUrl = $zoneId > 0 ? "sub_zones.php?zone_id={$zoneId}" : 'index.php';
        header("Location: {$redirectUrl}");
        exit();
        
    } catch (Exception $e) {
        // Rollback transaction
        if (method_exists($db, 'rollback')) {
            $db->rollback();
        } else {
            $db->execute("ROLLBACK");
        }
        
        throw $e; // Re-throw to be caught by outer try-catch
    }
    
} catch (Exception $e) {
    writeLog("Sub-zone deletion error (ID: {$subZoneId}): " . $e->getMessage(), 'ERROR');
    
    // Set error message based on the type of error
    if (strpos($e->getMessage(), 'foreign key constraint') !== false || 
        strpos($e->getMessage(), 'constraint violation') !== false) {
        setFlashMessage('error', 'Cannot delete this sub-zone because it has dependent records. Please remove all associated businesses and properties first.');
    } else {
        setFlashMessage('error', 'An error occurred while deleting the sub-zone: ' . $e->getMessage());
    }
    
    $redirectUrl = $zoneId > 0 ? "sub_zones.php?zone_id={$zoneId}" : 'index.php';
    header("Location: {$redirectUrl}");
    exit();
}

// This should never be reached, but just in case
setFlashMessage('error', 'Unexpected error during sub-zone deletion.');
$redirectUrl = $zoneId > 0 ? "sub_zones.php?zone_id={$zoneId}" : 'index.php';
header("Location: {$redirectUrl}");
exit();
?>