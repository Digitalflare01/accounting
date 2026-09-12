<?php
require_once __DIR__ . '/../api/config/Database.php';
require_once __DIR__ . '/../api/middleware/Auth.php';
require_once __DIR__ . '/../api/controllers/AuthController.php';
require_once __DIR__ . '/../api/controllers/InvoiceController.php';

use App\Config\Database;
use App\Middleware\Auth;
use App\Controllers\AuthController;
use App\Controllers\InvoiceController;

echo "=== INVOICE & PROFILE BACKEND INTEGRATION TEST ===\n";

$db = Database::getConnection();

// Mock Auth Header for user 1
$token = Auth::generateToken([
    'sub' => 1,
    'email' => 'admin@accounting.local',
    'business_name' => 'Apex Technologies & Advisory LLP',
    'role' => 'admin',
    'status' => 'active',
    'tier' => 'enterprise'
]);
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

// 1. Test updating profile
echo "\n1. Testing Profile Update...\n";
$auth = new AuthController();

ob_start();
$auth->updateProfile([
    'business_name' => 'Apex Global Tech Solutions Pvt Ltd',
    'owner_name' => 'Rajesh Sharma',
    'phone' => '+91 98765 43210',
    'address' => 'Floor 8, Tower B, Cyber City',
    'city' => 'Mumbai',
    'state' => 'Maharashtra',
    'pincode' => '400051',
    'gst_number' => '27ABCDE1234F1Z5',
    'pan_number' => 'ABCDE1234F',
    'bank_name' => 'HDFC Bank Ltd',
    'bank_account_no' => '50200012345678',
    'bank_ifsc' => 'HDFC0001234',
    'bank_branch' => 'Bandra Kurla Complex',
    'upi_id' => 'apextech@hdfcbank',
    'logo_data' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
    'invoice_terms' => "1. Payment is due within 15 days.\n2. Interest @18% p.a. will be charged on overdue bills.",
    'signature_title' => 'Authorized Signatory'
]);
$updateOut = ob_get_clean();
$updateRes = json_decode($updateOut, true);
assert($updateRes['success'] === true, "Profile update failed: $updateOut");
echo " Profile updated successfully! Business Name: " . $updateRes['user']['business_name'] . "\n";
assert($updateRes['user']['bank_name'] === 'HDFC Bank Ltd', "Bank name mismatch");
assert($updateRes['user']['upi_id'] === 'apextech@hdfcbank', "UPI ID mismatch");
assert(!empty($updateRes['user']['logo_data']), "Logo data missing");

// 2. Test fetching profile
echo "\n2. Testing Profile Fetch...\n";
ob_start();
$auth->getProfile();
$getProfileOut = ob_get_clean();
$getProfileRes = json_decode($getProfileOut, true);
assert($getProfileRes['success'] === true, "Profile get failed");
assert($getProfileRes['user']['owner_name'] === 'Rajesh Sharma', "Owner name mismatch");
echo " Profile retrieved successfully! Owner: " . $getProfileRes['user']['owner_name'] . ", State: " . $getProfileRes['user']['state'] . "\n";

// 3. Test Creating Invoice with post_to_ledger = 1
echo "\n3. Testing Invoice Creation with Automatic Ledger Posting...\n";
$inv = new InvoiceController();

$invoicePayload = [
    'invoice_number' => 'INV-TEST-9001',
    'invoice_date' => '2026-09-10',
    'due_date' => '2026-09-25',
    'customer_name' => 'Zenith Enterprises India Ltd',
    'customer_phone' => '+91 91234 56789',
    'customer_email' => 'accounts@zenithenterprises.in',
    'customer_address' => 'Plot 44, MIDC Industrial Area',
    'customer_state' => 'Maharashtra', // Intrastate -> CGST + SGST
    'customer_gstin' => '27ZYXWV9876E1Z2',
    'place_of_supply' => 'Maharashtra',
    'payment_status' => 'unpaid',
    'payment_mode' => 'credit',
    'notes' => 'Thank you for your business. Please quote invoice number on payment.',
    'post_to_ledger' => 1,
    'items' => [
        [
            'item_description' => 'Enterprise Cloud ERP Deployment & Setup',
            'hsn_code' => '998313',
            'quantity' => 1,
            'unit' => 'Service',
            'unit_price' => 100000.00,
            'discount_percent' => 10.0, // Taxable = 90,000.00
            'gst_rate' => 18.0 // 9% CGST (8,100) + 9% SGST (8,100) = 16,200 total tax
        ],
        [
            'item_description' => 'Dedicated Annual Maintenance Contract (Q1)',
            'hsn_code' => '998315',
            'quantity' => 2,
            'unit' => 'Months',
            'unit_price' => 15000.00,
            'discount_percent' => 0.0, // Taxable = 30,000.00
            'gst_rate' => 18.0 // 9% CGST (2,700) + 9% SGST (2,700) = 5,400 total tax
        ]
    ]
];

