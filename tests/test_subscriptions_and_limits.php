<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/config/Database.php';
require_once __DIR__ . '/../api/middleware/Auth.php';
require_once __DIR__ . '/../api/services/AIService.php';
require_once __DIR__ . '/../api/controllers/AuthController.php';
require_once __DIR__ . '/../api/controllers/SubscriptionController.php';
require_once __DIR__ . '/../api/controllers/TransactionController.php';
require_once __DIR__ . '/../api/controllers/InvoiceController.php';

use App\Config\Database;
use App\Middleware\Auth;
use App\Controllers\AuthController;
use App\Controllers\SubscriptionController;
use App\Controllers\TransactionController;
use App\Controllers\InvoiceController;

echo "====================================================================\n";
echo "TEST SUITE: SUBSCRIPTION PLANS, FREE LIMITS & UPGRADE WORKFLOW\n";
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

$db = Database::getConnection();
$auth = new AuthController();
$subController = new SubscriptionController();
$txController = new TransactionController();
$invController = new InvoiceController();

// 1. Get Public Plans
ob_start();
$subController->getPublicPlans();
$plansRes = json_decode(ob_get_clean(), true);

assertTest("Public Plans Fetched", !empty($plansRes['success']) && !empty($plansRes['plans']));
assertTest("Contains 4 Tier Plans", count($plansRes['plans'] ?? []) >= 4);

$freePlan = null;
$starterPlan = null;
foreach ($plansRes['plans'] as $p) {
    if ($p['slug'] === 'free') $freePlan = $p;
    if ($p['slug'] === 'starter') $starterPlan = $p;
}

assertTest("Free Plan Calibrated (15 tx, 5 inv, 10 ai)", 
    $freePlan && (int)$freePlan['max_transactions'] === 15 && (int)$freePlan['max_invoices'] === 5 && (int)$freePlan['ai_queries_limit'] === 10
);

// 2. Register New User & Auto-Provision Free Plan
$testEmail = 'sub_user_' . time() . '_' . rand(100, 999) . '@test.com';
ob_start();
$auth->register([
    'email' => $testEmail,
    'password' => 'SecurePass123!',
    'business_name' => 'Free Trial Ventures',
    'gst_number' => '29AABCF1234F1Z5'
]);
$regRes = json_decode(ob_get_clean(), true);
assertTest("User Registered Successfully", !empty($regRes['success']));
assertTest("User Tier is Free", ($regRes['user']['tier'] ?? '') === 'free');

$userToken = $regRes['token'];
$userId = (int)$regRes['user']['id'];

// Set Authorization header for user simulation
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $userToken;

// 3. Check /subscriptions/my
ob_start();
$subController->getMySubscription();
$mySub = json_decode(ob_get_clean(), true);

assertTest("Get My Subscription Response", !empty($mySub['success']));
assertTest("Active Subscription Slug is 'free'", ($mySub['subscription']['plan_slug'] ?? '') === 'free');
assertTest("Free Plan Allows Experiencing Software (15 tx)", ($mySub['limits']['max_transactions'] ?? 0) === 15);
assertTest("Remaining Transactions Initialized (15 remaining)", ($mySub['limits']['transactions_remaining'] ?? 0) === 15);

// 4. Test Transaction Limit Enforcement
// Find cash and sales accounts from chart_of_accounts
$cashAcct = $db->query("SELECT id FROM chart_of_accounts WHERE type = 'asset' LIMIT 1")->fetchColumn();
$salesAcct = $db->query("SELECT id FROM chart_of_accounts WHERE type = 'revenue' LIMIT 1")->fetchColumn();

if (!$cashAcct || !$salesAcct) {
    // Create test accounts if not existing
    $db->prepare("INSERT INTO chart_of_accounts (code, name, type, is_active) VALUES ('TEST100', 'Test Cash', 'asset', 1)")->execute();
    $cashAcct = $db->lastInsertId();
    $db->prepare("INSERT INTO chart_of_accounts (code, name, type, is_active) VALUES ('TEST400', 'Test Sales', 'revenue', 1)")->execute();
    $salesAcct = $db->lastInsertId();
}

echo "\nPosting transactions up to Free limit (15 allowed)...\n";
$txSuccessCount = 0;
for ($i = 1; $i <= 15; $i++) {
    ob_start();
    $txController->store([
        'date' => date('Y-m-d'),
        'description' => "Trial Transaction #$i",
        'type' => 'debit',
        'account_id' => $cashAcct,
        'amount' => 100.0,
        'entry_group_id' => 'vch_' . $i
    ]);
    $res = json_decode(ob_get_clean(), true);
    if (!empty($res['success'])) {
        $txSuccessCount++;
    }
}

