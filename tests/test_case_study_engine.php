<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/config/Database.php';
require_once __DIR__ . '/../api/services/AccountingCaseStudyEngine.php';

use App\Config\Database;
use App\Services\AccountingCaseStudyEngine;

$db = Database::getConnection();
$engine = new AccountingCaseStudyEngine($db);

$prompt = 'TechFlow Solutions
TechFlow Solutions commenced business on April 1, 2025. All transactions attract an 18% GST (9% CGST + 9% SGST) where applicable. During April 2025, the following transactions occurred:

Apr 1: The owner invested ₹500,000 cash into the business.

Apr 5: Purchased office equipment for ₹100,000 + 18% GST. Paid in full via bank.

Apr 10: Purchased inventory (goods for resale) for ₹50,000 + 18% GST on credit from ABC Corp.

Apr 15: Sold goods for ₹80,000 + 18% GST. The customer paid cash immediately.

Apr 25: Paid monthly office rent of ₹10,000 in cash (Assume no GST on this rent).

Apr 28: Paid ABC Corp ₹30,000 in cash towards the outstanding payable.

Apr 30: A physical count shows ₹10,000 worth of closing inventory remains.

Your Task: Prepare the Trial Balance, Statement of Profit & Loss, Balance Sheet, Cash Flow Statement, and a GST Summary for April.';

echo "=== 1. Testing Case Study Detection ===\n";
$isCaseStudy = AccountingCaseStudyEngine::isCaseStudy($prompt);
echo "Detected as Case Study: " . ($isCaseStudy ? "YES [PASS]" : "NO [FAIL]") . "\n\n";

echo "=== 2. Solving TechFlow Solutions Case Study ===\n";
$solution = $engine->solve($prompt, false, 1);

if (!$solution['success']) {
    echo "ERROR: " . ($solution['error'] ?? 'Unknown error') . "\n";
    exit(1);
}

echo "Entity: " . $solution['company_name'] . "\n";
echo "Period: " . $solution['period'] . "\n";
echo "GST Rate: " . $solution['gst_rate'] . "%\n\n";

echo "--- A. Journal Vouchers (" . count($solution['vouchers']) . " entries) ---\n";
foreach ($solution['vouchers'] as $v) {
    echo "#{$v['voucher_no']} [{$v['date']}] {$v['narration']}\n";
    foreach ($v['legs'] as $leg) {
        $type = strtoupper($leg['type']);
        echo "   - {$type}: {$leg['account_name']} (₹" . number_format($leg['amount'], 2) . ")\n";
    }
}
echo "\n";

echo "--- B. Trial Balance Reconciliation ---\n";
$tb = $solution['trial_balance'];
echo "Total Debits:  ₹" . number_format($tb['total_debits'], 2) . "\n";
echo "Total Credits: ₹" . number_format($tb['total_credits'], 2) . "\n";
echo "Difference:    ₹" . number_format($tb['difference'], 2) . "\n";
echo "Status:        " . $tb['compliance_status'] . "\n";
assert($tb['is_balanced'], "Trial Balance MUST be balanced!");
assert($tb['total_debits'] == $tb['total_credits'], "Total Debits must equal Total Credits");
assert($tb['total_debits'] == 751400.0 || $tb['total_debits'] == 741400.0, "Total Debits must match ledger totals");
echo "Trial Balance Verification: [PASS]\n\n";

echo "--- C. Statement of Profit & Loss ---\n";
$pl = $solution['profit_and_loss'];
echo "Revenue:       ₹" . number_format($pl['trading_account']['total_revenue'], 2) . "\n";
echo "COGS:          ₹" . number_format($pl['trading_account']['cost_of_goods_sold']['total_cogs'], 2) . "\n";
echo "Gross Profit:  ₹" . number_format($pl['trading_account']['gross_profit'], 2) . "\n";
echo "Expenses:      ₹" . number_format($pl['operating_expenses']['total_expenses'], 2) . "\n";
echo "Net Profit:    ₹" . number_format($pl['net_profit'], 2) . "\n";
assert($pl['trading_account']['gross_profit'] == 40000.0, "Gross Profit must equal ₹40,000.00");
assert($pl['net_profit'] == 30000.0, "Net Profit must equal ₹30,000.00");
echo "P&L Verification: [PASS]\n\n";

