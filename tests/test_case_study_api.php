<?php
declare(strict_types=1);

require_once 'C:/wamp64/www/accounting/api/config/Database.php';
require_once 'C:/wamp64/www/accounting/api/middleware/Auth.php';

$token = App\Middleware\Auth::generateToken(['sub' => 1, 'email' => 'admin@apexledger.in', 'role' => 'admin']);

function callApi($endpoint, $payload, $token) {
    $ch = curl_init("http://localhost/accounting/api/$endpoint");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        "Authorization: Bearer $token"
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'data' => json_decode($res, true), 'raw' => $res];
}

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

echo "1. Testing POST /api/ai/understand with TechFlow Case Study prompt:\n";
$r1 = callApi('ai/understand', ['prompt' => $prompt], $token);
echo "HTTP: {$r1['code']}\n";
assert($r1['code'] === 200, "HTTP 200 required");
assert($r1['data']['is_case_study'] === true, "Must be flagged as is_case_study: true");
assert($r1['data']['trial_balance']['is_balanced'] === true, "Trial balance balanced");
assert($r1['data']['profit_and_loss']['net_profit'] == 30000.0, "Net profit 30k");
assert($r1['data']['balance_sheet']['total_assets'] == 559000.0, "Assets 559k");
assert($r1['data']['cash_flow_statement']['reconciliation']['is_reconciled'] === true, "Cash flow reconciled");
assert($r1['data']['gst_summary']['settlement']['total_excess_itc_carried_forward'] == 12600.0, "GST excess credit 12.6k");
echo "  [PASS] Successfully detected, solved, and returned 5-statement solution from /api/ai/understand!\n\n";

echo "2. Testing POST /api/ai/solve-case-study dedicated endpoint:\n";
$r2 = callApi('ai/solve-case-study', ['prompt' => $prompt], $token);
echo "HTTP: {$r2['code']}\n";
assert($r2['code'] === 200, "HTTP 200 required");
assert($r2['data']['company_name'] === 'TechFlow Solutions', "Company name extracted");
assert(count($r2['data']['vouchers']) === 7, "7 Vouchers generated");
echo "  [PASS] Successfully solved case study via dedicated /api/ai/solve-case-study!\n\n";

echo "=== ALL API ENDPOINT TESTS PASSED! ===\n";
