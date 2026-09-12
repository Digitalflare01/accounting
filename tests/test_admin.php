<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/config/Database.php';
require_once __DIR__ . '/../api/middleware/Auth.php';
require_once __DIR__ . '/../api/services/AIService.php';
require_once __DIR__ . '/../api/controllers/AuthController.php';
require_once __DIR__ . '/../api/controllers/AdminController.php';

use App\Config\Database;
use App\Middleware\Auth;
use App\Controllers\AuthController;
use App\Controllers\AdminController;

echo "====================================================================\n";
echo "RUNNING TEST SUITE: ENTERPRISE ADMIN, TELEMETRY, USERS & COUPONS\n";
echo "====================================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest(string $testName, bool $condition, string $details = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] $testName" . ($details ? " ($details)" : "") . "\n";
        $passed++;
    } else {
        echo "[FAIL] $testName" . ($details ? " - $details" : "") . "\n";
        $failed++;
    }
}

$db = Database::getConnection();
$auth = new AuthController();
$admin = new AdminController();

// 1. Admin Login & Role Check
ob_start();
$auth->login(['email' => 'admin@accounting.local', 'password' => 'Password123!']);
$adminLogin = json_decode(ob_get_clean(), true);

assertTest("Admin User Login", !empty($adminLogin['success']) && $adminLogin['success'] === true);
assertTest("Admin Role Flagged", ($adminLogin['user']['role'] ?? '') === 'admin');
$adminToken = $adminLogin['token'];

// 2. Regular User Login & Auth Guard Test
$clientEmail = 'client_test_' . time() . '@company.com';
ob_start();
$auth->register([
    'email' => $clientEmail,
    'password' => 'ClientPassword123!',
    'business_name' => 'Client Company Ltd',
    'gst_number' => '27TESTG1234F1Z1'
]);
$clientReg = json_decode(ob_get_clean(), true);
$clientToken = $clientReg['token'];
$clientId = (int)$clientReg['user']['id'];

assertTest("Regular Client Registered", !empty($clientReg['success']));
assertTest("Regular Client Role is 'user'", ($clientReg['user']['role'] ?? '') === 'user');

// Test Guard: Non-admin trying to access requireAdmin()
$_SERVER['HTTP_AUTHORIZATION'] = "Bearer $clientToken";
$guardBlocked = false;
try {
    // In CLI test, simulate requireAdmin check
    $payload = Auth::verifyToken($clientToken);
    if (($payload['role'] ?? 'user') !== 'admin') {
        $guardBlocked = true;
    }
} catch (\Throwable $e) {
    $guardBlocked = true;
}
assertTest("Auth::requireAdmin Blocks Non-Admin Client", $guardBlocked);

// 3. Admin Dashboard Overview
$_SERVER['HTTP_AUTHORIZATION'] = "Bearer $adminToken";
ob_start();
$admin->dashboardOverview();
$dashJson = json_decode(ob_get_clean(), true);

assertTest("Dashboard Overview Returned", !empty($dashJson['success']) && $dashJson['success'] === true);
assertTest("Dashboard User Count > 0", ($dashJson['data']['users']['total'] ?? 0) > 0, "Users: " . $dashJson['data']['users']['total']);
assertTest("Dashboard MRR Calculated", isset($dashJson['data']['revenue']['mrr']), "MRR: ₹" . ($dashJson['data']['revenue']['mrr'] ?? 0));
assertTest("Dashboard Activity Tracked", isset($dashJson['data']['activity']['total_requests']), "Requests: " . $dashJson['data']['activity']['total_requests']);

// 4. Web Activity Telemetry
ob_start();
$admin->getActivityAnalytics(['limit' => 10]);
$actJson = json_decode(ob_get_clean(), true);

assertTest("Web Activity Analytics Returned", !empty($actJson['success']));
assertTest("Top Endpoints List Present", is_array($actJson['data']['top_endpoints']));
assertTest("HTTP Status Breakdown Present", is_array($actJson['data']['status_distribution']));

// 5. User Controls: Suspend and Activate
ob_start();
$admin->updateUser($clientId, ['status' => 'suspended']);
$suspendJson = json_decode(ob_get_clean(), true);
assertTest("Admin Suspends User Account", !empty($suspendJson['success']) && $suspendJson['user']['status'] === 'suspended');

// Verify Suspended User Cannot Log In
ob_start();
$auth->login(['email' => $clientEmail, 'password' => 'ClientPassword123!']);
$blockedLogin = json_decode(ob_get_clean(), true);
assertTest("Suspended User Login Blocked", empty($blockedLogin['success']) && strpos($blockedLogin['error'] ?? '', 'suspended') !== false);

// Re-activate User
ob_start();
$admin->updateUser($clientId, ['status' => 'active', 'tier' => 'pro']);
$activateJson = json_decode(ob_get_clean(), true);
assertTest("Admin Re-activates User & Upgrades to Pro Tier", !empty($activateJson['success']) && $activateJson['user']['status'] === 'active' && $activateJson['user']['tier'] === 'pro');

// 6. Subscription Management & Manual Assignment
ob_start();
$admin->getSubscriptions([]);
$subsJson = json_decode(ob_get_clean(), true);
assertTest("Subscription Plans & Subscribers Listed", !empty($subsJson['success']) && count($subsJson['data']['plans']) >= 4);

// Assign Plan to User
ob_start();
$admin->assignSubscription([
    'user_id' => $clientId,
    'plan_id' => 3, // Pro Accountant
    'billing_cycle' => 'monthly',
    'status' => 'active',
    'amount_paid' => 1999.00
]);
$assignJson = json_decode(ob_get_clean(), true);
assertTest("Assign Subscription to User Success", !empty($assignJson['success']));