echo "--- D. Balance Sheet Dual Aspect ---\n";
$bs = $solution['balance_sheet'];
echo "Total Assets:               ₹" . number_format($bs['total_assets'], 2) . "\n";
echo "Total Liabilities & Equity: ₹" . number_format($bs['total_liabilities_and_equity'], 2) . "\n";
echo "Balance Status:             " . ($bs['is_balanced'] ? "PERFECTLY_BALANCED [PASS]" : "IMBALANCE [FAIL]") . "\n";
assert($bs['is_balanced'], "Balance sheet must balance!");
assert($bs['total_assets'] == 559000.0, "Total assets must equal ₹559,000.00");
assert($bs['total_liabilities_and_equity'] == 559000.0, "Total Liab+Equity must equal ₹559,000.00");
echo "Balance Sheet Verification: [PASS]\n\n";

echo "--- E. Statement of Cash Flows ---\n";
$cf = $solution['cash_flow_statement'];
echo "Operating Cash Flow: ₹" . number_format($cf['operating_activities']['net_cash_from_operating'], 2) . "\n";
echo "Investing Cash Flow: ₹" . number_format($cf['investing_activities']['net_cash_from_investing'], 2) . "\n";
echo "Financing Cash Flow: ₹" . number_format($cf['financing_activities']['net_cash_from_financing'], 2) . "\n";
echo "Net Cash Change:     ₹" . number_format($cf['reconciliation']['net_change_in_cash_and_bank'], 2) . "\n";
echo "Reconciled Status:   " . ($cf['reconciliation']['is_reconciled'] ? "RECONCILED [PASS]" : "NOT RECONCILED [FAIL]") . "\n";
assert($cf['reconciliation']['is_reconciled'], "Cash flow must reconcile!");
assert($cf['reconciliation']['net_change_in_cash_and_bank'] == 436400.0, "Net cash change must equal ₹436,400.00");
echo "Cash Flow Verification: [PASS]\n\n";

echo "--- F. Statutory GST Summary ---\n";
$gst = $solution['gst_summary'];
echo "Total ITC Available:      ₹" . number_format($gst['input_tax_credit_available']['total_itc_total'], 2) . "\n";
echo "Total Output Liability:   ₹" . number_format($gst['output_tax_liability']['total_output_tax'], 2) . "\n";
echo "Net Tax Payable in Cash:  ₹" . number_format($gst['settlement']['net_gst_payable_in_cash'], 2) . "\n";
echo "Excess ITC Carried Fwd:   ₹" . number_format($gst['settlement']['total_excess_itc_carried_forward'], 2) . "\n";
assert($gst['input_tax_credit_available']['total_itc_total'] == 27000.0, "ITC must equal ₹27,000.00");
assert($gst['output_tax_liability']['total_output_tax'] == 14400.0, "Output tax must equal ₹14,400.00");
assert($gst['settlement']['net_gst_payable_in_cash'] == 0.0, "Cash payable must be ₹0.00");
assert($gst['settlement']['total_excess_itc_carried_forward'] == 12600.0, "Excess ITC must equal ₹12,600.00");
echo "GST Verification: [PASS]\n\n";

echo "=== 3. Testing 1-Click Auto-Posting to Books ===\n";
$postedSolution = $engine->solve($prompt, true, 1);
if (empty($postedSolution['is_posted'])) {
    echo "POSTING ERROR: " . json_encode($postedSolution['posting_details']) . "\n";
}
assert($postedSolution['is_posted'] === true, "is_posted must be true");
assert($postedSolution['posting_details']['success'] === true, "posting must succeed");
assert($postedSolution['posting_details']['vouchers_posted'] === 7, "All 7 vouchers must be posted");
$groupId = $postedSolution['posting_details']['case_study_group_id'];
echo "Posted to books under group: {$groupId}\n";

$count = $db->query("SELECT COUNT(*) FROM transactions WHERE entry_group_id = '{$groupId}'")->fetchColumn();
echo "Transactions recorded in DB: {$count}\n";
assert((int)$count >= 14, "At least 14 journal legs recorded in transactions table");

echo "\n=== ALL 7 DELIVERABLE & POSTING TESTS PASSED WITH 100% MATHEMATICAL PRECISION! ===\n";
