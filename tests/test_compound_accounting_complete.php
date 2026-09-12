<?php
declare(strict_types=1);

require_once 'C:/wamp64/www/accounting/api/config/Database.php';
require_once 'C:/wamp64/www/accounting/api/middleware/Auth.php';
require_once 'C:/wamp64/www/accounting/api/services/CompoundEntryEngine.php';

$db = App\Config\Database::getConnection();
$token = App\Middleware\Auth::generateToken(['sub' => 1, 'email' => 'admin@apexledger.in', 'role' => 'admin']);

function postApi($endpoint, $payload, $token) {
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

echo "======================================================================\n";
echo "TEST SUITE: Single-Prompt Auto-Accounting Engine & Verification\n";
echo "======================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertCondition($cond, $desc) {
    global $passCount, $failCount;
    if ($cond) {
        echo "  [PASS] $desc\n";
        $passCount++;
    } else {
        echo "  [FAIL] $desc\n";
        $failCount++;
    }
}

// TEST 1: UNDERSTAND COMPLEX SALE PROMPT
echo "1. Testing /api/ai/understand with Complex Sale + Partial Bank + Credit:\n";
$prompt1 = "Sold software consulting services to Reliance Ltd for 1,00,000 + 18% GST on 2026-09-10, received 50,000 in HDFC Bank and balance 68,000 is receivable from Reliance Ltd";
$u1 = postApi('ai/understand', ['prompt' => $prompt1], $token);

assertCondition($u1['code'] === 200, "HTTP status 200 OK");
assertCondition($u1['data']['success'] === true, "success === true");
assertCondition($u1['data']['verification']['is_balanced'] === true, "Double-entry is balanced (Debits == Credits)");
assertCondition(abs((float)$u1['data']['verification']['total_debit'] - 118000.0) < 0.01, "Total debit equals ₹1,18,000.00");
assertCondition(abs((float)$u1['data']['verification']['total_credit'] - 118000.0) < 0.01, "Total credit equals ₹1,18,000.00");
assertCondition(count($u1['data']['entries']) >= 4, "Extracted at least 4 compound journal legs");

// TEST 2: AUTO-POST RENT WITH TDS DIRECTLY TO BOOKS
echo "\n2. Testing /api/ai/auto-post with Office Rent + 10% TDS u/s 194I:\n";
$prompt2 = "Paid office rent of 50000 on 2026-09-01 via Bank, deducted 10% TDS (5000) under 194I and paid net 45000 to landlord Sharma Properties";
$p2 = postApi('ai/auto-post', ['prompt' => $prompt2], $token);

assertCondition($p2['code'] === 200, "HTTP status 200 OK");
assertCondition($p2['data']['success'] === true, "success === true");
assertCondition(!empty($p2['data']['is_posted']), "is_posted is true");
$grpId2 = $p2['data']['posting_details']['entry_group_id'] ?? null;
assertCondition(!empty($grpId2), "Generated unique entry_group_id ($grpId2)");
$postedEntries2 = $p2['data']['posting_details']['entries'] ?? [];
assertCondition(count($postedEntries2) >= 3, "Posted at least 3 balanced entries to accounts books");

// Verify in database transactions table
$stmt = $db->prepare("SELECT id, account_id, type, amount, description, entry_group_id FROM transactions WHERE entry_group_id = ?");
$stmt->execute([$grpId2]);
$dbRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
assertCondition(count($dbRows) >= 3, "Database contains " . count($dbRows) . " records under group $grpId2");
$sumDebit = 0.0; $sumCredit = 0.0;
foreach ($dbRows as $r) {
    if ($r['type'] === 'debit') $sumDebit += (float)$r['amount'];
    else $sumCredit += (float)$r['amount'];
}
assertCondition(abs($sumDebit - $sumCredit) < 0.01, "Database entries are exactly balanced: Debit ₹$sumDebit == Credit ₹$sumCredit");

// TEST 3: ANNUAL PREPAID RECHARGE (12 MONTHS SPLIT)
echo "\n3. Testing /api/ai/auto-post with 12-Month Prepaid Telecom Recharge:\n";
$prompt3 = "Paid 6000 rs for 12 month network recharge using bank";
$p3 = postApi('ai/auto-post', ['prompt' => $prompt3], $token);

assertCondition($p3['code'] === 200, "HTTP status 200 OK");
assertCondition($p3['data']['success'] === true, "success === true");
assertCondition(!empty($p3['data']['is_posted']), "is_posted is true");
$grpId3 = $p3['data']['posting_details']['entry_group_id'] ?? null;
assertCondition(!empty($grpId3), "Generated unique entry_group_id ($grpId3)");

// Verify matching principle split
$stmt3 = $db->prepare("SELECT t.type, t.amount, coa.name FROM transactions t JOIN chart_of_accounts coa ON t.account_id = coa.id WHERE t.entry_group_id = ?");
$stmt3->execute([$grpId3]);
$splitRows = $stmt3->fetchAll(PDO::FETCH_ASSOC);
$foundPrepaid = false;
$foundExpense = false;
$foundBank = false;
foreach ($splitRows as $sr) {
    if (stripos($sr['name'], 'prepaid') !== false) $foundPrepaid = true;
    if (stripos($sr['name'], 'util') !== false || stripos($sr['name'], 'expense') !== false) $foundExpense = true;
    if (stripos($sr['name'], 'bank') !== false) $foundBank = true;
}
assertCondition($foundPrepaid, "Successfully allocated to Prepaid Expenses asset account");
assertCondition($foundExpense, "Successfully allocated current month portion to Utility/Expense account");
assertCondition($foundBank, "Successfully credited Bank Operating Account");

// TEST 4: BABEL DELIMITER SYNTAX OF PUBLIC/INDEX.HTML
echo "\n4. Verifying public/index.html Babel Delimiter Balance:\n";
$html = file_get_contents('C:/wamp64/www/accounting/public/index.html');
$pos = strpos($html, '<script type="text/babel">');
$babelCode = substr($html, $pos);
$posEnd = strpos($babelCode, '</script>');
$babelCode = substr($babelCode, 0, $posEnd);
$curly = 0; $round = 0; $square = 0;
$lines = explode("\n", $babelCode);
for ($i = 0; $i < count($lines); $i++) {
    $line = $lines[$i];
    $len = strlen($line);
    for ($j = 0; $j < $len; $j++) {
        $c = $line[$j];
        $next = ($j + 1 < $len) ? $line[$j + 1] : '';
        if ($c === '"' || $c === "'" || $c === '`') {
            $quote = $c;
            $j++;
            while ($j < $len) {
                if ($line[$j] === '\\') { $j += 2; continue; }
                if ($line[$j] === $quote) break;
                $j++;
            }
            continue;
        }
        if ($c === '/' && $next === '/') break;
        if ($c === '{') $curly++;
        elseif ($c === '}') $curly--;
        elseif ($c === '(') $round++;
        elseif ($c === ')') $round--;
        elseif ($c === '[') $square++;
        elseif ($c === ']') $square--;
    }
}
assertCondition($curly === 0 && $round === 0 && $square === 0, "All delimiters balanced (curly=$curly, round=$round, square=$square)");

echo "\n======================================================================\n";
echo "TEST RESULTS: $passCount PASSED, $failCount FAILED\n";
echo "======================================================================\n";
if ($failCount > 0) exit(1);
