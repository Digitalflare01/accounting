<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Middleware\Auth;
use PDO;

class AuthController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * POST /api/auth/login
     */
    public function login(array $requestData): void
    {
        $email = trim($requestData['email'] ?? '');
        $password = $requestData['password'] ?? '';

        if (empty($email) || empty($password)) {
            if (!headers_sent()) { @http_response_code(400); }
            echo json_encode([
                'success' => false,
                'error' => 'Email and password are required.'
            ]);
            return;
        }

        $stmt = $this->db->prepare("SELECT id, email, password_hash, gst_number, business_name, role, status, tier FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            if (!headers_sent()) { @http_response_code(401); }
            echo json_encode([
                'success' => false,
                'error' => 'Invalid email or password.'
            ]);
            return;
        }

        // Check if account has been suspended by an administrator
        if (($user['status'] ?? 'active') === 'suspended') {
            if (!headers_sent()) { @http_response_code(403); }
            echo json_encode([
                'success' => false,
                'error' => 'Your account has been suspended by an administrator. Please contact support.'
            ]);
            return;
        }

        // Update last login timestamp
        try {
            $this->db->prepare("UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$user['id']]);
        } catch (\Throwable $e) {}

        // Ensure active subscription exists or auto-provision
        $activeSub = null;
        try {
            $subStmt = $this->db->prepare("
                SELECT s.id, s.status, s.current_period_end, sp.slug as plan_slug, sp.name as plan_name
                FROM subscriptions s
                JOIN subscription_plans sp ON s.plan_id = sp.id
                WHERE s.user_id = ? AND s.status = 'active'
                ORDER BY s.id DESC LIMIT 1
            ");
            $subStmt->execute([$user['id']]);
            $activeSub = $subStmt->fetch();

            if (!$activeSub) {
                $targetSlug = (($user['role'] ?? '') === 'admin') ? 'enterprise' : ($user['tier'] ?? 'free');
                $pStmt = $this->db->prepare("SELECT id, name, slug FROM subscription_plans WHERE slug = ? LIMIT 1");
                $pStmt->execute([$targetSlug]);
                $planRow = $pStmt->fetch();
                if ($planRow) {
                    $now = date('Y-m-d');
                    $expires = date('Y-m-d', strtotime('+1 month'));
                    $insSub = $this->db->prepare("
                        INSERT INTO subscriptions (user_id, plan_id, status, billing_cycle, current_period_start, current_period_end, amount_paid)
                        VALUES (?, ?, 'active', 'monthly', ?, ?, 0.00)
                    ");
                    $insSub->execute([$user['id'], $planRow['id'], $now, $expires]);
                    $activeSub = [
                        'plan_slug' => $planRow['slug'],
                        'plan_name' => $planRow['name'],
                        'status' => 'active',
                        'current_period_end' => $expires
                    ];
                }
            }
        } catch (\Throwable $e) {}

        $planName = $activeSub['plan_name'] ?? ucfirst($user['tier'] ?? 'Free');
        $tier = $activeSub['plan_slug'] ?? ($user['tier'] ?? 'free');
        $isUnlimited = (($user['role'] ?? '') === 'admin' || $tier === 'enterprise');

        // Generate JWT with role and tier
        $token = Auth::generateToken([
            'sub' => (int)$user['id'],
            'email' => $user['email'],
            'gst_number' => $user['gst_number'],
            'business_name' => $user['business_name'],
            'role' => $user['role'] ?? 'user',
            'status' => $user['status'] ?? 'active',
            'tier' => $tier,
            'plan_name' => $planName,
            'is_unlimited' => $isUnlimited
        ]);

        echo json_encode([
            'success' => true,
            'token' => $token,
            'user' => [
                'id' => (int)$user['id'],
                'email' => $user['email'],
                'gst_number' => $user['gst_number'],
                'business_name' => $businessName ?? $user['business_name'],
                'role' => $user['role'] ?? 'user',
                'status' => $user['status'] ?? 'active',
                'tier' => $tier,
                'plan_name' => $planName,
                'is_unlimited' => $isUnlimited
            ]
        ]);
    }

    /**
     * POST /api/auth/register
     * Registers a new individual user account and business profile
     */
    public function register(array $requestData): void
    {
        $email = strtolower(trim($requestData['email'] ?? ''));
        $password = $requestData['password'] ?? '';
        $businessName = trim($requestData['business_name'] ?? 'My Enterprise');
        $gstNumber = strtoupper(trim($requestData['gst_number'] ?? ''));

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            if (!headers_sent()) { @http_response_code(400); }
            echo json_encode([
                'success' => false,
                'error' => 'A valid email address is required.'
            ]);
            return;
        }

        if (strlen($password) < 6) {
            if (!headers_sent()) { @http_response_code(400); }
            echo json_encode([
                'success' => false,
                'error' => 'Password must be at least 6 characters long.'
            ]);
            return;
        }

        // Check if user already exists
        $stmt = $this->db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            if (!headers_sent()) { @http_response_code(409); }
            echo json_encode([
                'success' => false,
                'error' => 'An account with this email address already exists.'
            ]);
            return;
        }

        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        $passcode = trim($requestData['passcode'] ?? ($requestData['admin_passcode'] ?? ''));
        $role = ($email === 'admin@accounting.local' || $passcode === 'Parayulla@NGK') ? 'admin' : 'user';
        $tier = ($role === 'admin') ? 'enterprise' : 'free';
        $status = 'active';

        try {
            $insertStmt = $this->db->prepare("
                INSERT INTO users (email, password_hash, gst_number, business_name, role, status, tier)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $insertStmt->execute([$email, $passwordHash, $gstNumber ?: null, $businessName, $role, $status, $tier]);
            $userId = (int)$this->db->lastInsertId();

            // Auto-provision initial subscription
            $planName = ($tier === 'enterprise') ? 'Enterprise Elite' : 'Free Starter';
            try {
                $pStmt = $this->db->prepare("SELECT id, name, slug FROM subscription_plans WHERE slug = ? LIMIT 1");
                $pStmt->execute([$tier]);
                $pRow = $pStmt->fetch();
                if ($pRow) {
                    $planName = $pRow['name'];
                    $now = date('Y-m-d');
                    $expires = date('Y-m-d', strtotime('+1 month'));
                    $insSub = $this->db->prepare("
                        INSERT INTO subscriptions (user_id, plan_id, status, billing_cycle, current_period_start, current_period_end, amount_paid)
                        VALUES (?, ?, 'active', 'monthly', ?, ?, 0.00)
                    ");
                    $insSub->execute([$userId, $pRow['id'], $now, $expires]);
                }
            } catch (\Throwable $e) {}

            $isUnlimited = ($role === 'admin' || $tier === 'enterprise');

            // Auto-issue JWT token for immediate access
            $token = Auth::generateToken([
                'sub' => $userId,
                'email' => $email,
                'gst_number' => $gstNumber,
                'business_name' => $businessName,
                'role' => $role,
                'status' => $status,
                'tier' => $tier,
                'plan_name' => $planName,
                'is_unlimited' => $isUnlimited
            ]);

            if (!headers_sent()) { @http_response_code(201); }
            echo json_encode([
                'success' => true,
                'message' => 'Account registered successfully.',
                'token' => $token,
                'user' => [
                    'id' => $userId,
                    'email' => $email,
                    'gst_number' => $gstNumber,
                    'business_name' => $businessName,
                    'role' => $role,
                    'status' => $status,
                    'tier' => $tier,
                    'plan_name' => $planName,
                    'is_unlimited' => $isUnlimited
                ]
            ]);
        } catch (\Exception $e) {
            if (!headers_sent()) { @http_response_code(500); }
            echo json_encode([
                'success' => false,
                'error' => 'Registration failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * PUT /api/auth/profile
     * Updates business profile, details, logo, and bank accounts for the authenticated user
     */
    public function updateProfile(array $requestData): void
    {
        $payload = Auth::requireAuth();
        $userId = (int)$payload['sub'];

        $businessName   = trim($requestData['business_name'] ?? '');
        $gstNumber      = strtoupper(trim($requestData['gst_number'] ?? ''));
        $ownerName      = trim($requestData['owner_name'] ?? '');
        $phone          = trim($requestData['phone'] ?? '');
        $address        = trim($requestData['address'] ?? '');
        $city           = trim($requestData['city'] ?? '');
        $state          = trim($requestData['state'] ?? '');
        $pincode        = trim($requestData['pincode'] ?? '');
        $panNumber      = strtoupper(trim($requestData['pan_number'] ?? ''));
        $bankName       = trim($requestData['bank_name'] ?? '');
        $bankAccountNo  = trim($requestData['bank_account_no'] ?? '');
        $bankIfsc       = strtoupper(trim($requestData['bank_ifsc'] ?? ''));
        $bankBranch     = trim($requestData['bank_branch'] ?? '');
        $upiId          = trim($requestData['upi_id'] ?? '');
        $logoData       = $requestData['logo_data'] ?? null;
        $invoiceTerms   = trim($requestData['invoice_terms'] ?? '');
        $signatureTitle = trim($requestData['signature_title'] ?? '');

        $stmt = $this->db->prepare("
            UPDATE users SET 
                business_name = ?, 
                gst_number = ?,
                owner_name = ?,
                phone = ?,
                address = ?,
                city = ?,
                state = ?,
                pincode = ?,
                pan_number = ?,
                bank_name = ?,
                bank_account_no = ?,
                bank_ifsc = ?,
                bank_branch = ?,
                upi_id = ?,
                logo_data = ?,
                invoice_terms = ?,
                signature_title = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $businessName ?: 'My Enterprise',
            $gstNumber ?: null,
            $ownerName ?: null,
            $phone ?: null,
            $address ?: null,
            $city ?: null,
            $state ?: null,
            $pincode ?: null,
            $panNumber ?: null,
            $bankName ?: null,
            $bankAccountNo ?: null,
            $bankIfsc ?: null,
            $bankBranch ?: null,
            $upiId ?: null,
            $logoData ?: null,
            $invoiceTerms ?: null,
            $signatureTitle ?: null,
            $userId
        ]);

        $fetchStmt = $this->db->prepare("
            SELECT id, email, gst_number, business_name, role, status, tier,
                   owner_name, phone, address, city, state, pincode, pan_number,
                   bank_name, bank_account_no, bank_ifsc, bank_branch, upi_id,
                   logo_data, invoice_terms, signature_title, last_login_at, created_at
            FROM users WHERE id = ? LIMIT 1
        ");
        $fetchStmt->execute([$userId]);
        $user = $fetchStmt->fetch();

        echo json_encode([
            'success' => true,
            'message' => 'Business profile updated successfully.',
            'user' => $user
        ]);
    }

    /**
     * GET /api/auth/profile
     * Returns the full business profile for the authenticated user
     */
    public function getProfile(): void
    {
        $this->me();
    }

    /**
     * GET /api/auth/me
     */
    public function me(): void
    {
        $payload = Auth::requireAuth();
        
        $stmt = $this->db->prepare("
            SELECT id, email, gst_number, business_name, role, status, tier,
                   owner_name, phone, address, city, state, pincode, pan_number,
                   bank_name, bank_account_no, bank_ifsc, bank_branch, upi_id,
                   logo_data, invoice_terms, signature_title, last_login_at, created_at
            FROM users WHERE id = ? LIMIT 1
        ");
        $stmt->execute([$payload['sub']]);
        $user = $stmt->fetch();

        if (!$user) {
            if (!headers_sent()) { @http_response_code(404); }
            echo json_encode(['success' => false, 'error' => 'User not found']);
            return;
        }

        // Fetch subscription info
        $subStmt = $this->db->prepare("
            SELECT s.id, s.status, s.current_period_end, sp.slug as plan_slug, sp.name as plan_name
            FROM subscriptions s
            JOIN subscription_plans sp ON s.plan_id = sp.id
            WHERE s.user_id = ? AND s.status = 'active'
            ORDER BY s.id DESC LIMIT 1
        ");
        $subStmt->execute([$user['id']]);
        $sub = $subStmt->fetch();

        $user['plan_name'] = $sub['plan_name'] ?? ucfirst($user['tier'] ?? 'Free');
        $user['tier'] = $sub['plan_slug'] ?? ($user['tier'] ?? 'free');
        $user['is_unlimited'] = (($user['role'] ?? '') === 'admin' || ($user['tier'] ?? '') === 'enterprise');

        echo json_encode([
            'success' => true,
            'user' => $user
        ]);
    }

    /**
     * POST /api/auth/admin-register
     * Registers a new administrator account requiring the master passcode 'Parayulla@NGK'
     */
    public function registerAdmin(array $requestData): void
    {
        $email = strtolower(trim($requestData['email'] ?? ''));
        $password = $requestData['password'] ?? '';
        $businessName = trim($requestData['business_name'] ?? 'Admin Console');
        $passcode = trim($requestData['passcode'] ?? ($requestData['admin_passcode'] ?? ''));

        // Validate master passcode
        if ($passcode !== 'Parayulla@NGK') {
            if (!headers_sent()) { @http_response_code(403); }
            echo json_encode([
                'success' => false,
                'error' => 'Invalid administrator master passcode. Access denied.'
            ]);
            return;
        }

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            if (!headers_sent()) { @http_response_code(400); }
            echo json_encode([
                'success' => false,
                'error' => 'A valid administrator email address is required.'
            ]);
            return;
        }

        if (strlen($password) < 6) {
            if (!headers_sent()) { @http_response_code(400); }
            echo json_encode([
                'success' => false,
                'error' => 'Password must be at least 6 characters long.'
            ]);
            return;
        }

        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        $role = 'admin';
        $status = 'active';
        $tier = 'enterprise';

        // Check if user already exists
        $stmt = $this->db->prepare("SELECT id, role FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $existing = $stmt->fetch();

        if ($existing) {
            if (($existing['role'] ?? '') === 'admin') {
                if (!headers_sent()) { @http_response_code(409); }
                echo json_encode([
                    'success' => false,
                    'error' => 'An administrator account with this email already exists.'
                ]);
                return;
            }

            // Upgrade existing user to administrator
            try {
                $upStmt = $this->db->prepare("
                    UPDATE users 
                    SET role = 'admin', status = 'active', tier = 'enterprise', password_hash = ?, business_name = COALESCE(NULLIF(?, ''), business_name)
                    WHERE id = ?
                ");
                $upStmt->execute([$passwordHash, $businessName, $existing['id']]);
                $userId = (int)$existing['id'];
            } catch (\Exception $e) {
                if (!headers_sent()) { @http_response_code(500); }
                echo json_encode([
                    'success' => false,
                    'error' => 'Failed to upgrade account to administrator: ' . $e->getMessage()
                ]);
                return;
            }
        } else {
            // Insert new administrator
            try {
                $insertStmt = $this->db->prepare("
                    INSERT INTO users (email, password_hash, business_name, role, status, tier)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $insertStmt->execute([$email, $passwordHash, $businessName, $role, $status, $tier]);
                $userId = (int)$this->db->lastInsertId();
            } catch (\Exception $e) {
                if (!headers_sent()) { @http_response_code(500); }
                echo json_encode([
                    'success' => false,
                    'error' => 'Administrator registration failed: ' . $e->getMessage()
                ]);
                return;
            }
        }

        // Auto-provision Enterprise subscription
        $planName = 'Enterprise Elite';
        try {
            $pStmt = $this->db->prepare("SELECT id, name, slug FROM subscription_plans WHERE slug = 'enterprise' LIMIT 1");
            $pStmt->execute();
            $pRow = $pStmt->fetch();
            if ($pRow) {
                $planName = $pRow['name'];
                $now = date('Y-m-d');
                $expires = date('Y-m-d', strtotime('+5 years'));
                // Deactivate any old subscriptions
                $this->db->prepare("UPDATE subscriptions SET status = 'canceled' WHERE user_id = ?")->execute([$userId]);
                $insSub = $this->db->prepare("
                    INSERT INTO subscriptions (user_id, plan_id, status, billing_cycle, current_period_start, current_period_end, amount_paid)
                    VALUES (?, ?, 'active', 'annual', ?, ?, 0.00)
                ");
                $insSub->execute([$userId, $pRow['id'], $now, $expires]);
            }
        } catch (\Throwable $e) {}

        // Auto-issue JWT token with admin role
        $token = Auth::generateToken([
            'sub' => $userId,
            'email' => $email,
            'business_name' => $businessName,
            'role' => 'admin',
            'status' => 'active',
            'tier' => 'enterprise',
            'plan_name' => $planName,
            'is_unlimited' => true
        ]);

        if (!headers_sent()) { @http_response_code(201); }
        echo json_encode([
            'success' => true,
            'message' => 'Administrator account registered successfully.',
            'token' => $token,
            'user' => [
                'id' => $userId,
                'email' => $email,
                'business_name' => $businessName,
                'role' => 'admin',
                'status' => 'active',
                'tier' => 'enterprise',
                'plan_name' => $planName,
                'is_unlimited' => true
            ]
        ]);
    }
}
