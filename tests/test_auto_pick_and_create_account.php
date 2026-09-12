<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/config/Database.php';
require_once __DIR__ . '/../api/middleware/Auth.php';
require_once __DIR__ . '/../api/services/AIService.php';
require_once __DIR__ . '/../api/controllers/TransactionController.php';

use App\Config\Database;
use App\Middleware\Auth;
use App\Services\AIService;
use App\Controllers\TransactionController;

echo "=======================================================\n";
echo "TEST SUITE: AUTO-PICK TARGET ACCOUNT & INSTANT CREATION\n";
echo "=======================================================\n\n";

$db = Database::getConnection();
$aiService = new AIService();
$txController = new TransactionController();

$passed = 0;
$total = 0;

function assertTest(bool $condition, string $title): void {
    global $passed, $total;
    $total++;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$title}\n";
    } else {
        echo "  [FAIL] {$title}\n";
    }
}

// -------------------------------------------------------------
// TEST 1: User's Exact Prompt from Screenshot
// "i got income from 3 clients they paid 60000 each for their e commerce website"
// -------------------------------------------------------------
echo "1. Testing User's Exact Screenshot Input:\n";
$userPrompt = "i got income from 3 clients they paid 60000 each for their e commerce website";
$result = $aiService->parseFinancialText($userPrompt);

echo "   Parsed Output:\n";
echo "   - parsed_amount: " . ($result['parsed_amount'] ?? 0) . " (Expected: 180000)\n";
echo "   - transaction_type: " . ($result['transaction_type'] ?? '') . " (Expected: credit)\n";
echo "   - suggested_category: " . ($result['suggested_category'] ?? '') . " (Expected: software_development)\n";
echo "   - suggested_account_name: " . ($result['suggested_account_name'] ?? '') . " (Expected: Software Development Services)\n";
echo "   - target_account: " . json_encode($result['target_account'] ?? []) . "\n";
echo "   - has_multiplier: " . (empty($result['has_multiplier']) ? 'false' : 'true') . "\n";

assertTest(
    isset($result['parsed_amount']) && (float)$result['parsed_amount'] === 180000.0,
    "Calculates 3 * 60,000 = 180,000 (does NOT pick 3 from '3 clients')"
);
assertTest(
    ($result['transaction_type'] ?? '') === 'credit',
    "Identifies 'i got income ... they paid' as credit (revenue), not debit"
);
assertTest(
    in_array($result['suggested_category'] ?? '', ['software_development', 'services']),
    "Identifies e-commerce website category as software development services"
);
assertTest(
    ($result['suggested_account_name'] ?? '') === 'Software Development Services',
    "Auto-picks target account 'Software Development Services'"
);
assertTest(
    isset($result['target_account']['name']) && $result['target_account']['name'] === 'Software Development Services',
    "Target account object contains 'Software Development Services'"
);

// -------------------------------------------------------------
// TEST 2: Other Multiplier & Standard Natural Language Formats
// -------------------------------------------------------------
echo "\n2. Testing Multiplier & Single Amount Variations:\n";

// Multiplier debit: "bought 5 ergonomic chairs for 4000 each"
$chairResult = $aiService->parseFinancialText("bought 5 ergonomic chairs for 4000 each");
assertTest(
    (float)$chairResult['parsed_amount'] === 20000.0 && $chairResult['transaction_type'] === 'debit',
    "Debit multiplier: 'bought 5 ergonomic chairs for 4000 each' = 20,000 debit"
);
assertTest(
    ($chairResult['suggested_account_name'] ?? '') === 'Office Equipment & Furniture',
    "Auto-picks 'Office Equipment & Furniture' for ergonomic chairs"
);

// Multiplier with 'k': "received 50k each from 4 customers for consulting"
$kResult = $aiService->parseFinancialText("received 50k each from 4 customers for consulting");
assertTest(
    (float)$kResult['parsed_amount'] === 200000.0 && $kResult['transaction_type'] === 'credit',
    "Credit multiplier: 'received 50k each from 4 customers' = 200,000 credit"
);
assertTest(
    ($kResult['suggested_account_name'] ?? '') === 'Consulting Income',
    "Auto-picks 'Consulting Income' for consulting fees"
);

// Standard: "Bought a Macbook for 1 lakh"
$macbook = $aiService->parseFinancialText("Bought a Macbook for 1 lakh");
assertTest(
    (float)$macbook['parsed_amount'] === 100000.0 && $macbook['suggested_account_name'] === 'Fixed Asset - Computers',
    "Single amount: 'Bought a Macbook for 1 lakh' = 100,000, Fixed Asset - Computers"
);

// Count noun without 'each' shouldn't be picked as amount: "i got income from 3 clients they paid 60000"
$noEach = $aiService->parseFinancialText("i got income from 3 clients they paid 60000");
assertTest(
    (float)$noEach['parsed_amount'] === 60000.0,
    "Count noun protection: picks 60000 instead of digit 3 even without 'each'"
);

