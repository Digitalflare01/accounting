<?php
declare(strict_types=1);

echo "Testing Live HTTP Server E2E at http://127.0.0.1:8080/ ...\n";

// 1. Login
$ch = curl_init("http://127.0.0.1:8080/api/auth/login");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    "email" => "admin@accounting.local",
    "password" => "Password123!"
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
$loginRes = json_decode(curl_exec($ch), true);
$token = $loginRes["token"] ?? "";
if (empty($token)) {
    echo "[FAIL] Could not log in to live server: " . json_encode($loginRes) . "\n";
    exit(1);
}
echo "[PASS] Authenticated against live server. Token: " . substr($token, 0, 15) . "...\n";

// 2. Parse exact user screenshot prompt over live HTTP
$userPrompt = "i got income from 3 clients they paid 60000 each for their e commerce website";
$ch2 = curl_init("http://127.0.0.1:8080/api/ai/parse");
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode(["text" => $userPrompt]));
curl_setopt($ch2, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Authorization: Bearer " . $token
]);
$parseRes = json_decode(curl_exec($ch2), true);

echo "\n--- LIVE HTTP AI PARSE RESULTS ---\n";
echo "Amount: ₹" . number_format((float)$parseRes["data"]["parsed_amount"]) . "\n";
echo "Transaction Type: " . strtoupper($parseRes["data"]["transaction_type"]) . "\n";
echo "Category: " . $parseRes["data"]["suggested_category"] . "\n";
echo "Auto-Picked Target Account: " . $parseRes["target_account"]["name"] . " (" . $parseRes["target_account"]["type"] . ")\n";

if ((float)$parseRes["data"]["parsed_amount"] === 180000.0 &&
    $parseRes["data"]["transaction_type"] === "credit" &&
    $parseRes["target_account"]["name"] === "Software Development Services") {
    echo "[PASS] All live HTTP parser assertions matched exactly!\n";
} else {
    echo "[FAIL] Unexpected live parse output!\n";
    exit(1);
}

// 3. Post to SQL
$ch3 = curl_init("http://127.0.0.1:8080/api/transactions");
curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch3, CURLOPT_POSTFIELDS, json_encode([
    "amount" => $parseRes["data"]["parsed_amount"],
    "type" => $parseRes["data"]["transaction_type"],
    "account_name" => $parseRes["target_account"]["name"],
    "date" => date('Y-m-d'),
    "description" => $userPrompt,
    "raw_ai_input" => $userPrompt,
    "gst_rate" => 18,
    "is_interstate" => false
]));
curl_setopt($ch3, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Authorization: Bearer " . $token
]);
$postRes = json_decode(curl_exec($ch3), true);

if (!empty($postRes["success"]) && !empty($postRes["transaction"]["id"])) {
    echo "[PASS] Transaction #" . $postRes["transaction"]["id"] . " posted cleanly to SQL!\n";
    echo "Account: " . $postRes["transaction"]["account_name"] . " | Amount: ₹" . number_format((float)$postRes["transaction"]["amount"]) . "\n";
} else {
    echo "[FAIL] Failed to post transaction: " . json_encode($postRes) . "\n";
    exit(1);
}

// Clean up test entry
$txId = (int)$postRes["transaction"]["id"];
$ch4 = curl_init("http://127.0.0.1:8080/api/transactions/{$txId}");
curl_setopt($ch4, CURLOPT_CUSTOMREQUEST, "DELETE");
curl_setopt($ch4, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch4, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $token]);
$delRes = json_decode(curl_exec($ch4), true);
echo "[PASS] Cleaned up test transaction #{$txId}: " . ($delRes["message"] ?? "") . "\n";

echo "\n=== ALL LIVE HTTP E2E VERIFICATIONS COMPLETED WITH 100% SUCCESS! ===\n";
