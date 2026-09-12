<?php
declare(strict_types=1);

spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/../api/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) require_once $file;
});

echo "========================================================\n";
echo "   COMPREHENSIVE ACCOUNTING & GOOGLE DB TEST SUITE     \n";
echo "========================================================\n\n";

$db = \App\Config\Database::getConnection();

// 1. Verify Chart of Accounts Expansion
$stmtCoa = $db->query("SELECT COUNT(*) FROM chart_of_accounts");
$totalAccounts = (int)$stmtCoa->fetchColumn();
echo "[1] Chart of Accounts Verification:\n";
echo "    Total Accounts in COA: {$totalAccounts}\n";
assert($totalAccounts >= 45, "COA must have at least 45 expanded accounts");
echo "    ✓ COA successfully expanded with Prepaid, Accrued, Statutory & Contra accounts.\n\n";

// 2. Mock Admin Auth
$token = \App\Middleware\Auth::generateToken([
    'sub' => 1,
    'user_id' => 1,
    'email' => 'admin@ledgerflow.local',
    'role' => 'admin'
]);
$_SERVER['HTTP_AUTHORIZATION'] = "Bearer {$token}";
$controller = new \App\Controllers\ReportController();

// 3. Test Trial Balance Arithmetical Equality
echo "[2] Trial Balance (Dual Aspect General Ledger Reconciliation):\n";
ob_start();
$controller->trialBalance([]);
$tbOut = ob_get_clean();
$tb = json_decode($tbOut, true);
assert($tb && $tb['success'] === true, "Trial balance request failed: {$tbOut}");

$data = $tb['data'];
echo "    Total Debits:  ₹" . number_format((float)$data['total_debits'], 2) . "\n";
echo "    Total Credits: ₹" . number_format((float)$data['total_credits'], 2) . "\n";
echo "    Difference:    ₹" . number_format((float)$data['difference'], 2) . "\n";
echo "    Status:        " . $data['compliance_status'] . "\n";
echo "    Is Balanced:   " . ($data['is_balanced'] ? 'YES' : 'NO') . "\n";
assert($data['is_balanced'] === true, "Trial Balance must be perfectly balanced!");
assert((float)$data['difference'] == 0.0, "Difference must be 0.00");
echo "    ✓ Dual Aspect arithmetical equality verified 100%!\n\n";

// 4. Test Cash Flow Statement (Ind AS 7 / IAS 7)
echo "[3] Cash Flow Statement (Operating, Investing, Financing & Reconciliation):\n";
ob_start();
$controller->cashFlow([]);
$cfOut = ob_get_clean();
$cf = json_decode($cfOut, true);
assert($cf && $cf['success'] === true, "Cash Flow request failed: {$cfOut}");

$cfData = $cf['data'];
echo "    Net Operating Cash:  ₹" . number_format((float)$cfData['operating_activities']['net_cash_from_operating'], 2) . "\n";
echo "    Net Investing Cash:  ₹" . number_format((float)$cfData['investing_activities']['net_cash_from_investing'], 2) . "\n";
echo "    Net Financing Cash:  ₹" . number_format((float)$cfData['financing_activities']['net_cash_from_financing'], 2) . "\n";
echo "    Opening Cash & Bank: ₹" . number_format((float)$cfData['reconciliation']['opening_cash_and_bank'], 2) . "\n";
echo "    Net Period Change:   ₹" . number_format((float)$cfData['reconciliation']['net_change_in_cash'], 2) . "\n";
echo "    Closing Cash & Bank: ₹" . number_format((float)$cfData['reconciliation']['closing_cash_and_bank'], 2) . "\n";
echo "    Is Reconciled:       " . ($cfData['reconciliation']['is_reconciled'] ? 'YES' : 'NO') . "\n";
assert($cfData['reconciliation']['is_reconciled'] === true, "Cash flow must reconcile with cash & bank balances!");
echo "    ✓ Ind AS 7 Cash Flow reconciliation verified!\n\n";

// 5. Test Accounting Principles & Golden Rules
echo "[4] Accounting Principles & Knowledge Hub Catalog:\n";
ob_start();
$controller->getAccountingPrinciples([]);
$pOut = ob_get_clean();
$pRes = json_decode($pOut, true);
assert($pRes && $pRes['success'] === true, "Principles request failed: {$pOut}");

echo "    Total Principles in Knowledge Base: " . count($pRes['data']) . "\n";
echo "    Stats: " . json_encode($pRes['stats']) . "\n";
assert(count($pRes['data']) >= 16, "Must have at least 16 accounting principles");
assert(($pRes['stats']['golden_rules'] ?? 0) >= 3, "Must have 3 Golden Rules");
assert(($pRes['stats']['core_concepts'] ?? 0) >= 5, "Must have Core Concepts");
assert(($pRes['stats']['standard_entries'] ?? 0) >= 8, "Must have Standard Double-Entry Schemas");
echo "    ✓ Accounting Principles and Golden Rules loaded and structured!\n\n";

// 6. Test AI Grounding Context Integration
echo "[5] AI Grounding Context Integration:\n";
$grounding = \App\Services\AccountingPrinciplesService::getPrinciplesContextForAI();
echo "    AI Principles Grounding Context length: " . strlen($grounding) . " chars\n";
assert(stripos($grounding, 'Real Accounts') !== false, "Must include Real Accounts rule");
assert(stripos($grounding, 'Personal Accounts') !== false, "Must include Personal Accounts rule");
assert(stripos($grounding, 'Nominal Accounts') !== false, "Must include Nominal Accounts rule");
echo "    ✓ AI classification engine successfully grounded with real/personal/nominal rules!\n\n";

echo "========================================================\n";
echo "   ALL TESTS PASSED! FULL VERIFICATION SUCCESSFUL!     \n";
echo "========================================================\n";
