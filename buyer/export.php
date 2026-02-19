<?php
/**
 * Buyer Portal - Export Leads to CSV
 */

require_once __DIR__ . '/BuyerAuth.php';
$auth = new BuyerAuth();
$buyer = $auth->requireAuth();
$db = $auth->getDb();

// Get all leads for this buyer
$stmt = $db->prepare("
    SELECT * FROM buyer_leads
    WHERE buyer_id = :id
    ORDER BY created_at DESC
");
$stmt->bindValue(':id', $buyer['id']);
$result = $stmt->execute();

$leads = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $data = json_decode($row['lead_data'], true) ?? [];
    $leads[] = [
        'id' => $row['id'],
        'first_name' => $data['first_name'] ?? '',
        'last_name' => $data['last_name'] ?? '',
        'phone' => $data['phone'] ?? '',
        'email' => $data['email'] ?? '',
        'address' => $data['address'] ?? '',
        'year' => $data['year'] ?? '',
        'make' => $data['make'] ?? '',
        'model' => $data['model'] ?? '',
        'notes' => $data['notes'] ?? '',
        'source' => $row['site_domain'] ?? '',
        'price' => number_format($row['price'] / 100, 2),
        'status' => $row['status'],
        'date' => $row['created_at'],
    ];
}

// Output CSV
$filename = 'leads-' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// Header row
fputcsv($output, ['ID', 'First Name', 'Last Name', 'Phone', 'Email', 'Address', 'Year', 'Make', 'Model', 'Notes', 'Source', 'Price', 'Status', 'Date']);

// Data rows
foreach ($leads as $lead) {
    fputcsv($output, $lead);
}

fclose($output);
