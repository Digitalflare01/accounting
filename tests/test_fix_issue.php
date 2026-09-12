<?php
// Test for the reported issue: "expence 500 rs used each paid for 12 month network recharg"
$baseUrl = 'http://127.0.0.1:8080';

// 1. User login (or register if needed)
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
    echo "[FAIL] User login failed: $loginRes\n";
    exit(1);
}
$token = $loginJson['token'];
$email = $loginJson['user']['email'] ?? 'admin';
$role = $loginJson['user']['role'] ?? 'admin';
echo "[PASS] Logged in as: $email (Role: $role)\n";

// 2. Test AI Parse on exact prompt
$prompt = "expence 500 rs used each paid for 12 month network recharg";
echo "[INFO] Testing AI Parse on: \"$prompt\"\n";

$ch = curl_init("{$baseUrl}/api/ai/parse");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer {$token}",
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['text' => $prompt]));
$parseRes = curl_exec($ch);
$parseJson = json_decode($parseRes, true);
curl_close($ch);

if (empty($parseJson['success'])) {
    echo "[FAIL] AI Parse failed: $parseRes\n";
    exit(1);
}

$ai = $parseJson['data'];
echo "[PASS] AI Parse returned success=true\n";
echo "       Parsed Amount: ₹{$ai['parsed_amount']}\n";
echo "       Transaction Type: {$ai['transaction_type']}\n";
echo "       Suggested Category: {$ai['suggested_category']}\n";
echo "       Suggested Account: " . ($ai['suggested_account_name'] ?? 'N/A') . "\n";
echo "       Review Options: " . json_encode($ai['review_options']) . "\n";
echo "       All Accounts Count: " . count($parseJson['all_accounts'] ?? []) . "\n";

if ($ai['parsed_amount'] != 500) {
    echo "[FAIL] Expected parsed_amount 500, got: {$ai['parsed_amount']}\n";
    exit(1);
}

if (empty($ai['review_options'])) {
    echo "[FAIL] review_options is empty!\n";
    exit(1);
}

// 3. Test Transaction Submission Cases
// Case A: With explicit Account Name 'Utility Bills'
echo "\n[INFO] Testing Case A: Submitting with account_name = 'Utility Bills'...\n";
$ch = curl_init("{$baseUrl}/api/transactions");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer {$token}",
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'amount' => $ai['parsed_amount'],
    'type' => $ai['transaction_type'],
    'account_name' => 'Utility Bills',
    'date' => date('Y-m-d'),
    'description' => $prompt,
    'gst_amount' => 90,
    'gst_rate' => 18,
    'is_interstate' => false,
    'raw_ai_input' => $prompt
]));
$txResA = curl_exec($ch);
$httpCodeA = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$txJsonA = json_decode($txResA, true);
curl_close($ch);

if ($httpCodeA !== 200 || empty($txJsonA['success'])) {
    echo "[FAIL] Case A failed ($httpCodeA): $txResA\n";
    exit(1);
}
echo "[PASS] Case A succeeded! Transaction #{$txJsonA['transaction']['id']} saved with Account: {$txJsonA['transaction']['account_name']}\n";

// Case B: Resilient Fallback - empty account_name (must NOT return 400 error!)
echo "\n[INFO] Testing Case B: Submitting with empty account_name '' (Verifying zero-crash resilient fallback)...\n";
$ch = curl_init("{$baseUrl}/api/transactions");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer {$token}",
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'amount' => 500,
    'type' => 'debit',
    'account_name' => '',
    'date' => date('Y-m-d'),
    'description' => $prompt,
    'gst_amount' => 0,
    'gst_rate' => 0,
    'is_interstate' => false,
    'raw_ai_input' => $prompt
]));
$txResB = curl_exec($ch);
$httpCodeB = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$txJsonB = json_decode($txResB, true);
curl_close($ch);

if ($httpCodeB !== 200 || empty($txJsonB['success'])) {
    echo "[FAIL] Case B failed with ($httpCodeB): $txResB\n";
    exit(1);
}
echo "[PASS] Case B succeeded! Fallback successfully assigned Account: {$txJsonB['transaction']['account_name']} (#{$txJsonB['transaction']['id']})\n";

// Case C: Submitting with alias account_name = 'general'
echo "\n[INFO] Testing Case C: Submitting with account_name = 'general' (Verifying category alias resolution)...\n";
$ch = curl_init("{$baseUrl}/api/transactions");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer {$token}",
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'amount' => 500,
    'type' => 'debit',
    'account_name' => 'general',
    'date' => date('Y-m-d'),
    'description' => $prompt,
    'gst_amount' => 0,
    'gst_rate' => 0,
    'is_interstate' => false,
    'raw_ai_input' => $prompt
]));
$txResC = curl_exec($ch);
$httpCodeC = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$txJsonC = json_decode($txResC, true);
curl_close($ch);

if ($httpCodeC !== 200 || empty($txJsonC['success'])) {
    echo "[FAIL] Case C failed with ($httpCodeC): $txResC\n";
    exit(1);
}
echo "[PASS] Case C succeeded! 'general' alias cleanly mapped to: {$txJsonC['transaction']['account_name']} (#{$txJsonC['transaction']['id']})\n";

// 4. Verify transaction list
echo "\n[INFO] Fetching recent transactions to verify database persistence...\n";
$ch = curl_init("{$baseUrl}/api/transactions");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}"]);
$listRes = curl_exec($ch);
$listJson = json_decode($listRes, true);
curl_close($ch);

if (empty($listJson['success']) || count($listJson['data']) < 3) {
    echo "[FAIL] Transaction list check failed: $listRes\n";
    exit(1);
}
echo "[PASS] Successfully verified persistence: " . count($listJson['data']) . " transactions found in DB.\n";
echo "\n=== ALL VERIFICATION TESTS PASSED SUCCESSFULLY! ===\n";
