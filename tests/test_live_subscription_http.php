<?php
declare(strict_types=1);

$baseUrl = 'http://localhost/accounting/api';

echo "====================================================================\n";
echo "LIVE HTTP E2E TEST: SUBSCRIPTIONS, LIMITS & COUPONS VIA WAMP APACHE\n";
echo "====================================================================\n\n";

$passed = 0;
$failed = 0;

function assertHttp(string $testName, bool $condition, string $details = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] $testName" . ($details ? " ($details)" : "") . "\n";
        $passed++;
    } else {
        echo "[FAIL] $testName" . ($details ? " - $details" : "") . "\n";
        $failed++;
    }
}

function httpReq(string $method, string $url, array $data = null, string $token = null): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $headers = ['Content-Type: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    
    $response = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [
        'status' => $statusCode,
        'body' => json_decode((string)$response, true) ?: []
    ];
}

// 1. Fetch Public Subscription Plans
$plansRes = httpReq('GET', "{$baseUrl}/subscriptions/plans");
assertHttp("HTTP GET /subscriptions/plans Status 200", $plansRes['status'] === 200);
assertHttp("4 Active Subscription Plans Available", count($plansRes['body']['plans'] ?? []) >= 4);

$plans = $plansRes['body']['plans'] ?? [];
$freePlan = null;
foreach ($plans as $p) {
    if (($p['slug'] ?? '') === 'free') $freePlan = $p;
}
assertHttp("Free Plan Limits Calibrated (15 tx, 5 inv, 10 ai)", 
    $freePlan && (int)$freePlan['max_transactions'] === 15 && (int)$freePlan['max_invoices'] === 5 && (int)$freePlan['ai_queries_limit'] === 10
);

// 2. Register New User Over Live HTTP
$userEmail = 'live_sub_' . time() . '_' . rand(100, 999) . '@testcompany.in';
$regRes = httpReq('POST', "{$baseUrl}/auth/register", [
    'email' => $userEmail,
    'password' => 'LiveTesting123!',
    'business_name' => 'Live Trial Services LLP',
    'gst_number' => '27TESTG9999F1Z9'
]);

assertHttp("HTTP POST /auth/register Status 201", $regRes['status'] === 201);
assertHttp("Registered User Tier is 'free'", ($regRes['body']['user']['tier'] ?? '') === 'free');
$userToken = $regRes['body']['token'] ?? '';
assertHttp("JWT Token Received", !empty($userToken));

// 3. Check Live User Subscription & Usage
$myRes = httpReq('GET', "{$baseUrl}/subscriptions/my", null, $userToken);
assertHttp("HTTP GET /subscriptions/my Status 200", $myRes['status'] === 200);
assertHttp("Active Subscription is Free Plan", ($myRes['body']['subscription']['plan_slug'] ?? '') === 'free');
assertHttp("Max Transactions Limit is 15", ($myRes['body']['limits']['max_transactions'] ?? 0) === 15);
assertHttp("Max Invoices Limit is 5", ($myRes['body']['limits']['max_invoices'] ?? 0) === 5);
assertHttp("Max AI Limit is 10", ($myRes['body']['limits']['max_ai_queries'] ?? 0) === 10);
assertHttp("Remaining Transactions is 15", ($myRes['body']['limits']['transactions_remaining'] ?? 0) === 15);

// 4. Validate Coupon WELCOME50
$couponRes = httpReq('POST', "{$baseUrl}/subscriptions/validate-coupon", [
    'code' => 'WELCOME50',
    'plan_slug' => 'starter',
    'billing_cycle' => 'monthly'
], $userToken);
assertHttp("HTTP POST /subscriptions/validate-coupon Status 200", $couponRes['status'] === 200);
assertHttp("WELCOME50 50% Discount Applied", ($couponRes['body']['coupon']['discount_amount'] ?? 0) > 0);

// 5. Upgrade User to Starter Plan
$upRes = httpReq('POST', "{$baseUrl}/subscriptions/upgrade", [
    'plan_slug' => 'starter',
    'coupon_code' => 'WELCOME50',
    'billing_cycle' => 'monthly'
], $userToken);
assertHttp("HTTP POST /subscriptions/upgrade Status 200", $upRes['status'] === 200);
assertHttp("Upgraded to Starter", ($upRes['body']['plan']['slug'] ?? '') === 'starter');

// 6. Verify Refreshed Subscription Limits
$myUpgraded = httpReq('GET', "{$baseUrl}/subscriptions/my", null, $userToken);
assertHttp("Refreshed Subscription is Starter", ($myUpgraded['body']['subscription']['plan_slug'] ?? '') === 'starter');
assertHttp("Starter Transactions Limit is 300", ($myUpgraded['body']['limits']['max_transactions'] ?? 0) === 300);
assertHttp("Starter Invoices Limit is 100", ($myUpgraded['body']['limits']['max_invoices'] ?? 0) === 100);

// 7. Verify Admin User is Unlimited
$adminLogin = httpReq('POST', "{$baseUrl}/auth/login", [
    'email' => 'admin@accounting.local',
    'password' => 'Password123!'
]);
$adminToken = $adminLogin['body']['token'] ?? '';
$adminSub = httpReq('GET', "{$baseUrl}/subscriptions/my", null, $adminToken);
assertHttp("Admin Sub is Unlimited", !empty($adminSub['body']['subscription']['is_unlimited']));
assertHttp("Admin Max Transactions is 999999", ($adminSub['body']['limits']['max_transactions'] ?? 0) >= 999999);

echo "\n====================================================================\n";
echo "LIVE HTTP TEST RESULTS: Passed: $passed, Failed: $failed\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}
