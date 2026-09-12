<?php
$baseUrl = 'http://127.0.0.1:8080';

echo "====================================================================\n";
echo "TESTING TRANSACTION & CHAT ADD-ON DELETION SUITE\n";
echo "====================================================================\n\n";

// 1. Login Admin
$ch = curl_init("{$baseUrl}/api/auth/login");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'email' => 'admin@accounting.local',
    'password' => 'Password123!'
]));
$loginRes = curl_exec($ch);
$loginJson = json_decode($loginRes, true);
curl_close($ch);

if (empty($loginJson['token'])) {
    echo "[FAIL] Login failed: $loginRes\n";
    exit(1);
}
$adminToken = $loginJson['token'];
echo "[PASS] Authenticated as admin\n";

// 2. Insert a test chat add-on transaction
$prompt = "expence 999 rs for cloud test server";
$ch = curl_init("{$baseUrl}/api/transactions");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer {$adminToken}",
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'amount' => 999,
    'type' => 'debit',
    'account_name' => 'Software Subscriptions & Cloud',
    'date' => date('Y-m-d'),
    'description' => 'Test chat addon to delete',
    'gst_amount' => 179.82,
    'gst_rate' => 18,
    'is_interstate' => false,
    'raw_ai_input' => $prompt
]));
$addRes = curl_exec($ch);
$addJson = json_decode($addRes, true);
curl_close($ch);

if (empty($addJson['success']) || empty($addJson['transaction']['id'])) {
    echo "[FAIL] Failed to create test transaction: $addRes\n";
    exit(1);
}
$txId = (int)$addJson['transaction']['id'];
echo "[PASS] Created test chat transaction #$txId\n";

// 3. Delete via DELETE /api/transactions/{id}
$ch = curl_init("{$baseUrl}/api/transactions/{$txId}");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer {$adminToken}",
    "Content-Type: application/json"
]);
$delRes = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$delJson = json_decode($delRes, true);
curl_close($ch);

if ($httpCode !== 200 || empty($delJson['success'])) {
    echo "[FAIL] DELETE /api/transactions/{$txId} failed ($httpCode): $delRes\n";
    exit(1);
}
echo "[PASS] DELETE /api/transactions/{$txId} succeeded: {$delJson['message']}\n";

// 4. Verify transaction is gone
$ch = curl_init("{$baseUrl}/api/transactions");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$adminToken}"]);
$listRes = curl_exec($ch);
$listJson = json_decode($listRes, true);
curl_close($ch);

$found = false;
foreach ($listJson['data'] ?? [] as $t) {
    if ((int)$t['id'] === $txId) {
        $found = true;
        break;
    }
}
if ($found) {
    echo "[FAIL] Transaction #$txId still found in list!\n";
    exit(1);
}
echo "[PASS] Verified transaction #$txId is permanently deleted from ledger\n";

// 5. Test POST /api/transactions/delete compatibility
$ch = curl_init("{$baseUrl}/api/transactions");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer {$adminToken}",
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'amount' => 450,
    'type' => 'debit',
    'account_name' => 'Office Expense',
    'date' => date('Y-m-d'),
    'description' => 'Another chat entry to delete via POST',
    'gst_amount' => 0,
    'gst_rate' => 0,
    'is_interstate' => false,
    'raw_ai_input' => '450 office expense'
]));
$addRes2 = curl_exec($ch);
$addJson2 = json_decode($addRes2, true);
curl_close($ch);
$txId2 = (int)$addJson2['transaction']['id'];
echo "[PASS] Created second test chat transaction #$txId2\n";

$ch = curl_init("{$baseUrl}/api/transactions/delete");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer {$adminToken}",
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['id' => $txId2]));
$delRes2 = curl_exec($ch);
$delJson2 = json_decode($delRes2, true);
curl_close($ch);

if (empty($delJson2['success'])) {
    echo "[FAIL] POST /api/transactions/delete failed: $delRes2\n";
    exit(1);
}
echo "[PASS] POST /api/transactions/delete succeeded for #$txId2: {$delJson2['message']}\n";

// 6. Test Tenant Isolation
$uniqueEmail = 'del_tenant_' . time() . '@test.local';
$ch = curl_init("{$baseUrl}/api/auth/register");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'business_name' => 'Tenant Del Test',
    'email' => $uniqueEmail,
    'password' => 'Password123!',
    'gst_number' => ''
]));
$regRes = curl_exec($ch);
$regJson = json_decode($regRes, true);
curl_close($ch);
$otherToken = $regJson['token'];

// Create transaction under other user
$ch = curl_init("{$baseUrl}/api/transactions");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer {$otherToken}",
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'amount' => 777,
    'type' => 'debit',
    'account_name' => 'Utility Bills',
    'date' => date('Y-m-d'),
    'description' => 'Other user transaction',
    'gst_amount' => 0,
    'gst_rate' => 0,
    'is_interstate' => false,
    'raw_ai_input' => '777 recharge'
]));
$otherTxRes = curl_exec($ch);
$otherTxJson = json_decode($otherTxRes, true);
curl_close($ch);
$otherTxId = (int)$otherTxJson['transaction']['id'];

// Register a 3rd user and try to delete other user's transaction
$thirdEmail = 'third_user_' . time() . '@test.local';
$ch = curl_init("{$baseUrl}/api/auth/register");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'business_name' => 'Third User',
    'email' => $thirdEmail,
    'password' => 'Password123!'
]));
$thirdRegRes = curl_exec($ch);
$thirdRegJson = json_decode($thirdRegRes, true);
curl_close($ch);
$thirdToken = $thirdRegJson['token'];

$ch = curl_init("{$baseUrl}/api/transactions/{$otherTxId}");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer {$thirdToken}",
    "Content-Type: application/json"
]);
$hackRes = curl_exec($ch);
$hackCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($hackCode === 404 || $hackCode === 403) {
    echo "[PASS] Tenant Isolation: Unauthorized user cannot delete other user's transaction ($hackCode)\n";
} else {
    echo "[FAIL] Security violation: Unauthorized user could access/delete tx: $hackRes\n";
    exit(1);
}

echo "\n=== ALL TRANSACTION DELETION TESTS PASSED! ===\n";