ob_start();
$inv->create($invoicePayload);
$createOut = ob_get_clean();
$createRes = json_decode($createOut, true);
assert($createRes['success'] === true, "Invoice creation failed: $createOut");
$createdId = (int)$createRes['invoice_id'];
echo " Invoice created with ID: $createdId, Total: Rs " . $createRes['total_amount'] . "\n";

// Expected Math:
// Item 1: Taxable = 90,000; CGST = 8,100; SGST = 8,100; Total = 106,200
// Item 2: Taxable = 30,000; CGST = 2,700; SGST = 2,700; Total = 35,400
// Grand Total = 120,000 (subtotal) + 10,800 (CGST) + 10,800 (SGST) = 141,600.00
assert(abs($createRes['total_amount'] - 141600.00) < 0.01, "Grand total mismatch, got: " . $createRes['total_amount']);

// 4. Verify Double-Entry Ledger Transactions
echo "\n4. Verifying Double-Entry Ledger Transactions...\n";
$invRow = $db->query("SELECT * FROM invoices WHERE id = " . (int)$createdId)->fetch(PDO::FETCH_ASSOC);
assert(!empty($invRow['transaction_group_id']), "transaction_group_id missing on invoice");
$grpId = $invRow['transaction_group_id'];

$txs = $db->query("SELECT * FROM transactions WHERE entry_group_id = '$grpId'")->fetchAll(PDO::FETCH_ASSOC);
echo " Ledger transactions posted for invoice: " . count($txs) . " rows\n";

$totalDebits = 0;
$totalCredits = 0;
foreach ($txs as $t) {
    echo "   - Account ID {$t['account_id']} | Type: " . strtoupper($t['type']) . " | Rs " . number_format($t['amount'], 2) . " | " . $t['description'] . "\n";
    if ($t['type'] === 'debit') {
        $totalDebits += (float)$t['amount'];
    } else {
        $totalCredits += (float)$t['amount'];
    }
    assert($t['supply_type'] === 'outward', "Supply type must be outward for sales invoice");
}

echo " Total Debits: Rs " . number_format($totalDebits, 2) . " | Total Credits: Rs " . number_format($totalCredits, 2) . "\n";
assert(abs($totalDebits - $totalCredits) < 0.01, "Double entry out of balance! Debits: $totalDebits vs Credits: $totalCredits");
assert(abs($totalDebits - 141600.00) < 0.01, "Ledger total does not match invoice total!");
echo " Ledger is perfectly balanced!\n";

// 5. Test Show Invoice (For printing & sharing payload)
echo "\n5. Testing GET /invoices/{id} for printing view...\n";
ob_start();
$inv->show($createdId);
$showOut = ob_get_clean();
$showRes = json_decode($showOut, true);
assert($showRes['success'] === true, "Invoice show failed: $showOut");
assert(count($showRes['items']) === 2, "Items count mismatch");
assert($showRes['business']['business_name'] === 'Apex Global Tech Solutions Pvt Ltd', "Business name missing");
assert(!empty($showRes['business']['bank_name']), "Bank details missing for print");
echo " Invoice show payload verified! Customer: " . $showRes['invoice']['customer_name'] . ", Bank: " . $showRes['business']['bank_name'] . "\n";

// 6. Test Delete Invoice and cascading ledger cleanup
echo "\n6. Testing Invoice Deletion and Ledger Cascade...\n";
ob_start();
$inv->delete($createdId);
$delOut = ob_get_clean();
$delRes = json_decode($delOut, true);
assert($delRes['success'] === true, "Delete invoice failed: $delOut");

$postTxCount = (int)$db->query("SELECT COUNT(*) FROM transactions WHERE entry_group_id = '$grpId'")->fetchColumn();
assert($postTxCount === 0, "Transactions were not cleaned up on invoice deletion!");
echo " Invoice deleted and all $postTxCount associated ledger transactions cleaned up!\n";

echo "\n ALL INVOICE & PROFILE BACKEND TESTS PASSED WITH 100% SUCCESS!\n";
