<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/config/Database.php';
require_once __DIR__ . '/../api/middleware/Auth.php';
require_once __DIR__ . '/../api/services/AIService.php';
require_once __DIR__ . '/../api/controllers/AuthController.php';
require_once __DIR__ . '/../api/controllers/TransactionController.php';
require_once __DIR__ . '/../api/controllers/ReportController.php';

use App\Config\Database;
use App\Middleware\Auth;
use App\Services\AIService;
use App\Controllers\AuthController;
use App\Controllers\TransactionController;
use App\Controllers\ReportController;

echo "====================================================================\n";
echo "RUNNING EXTENDED TEST SUITE: MULTI-USER AUTH & AI DEPRECIATION SOLVER\n";
echo "====================================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest(string $testName, bool $condition, string $details = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] $testName" . ($details ? " ($details)" : "") . "\n";
        $passed++;
    } else {
        echo "[FAIL] $testName" . ($details ? " - $details" : "") . "\n";
        $failed++;
    }
}

// 1. Database Connection
try {
    $db = Database::getConnection();
    $driver = Database::getDriver();
    assertTest("Database Connection", true, "Driver: $driver");
} catch (Exception $e) {
    assertTest("Database Connection", false, $e->getMessage());
    exit(1);
}

// 2. Existing Seed User Login
$auth = new AuthController();
ob_start();
$auth->login(['email' => 'admin@accounting.local', 'password' => 'Password123!']);
$loginOutput = ob_get_clean();
$loginJson = json_decode($loginOutput, true);
assertTest("Existing User Login", !empty($loginJson['success']) && $loginJson['success'] === true, "Token received");

// 3. New User Registration & Individual Account Creation
$uniqueEmail = 'founder_' . time() . '@apextech.io';
ob_start();
$auth->register([
    'business_name' => 'Apex Cloud Innovations LLP',
    'email' => $uniqueEmail,
    'password' => 'SecurePass987!',
    'gst_number' => '29XYZAB9876C1Z3'
]);
$regOutput = ob_get_clean();
$regJson = json_decode($regOutput, true);

assertTest("New User Sign Up / Registration", !empty($regJson['success']) && $regJson['success'] === true, "New User ID: " . ($regJson['user']['id'] ?? 'none'));
assertTest("New User JWT Auto-Issued", !empty($regJson['token']));

$newUserToken = $regJson['token'];
$newUserId = (int)$regJson['user']['id'];

// 4. Test Multi-User Data Isolation
$_SERVER['HTTP_AUTHORIZATION'] = "Bearer $newUserToken";
$txController = new TransactionController();
$reportController = new ReportController();

// Verify new user has 0 transactions initially
ob_start();
$txController->list();
$newTxList = json_decode(ob_get_clean(), true);
assertTest("New User Data Isolation (Empty Ledger initially)", ($newTxList['count'] ?? -1) === 0);

// Record a transaction for the new user
ob_start();
$txController->store([
    'amount' => 75000.0,
    'type' => 'credit',
    'account_name' => 'Software Development Services',
    'date' => date('Y-m-d'),
    'description' => 'Custom API Design for Cloud Partner',
    'gst_amount' => 13500.0,
    'is_interstate' => false
]);
$newStoreJson = json_decode(ob_get_clean(), true);
assertTest("New User Store Transaction", !empty($newStoreJson['success']));

// Check new user's P&L
ob_start();
$reportController->profitAndLoss(['start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
$newUserPnl = json_decode(ob_get_clean(), true);
assertTest("New User P&L Scoped", (float)$newUserPnl['data']['total_revenue'] === 75000.0, "Revenue: " . $newUserPnl['data']['total_revenue']);

// 5. Test AI Depreciation Solver Engine
$ai = new AIService();

// Test A: Normal Rate (Full 40% WDV for Macbook acquired before Oct)
$deprMacbook = $ai->resolveDepreciationPrompt("I bought a Macbook for 1 lakh on August 25, what is the depreciation?");
assertTest("Depreciation Asset Detection (Macbook -> Computers)", $deprMacbook['query_parsed']['detected_category'] === 'Computers & IT Hardware');
assertTest("Depreciation Cost Parsed (1 lakh -> 100,000)", (float)$deprMacbook['query_parsed']['detected_cost'] === 100000.0);
assertTest("Income Tax WDV Rate (40%)", (float)$deprMacbook['rates']['income_tax_wdv_rate'] === 40.0);
assertTest("180-Day Rule Not Triggered (Full 40% Year 1)", 
    (float)$deprMacbook['depreciation_computations']['income_tax_act_wdv']['first_year_rate_applied'] === 40.0 &&
    (float)$deprMacbook['depreciation_computations']['income_tax_act_wdv']['year_1_depreciation'] === 40000.0
);
assertTest("Depreciation Closing NBV (100k - 40k = 60k)", (float)$deprMacbook['depreciation_computations']['income_tax_act_wdv']['year_1_closing_nbv'] === 60000.0);

// Test B: Half-Rate 180-Day Rule (< 180 days: Put to use in November)
$deprFurniture = $ai->resolveDepreciationPrompt("Calculate depreciation on 2 lakhs of office furniture bought in November");
assertTest("Depreciation Asset Detection (Furniture -> 10% WDV)", (float)$deprFurniture['rates']['income_tax_wdv_rate'] === 10.0);
assertTest("180-Day Rule Triggered (Half-Rate 5% for November purchase)", 
    $deprFurniture['depreciation_computations']['income_tax_act_wdv']['is_half_rate_applied'] === true &&
    (float)$deprFurniture['depreciation_computations']['income_tax_act_wdv']['first_year_rate_applied'] === 5.0 &&
    (float)$deprFurniture['depreciation_computations']['income_tax_act_wdv']['year_1_depreciation'] === 10000.0 // 5% of 200,000
);

// 6. Test Posting Depreciation Journal Entry to Ledger
ob_start();
$txController->postDepreciation([
    'amount' => 40000.0,
    'date' => date('Y-m-d'),
    'description' => 'Annual WDV Depreciation on Macbook Workstation (40% IT Act Sec 32)',
    'raw_prompt' => 'I bought a Macbook for 1 lakh on August 25, what is the depreciation?'
]);
$deprPostOutput = ob_get_clean();
$deprPostJson = json_decode($deprPostOutput, true);

assertTest("Post Depreciation Journal Entry Success", !empty($deprPostJson['success']) && $deprPostJson['success'] === true);
assertTest("Depreciation Debit Leg (Depreciation Expense)", $deprPostJson['journal_entry']['debit_account'] === 'Depreciation & Amortization Expense');
assertTest("Depreciation Credit Leg (Accumulated Depreciation)", $deprPostJson['journal_entry']['credit_account'] === 'Accumulated Depreciation - Assets');

// Check that depreciation shows up as an operating expense in P&L
ob_start();
$reportController->profitAndLoss(['start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
$updatedPnl = json_decode(ob_get_clean(), true);
assertTest("P&L Reflects Depreciation Write-Off", (float)$updatedPnl['data']['total_expenses'] === 40000.0);
assertTest("P&L Net Profit Adjusted (75k Rev - 40k Depr = 35k Net Profit)", (float)$updatedPnl['data']['net_profit'] === 35000.0);

echo "\n====================================================================\n";
echo "TEST RESULTS: $passed PASSED, $failed FAILED\n";
echo "====================================================================\n";

exit($failed > 0 ? 1 : 0);