// -------------------------------------------------------------
// TEST 3: Code Generation & Instant Account Creation via API
// -------------------------------------------------------------
echo "\n3. Testing Instant Target Account Creation:\n";

// Generate sequential code
$nextRevenueCode = $txController->generateNextAccountCode('revenue');
$nextExpenseCode = $txController->generateNextAccountCode('expense');
$nextAssetCode   = $txController->generateNextAccountCode('asset');

assertTest(
    is_numeric($nextRevenueCode) && (int)$nextRevenueCode >= 4040,
    "Generates valid next revenue account code ({$nextRevenueCode})"
);
assertTest(
    is_numeric($nextExpenseCode) && (int)$nextExpenseCode >= 5080,
    "Generates valid next expense account code ({$nextExpenseCode})"
);
assertTest(
    is_numeric($nextAssetCode) && (int)$nextAssetCode >= 1530,
    "Generates valid next asset account code ({$nextAssetCode})"
);

// Create custom account via createAccount
$customAccName = 'AI Cloud Compute Subscription ' . time();
ob_start();
// Mock authenticated user context
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . Auth::generateToken(['sub' => 1, 'email' => 'admin@accounting.local', 'role' => 'admin', 'tier' => 'enterprise']);
$txController->createAccount([
    'name' => $customAccName,
    'type' => 'expense',
    'gst_applicable' => 1,
    'description' => 'Dedicated GPU server leasing charges'
]);
$jsonOutput = ob_get_clean();
$createdRes = json_decode($jsonOutput, true);

assertTest(
    isset($createdRes['success']) && $createdRes['success'] === true && !empty($createdRes['account']['code']),
    "Creates custom account '{$customAccName}' instantly with code {$createdRes['account']['code']}"
);

// Verify DB persistence
$stmt = $db->prepare("SELECT id, name, code, type FROM chart_of_accounts WHERE name = ?");
$stmt->execute([$customAccName]);
$persistedAcc = $stmt->fetch(PDO::FETCH_ASSOC);
assertTest(
    !empty($persistedAcc) && $persistedAcc['type'] === 'expense',
    "Account successfully saved and queryable in chart_of_accounts"
);

// -------------------------------------------------------------
// TEST 4: On-The-Fly Account Creation during Transaction Posting
// -------------------------------------------------------------
echo "\n4. Testing Auto-Creation during Transaction Posting:\n";

$autoAccName = 'E-Commerce Website Hosting ' . time();
ob_start();
$txController->store([
    'amount' => 15000.0,
    'type' => 'debit',
    'account_name' => $autoAccName,
    'date' => date('Y-m-d'),
    'description' => 'Annual hosting renewal for web storefront',
    'raw_ai_input' => 'Paid 15000 for E-Commerce Website Hosting'
]);
$txOutput = ob_get_clean();
$txRes = json_decode($txOutput, true);

assertTest(
    isset($txRes['success']) && $txRes['success'] === true && !empty($txRes['transaction']['id']),
    "Transaction posted successfully with previously non-existent account"
);

// Verify that the account was created on the fly
$stmt = $db->prepare("SELECT id, name, code, type FROM chart_of_accounts WHERE name = ?");
$stmt->execute([$autoAccName]);
$autoCreatedRow = $stmt->fetch(PDO::FETCH_ASSOC);
assertTest(
    !empty($autoCreatedRow),
    "Account '{$autoAccName}' was automatically created in chart_of_accounts on the fly"
);

// -------------------------------------------------------------
// TEST 5: Parse Endpoint Integration (Auto-picking & returning target_account)
// -------------------------------------------------------------
echo "\n5. Testing /api/ai/parse Response Payload Integration:\n";

ob_start();
$txController->parseNaturalLanguage([
    'text' => 'i got income from 3 clients they paid 60000 each for their e commerce website'
]);
$parseOutput = ob_get_clean();
$parseRes = json_decode($parseOutput, true);

assertTest(
    isset($parseRes['success']) && $parseRes['success'] === true,
    "parseNaturalLanguage endpoint returns success: true"
);
assertTest(
    isset($parseRes['target_account']['name']) && $parseRes['target_account']['name'] === 'Software Development Services',
    "target_account is auto-picked in endpoint response: Software Development Services"
);
assertTest(
    isset($parseRes['data']['parsed_amount']) && (float)$parseRes['data']['parsed_amount'] === 180000.0,
    "data.parsed_amount in endpoint response is exactly 180,000"
);

// Clean up temporary test accounts & transactions
$db->exec("DELETE FROM transactions WHERE description LIKE '%hosting renewal%'");
$db->exec("DELETE FROM chart_of_accounts WHERE name LIKE '%Subscription " . substr((string)time(), 0, 4) . "%' OR name LIKE '%Hosting " . substr((string)time(), 0, 4) . "%'");

echo "\n-------------------------------------------------------\n";
echo "SUMMARY: {$passed} / {$total} Tests Passed.\n";
echo "-------------------------------------------------------\n";

if ($passed === $total) {
    echo "ALL TESTS PASSED SUCCESSFULLY!\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED!\n";
    exit(1);
}
