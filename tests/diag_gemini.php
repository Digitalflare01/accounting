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

$ai = new \App\Services\AIService();
$apiKey = $ai->getGeminiApiKey();
$cand = $ai->getGeminiModel();
$topic = 'Prepaid Expenses, Accrued Incomes, and Payroll Statutory Deductions';
$count = 2;

$prompt = <<<PROMPT
You are the Chief Accounting Standard Setter and Google Senior Enterprise Accounting Database Intelligence Engine.
Your task is to provide exactly {$count} rigorous, GAAP / Ind AS / IFRS-compliant accounting principles, golden rules, or standard journal entry templates for the domain: "{$topic}".

Return ONLY a valid JSON array of objects adhering to this schema:
[
  {
    "category": "standard_entry",
    "principle_code": "ENTRY_... in UPPER_SNAKE_CASE",
    "title": "Clear descriptive title",
    "standard_ref": "Standard reference",
    "statement": "Rigorous accounting statement",
    "debit_rule": "Debit explanation",
    "credit_rule": "Credit explanation",
    "applicable_accounts": ["Acc 1", "Acc 2"],
    "journal_schema": {
      "debit": "Debit entry",
      "credit": "Credit entry",
      "example": "Rupee example"
    },
    "practical_implication": "Impact on Trial Balance and P&L"
  }
]
PROMPT;

$url = "https://generativelanguage.googleapis.com/v1beta/models/{$cand}:generateContent?key={$apiKey}";
$payload = [
    'contents' => [
        ['parts' => [['text' => $prompt]]]
    ],
    'generationConfig' => [
        'temperature' => 0.2,
        'maxOutputTokens' => 4096,
        'responseMimeType' => 'application/json'
    ]
];

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 25,
    CURLOPT_SSL_VERIFYPEER => false
]);

$rawRes = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: {$httpCode}\n";
echo "Response:\n{$rawRes}\n";
