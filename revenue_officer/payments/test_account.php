<?php
/**
 * Test Account Fetch Script
 * Create this as test_account.php in the same directory as search.php
 * Access it via: test_account.php?type=business&id=6
 */

define('QUICKBILL_305', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$type = $_GET['type'] ?? 'business';
$id = intval($_GET['id'] ?? 6);

echo "<h2>Testing Account Fetch</h2>";
echo "<p>Type: $type, ID: $id</p>";

try {
    $db = new Database();
    echo "<p style='color: green;'>Database connection successful</p>";
    
    if ($type === 'business') {
        // Test business fetch
        echo "<h3>Testing Business Fetch</h3>";
        
        // First, show all businesses
        $allBusinesses = $db->fetchAll("SELECT business_id, account_number, business_name, status FROM businesses");
        echo "<h4>All Businesses in Database:</h4>";
        echo "<table border='1'>";
        echo "<tr><th>ID</th><th>Account</th><th>Name</th><th>Status</th></tr>";
        foreach ($allBusinesses as $biz) {
            echo "<tr><td>{$biz['business_id']}</td><td>{$biz['account_number']}</td><td>{$biz['business_name']}</td><td>{$biz['status']}</td></tr>";
        }
        echo "</table>";
        
        // Test the specific business
        echo "<h4>Testing Business ID: $id</h4>";
        $business = $db->fetchRow("
            SELECT b.*, z.zone_name, sz.sub_zone_name,
                   (SELECT COUNT(*) FROM bills WHERE bill_type = 'Business' AND reference_id = b.business_id) as total_bills,
                   (SELECT COUNT(*) FROM payments p JOIN bills bl ON p.bill_id = bl.bill_id 
                    WHERE bl.bill_type = 'Business' AND bl.reference_id = b.business_id) as total_payments
            FROM businesses b
            LEFT JOIN zones z ON b.zone_id = z.zone_id
            LEFT JOIN sub_zones sz ON b.sub_zone_id = sz.sub_zone_id
            WHERE b.business_id = ?
        ", [$id]);
        
        if ($business) {
            echo "<p style='color: green;'>Business found!</p>";
            echo "<pre>" . print_r($business, true) . "</pre>";
        } else {
            echo "<p style='color: red;'>Business with ID $id not found</p>";
        }
        
    } else {
        // Test property fetch
        echo "<h3>Testing Property Fetch</h3>";
        
        // First, show all properties
        $allProperties = $db->fetchAll("SELECT property_id, property_number, owner_name FROM properties");
        echo "<h4>All Properties in Database:</h4>";
        echo "<table border='1'>";
        echo "<tr><th>ID</th><th>Number</th><th>Owner</th></tr>";
        foreach ($allProperties as $prop) {
            echo "<tr><td>{$prop['property_id']}</td><td>{$prop['property_number']}</td><td>{$prop['owner_name']}</td></tr>";
        }
        echo "</table>";
        
        // Test the specific property
        echo "<h4>Testing Property ID: $id</h4>";
        $property = $db->fetchRow("
            SELECT p.*, z.zone_name,
                   (SELECT COUNT(*) FROM bills WHERE bill_type = 'Property' AND reference_id = p.property_id) as total_bills,
                   (SELECT COUNT(*) FROM payments py JOIN bills bl ON py.bill_id = bl.bill_id 
                    WHERE bl.bill_type = 'Property' AND bl.reference_id = p.property_id) as total_payments
            FROM properties p
            LEFT JOIN zones z ON p.zone_id = z.zone_id
            WHERE p.property_id = ?
        ", [$id]);
        
        if ($property) {
            echo "<p style='color: green;'>Property found!</p>";
            echo "<pre>" . print_r($property, true) . "</pre>";
        } else {
            echo "<p style='color: red;'>Property with ID $id not found</p>";
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<h3>Test Links:</h3>";
echo "<a href='?type=business&id=6'>Test Business ID 6</a><br>";
echo "<a href='?type=business&id=11'>Test Business ID 11</a><br>";
echo "<a href='?type=property&id=4'>Test Property ID 4</a><br>";
echo "<a href='search.php?debug=1'>Back to Search (Debug Mode)</a>";
?>