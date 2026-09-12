<?php
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/../api/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) require_once $file;
});

$db = \App\Config\Database::getConnection();

// Mock Auth token with sub
$token = \App\Middleware\Auth::generateToken([
    'sub' => 1,
    'user_id' => 1,
    'email' => 'admin@ledgerflow.local',
    'role' => 'admin'
]);
$_SERVER['HTTP_AUTHORIZATION'] = "Bearer {$token}";

$controller = new \App\Controllers\ReportController();

echo "=== Testing Trial Balance ===\n";
ob_start();
$controller->trialBalance([]);
$output = ob_get_clean();

$data = json_decode($output, true);
if (!$data || !($data['success'] ?? false)) {
    echo "Trial balance failed:\n" . $output . "\n";
} else {
    echo "Trial Balance Success!\n";
    echo "Total Debits: " . $data['data']['total_debits'] . "\n";
    echo "Total Credits: " . $data['data']['total_credits'] . "\n";
    echo "Difference: " . $data['data']['difference'] . "\n";
    echo "Is Balanced: " . ($data['data']['is_balanced'] ? 'YES' : 'NO') . "\n";
    echo "Compliance: " . ($data['data']['compliance_status'] ?? '') . "\n";
    echo "Accounts count: " . count($data['data']['accounts']) . "\n";
}

echo "\n=== Testing Cash Flow ===\n";
ob_start();
$controller->cashFlow([]);
$output = ob_get_clean();

$data = json_decode($output, true);
if (!$data || !($data['success'] ?? false)) {
    echo "Cash flow failed:\n" . $output . "\n";
} else {
    echo "Cash Flow Success!\n";
    echo "Operating Net Cash: " . $data['data']['operating_activities']['net_cash_from_operating'] . "\n";
    echo "Investing Net Cash: " . $data['data']['investing_activities']['net_cash_from_investing'] . "\n";
    echo "Financing Net Cash: " . $data['data']['financing_activities']['net_cash_from_financing'] . "\n";
    echo "Opening Cash: " . $data['data']['reconciliation']['opening_cash_and_bank'] . "\n";
    echo "Net Change: " . $data['data']['reconciliation']['net_change_in_cash'] . "\n";
    echo "Closing Cash: " . $data['data']['reconciliation']['closing_cash_and_bank'] . "\n";
    echo "Is Reconciled: " . ($data['data']['reconciliation']['is_reconciled'] ? 'YES' : 'NO') . "\n";
}

echo "\n=== Testing Principles Endpoint ===\n";
ob_start();
$controller->getAccountingPrinciples([]);
$pOutput = ob_get_clean();
$pData = json_decode($pOutput, true);
if ($pData && ($pData['success'] ?? false)) {
    echo "Principles loaded: " . count($pData['data']) . "\n";
    echo "Stats: " . json_encode($pData['stats']) . "\n";
    echo "Sample: " . $pData['data'][0]['title'] . " (" . $pData['data'][0]['principle_code'] . ")\n";
} else {
    echo "Principles failed:\n" . $pOutput . "\n";
}
