<?php
/**
 * Zone Management - Delete Zone
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
    setFlashMessage('error', 'Access denied. You do not have permission to delete zones.');
    header('Location: index.php');
    exit();
}

// Validate zone ID parameter
$zoneId = intval($_GET['id'] ?? 0);

if ($zoneId <= 0) {
    setFlashMessage('error', 'Invalid zone ID provided.');
    header('Location: index.php');
    exit();
}

try {
    $db = new Database();
    
    // Get zone details first
    $zone = $db->fetchRow("SELECT * FROM zones WHERE zone_id = ?", [$zoneId]);
    
    if (!$zone) {
        setFlashMessage('error', 'Zone not found.');
        header('Location: index.php');
        exit();
    }
    
    // Check for dependent records
    $dependencies = [];
    
    // Check for sub-zones
    $subZones = $db->fetchRow("SELECT COUNT(*) as count FROM sub_zones WHERE zone_id = ?", [$zoneId]);
    if ($subZones['count'] > 0) {
        $dependencies[] = $subZones['count'] . ' sub-zone(s)';
    }
    
    // Check for businesses
    $businesses = $db->fetchRow("SELECT COUNT(*) as count FROM businesses WHERE zone_id = ?", [$zoneId]);
    if ($businesses['count'] > 0) {
        $dependencies[] = $businesses['count'] . ' business(es)';
    }
    
    // Check for properties
    $properties = $db->fetchRow("SELECT COUNT(*) as count FROM properties WHERE zone_id = ?", [$zoneId]);
    if ($properties['count'] > 0) {
        $dependencies[] = $properties['count'] . ' property/properties';
    }
    
    // If there are dependencies, prevent deletion
    if (!empty($dependencies)) {
        $dependencyList = implode(', ', $dependencies);
        setFlashMessage('error', "Cannot delete zone '{$zone['zone_name']}'. It is currently assigned to: {$dependencyList}. Please reassign or remove these records first.");
        header('Location: index.php');
        exit();
    }
    
    // Store zone data for audit log
    $oldZoneData = [
        'zone_id' => $zone['zone_id'],
        'zone_name' => $zone['zone_name'],
        'zone_code' => $zone['zone_code'],
        'description' => $zone['description'],
        'created_by' => $zone['created_by'],
        'created_at' => $zone['created_at']
    ];
    
    // Begin transaction
    if (method_exists($db, 'beginTransaction')) {
        $db->beginTransaction();
    } else {
        $db->execute("START TRANSACTION");
    }
    
    try {
        // Delete the zone
        $deleteResult = $db->execute("DELETE FROM zones WHERE zone_id = ?", [$zoneId]);
        
        if (!$deleteResult) {
            throw new Exception("Failed to delete zone from database.");
        }
        
        // Commit transaction
        if (method_exists($db, 'commit')) {
            $db->commit();
        } else {
            $db->execute("COMMIT");
        }
        
        // Log the deletion action
        logUserAction(
            'DELETE_ZONE',
            'zones',
            $zoneId,
            $oldZoneData,
            null
        );
        
        // Set success message
        setFlashMessage('success', "Zone '{$zone['zone_name']}' has been successfully deleted.");
        
        // Redirect to zones list
        header('Location: index.php');
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
    writeLog("Zone deletion error (ID: {$zoneId}): " . $e->getMessage(), 'ERROR');
    
    // Set error message based on the type of error
    if (strpos($e->getMessage(), 'foreign key constraint') !== false || 
        strpos($e->getMessage(), 'constraint violation') !== false) {
        setFlashMessage('error', 'Cannot delete this zone because it has dependent records. Please remove all associated sub-zones, businesses, and properties first.');
    } else {
        setFlashMessage('error', 'An error occurred while deleting the zone: ' . $e->getMessage());
    }
    
    header('Location: index.php');
    exit();
}

// This should never be reached, but just in case
setFlashMessage('error', 'Unexpected error during zone deletion.');
header('Location: index.php');
exit();
?>