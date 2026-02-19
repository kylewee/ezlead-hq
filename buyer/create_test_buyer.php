<?php
/**
 * Create a test buyer account
 */

require_once __DIR__ . '/BuyerAuth.php';

$auth = new BuyerAuth();

// Create test buyer
$buyerId = $auth->createBuyer([
    'email' => 'test@buyer.com',
    'password' => 'testpass123',
    'name' => 'Test Buyer',
    'company' => 'Test Company LLC',
    'phone' => '9045551234',
    'price_per_lead' => 2500, // $25
]);

if ($buyerId) {
    echo "Test buyer created with ID: {$buyerId}\n";

    // Add some test credit
    $auth->updateBalance($buyerId, 10000, 'deposit', 'Initial test credit'); // $100
    echo "Added $100 test credit\n";

    echo "\nLogin credentials:\n";
    echo "  Email: test@buyer.com\n";
    echo "  Password: testpass123\n";
    echo "\nAccess: https://mechanicstaugustine.com/buyer/\n";
} else {
    echo "Failed to create buyer (may already exist)\n";
}
