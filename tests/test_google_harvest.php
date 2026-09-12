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

$token = \App\Middleware\Auth::generateToken([
    'sub' => 1,
    'user_id' => 1,
    'email' => 'admin@ledgerflow.local',
    'role' => 'admin'
]);
$_SERVER['HTTP_AUTHORIZATION'] = "Bearer {$token}";

$controller = new \App\Controllers\ReportController();

echo "=== Testing Google Database Principles Harvesting ===\n";
ob_start();
$controller->collectPrinciplesFromGoogle([
    'topic' => 'Prepaid Expenses, Accrued Incomes, and Payroll Statutory Deductions',
    'count' => 2
]);
$gOutput = ob_get_clean();
$gData = json_decode($gOutput, true);
echo "Response:\n";
print_r($gData);
