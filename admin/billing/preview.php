<?php
/**
 * Bill Preview Endpoint
 * QUICKBILL 305 - Admin Panel
 */

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
if (!hasPermission('billing.create')) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Access denied.']);
    exit();
}

// Check session expiration
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > 5600)) {
    // Session expired (30 minutes)
    session_unset();
    session_destroy();
    setFlashMessage('error', 'Your session has expired. Please log in again.');
    header('Location: ../../index.php');
    exit();
}

header('Content-Type: application/json');

try {
    $db = new Database();
    
    $generationType = sanitizeInput($_POST['generation_type'] ?? '');
    $billingYear = intval($_POST['billing_year'] ?? date('Y'));
    $selectedZone = intval($_POST['zone_id'] ?? 0);
    $selectedBusinessType = sanitizeInput($_POST['business_type'] ?? '');
    $specificType = sanitizeInput($_POST['specific_type'] ?? '');
    $specificId = intval($_POST['specific_id'] ?? 0);
    
    // Validate inputs
    if (empty($generationType)) {
        echo json_encode(['error' => 'Invalid generation type.']);
        exit();
    }
    if ($billingYear < 2020 || $billingYear > date('Y') + 1) {
        echo json_encode(['error' => 'Invalid billing year.']);
        exit();
    }
    
    $businessBills = 0;
    $propertyBills = 0;
    $totalAmount = 0.0;
    
    // Business preview
    if ($generationType === 'all' || $generationType === 'businesses' || ($generationType === 'specific' && $specificType === 'business')) {
        $businessQuery = "
            SELECT b.*, bfs.fee_amount 
            FROM businesses b 
            LEFT JOIN business_fee_structure bfs ON b.business_type = bfs.business_type AND b.category = bfs.category 
            WHERE b.status = 'Active' AND bfs.is_active = 1
        ";
        $businessParams = [];
        
        if ($generationType === 'specific' && $specificId) {
            $businessQuery .= " AND b.business_id = ?";
            $businessParams[] = $specificId;
        } elseif ($selectedZone > 0) {
            $businessQuery .= " AND b.zone_id = ?";
            $businessParams[] = $selectedZone;
        }
        
        if (!empty($selectedBusinessType)) {
            $businessQuery .= " AND b.business_type = ?";
            $businessParams[] = $selectedBusinessType;
        }
        
        $businesses = $db->fetchAll($businessQuery, $businessParams);
        
        foreach ($businesses as $business) {
            $existingBill = $db->fetchRow("
                SELECT bill_id FROM bills 
                WHERE bill_type = 'Business' AND reference_id = ? AND billing_year = ?
            ", [$business['business_id'], $billingYear]);
            
            if (!$existingBill) {
                $businessBills++;
                $currentBill = $business['fee_amount'] ?? 0;
                $totalAmount += $business['old_bill'] + $business['arrears'] + $currentBill - $business['previous_payments'];
            }
        }
    }
    
    // Property preview
    if ($generationType === 'all' || $generationType === 'properties' || ($generationType === 'specific' && $specificType === 'property')) {
        $propertyQuery = "
            SELECT p.*, pfs.fee_per_room 
            FROM properties p 
            LEFT JOIN property_fee_structure pfs ON p.structure = pfs.structure AND p.property_use = pfs.property_use 
            WHERE pfs.is_active = 1
        ";
        $propertyParams = [];
        
        if ($generationType === 'specific' && $specificId) {
            $propertyQuery .= " AND p.property_id = ?";
            $propertyParams[] = $specificId;
        } elseif ($selectedZone > 0) {
            $propertyQuery .= " AND p.zone_id = ?";
            $propertyParams[] = $selectedZone;
        }
        
        $properties = $db->fetchAll($propertyQuery, $propertyParams);
        
        foreach ($properties as $property) {
            $existingBill = $db->fetchRow("
                SELECT bill_id FROM bills 
                WHERE bill_type = 'Property' AND reference_id = ? AND billing_year = ?
            ", [$property['property_id'], $billingYear]);
            
            if (!$existingBill) {
                $propertyBills++;
                $currentBill = ($property['fee_per_room'] ?? 0) * $property['number_of_rooms'];
                $totalAmount += $property['old_bill'] + $property['arrears'] + $currentBill - $property['previous_payments'];
            }
        }
    }
    
    echo json_encode([
        'business_bills' => $businessBills,
        'property_bills' => $propertyBills,
        'total_bills' => $businessBills + $propertyBills,
        'total_amount' => $totalAmount
    ]);
    
} catch (Exception $e) {
    echo json_encode(['error' => 'Error generating preview: ' . $e->getMessage()]);
}
?>