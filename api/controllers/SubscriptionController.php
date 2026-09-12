<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Middleware\Auth;
use PDO;

class SubscriptionController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Gets user subscription data, limits, and usage
     */
    public function getSubscriptionDataForUser(int $userId, bool $isAdmin = false): array
    {
        $sub = $this->getOrCreateUserSubscription($userId, $isAdmin);
        $usage = $this->calculateUserUsage($userId);

        $maxTx = (int)($sub['max_transactions'] ?? 15);
        $maxInv = (int)($sub['max_invoices'] ?? 5);
        $maxAI = (int)($sub['ai_queries_limit'] ?? 10);

        $isUnlimited = $isAdmin || ($sub['plan_slug'] ?? '') === 'enterprise';

        $features = json_decode($sub['features_json'] ?? '[]', true) ?: [];

        return [
            'subscription' => [
                'id' => (int)$sub['subscription_id'],
                'plan_id' => (int)$sub['plan_id'],
                'plan_slug' => $sub['plan_slug'],
                'plan_name' => $sub['plan_name'],
                'billing_cycle' => $sub['billing_cycle'],
                'status' => $sub['status'],
                'current_period_start' => $sub['current_period_start'],
                'current_period_end' => $sub['current_period_end'],
                'amount_paid' => (float)$sub['amount_paid'],
                'is_admin' => $isAdmin,
                'is_unlimited' => $isUnlimited,
                'features' => $features
            ],
            'usage' => [
                'transactions_count' => $usage['transactions'],
                'invoices_count' => $usage['invoices'],
                'ai_queries_count' => $usage['ai_queries']
            ],
            'limits' => [
                'max_transactions' => $isUnlimited ? 999999 : $maxTx,
                'max_invoices' => $isUnlimited ? 999999 : $maxInv,
                'max_ai_queries' => $isUnlimited ? 999999 : $maxAI,
                'transactions_remaining' => $isUnlimited ? 999999 : max(0, $maxTx - $usage['transactions']),
                'invoices_remaining' => $isUnlimited ? 999999 : max(0, $maxInv - $usage['invoices']),
                'ai_queries_remaining' => $isUnlimited ? 999999 : max(0, $maxAI - $usage['ai_queries']),
                'is_transaction_limit_reached' => !$isUnlimited && ($usage['transactions'] >= $maxTx),
                'is_invoice_limit_reached' => !$isUnlimited && ($usage['invoices'] >= $maxInv),
                'is_ai_limit_reached' => !$isUnlimited && ($usage['ai_queries'] >= $maxAI)
            ]
        ];
    }

    /**
     * GET /api/subscriptions/my
     * Retrieves current active subscription details, limits, and live usage
     */
    public function getMySubscription(): void
    {
        $user = Auth::requireAuth();
        $userId = (int)$user['sub'];
        $isAdmin = ($user['role'] ?? '') === 'admin';

        $data = $this->getSubscriptionDataForUser($userId, $isAdmin);

        echo json_encode(array_merge(['success' => true], $data));
    }


    /**
     * GET /api/subscriptions/plans
     * Lists all public active subscription plans with pricing and features
     */
    public function getPublicPlans(): void
    {
        $stmt = $this->db->query("
            SELECT id, slug, name, price_monthly, price_annual, max_transactions, max_invoices, ai_queries_limit, features_json
            FROM subscription_plans
            WHERE is_active = 1
            ORDER BY price_monthly ASC
        ");
        $plans = $stmt->fetchAll();

        foreach ($plans as &$p) {
            $p['features'] = json_decode($p['features_json'] ?? '[]', true) ?: [];
            unset($p['features_json']);
        }

        echo json_encode([
            'success' => true,
            'plans' => $plans
        ]);
    }

    /**
     * POST /api/subscriptions/validate-coupon
     * Checks coupon validity and calculates discount
     */
    public function validateCoupon(array $data): void
    {
        Auth::requireAuth();
        $code = strtoupper(trim($data['code'] ?? ''));
        $planSlug = trim($data['plan_slug'] ?? 'pro');
        $billingCycle = ($data['billing_cycle'] ?? 'monthly') === 'annual' ? 'annual' : 'monthly';

        if (empty($code)) {
            if (!headers_sent()) { @http_response_code(400); }
            echo json_encode(['success' => false, 'error' => 'Coupon code is required.']);
            return;
        }

        $planStmt = $this->db->prepare("SELECT price_monthly, price_annual FROM subscription_plans WHERE slug = ? LIMIT 1");
        $planStmt->execute([$planSlug]);
        $plan = $planStmt->fetch();

        if (!$plan) {
            if (!headers_sent()) { @http_response_code(404); }
            echo json_encode(['success' => false, 'error' => 'Selected plan not found.']);
            return;
        }

        $basePrice = ($billingCycle === 'annual') ? (float)$plan['price_annual'] : (float)$plan['price_monthly'];

        $couponStmt = $this->db->prepare("
            SELECT * FROM coupons 
            WHERE code = ? AND is_active = 1 
              AND valid_from <= CURRENT_DATE AND valid_until >= CURRENT_DATE
              AND used_count < max_uses
            LIMIT 1
        ");
        $couponStmt->execute([$code]);
        $coupon = $couponStmt->fetch();

        if (!$coupon) {
            if (!headers_sent()) { @http_response_code(404); }
            echo json_encode(['success' => false, 'error' => 'Invalid or expired discount coupon code.']);
            return;
        }

        $discount = 0.0;
        if ($coupon['discount_type'] === 'percentage') {
            $discount = round(($basePrice * (float)$coupon['discount_value']) / 100, 2);
        } else {
            $discount = min($basePrice, (float)$coupon['discount_value']);
        }

        $finalPrice = max(0.0, $basePrice - $discount);

        echo json_encode([
            'success' => true,
            'coupon' => [
                'id' => (int)$coupon['id'],
                'code' => $coupon['code'],
                'discount_type' => $coupon['discount_type'],
                'discount_value' => (float)$coupon['discount_value'],
                'discount_amount' => $discount,
                'base_price' => $basePrice,
                'final_price' => $finalPrice
            ]
        ]);
    }

    /**
     * POST /api/subscriptions/upgrade
     * Upgrades authenticated user to selected subscription plan with optional coupon
     */
    public function upgrade(array $data): void
    {
        $user = Auth::requireAuth();
        $userId = (int)$user['sub'];

        $planSlug = trim($data['plan_slug'] ?? '');
        $billingCycle = in_array($data['billing_cycle'] ?? '', ['monthly', 'annual']) ? $data['billing_cycle'] : 'monthly';
        $couponCode = strtoupper(trim($data['coupon_code'] ?? ''));

        if (empty($planSlug)) {
            if (!headers_sent()) { @http_response_code(400); }
            echo json_encode(['success' => false, 'error' => 'Plan slug is required.']);
            return;
        }

        $planStmt = $this->db->prepare("SELECT * FROM subscription_plans WHERE slug = ? AND is_active = 1 LIMIT 1");
        $planStmt->execute([$planSlug]);
        $plan = $planStmt->fetch();

        if (!$plan) {
            if (!headers_sent()) { @http_response_code(404); }
            echo json_encode(['success' => false, 'error' => 'Subscription plan not found.']);
            return;
        }

        $basePrice = ($billingCycle === 'annual') ? (float)$plan['price_annual'] : (float)$plan['price_monthly'];
        $amountPaid = $basePrice;
        $couponId = null;

        // Process coupon if provided
        if (!empty($couponCode)) {
            $couponStmt = $this->db->prepare("
                SELECT * FROM coupons 
                WHERE code = ? AND is_active = 1 
                  AND valid_from <= CURRENT_DATE AND valid_until >= CURRENT_DATE
                  AND used_count < max_uses
                LIMIT 1
            ");
            $couponStmt->execute([$couponCode]);
            $coupon = $couponStmt->fetch();

            if ($coupon) {
                $couponId = (int)$coupon['id'];
                $discount = ($coupon['discount_type'] === 'percentage')
                    ? round(($basePrice * (float)$coupon['discount_value']) / 100, 2)
                    : min($basePrice, (float)$coupon['discount_value']);
                $amountPaid = max(0.0, $basePrice - $discount);

                // Increment coupon used count
                $this->db->prepare("UPDATE coupons SET used_count = used_count + 1 WHERE id = ?")->execute([$couponId]);

                // Record redemption
                try {
                    $this->db->prepare("
                        INSERT INTO coupon_redemptions (coupon_id, user_id, discount_applied, order_amount)
                        VALUES (?, ?, ?, ?)
                    ")->execute([$couponId, $userId, $discount, $amountPaid]);
                } catch (\Throwable $e) {}
            }
        }

        $startDate = date('Y-m-d');
        $endDate = ($billingCycle === 'annual') 
            ? date('Y-m-d', strtotime('+1 year')) 
            : date('Y-m-d', strtotime('+1 month'));

        // Update existing subscription or insert new
        $existing = $this->db->prepare("SELECT id FROM subscriptions WHERE user_id = ? ORDER BY id DESC LIMIT 1");
        $existing->execute([$userId]);
        $existingSub = $existing->fetch();

        if ($existingSub) {
            $this->db->prepare("
                UPDATE subscriptions 
                SET plan_id = ?, billing_cycle = ?, status = 'active', 
                    current_period_start = ?, current_period_end = ?, 
                    amount_paid = ?, coupon_id = ?
                WHERE id = ?
            ")->execute([$plan['id'], $billingCycle, $startDate, $endDate, $amountPaid, $couponId, $existingSub['id']]);
        } else {
            $this->db->prepare("
                INSERT INTO subscriptions (user_id, plan_id, billing_cycle, status, current_period_start, current_period_end, amount_paid, coupon_id)
                VALUES (?, ?, ?, 'active', ?, ?, ?, ?)
            ")->execute([$userId, $plan['id'], $billingCycle, $startDate, $endDate, $amountPaid, $couponId]);
        }

        // Synchronize users.tier
        $this->db->prepare("UPDATE users SET tier = ? WHERE id = ?")->execute([$plan['slug'], $userId]);

        echo json_encode([
            'success' => true,
            'message' => "Successfully upgraded to {$plan['name']}!",
            'plan' => [
                'slug' => $plan['slug'],
                'name' => $plan['name'],
                'billing_cycle' => $billingCycle,
                'amount_paid' => $amountPaid,
                'period_end' => $endDate
            ]
        ]);
    }

    /**
     * Helper to get or create active subscription record
     */
    private function getOrCreateUserSubscription(int $userId, bool $isAdmin): array
    {
        $stmt = $this->db->prepare("
            SELECT s.id as subscription_id, s.billing_cycle, s.status, s.current_period_start, s.current_period_end, s.amount_paid,
                   sp.id as plan_id, sp.slug as plan_slug, sp.name as plan_name, sp.price_monthly, sp.price_annual,
                   sp.max_transactions, sp.max_invoices, sp.ai_queries_limit, sp.features_json
            FROM subscriptions s
            JOIN subscription_plans sp ON s.plan_id = sp.id
            WHERE s.user_id = ? AND s.status = 'active'
            ORDER BY s.id DESC LIMIT 1
        ");
        $stmt->execute([$userId]);
        $sub = $stmt->fetch();

        if ($sub) {
            return $sub;
        }

        // Auto-provision default subscription
        $targetSlug = $isAdmin ? 'enterprise' : 'free';
        $planStmt = $this->db->prepare("SELECT id FROM subscription_plans WHERE slug = ? LIMIT 1");
        $planStmt->execute([$targetSlug]);
        $planId = (int)($planStmt->fetchColumn() ?: 1);

        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime('+1 month'));

        $this->db->prepare("
            INSERT INTO subscriptions (user_id, plan_id, billing_cycle, status, current_period_start, current_period_end, amount_paid)
            VALUES (?, ?, 'monthly', 'active', ?, ?, 0.00)
        ")->execute([$userId, $planId, $startDate, $endDate]);

        $this->db->prepare("UPDATE users SET tier = ? WHERE id = ?")->execute([$targetSlug, $userId]);

        $stmt->execute([$userId]);
        return $stmt->fetch() ?: [];
    }

    /**
     * Calculates real-time usage for a user
     */
    private function calculateUserUsage(int $userId): array
    {
        // Count transactions / vouchers (count distinct entry_group_id, or count lines if no entry_group_id)
        $txStmt = $this->db->prepare("SELECT COUNT(DISTINCT COALESCE(entry_group_id, 'tx_' || id)) FROM transactions WHERE user_id = ?");
        $txStmt->execute([$userId]);
        $txCount = (int)$txStmt->fetchColumn();

        // Count invoices
        $invStmt = $this->db->prepare("SELECT COUNT(*) FROM invoices WHERE user_id = ?");
        $invStmt->execute([$userId]);
        $invCount = (int)$invStmt->fetchColumn();

        // Count AI queries from activity logs
        $aiStmt = $this->db->prepare("SELECT COUNT(*) FROM web_activity_logs WHERE user_id = ? AND (endpoint LIKE '%/ai/%' OR endpoint LIKE '%/ai')");
        $aiStmt->execute([$userId]);
        $aiCount = (int)$aiStmt->fetchColumn();

        return [
            'transactions' => $txCount,
            'invoices' => $invCount,
            'ai_queries' => $aiCount
        ];
    }

    /**
     * Static check: Can user add another transaction?
     * Returns null if allowed, or error array if limit reached
     */
    public static function checkTransactionLimit(int $userId): ?array
    {
        $db = Database::getConnection();

        // Check if user is admin
        $userStmt = $db->prepare("SELECT role, tier FROM users WHERE id = ? LIMIT 1");
        $userStmt->execute([$userId]);
        $u = $userStmt->fetch();

        if (!$u || ($u['role'] ?? '') === 'admin' || ($u['tier'] ?? '') === 'enterprise') {
            return null;
        }

        // Get plan limits
        $subStmt = $db->prepare("
            SELECT sp.max_transactions, sp.slug, sp.name 
            FROM subscriptions s
            JOIN subscription_plans sp ON s.plan_id = sp.id
            WHERE s.user_id = ? AND s.status = 'active'
            ORDER BY s.id DESC LIMIT 1
        ");
        $subStmt->execute([$userId]);
        $sub = $subStmt->fetch();

        $maxTx = (int)($sub['max_transactions'] ?? 15);
        $planSlug = $sub['slug'] ?? 'free';
        $planName = $sub['name'] ?? 'Free Starter';

        // Count current transactions
        $txStmt = $db->prepare("SELECT COUNT(DISTINCT COALESCE(entry_group_id, 'tx_' || id)) FROM transactions WHERE user_id = ?");
        $txStmt->execute([$userId]);
        $currentTx = (int)$txStmt->fetchColumn();

        if ($currentTx >= $maxTx) {
            return [
                'error' => "{$planName} transaction limit reached ({$currentTx}/{$maxTx}). Upgrade your subscription to continue posting entries.",
                'upgrade_required' => true,
                'limit_type' => 'transactions',
                'current_usage' => $currentTx,
                'max_allowed' => $maxTx,
                'plan' => $planSlug
            ];
        }

        return null;
    }

    /**
     * Static check: Can user create another invoice?
     * Returns null if allowed, or error array if limit reached
     */
    public static function checkInvoiceLimit(int $userId): ?array
    {
        $db = Database::getConnection();

        $userStmt = $db->prepare("SELECT role, tier FROM users WHERE id = ? LIMIT 1");
        $userStmt->execute([$userId]);
        $u = $userStmt->fetch();

        if (!$u || ($u['role'] ?? '') === 'admin' || ($u['tier'] ?? '') === 'enterprise') {
            return null;
        }

        $subStmt = $db->prepare("
            SELECT sp.max_invoices, sp.slug, sp.name 
            FROM subscriptions s
            JOIN subscription_plans sp ON s.plan_id = sp.id
            WHERE s.user_id = ? AND s.status = 'active'
            ORDER BY s.id DESC LIMIT 1
        ");
        $subStmt->execute([$userId]);
        $sub = $subStmt->fetch();

        $maxInv = (int)($sub['max_invoices'] ?? 5);
        $planSlug = $sub['slug'] ?? 'free';
        $planName = $sub['name'] ?? 'Free Starter';

        $invStmt = $db->prepare("SELECT COUNT(*) FROM invoices WHERE user_id = ?");
        $invStmt->execute([$userId]);
        $currentInv = (int)$invStmt->fetchColumn();

        if ($currentInv >= $maxInv) {
            return [
                'error' => "{$planName} invoice limit reached ({$currentInv}/{$maxInv}). Upgrade your subscription to create more tax invoices.",
                'upgrade_required' => true,
                'limit_type' => 'invoices',
                'current_usage' => $currentInv,
                'max_allowed' => $maxInv,
                'plan' => $planSlug
            ];
        }

        return null;
    }

    /**
     * Static check: Can user perform another AI query?
     */
    public static function checkAILimit(int $userId): ?array
    {
        $db = Database::getConnection();

        $userStmt = $db->prepare("SELECT role, tier FROM users WHERE id = ? LIMIT 1");
        $userStmt->execute([$userId]);
        $u = $userStmt->fetch();

        if (!$u || ($u['role'] ?? '') === 'admin' || ($u['tier'] ?? '') === 'enterprise') {
            return null;
        }

        $subStmt = $db->prepare("
            SELECT sp.ai_queries_limit, sp.slug, sp.name 
            FROM subscriptions s
            JOIN subscription_plans sp ON s.plan_id = sp.id
            WHERE s.user_id = ? AND s.status = 'active'
            ORDER BY s.id DESC LIMIT 1
        ");
        $subStmt->execute([$userId]);
        $sub = $subStmt->fetch();

        $maxAI = (int)($sub['ai_queries_limit'] ?? 10);
        $planSlug = $sub['slug'] ?? 'free';
        $planName = $sub['name'] ?? 'Free Starter';

        $aiStmt = $db->prepare("SELECT COUNT(*) FROM web_activity_logs WHERE user_id = ? AND (endpoint LIKE '%/ai/%' OR endpoint LIKE '%/ai')");
        $aiStmt->execute([$userId]);
        $currentAI = (int)$aiStmt->fetchColumn();

        if ($currentAI >= $maxAI) {
            return [
                'error' => "{$planName} AI query limit reached ({$currentAI}/{$maxAI}). Upgrade your subscription for higher AI query volume.",
                'upgrade_required' => true,
                'limit_type' => 'ai_queries',
                'current_usage' => $currentAI,
                'max_allowed' => $maxAI,
                'plan' => $planSlug
            ];
        }

        return null;
    }
}
