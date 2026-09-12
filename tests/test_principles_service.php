<?php
require_once __DIR__ . '/../api/config/Database.php';
require_once __DIR__ . '/../api/services/AccountingPrinciplesService.php';
require_once __DIR__ . '/../api/controllers/ReportController.php';

echo "Initializing Database...\n";
$db = \App\Config\Database::getConnection();

$count = $db->query("SELECT COUNT(*) FROM chart_of_accounts")->fetchColumn();
echo "Chart of Accounts total accounts: {$count}\n";

$pCount = $db->query("SELECT COUNT(*) FROM accounting_principles")->fetchColumn();
echo "Accounting Principles in DB: {$pCount}\n";

$principles = \App\Services\AccountingPrinciplesService::listPrinciples();
echo "Listed principles: " . count($principles) . "\n";
foreach (array_slice($principles, 0, 8) as $p) {
    echo " - [{$p['category']}] {$p['principle_code']}: {$p['title']}\n";
}

$promptContext = \App\Services\AccountingPrinciplesService::getPrinciplesContextForAI();
echo "AI Grounding Context length: " . strlen($promptContext) . " chars\n";