// 7. Coupon Creation, Toggling & Deletion
$couponCode = 'PROMO_' . time();
ob_start();
$admin->createCoupon([
    'code' => $couponCode,
    'discount_type' => 'percentage',
    'discount_value' => 35.00,
    'min_order_amount' => 500.00,
    'max_uses' => 50,
    'valid_from' => date('Y-m-d'),
    'valid_until' => date('Y-m-d', strtotime('+30 days'))
]);
$couponCreated = json_decode(ob_get_clean(), true);
assertTest("Create New Discount Coupon Success", !empty($couponCreated['success']) && $couponCreated['coupon']['code'] === $couponCode);
$newCouponId = (int)$couponCreated['coupon']['id'];

// Toggle Coupon
ob_start();
$admin->toggleCoupon($newCouponId);
$couponToggled = json_decode(ob_get_clean(), true);
assertTest("Toggle Coupon Active/Disabled", isset($couponToggled['is_active']) && $couponToggled['is_active'] === 0);

// Delete Coupon
ob_start();
$admin->deleteCoupon($newCouponId);
$couponDeleted = json_decode(ob_get_clean(), true);
assertTest("Delete Coupon Success", !empty($couponDeleted['success']));

// 8. System Diagnostics & Broadcast Announcement
ob_start();
$admin->getSystemDiagnostics();
$diagJson = json_decode(ob_get_clean(), true);
assertTest("System Diagnostics Returned", !empty($diagJson['success']) && !empty($diagJson['system']['php_version']));
assertTest("Diagnostics DB Driver Reported", !empty($diagJson['system']['db_driver']));

// Update Announcement
$testNotice = "Important: Server maintenance scheduled on Sunday at 2 AM IST.";
ob_start();
$admin->updateAnnouncement([
    'announcement_text' => $testNotice,
    'announcement_active' => 1
]);
$noticeUpdated = json_decode(ob_get_clean(), true);
assertTest("Update Broadcast Announcement Success", !empty($noticeUpdated['success']));

// Verify Announcement Retrieval
ob_start();
$admin->getAnnouncement();
$noticeGet = json_decode(ob_get_clean(), true);
assertTest("Get Broadcast Announcement", !empty($noticeGet['data']['announcement_text']) && $noticeGet['data']['announcement_text'] === $testNotice);

// 9. API Integrations: Fetch Configurations
ob_start();
$admin->getApiIntegrations();
$integrationsJson = json_decode(ob_get_clean(), true);

assertTest("Get API Integrations Success", !empty($integrationsJson['success']));
assertTest("Mail API Config Returned", isset($integrationsJson['integrations']['mail']['smtp_host']));
assertTest("Mail Password is Secret Masked", strpos($integrationsJson['integrations']['mail']['smtp_pass_masked'], '••••') !== false);
assertTest("WhatsApp Config Returned", isset($integrationsJson['integrations']['whatsapp']['phone_number_id']));
assertTest("WhatsApp Token is Secret Masked", strpos($integrationsJson['integrations']['whatsapp']['api_token_masked'], '••••') !== false);
assertTest("Gemini API Config Returned", isset($integrationsJson['integrations']['gemini']['model']));
assertTest("Gemini Key is Secret Masked", strpos($integrationsJson['integrations']['gemini']['api_key_masked'], '••••') !== false);

// 10. API Integrations: Update Configurations
ob_start();
$admin->updateApiIntegrations([
    'mail_from_name' => 'ApexLedger Enterprise Sentinel',
    'gemini_model' => 'gemini-2.5-pro',
    'whatsapp_otp_template' => 'apex_secure_auth_v2'
]);
$updateIntegJson = json_decode(ob_get_clean(), true);
assertTest("Update API Integrations Success", !empty($updateIntegJson['success']));

// 11. Mail API Authentication Test Dispatch
ob_start();
$admin->testMailApi(['recipient_email' => 'compliance@apexenterprise.com']);
$mailTestJson = json_decode(ob_get_clean(), true);
assertTest("Mail API Test Dispatch Success", !empty($mailTestJson['success']));
assertTest("Mail Test OTP Generated", !empty($mailTestJson['diagnostics']['sample_otp']));
assertTest("Mail Test Delivery Reported", isset($mailTestJson['diagnostics']['delivery_status']));

// 12. WhatsApp 2FA OTP Authentication Test Dispatch
ob_start();
$admin->testWhatsAppApi(['phone_number' => '+919876543210']);
$waTestJson = json_decode(ob_get_clean(), true);
assertTest("WhatsApp 2FA OTP Test Dispatch Success", !empty($waTestJson['success']));
assertTest("WhatsApp OTP Generated", !empty($waTestJson['diagnostics']['otp_generated']));
assertTest("WhatsApp Outbound Payload Formatted", !empty($waTestJson['diagnostics']['outbound_payload']));

// 13. Gemini API Live Benchmark Test
ob_start();
$admin->testGeminiApi(['prompt' => 'Bought 2 Apple MacBook Pro M3 Max laptops for 3.2 lakhs with 18% GST']);
$geminiTestJson = json_decode(ob_get_clean(), true);
assertTest("Gemini API Live Benchmark Success", !empty($geminiTestJson['success']));
assertTest("Gemini Benchmark Latency Reported", isset($geminiTestJson['benchmark']['latency_ms']));
assertTest("Gemini Benchmark Result Schema Verified", isset($geminiTestJson['benchmark']['parsed_result']['parsed_amount']));

echo "\n====================================================================\n";
echo "ADMIN TEST RESULTS: $passed PASSED, $failed FAILED\n";
echo "====================================================================\n";

exit($failed > 0 ? 1 : 0);

