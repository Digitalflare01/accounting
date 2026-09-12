<?php
/**
 * Test Suite: Cascading Linked Ledger Deletion
 * Verifies that deleting any entry removes ALL postings generated from that entry
 * (e.g. Primary expense/income/asset leg + Bank counter-leg, Depreciation debit + credit).
 */

$baseUrl = 'http://127.0.0.1:8080';

echo "====================================================================\n";
echo "TESTING CASCADING LINKED LEDGER DELETION SUITE\n";
echo "====================================================================\n\n";

// 1. Authenticate as Admin
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
$token = $loginJson['token'];
echo "[PASS] Authenticated successfully as admin\n";

// Helper function to get transactions
function getLedger(string $baseUrl, string $token): array {
    $ch = curl_init("{$baseUrl}/api/transactions");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}"]);
    $res = curl_exec($ch);
    curl_close($ch);
    $json = json_decode($res, true);
    return $json['data'] ?? [];
}

// -------------------------------------------------------------------------
// TEST 1: Double-Entry Standard Post -> Delete Primary Leg -> Removes Bank Leg
// -------------------------------------------------------------------------
echo "\n--- TEST 1: Double-Entry Post & Primary Leg Deletion ---\n";
$ch = curl_init("{$baseUrl}/api/transactions");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer {$token}",
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'amount' => 5432.10,
    'type' => 'debit',
    'account_name' => 'Office Expense',
    'date' => date('Y-m-d'),
    'description' => 'Cascade Test 1: Office Supplies',
    'gst_amount' => 0,
    'raw_ai_input' => '5432.10 office supplies'
]));
$addRes = curl_exec($ch);
$addJson = json_decode($addRes, true);
curl_close($ch);

if (empty($addJson['success']) || empty($addJson['transaction']['id'])) {
    echo "[FAIL] Failed to post transaction: $addRes\n";
    exit(1);
}
$primaryId1 = (int)$addJson['transaction']['id'];
$groupId1 = $addJson['transaction']['entry_group_id'] ?? null;
echo "[PASS] Posted primary transaction #$primaryId1 with entry_group_id: $groupId1\n";

// Check ledger for both primary and counter legs
$ledger = getLedger($baseUrl, $token);
$groupPostings = array_filter($ledger, fn($t) => ($t['entry_group_id'] ?? '') === $groupId1);
echo "[INFO] Found " . count($groupPostings) . " postings with entry_group_id: $groupId1\n";

if (count($groupPostings) < 2) {
    echo "[FAIL] Expected 2 balanced postings (primary + bank), found " . count($groupPostings) . "\n";
    exit(1);
}

$postingIds1 = array_map(fn($t) => (int)$t['id'], $groupPostings);
echo "[PASS] Verified balanced postings generated: " . implode(', ', $postingIds1) . "\n";

// Now delete the primary transaction
$ch = curl_init("{$baseUrl}/api/transactions/{$primaryId1}");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer {$token}",
    "Content-Type: application/json"
]);
$delRes = curl_exec($ch);
$delJson = json_decode($delRes, true);
curl_close($ch);

if (empty($delJson['success'])) {
    echo "[FAIL] Failed to delete transaction #$primaryId1: $delRes\n";
    exit(1);
}
echo "[PASS] DELETE returned success: {$delJson['message']}\n";
echo "[INFO] Deleted IDs reported: " . implode(', ', $delJson['deleted_ids'] ?? []) . " (count: {$delJson['deleted_count']})\n";

// Verify BOTH legs are completely purged from ledger
$ledgerAfter = getLedger($baseUrl, $token);
foreach ($postingIds1 as $pId) {
    foreach ($ledgerAfter as $t) {
        if ((int)$t['id'] === $pId) {
            echo "[FAIL] Linked posting #$pId still exists in ledger!\n";
            exit(1);
        }
    }
}
echo "[PASS] Verified ALL postings based on transaction #$primaryId1 were cleanly purged!\n";

// -------------------------------------------------------------------------
// TEST 2: Double-Entry Standard Post -> Delete from Counter-Leg Side (Bank leg)
// -------------------------------------------------------------------------
echo "\n--- TEST 2: Deletion from Bank Counter-Leg Side ---\n";
$ch = curl_init("{$baseUrl}/api/transactions");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer {$token}",
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'amount' => 12500.00,
    'type' => 'credit',
    'account_name' => 'Consulting Income',
    'date' => date('Y-m-d'),
    'description' => 'Cascade Test 2: Advisory Revenue',
    'gst_amount' => 0,
    'raw_ai_input' => '12500 consulting income received'
]));
$addRes2 = curl_exec($ch);
$addJson2 = json_decode($addRes2, true);
curl_close($ch);

