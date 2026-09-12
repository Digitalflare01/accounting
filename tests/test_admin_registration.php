<?php
declare(strict_types=1);

echo "====================================================================\n";
echo "TEST SUITE: ADMIN REGISTRATION & PASSCODE SECURITY VERIFICATION\n";
echo "====================================================================\n\n";

$baseUrl = 'http://localhost/accounting/api';
$passcode = 'Parayulla@NGK';

function postJson(string $url, array $data): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode((string)$raw, true) ?: [], 'raw' => $raw];
}

function getAuthJson(string $url, string $token): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$token}",
        'Content-Type: application/json'
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode((string)$raw, true) ?: [], 'raw' => $raw];
}

// TEST 1: Reject admin registration when passcode is missing
echo "1. Testing admin registration with missing passcode...\n";
$res1 = postJson("{$baseUrl}/auth/admin-register", [
    'email' => 'hacker@accounting.local',
    'password' => 'Password123!'
]);
assert($res1['code'] === 403, "Expected 403 for missing passcode, got {$res1['code']}");
assert($res1['body']['success'] === false, "Expected success=false");
echo "   ✓ Correctly blocked (HTTP 403): {$res1['body']['error']}\n\n";

// TEST 2: Reject admin registration when passcode is wrong
echo "2. Testing admin registration with WRONG passcode...\n";
$res2 = postJson("{$baseUrl}/auth/admin-register", [
    'email' => 'fakeadmin@accounting.local',
    'password' => 'Password123!',
    'passcode' => 'WrongSecret123!'
]);
assert($res2['code'] === 403, "Expected 403 for wrong passcode, got {$res2['code']}");
assert(strpos($res2['body']['error'], 'Invalid administrator master passcode') !== false);
echo "   ✓ Correctly blocked (HTTP 403): {$res2['body']['error']}\n\n";

// TEST 3: Successfully register new administrator with master passcode
$newAdminEmail = 'admin_' . time() . '@accounting.local';
$newAdminPass = 'MasterAdminPass2026!';
echo "3. Testing admin registration with REQUIRED passcode 'Parayulla@NGK' ({$newAdminEmail})...\n";
$res3 = postJson("{$baseUrl}/auth/admin-register", [
    'email' => $newAdminEmail,
    'password' => $newAdminPass,
    'business_name' => 'Apex Chief Auditor',
    'passcode' => $passcode
]);
assert($res3['code'] === 201, "Expected 201 Created, got {$res3['code']}: {$res3['raw']}");
assert($res3['body']['success'] === true, "Expected success=true");
assert($res3['body']['user']['role'] === 'admin', "Expected role 'admin'");
assert(!empty($res3['body']['token']), "Expected valid JWT token");
$newAdminToken = $res3['body']['token'];
echo "   ✓ Successfully registered! Role: {$res3['body']['user']['role']}, Token issued.\n\n";

// TEST 4: Log in using the newly created admin account credentials
echo "4. Testing login with newly created admin credentials via /auth/login...\n";
$res4 = postJson("{$baseUrl}/auth/login", [
    'email' => $newAdminEmail,
    'password' => $newAdminPass
]);
assert($res4['code'] === 200, "Expected 200 OK for login, got {$res4['code']}");
assert($res4['body']['user']['role'] === 'admin', "Expected role 'admin'");
$loginToken = $res4['body']['token'];
echo "   ✓ Login successful! Confirmed admin role.\n\n";

// TEST 5: Access Admin Dashboard API with new admin token
echo "5. Testing /admin/dashboard access with newly provisioned admin token...\n";
$res5 = getAuthJson("{$baseUrl}/admin/dashboard", $loginToken);
assert($res5['code'] === 200, "Expected 200 OK for dashboard, got {$res5['code']}");
assert($res5['body']['success'] === true, "Expected dashboard success");
echo "   ✓ Successfully retrieved Admin Dashboard! Users count: " . $res5['body']['data']['users']['total'] . "\n\n";

// TEST 6: Prevent duplicate administrator registration
echo "6. Testing duplicate registration prevention...\n";
$res6 = postJson("{$baseUrl}/auth/admin-register", [
    'email' => $newAdminEmail,
    'password' => $newAdminPass,
    'passcode' => $passcode
]);
assert($res6['code'] === 409, "Expected 409 Conflict, got {$res6['code']}");
echo "   ✓ Correctly prevented duplicate admin (HTTP 409): {$res6['body']['error']}\n\n";

// TEST 7: Upgrading an existing regular user account to admin via master passcode
$regularUserEmail = 'staff_' . time() . '@company.com';
echo "7. Registering standard user ({$regularUserEmail}) then upgrading with master passcode...\n";
$regStd = postJson("{$baseUrl}/auth/register", [
    'email' => $regularUserEmail,
    'password' => 'StaffPass123!',
    'business_name' => 'Staff Services'
]);
assert($regStd['body']['user']['role'] === 'user', "Initial role must be 'user'");

// Upgrade user to admin
$upgradeRes = postJson("{$baseUrl}/auth/admin-register", [
    'email' => $regularUserEmail,
    'password' => 'UpgradedAdminPass123!',
    'passcode' => $passcode
]);
assert($upgradeRes['code'] === 201, "Expected 201 for upgrade");
assert($upgradeRes['body']['user']['role'] === 'admin', "Upgraded role must be 'admin'");
echo "   ✓ Standard user successfully upgraded to Administrator using master passcode!\n\n";

echo "====================================================================\n";
echo "ALL 7 ADMIN REGISTRATION & PASSCODE SECURITY TESTS PASSED!\n";
echo "====================================================================\n";