assertTest("All 15 Free Transactions Posted", $txSuccessCount === 15, "$txSuccessCount/15 posted");

// Post 16th Transaction (Should be blocked with 403 upgrade_required)
ob_start();
$txController->store([
    'date' => date('Y-m-d'),
    'description' => "Trial Transaction #16 Excess",
    'type' => 'debit',
    'account_id' => $cashAcct,
    'amount' => 200.0,
    'entry_group_id' => 'vch_16'
]);
$blockedRes = json_decode(ob_get_clean(), true);
assertTest("16th Transaction Blocked by Free Plan Limit", empty($blockedRes['success']));
assertTest("Upgrade Required Flagged", !empty($blockedRes['upgrade_required']) && $blockedRes['upgrade_required'] === true);

// 5. Test Invoice Limit Enforcement (5 allowed on Free plan)
echo "\nCreating invoices up to Free limit (5 allowed)...\n";
$invSuccessCount = 0;
for ($i = 1; $i <= 5; $i++) {
    ob_start();
    $invController->create([
        'customer_name' => "Trial Customer #$i",
        'customer_gstin' => '27TESTG1234F1Z1',
        'invoice_date' => date('Y-m-d'),
        'items' => [
            ['description' => "Service #$i", 'hsn_sac' => '998311', 'quantity' => 1, 'unit_price' => 500, 'gst_rate' => 18]
        ]
    ]);
    $invRes = json_decode(ob_get_clean(), true);
    if (!empty($invRes['success'])) {
        $invSuccessCount++;
    }
}
assertTest("All 5 Free Invoices Created", $invSuccessCount === 5, "$invSuccessCount/5 created");

// Create 6th Invoice (Should be blocked)
ob_start();
$invController->create([
    'customer_name' => "Excess Customer #6",
    'items' => [
        ['description' => "Excess Item", 'quantity' => 1, 'unit_price' => 1000]
    ]
]);
$blockedInv = json_decode(ob_get_clean(), true);
assertTest("6th Invoice Blocked by Free Plan Limit", empty($blockedInv['success']));
assertTest("Invoice Upgrade Required Flagged", !empty($blockedInv['upgrade_required']) && $blockedInv['upgrade_required'] === true);

// 6. Test Coupon Validation
ob_start();
$subController->validateCoupon([
    'plan_slug' => 'starter',
    'code' => 'WELCOME50',
    'billing_cycle' => 'monthly'
]);
$couponRes = json_decode(ob_get_clean(), true);
assertTest("Coupon WELCOME50 Validated", !empty($couponRes['success']));
assertTest("50% Discount Applied", ($couponRes['coupon']['discount_amount'] ?? 0) > 0);

// 7. Upgrade User to Starter Plan
ob_start();
$subController->upgrade([
    'plan_slug' => 'starter',
    'coupon_code' => 'WELCOME50',
    'billing_cycle' => 'monthly'
]);
$upgradeRes = json_decode(ob_get_clean(), true);
assertTest("Plan Upgraded to Starter", !empty($upgradeRes['success']));
assertTest("New Tier is Starter", ($upgradeRes['plan']['slug'] ?? '') === 'starter');

// Verify refreshed /subscriptions/my
ob_start();
$subController->getMySubscription();
$upgradedSub = json_decode(ob_get_clean(), true);
assertTest("My Subscription Reflects Starter Plan", ($upgradedSub['subscription']['plan_slug'] ?? '') === 'starter');
assertTest("Starter Plan Max Transactions is 300", ($upgradedSub['limits']['max_transactions'] ?? 0) === 300);

// 8. Test posting previously blocked 16th Transaction after upgrade
ob_start();
$txController->store([
    'date' => date('Y-m-d'),
    'description' => "Post-Upgrade Transaction #16",
    'type' => 'debit',
    'account_id' => $cashAcct,
    'amount' => 500.0,
    'entry_group_id' => 'vch_16'
]);
$unblockedRes = json_decode(ob_get_clean(), true);
assertTest("16th Transaction Now Allowed After Upgrade", !empty($unblockedRes['success']));

// 9. Test Admin Limit Bypass
$adminEmail = 'admin@accounting.local';
ob_start();
$auth->login(['email' => $adminEmail, 'password' => 'Password123!']);
$adminLogin = json_decode(ob_get_clean(), true);
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $adminLogin['token'];

ob_start();
$subController->getMySubscription();
$adminSub = json_decode(ob_get_clean(), true);
assertTest("Admin User is Unlimited", !empty($adminSub['subscription']['is_unlimited']));

echo "\n====================================================================\n";
echo "TEST RESULTS: Passed: $passed, Failed: $failed\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}