$primaryId2 = (int)$addJson2['transaction']['id'];
$groupId2 = $addJson2['transaction']['entry_group_id'] ?? null;

$ledger2 = getLedger($baseUrl, $token);
$groupPostings2 = array_values(array_filter($ledger2, fn($t) => ($t['entry_group_id'] ?? '') === $groupId2));
if (count($groupPostings2) < 2) {
    echo "[FAIL] Expected 2 balanced postings, found " . count($groupPostings2) . "\n";
    exit(1);
}

// Find the bank counter-leg ID (which is not primaryId2)
$counterLeg = null;
foreach ($groupPostings2 as $p) {
    if ((int)$p['id'] !== $primaryId2) {
        $counterLeg = $p;
        break;
    }
}
$counterLegId = (int)$counterLeg['id'];
echo "[INFO] Primary ID is #$primaryId2; Counter Bank Leg ID is #$counterLegId\n";

// Delete using counterLegId
$ch = curl_init("{$baseUrl}/api/transactions/{$counterLegId}");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer {$token}",
    "Content-Type: application/json"
]);
$delRes2 = curl_exec($ch);
$delJson2 = json_decode($delRes2, true);
curl_close($ch);

if (empty($delJson2['success'])) {
    echo "[FAIL] Failed to delete via counter leg #$counterLegId: $delRes2\n";
    exit(1);
}
echo "[PASS] Counter leg delete succeeded: {$delJson2['message']}\n";

// Verify BOTH legs are gone
$ledgerAfter2 = getLedger($baseUrl, $token);
foreach ([$primaryId2, $counterLegId] as $chkId) {
    foreach ($ledgerAfter2 as $t) {
        if ((int)$t['id'] === $chkId) {
            echo "[FAIL] Leg #$chkId still exists after counter-leg deletion!\n";
            exit(1);
        }
    }
}
echo "[PASS] Verified bidirectional cascade: deleting bank leg also deleted primary leg!\n";

// -------------------------------------------------------------------------
// TEST 3: Depreciation Journal Entry Cascading Deletion (Expense + Contra Asset)
// -------------------------------------------------------------------------
echo "\n--- TEST 3: Depreciation Journal Entry Cascade ---\n";
$ch = curl_init("{$baseUrl}/api/transactions/depreciation");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer {$token}",
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'amount' => 7500.00,
    'date' => date('Y-m-d'),
    'description' => 'Cascade Test 3: Depreciation on hardware workstation',
    'raw_prompt' => 'hardware depreciation 7500'
]));
$deprRes = curl_exec($ch);
$deprJson = json_decode($deprRes, true);
curl_close($ch);

if (empty($deprJson['success']) || empty($deprJson['journal_entry']['debit_tx_id'])) {
    echo "[FAIL] Failed to post depreciation entry: $deprRes\n";
    exit(1);
}
$debitTxId = (int)$deprJson['journal_entry']['debit_tx_id'];
$creditTxId = (int)$deprJson['journal_entry']['credit_tx_id'];
$deprGroupId = $deprJson['journal_entry']['entry_group_id'] ?? null;
echo "[PASS] Depreciation posted: Debit #$debitTxId, Credit #$creditTxId (Group: $deprGroupId)\n";

// Delete the debit leg
$ch = curl_init("{$baseUrl}/api/transactions/{$debitTxId}");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer {$token}",
    "Content-Type: application/json"
]);
$delDeprRes = curl_exec($ch);
$delDeprJson = json_decode($delDeprRes, true);
curl_close($ch);

if (empty($delDeprJson['success'])) {
    echo "[FAIL] Failed to delete depreciation entry #$debitTxId: $delDeprRes\n";
    exit(1);
}
echo "[PASS] Depreciation delete succeeded: {$delDeprJson['message']}\n";

$ledgerAfter3 = getLedger($baseUrl, $token);
foreach ([$debitTxId, $creditTxId] as $chkId) {
    foreach ($ledgerAfter3 as $t) {
        if ((int)$t['id'] === $chkId) {
            echo "[FAIL] Depreciation leg #$chkId still found in ledger!\n";
            exit(1);
        }
    }
}
echo "[PASS] Verified both Depreciation Expense and Accumulated Depreciation were removed!\n";

// -------------------------------------------------------------------------
// TEST 4: Legacy Un-grouped Records Heuristic Cascading Deletion
// -------------------------------------------------------------------------
echo "\n--- TEST 4: Legacy Un-grouped Records Cascade ---\n";
// Insert paired records with entry_group_id = NULL directly via Database
require_once __DIR__ . '/../api/config/Database.php';
$db = \App\Config\Database::getConnection();

$date = date('Y-m-d');
$db->exec("
    INSERT INTO transactions (user_id, account_id, type, amount, date, description, gst_amount, cgst, sgst, igst, supply_type, status, raw_ai_input, entry_group_id)
    VALUES (1, 20, 'debit', 3333.00, '{$date}', 'Legacy Test Primary Office Rent', 0, 0, 0, 0, 'inward', 'posted', 'legacy rent 3333', NULL)
");
$legacyId1 = (int)$db->lastInsertId();

$db->exec("
    INSERT INTO transactions (user_id, account_id, type, amount, date, description, gst_amount, cgst, sgst, igst, supply_type, status, raw_ai_input, entry_group_id)
    VALUES (1, 2, 'credit', 3333.00, '{$date}', 'Bank Outflow: Legacy Test Primary Office Rent', 0, 0, 0, 0, 'inward', 'posted', NULL, NULL)
");
$legacyId2 = (int)$db->lastInsertId();

echo "[INFO] Seeded legacy un-grouped pair: #$legacyId1 and #$legacyId2\n";

// Delete legacy primary via POST /api/transactions/delete
$ch = curl_init("{$baseUrl}/api/transactions/delete");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer {$token}",
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['id' => $legacyId1]));
$delLegRes = curl_exec($ch);
$delLegJson = json_decode($delLegRes, true);
curl_close($ch);

if (empty($delLegJson['success'])) {
    echo "[FAIL] Failed to delete legacy transaction #$legacyId1: $delLegRes\n";
    exit(1);
}
echo "[PASS] Legacy delete response: {$delLegJson['message']}\n";

$ledgerAfter4 = getLedger($baseUrl, $token);
foreach ([$legacyId1, $legacyId2] as $chkId) {
    foreach ($ledgerAfter4 as $t) {
        if ((int)$t['id'] === $chkId) {
            echo "[FAIL] Legacy posting #$chkId still found in ledger!\n";
            exit(1);
        }
    }
}
echo "[PASS] Verified heuristic successfully paired and deleted both legacy postings!\n";

// -------------------------------------------------------------------------
// TEST 5: Tenant Isolation on Linked Deletion
// -------------------------------------------------------------------------
echo "\n--- TEST 5: Tenant Isolation ---\n";
// Create user B and post a transaction
$userBEmail = 'tenant_b_' . time() . '@apexaccounting.io';
$ch = curl_init("{$baseUrl}/api/auth/register");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'business_name' => 'Tenant B Corp',
    'email' => $userBEmail,
    'password' => 'Password123!',
    'gst_number' => ''
]));
$regRes = curl_exec($ch);
$regJson = json_decode($regRes, true);
curl_close($ch);
$tokenB = $regJson['token'];

$ch = curl_init("{$baseUrl}/api/transactions");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer {$tokenB}",
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'amount' => 8888.00,
    'type' => 'debit',
    'account_name' => 'Utility Bills',
    'date' => date('Y-m-d'),
    'description' => 'User B Private Transaction',
    'gst_amount' => 0,
    'raw_ai_input' => '8888 utility'
]));
$addResB = curl_exec($ch);
$addJsonB = json_decode($addResB, true);
curl_close($ch);
$txIdB = (int)$addJsonB['transaction']['id'];

// Create user C
$userCEmail = 'tenant_c_' . time() . '@apexaccounting.io';
$ch = curl_init("{$baseUrl}/api/auth/register");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'business_name' => 'Tenant C Corp',
    'email' => $userCEmail,
    'password' => 'Password123!',
    'gst_number' => ''
]));
$regResC = curl_exec($ch);
$regJsonC = json_decode($regResC, true);
curl_close($ch);
$tokenC = $regJsonC['token'];

// User C tries to delete User B's transaction
$ch = curl_init("{$baseUrl}/api/transactions/{$txIdB}");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer {$tokenC}",
    "Content-Type: application/json"
]);
$hackRes = curl_exec($ch);
$hackCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($hackCode === 404 || $hackCode === 403) {
    echo "[PASS] Tenant Isolation: User C was blocked from deleting User B's entries ($hackCode)\n";
} else {
    echo "[FAIL] Security violation: User C was able to access/delete User B's entry: $hackRes\n";
    exit(1);
}

echo "\n====================================================================\n";
echo "✓ ALL CASCADING LINKED LEDGER DELETION TESTS PASSED WITH 100% SUCCESS!\n";
echo "====================================================================\n";
