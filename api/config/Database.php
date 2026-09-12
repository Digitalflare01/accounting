<?php
declare(strict_types=1);

namespace App\Config;

use PDO;
use PDOException;

/**
 * Database Connection Manager
 * Provides singleton PDO instance with support for MySQL (WAMP) and automatic
 * SQLite fallback for seamless portable testing and zero-configuration execution.
 * Includes idempotent automatic migrations for Enterprise Admin, Telemetry & Subscriptions.
 */
class Database
{
    private static ?PDO $instance = null;
    private static string $driverUsed = '';

    public static function getConnection(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $mysqlHost = getenv('DB_HOST') ?: '127.0.0.1';
        $mysqlPort = getenv('DB_PORT') ?: '3306';
        $mysqlDb   = getenv('DB_NAME') ?: 'accounting_db';
        $mysqlUser = getenv('DB_USER') ?: 'root';
        $mysqlPass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';

        // Attempt 1: Connect to MySQL / MariaDB
        try {
            $dsn = "mysql:host={$mysqlHost};port={$mysqlPort};dbname={$mysqlDb};charset=utf8mb4";
            $pdo = new PDO($dsn, $mysqlUser, $mysqlPass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            self::$instance = $pdo;
            self::$driverUsed = 'mysql';
            self::runPendingMigrations($pdo);
            return self::$instance;
        } catch (PDOException $e) {
            // Fallback to SQLite for local tests & environments without active MySQL
            $sqlitePath = dirname(__DIR__, 2) . '/database/accounting.sqlite';
            $sqliteDsn = "sqlite:{$sqlitePath}";
            
            $pdo = new PDO($sqliteDsn, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec("PRAGMA foreign_keys = ON;");
            self::$instance = $pdo;
            self::$driverUsed = 'sqlite';

            // Auto-initialize SQLite base schema if newly created
            self::initializeSqliteSchema($pdo);
            // Run latest admin migrations
            self::runPendingMigrations($pdo);

            return self::$instance;
        }
    }

    public static function getDriver(): string
    {
        return self::$driverUsed;
    }

    /**
     * Initializes SQLite schema and seeds standard accounting ledger if empty
     */
    private static function initializeSqliteSchema(PDO $pdo): void
    {
        $check = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetch();
        if ($check) {
            return;
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                gst_number TEXT,
                business_name TEXT DEFAULT 'My Enterprise',
                role TEXT DEFAULT 'user',
                status TEXT DEFAULT 'active',
                tier TEXT DEFAULT 'free',
                last_login_at DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS chart_of_accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                code TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                type TEXT NOT NULL CHECK(type IN ('asset', 'liability', 'equity', 'revenue', 'expense')),
                gst_applicable INTEGER NOT NULL DEFAULT 0,
                description TEXT,
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                account_id INTEGER NOT NULL,
                type TEXT NOT NULL CHECK(type IN ('credit', 'debit')),
                amount REAL NOT NULL,
                date TEXT NOT NULL,
                description TEXT NOT NULL,
                gst_amount REAL NOT NULL DEFAULT 0.0,
                cgst REAL NOT NULL DEFAULT 0.0,
                sgst REAL NOT NULL DEFAULT 0.0,
                igst REAL NOT NULL DEFAULT 0.0,
                supply_type TEXT NOT NULL DEFAULT 'inward' CHECK(supply_type IN ('inward', 'outward')),
                status TEXT NOT NULL DEFAULT 'posted' CHECK(status IN ('pending', 'posted', 'flagged', 'void')),
                raw_ai_input TEXT,
                entry_group_id TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (account_id) REFERENCES chart_of_accounts(id)
            );
        ");

        // Seed default user
        $pdo->exec("
            INSERT INTO users (id, email, password_hash, gst_number, business_name, role, status, tier, last_login_at)
            VALUES (1, 'admin@accounting.local', '$2y$10$4yfVVH9eyh1r0U6Kryt3yurIGzUROvnQeQh8zO5z.pjZU7zJiaDzK', '27ABCDE1234F1Z5', 'Apex Technologies & Advisory LLP', 'admin', 'active', 'enterprise', datetime('now'));
        ");

        // Seed Chart of Accounts
        $pdo->exec("
            INSERT INTO chart_of_accounts (id, code, name, type, gst_applicable, description) VALUES
            (1,  '1010', 'Cash and Cash Equivalents',      'asset',     0, 'Liquid currency and petty cash reserves'),
            (2,  '1020', 'Bank Operating Account',         'asset',     0, 'Current bank account for business operations'),
            (3,  '1100', 'Accounts Receivable (Debtors)',  'asset',     0, 'Invoices billed to clients awaiting settlement'),
            (4,  '1510', 'Fixed Asset - Computers',        'asset',     1, 'Hardware, servers, laptops, and IT infrastructure'),
            (5,  '1520', 'Office Equipment & Furniture',   'asset',     1, 'Office desks, air conditioners, furniture'),
            (6,  '1710', 'Input CGST (Tax Credit)',        'asset',     0, 'Central GST Input Tax Credit receivable'),
            (7,  '1720', 'Input SGST (Tax Credit)',        'asset',     0, 'State GST Input Tax Credit receivable'),
            (8,  '1730', 'Input IGST (Tax Credit)',        'asset',     0, 'Integrated GST Input Tax Credit receivable'),
            (9,  '2010', 'Accounts Payable (Creditors)',   'liability', 0, 'Supplier invoices payable'),
            (10, '2110', 'Output CGST (Tax Payable)',      'liability', 0, 'Central GST collected on taxable supplies'),
            (11, '2120', 'Output SGST (Tax Payable)',      'liability', 0, 'State GST collected on taxable supplies'),
            (12, '2130', 'Output IGST (Tax Payable)',      'liability', 0, 'Integrated GST collected on interstate supplies'),
            (13, '2200', 'Working Capital Loan',          'liability', 0, 'Short-term credit line from bank'),
            (14, '3010', 'Owner Capital',                  'equity',    0, 'Initial capital infused by founding partners'),
            (15, '3020', 'Retained Earnings',              'equity',    0, 'Cumulative net profits retained in business'),
            (16, '4010', 'Software Development Services',  'revenue',   1, 'SaaS, custom engineering, and technology services'),
            (17, '4020', 'Consulting Income',              'revenue',   1, 'Advisory, financial, and strategic consulting fees'),
            (18, '4030', 'Sales of Hardware & Goods',      'revenue',   1, 'Direct wholesale or retail supply of products'),
            (19, '5010', 'Office Expense',                 'expense',   1, 'General office operating expenses, tea, stationery'),
            (20, '5020', 'Office Rent',                    'expense',   1, 'Commercial office premise leasing charges'),
            (21, '5030', 'Software Subscriptions & Cloud', 'expense',   1, 'AWS, GCP, GitHub, Slack, and digital tool fees'),
            (22, '5040', 'Travel & Conveyance',            'expense',   1, 'Client site visits, airfare, local transit'),
            (23, '5050', 'Legal & Professional Fees',      'expense',   1, 'Auditor, accounting, and compliance retainers'),
            (24, '5060', 'Utility Bills',                  'expense',   0, 'Electricity, broadband, and maintenance'),
            (25, '5070', 'Depreciation & Amortization Expense', 'expense', 0, 'Periodic write-off of tangible and intangible capital assets'),
            (26, '1590', 'Accumulated Depreciation - Assets', 'asset',     0, 'Contra-asset account tracking cumulative asset depreciation');
        ");

        // Seed Initial Balanced Double-Entry Transactions
        $pdo->exec("
            INSERT INTO transactions (id, user_id, account_id, type, amount, date, description, gst_amount, cgst, sgst, igst, supply_type, status, raw_ai_input, entry_group_id) VALUES
            (1,  1, 14, 'credit', 500000.00, '2026-08-01', 'Initial Partner Capital contribution', 0.00, 0.00, 0.00, 0.00, 'inward', 'posted', 'Infused capital 5 lakhs into business account', 'grp_seed_001'),
            (2,  1, 2,  'debit',  500000.00, '2026-08-01', 'Bank Deposit: Capital infusion', 0.00, 0.00, 0.00, 0.00, 'inward', 'posted', NULL, 'grp_seed_001'),
            (3,  1, 17, 'credit', 200000.00, '2026-08-10', 'Corporate Advisory Services provided to Zeta Corp', 36000.00, 18000.00, 18000.00, 0.00, 'outward', 'posted', 'Billed client 2 lakhs plus 18 percent GST for advisory', 'grp_seed_002'),
            (4,  1, 2,  'debit',  200000.00, '2026-08-10', 'Bank Receipt: Corporate Advisory settlement', 0.00, 0.00, 0.00, 0.00, 'inward', 'posted', NULL, 'grp_seed_002'),
            (5,  1, 16, 'credit', 150000.00, '2026-08-20', 'Custom Fullstack Portal Development for Bluefin Ltd', 27000.00, 0.00, 0.00, 27000.00, 'outward', 'posted', 'Received 1.5 lakhs from interstate client for software build', 'grp_seed_003'),
            (6,  1, 2,  'debit',  150000.00, '2026-08-20', 'Bank Receipt: Software portal settlement', 0.00, 0.00, 0.00, 0.00, 'inward', 'posted', NULL, 'grp_seed_003'),
            (7,  1, 4,  'debit',  100000.00, '2026-08-25', 'Apple MacBook Pro M3 Max 36GB RAM Development Workstation', 18000.00, 9000.00, 9000.00, 0.00, 'inward', 'posted', 'Bought a Macbook for 1 lakh', 'grp_seed_004'),
            (8,  1, 2,  'credit', 100000.00, '2026-08-25', 'Bank Payment: Apple Store Workstation', 0.00, 0.00, 0.00, 0.00, 'inward', 'posted', NULL, 'grp_seed_004'),
            (9,  1, 20, 'debit',   45000.00, '2026-09-01', 'September 2026 Office Rent for Bandra Kurla Complex Suite', 8100.00, 4050.00, 4050.00, 0.00, 'inward', 'posted', 'Paid office rent 45000 with 18% GST', 'grp_seed_005'),
            (10, 1, 2,  'credit',  45000.00, '2026-09-01', 'Bank Payment: Commercial Lease September', 0.00, 0.00, 0.00, 0.00, 'inward', 'posted', NULL, 'grp_seed_005'),
            (11, 1, 21, 'debit',   18000.00, '2026-09-05', 'AWS and Google Cloud Platform monthly server bills', 3240.00, 0.00, 0.00, 3240.00, 'inward', 'posted', 'Paid cloud hosting bills 18000 interstate', 'grp_seed_006'),
            (12, 1, 2,  'credit',  18000.00, '2026-09-05', 'Bank Payment: AWS & GCP Cloud Servers', 0.00, 0.00, 0.00, 0.00, 'inward', 'posted', NULL, 'grp_seed_006');
        ");
    }

    /**
     * Idempotent migration runner that ensures all admin tables and columns exist
     */
    private static function runPendingMigrations(PDO $pdo): void
    {
        $driver = self::$driverUsed;

        // Ensure transactions table has entry_group_id column
        self::ensureColumnExists($pdo, 'transactions', 'entry_group_id', "TEXT NULL", "VARCHAR(64) NULL");

        // Backfill entry_group_id for seed pairs if missing
        try {
            $pdo->exec("UPDATE transactions SET entry_group_id = 'grp_seed_001' WHERE id IN (1, 2) AND (entry_group_id IS NULL OR entry_group_id = '')");
            $pdo->exec("UPDATE transactions SET entry_group_id = 'grp_seed_002' WHERE id IN (3, 4) AND (entry_group_id IS NULL OR entry_group_id = '')");
            $pdo->exec("UPDATE transactions SET entry_group_id = 'grp_seed_003' WHERE id IN (5, 6) AND (entry_group_id IS NULL OR entry_group_id = '')");
            $pdo->exec("UPDATE transactions SET entry_group_id = 'grp_seed_004' WHERE id IN (7, 8) AND (entry_group_id IS NULL OR entry_group_id = '')");
            $pdo->exec("UPDATE transactions SET entry_group_id = 'grp_seed_005' WHERE id IN (9, 10) AND (entry_group_id IS NULL OR entry_group_id = '')");
            $pdo->exec("UPDATE transactions SET entry_group_id = 'grp_seed_006' WHERE id IN (11, 12) AND (entry_group_id IS NULL OR entry_group_id = '')");
        } catch (\Throwable $e) {
            // Ignore if schema in transition
        }

        // 1. Ensure user table has role, status, tier, and last_login_at columns
        self::ensureColumnExists($pdo, 'users', 'role', "TEXT DEFAULT 'user'", "ENUM('admin', 'user') NOT NULL DEFAULT 'user'");
        self::ensureColumnExists($pdo, 'users', 'status', "TEXT DEFAULT 'active'", "ENUM('active', 'suspended', 'pending') NOT NULL DEFAULT 'active'");
        self::ensureColumnExists($pdo, 'users', 'tier', "TEXT DEFAULT 'free'", "ENUM('free', 'starter', 'pro', 'enterprise') NOT NULL DEFAULT 'free'");
        self::ensureColumnExists($pdo, 'users', 'last_login_at', "DATETIME", "DATETIME NULL DEFAULT NULL");

        // Make user 1 admin
        $pdo->exec("UPDATE users SET role = 'admin', status = 'active', tier = 'enterprise' WHERE id = 1 AND (role IS NULL OR role != 'admin')");

        // 2. Web Activity Logs Table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS web_activity_logs (
                id " . ($driver === 'sqlite' ? "INTEGER PRIMARY KEY AUTOINCREMENT" : "BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY") . ",
                user_id " . ($driver === 'sqlite' ? "INTEGER" : "BIGINT UNSIGNED NULL") . ",
                ip_address VARCHAR(45) NOT NULL DEFAULT '127.0.0.1',
                method VARCHAR(10) NOT NULL,
                endpoint VARCHAR(255) NOT NULL,
                status_code INT NOT NULL DEFAULT 200,
                response_time_ms " . ($driver === 'sqlite' ? "REAL" : "DECIMAL(8, 2)") . " NOT NULL DEFAULT 0.00,
                user_agent TEXT,
                created_at " . ($driver === 'sqlite' ? "DATETIME DEFAULT CURRENT_TIMESTAMP" : "TIMESTAMP DEFAULT CURRENT_TIMESTAMP") . "
            );
        ");

        // 3. Subscription Plans Table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS subscription_plans (
                id " . ($driver === 'sqlite' ? "INTEGER PRIMARY KEY AUTOINCREMENT" : "INT UNSIGNED AUTO_INCREMENT PRIMARY KEY") . ",
                slug VARCHAR(50) NOT NULL UNIQUE,
                name VARCHAR(100) NOT NULL,
                price_monthly " . ($driver === 'sqlite' ? "REAL" : "DECIMAL(10, 2)") . " NOT NULL DEFAULT 0.00,
                price_annual " . ($driver === 'sqlite' ? "REAL" : "DECIMAL(10, 2)") . " NOT NULL DEFAULT 0.00,
                max_transactions INT NOT NULL DEFAULT 100,
                ai_queries_limit INT NOT NULL DEFAULT 50,
                features_json TEXT,
                is_active " . ($driver === 'sqlite' ? "INTEGER" : "TINYINT(1)") . " NOT NULL DEFAULT 1,
                created_at " . ($driver === 'sqlite' ? "DATETIME DEFAULT CURRENT_TIMESTAMP" : "TIMESTAMP DEFAULT CURRENT_TIMESTAMP") . "
            );
        ");

        // 4. Subscriptions Table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS subscriptions (
                id " . ($driver === 'sqlite' ? "INTEGER PRIMARY KEY AUTOINCREMENT" : "BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY") . ",
                user_id " . ($driver === 'sqlite' ? "INTEGER" : "BIGINT UNSIGNED") . " NOT NULL,
                plan_id " . ($driver === 'sqlite' ? "INTEGER" : "INT UNSIGNED") . " NOT NULL,
                billing_cycle " . ($driver === 'sqlite' ? "TEXT DEFAULT 'monthly'" : "ENUM('monthly', 'annual') NOT NULL DEFAULT 'monthly'") . ",
                status " . ($driver === 'sqlite' ? "TEXT DEFAULT 'active'" : "ENUM('active', 'trial', 'past_due', 'canceled') NOT NULL DEFAULT 'active'") . ",
                current_period_start DATE NOT NULL,
                current_period_end DATE NOT NULL,
                amount_paid " . ($driver === 'sqlite' ? "REAL" : "DECIMAL(10, 2)") . " NOT NULL DEFAULT 0.00,
                coupon_id " . ($driver === 'sqlite' ? "INTEGER" : "INT UNSIGNED NULL") . ",
                created_at " . ($driver === 'sqlite' ? "DATETIME DEFAULT CURRENT_TIMESTAMP" : "TIMESTAMP DEFAULT CURRENT_TIMESTAMP") . "
            );
        ");

        // 5. Coupons Table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS coupons (
                id " . ($driver === 'sqlite' ? "INTEGER PRIMARY KEY AUTOINCREMENT" : "INT UNSIGNED AUTO_INCREMENT PRIMARY KEY") . ",
                code VARCHAR(50) NOT NULL UNIQUE,
                discount_type " . ($driver === 'sqlite' ? "TEXT DEFAULT 'percentage'" : "ENUM('percentage', 'fixed') NOT NULL DEFAULT 'percentage'") . ",
                discount_value " . ($driver === 'sqlite' ? "REAL" : "DECIMAL(10, 2)") . " NOT NULL,
                min_order_amount " . ($driver === 'sqlite' ? "REAL" : "DECIMAL(10, 2)") . " NOT NULL DEFAULT 0.00,
                max_uses INT NOT NULL DEFAULT 100,
                used_count INT NOT NULL DEFAULT 0,
                valid_from DATE NOT NULL,
                valid_until DATE NOT NULL,
                is_active " . ($driver === 'sqlite' ? "INTEGER" : "TINYINT(1)") . " NOT NULL DEFAULT 1,
                created_at " . ($driver === 'sqlite' ? "DATETIME DEFAULT CURRENT_TIMESTAMP" : "TIMESTAMP DEFAULT CURRENT_TIMESTAMP") . "
            );
        ");

        // 6. Coupon Redemptions Table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS coupon_redemptions (
                id " . ($driver === 'sqlite' ? "INTEGER PRIMARY KEY AUTOINCREMENT" : "BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY") . ",
                coupon_id " . ($driver === 'sqlite' ? "INTEGER" : "INT UNSIGNED") . " NOT NULL,
                user_id " . ($driver === 'sqlite' ? "INTEGER" : "BIGINT UNSIGNED") . " NOT NULL,
                discount_applied " . ($driver === 'sqlite' ? "REAL" : "DECIMAL(10, 2)") . " NOT NULL,
                order_amount " . ($driver === 'sqlite' ? "REAL" : "DECIMAL(10, 2)") . " NOT NULL,
                redeemed_at " . ($driver === 'sqlite' ? "DATETIME DEFAULT CURRENT_TIMESTAMP" : "TIMESTAMP DEFAULT CURRENT_TIMESTAMP") . "
            );
        ");

        // 7. System Settings & Announcements Table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS system_settings (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_value TEXT,
                updated_at " . ($driver === 'sqlite' ? "DATETIME DEFAULT CURRENT_TIMESTAMP" : "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP") . "
            );
        ");

        self::ensureColumnExists($pdo, 'subscription_plans', 'max_invoices', "INT NOT NULL DEFAULT 5", "INT NOT NULL DEFAULT 5");

        // Seed or update Plans with calibrated limits and features
        $plansConfig = [
            [
                'id' => 1,
                'slug' => 'free',
                'name' => 'Free Starter',
                'price_monthly' => 0.00,
                'price_annual' => 0.00,
                'max_transactions' => 15,
                'max_invoices' => 5,
                'ai_queries_limit' => 10,
                'features' => json_encode([
                    "15 Double-Entry Ledger Transactions",
                    "5 GST Tax Invoices & Print Preview",
                    "10 AI Accounting & Depreciation Queries",
                    "Trial Balance, P&L & Balance Sheet",
                    "General Ledger & Accounting Principles",
                    "Community & Documentation Access"
                ]),
                'is_active' => 1
            ],
            [
                'id' => 2,
                'slug' => 'starter',
                'name' => 'Starter Solo',
                'price_monthly' => 499.00,
                'price_annual' => 4990.00,
                'max_transactions' => 300,
                'max_invoices' => 100,
                'ai_queries_limit' => 150,
                'features' => json_encode([
                    "Up to 300 Transactions / Month",
                    "Up to 100 Tax Invoices with PDF & Print",
                    "150 AI Accounting Queries",
                    "GSTR-1 & GSTR-3B Tax Summary Reports",
                    "Automated Asset Depreciation Solver",
                    "Email Support (24h response)"
                ]),
                'is_active' => 1
            ],
            [
                'id' => 3,
                'slug' => 'pro',
                'name' => 'Pro Accountant',
                'price_monthly' => 1499.00,
                'price_annual' => 14990.00,
                'max_transactions' => 3000,
                'max_invoices' => 1000,
                'ai_queries_limit' => 1500,
                'features' => json_encode([
                    "Up to 3,000 Transactions / Month",
                    "Up to 1,000 Invoices with Custom Terms & Logo",
                    "1,500 AI Automation & Case Study Queries",
                    "Full Compound Journal Entry Engine",
                    "Ind AS 7 Cash Flow & Balance Sheet Schedules",
                    "GSTR Export & Reconciliation",
                    "Priority Phone & Email Support"
                ]),
                'is_active' => 1
            ],
            [
                'id' => 4,
                'slug' => 'enterprise',
                'name' => 'Enterprise Tax Prep',
                'price_monthly' => 3999.00,
                'price_annual' => 39990.00,
                'max_transactions' => 999999,
                'max_invoices' => 999999,
                'ai_queries_limit' => 999999,
                'features' => json_encode([
                    "Unlimited Transactions & Vouchers",
                    "Unlimited Tax Invoices & Client Billing",
                    "Unlimited Gemini 3 Flash AI Ingestion",
                    "Multi-Branch & Role-Based Governance",
                    "Direct GST Portal & WhatsApp Dispatch",
                    "Dedicated Account Manager & 24/7 Escalation"
                ]),
                'is_active' => 1
            ]
        ];

        foreach ($plansConfig as $p) {
            $check = $pdo->prepare("SELECT id FROM subscription_plans WHERE slug = ? OR id = ?");
            $check->execute([$p['slug'], $p['id']]);
            if ($check->fetch()) {
                $up = $pdo->prepare("
                    UPDATE subscription_plans 
                    SET name = ?, price_monthly = ?, price_annual = ?, 
                        max_transactions = ?, max_invoices = ?, ai_queries_limit = ?, 
                        features_json = ?, is_active = ?
                    WHERE slug = ?
                ");
                $up->execute([
                    $p['name'], $p['price_monthly'], $p['price_annual'],
                    $p['max_transactions'], $p['max_invoices'], $p['ai_queries_limit'],
                    $p['features'], $p['is_active'], $p['slug']
                ]);
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO subscription_plans (id, slug, name, price_monthly, price_annual, max_transactions, max_invoices, ai_queries_limit, features_json, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $ins->execute([
                    $p['id'], $p['slug'], $p['name'], $p['price_monthly'], $p['price_annual'],
                    $p['max_transactions'], $p['max_invoices'], $p['ai_queries_limit'], $p['features'], $p['is_active']
                ]);
            }
        }

        // Auto-provision subscriptions for all users missing active subscriptions
        try {
            $unsubUsers = $pdo->query("
                SELECT u.id, u.role, u.tier FROM users u
                LEFT JOIN subscriptions s ON u.id = s.user_id AND s.status = 'active'
                WHERE s.id IS NULL
            ")->fetchAll(\PDO::FETCH_ASSOC);

            $startDate = date('Y-m-d');
            $endDate = date('Y-m-d', strtotime('+1 month'));

            foreach ($unsubUsers as $u) {
                $planSlug = ($u['role'] === 'admin') ? 'enterprise' : 'free';
                $planStmt = $pdo->prepare("SELECT id FROM subscription_plans WHERE slug = ? LIMIT 1");
                $planStmt->execute([$planSlug]);
                $pId = (int)($planStmt->fetchColumn() ?: 1);

                $pdo->prepare("
                    INSERT INTO subscriptions (user_id, plan_id, billing_cycle, status, current_period_start, current_period_end, amount_paid)
                    VALUES (?, ?, 'monthly', 'active', ?, ?, 0.00)
                ")->execute([$u['id'], $pId, $startDate, $endDate]);

                $pdo->prepare("UPDATE users SET tier = ? WHERE id = ?")->execute([$planSlug, $u['id']]);
            }
        } catch (\Throwable $e) {}

        // Seed Coupons if empty
        $couponCount = (int)$pdo->query("SELECT COUNT(*) FROM coupons")->fetchColumn();
        if ($couponCount === 0) {
            $pdo->exec("
                INSERT INTO coupons (id, code, discount_type, discount_value, min_order_amount, max_uses, used_count, valid_from, valid_until, is_active)
                VALUES
                (1, 'WELCOME50', 'percentage', 50.00, 500.00, 500, 42, '2026-01-01', '2026-12-31', 1),
                (2, 'TAXPRO2026', 'percentage', 25.00, 1000.00, 200, 18, '2026-01-01', '2026-12-31', 1),
                (3, 'FLAT500',    'fixed',      500.00, 1500.00, 100, 9,  '2026-06-01', '2026-11-30', 1),
                (4, 'DIWALI2026', 'percentage', 30.00, 799.00,  1000, 0,  '2026-10-15', '2026-11-15', 1),
                (5, 'EARLYBIRD',  'percentage', 100.00, 0.00,    50,   50, '2026-01-01', '2026-06-30', 0);
            ");
        }

        // Seed Settings if empty
        $defaultSettings = [
            'announcement_text' => 'FY 2026-27 Tax Notice: Complete quarterly GST reconciliation for inward input credits prior to the 11th.',
            'announcement_active' => '1',
            'maintenance_mode' => '0',
            'support_email' => 'admin@accounting.local',
            
            // Mail Authentication API Settings
            'mail_provider' => 'smtp',
            'mail_smtp_host' => 'smtp.gmail.com',
            'mail_smtp_port' => '587',
            'mail_smtp_user' => 'notifications@apexaccounting.io',
            'mail_smtp_pass' => 'app_secret_pass_123',
            'mail_smtp_encryption' => 'tls',
            'mail_from_address' => 'auth@apexaccounting.io',
            'mail_from_name' => 'ApexLedger AI Authentication',
            'mail_auth_enabled' => '1',

            // WhatsApp Authentication API Settings
            'whatsapp_provider' => 'meta_cloud',
            'whatsapp_api_token' => 'EAAG_SAMPLE_WHATSAPP_TOKEN_SECRET_9876',
            'whatsapp_phone_number_id' => '109876543210987',
            'whatsapp_waba_id' => '987654321098765',
            'whatsapp_otp_template' => 'apex_auth_otp_verification',
            'whatsapp_auth_enabled' => '1',

            // Gemini API Management Settings
            'gemini_api_key' => getenv('GEMINI_API_KEY') ?: '',
            'gemini_api_name' => 'Gemini API Key',
            'gemini_project_name' => 'projects/1062607909799',
            'gemini_project_number' => '1062607909799',
            'gemini_model' => 'gemini-3.6-flash',
            'gemini_temperature' => '0.1',
            'gemini_max_tokens' => '2048',
            'gemini_fallback_enabled' => '1'
        ];

        $insSetting = ($driver === 'sqlite')
            ? $pdo->prepare("INSERT OR IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)")
            : $pdo->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");

        foreach ($defaultSettings as $k => $v) {
            $insSetting->execute([$k, $v]);
        }

        // Seed Subscriptions if empty
        $subCount = (int)$pdo->query("SELECT COUNT(*) FROM subscriptions")->fetchColumn();
        if ($subCount === 0) {
            $pdo->exec("
                INSERT INTO subscriptions (id, user_id, plan_id, billing_cycle, status, current_period_start, current_period_end, amount_paid, coupon_id)
                VALUES
                (1, 1, 4, 'annual', 'active', '2026-01-01', '2026-12-31', 49990.00, NULL);
            ");
        }

        // Seed Activity Logs if empty
        $logCount = (int)$pdo->query("SELECT COUNT(*) FROM web_activity_logs")->fetchColumn();
        if ($logCount === 0) {
            $pdo->exec("
                INSERT INTO web_activity_logs (user_id, ip_address, method, endpoint, status_code, response_time_ms, user_agent)
                VALUES
                (1, '127.0.0.1', 'POST', '/api/auth/login', 200, 42.5, 'Mozilla/5.0 Chrome/128.0'),
                (1, '127.0.0.1', 'POST', '/api/ai/parse', 200, 298.1, 'Mozilla/5.0 Chrome/128.0'),
                (1, '127.0.0.1', 'POST', '/api/transactions', 201, 24.8, 'Mozilla/5.0 Chrome/128.0'),
                (1, '127.0.0.1', 'POST', '/api/ai/depreciation', 200, 162.3, 'Mozilla/5.0 Chrome/128.0'),
                (1, '127.0.0.1', 'GET',  '/api/reports/profit-loss', 200, 38.4, 'Mozilla/5.0 Chrome/128.0'),
                (1, '127.0.0.1', 'GET',  '/api/reports/balance-sheet', 200, 32.7, 'Mozilla/5.0 Chrome/128.0'),
                (1, '127.0.0.1', 'GET',  '/api/reports/gst', 200, 45.2, 'Mozilla/5.0 Chrome/128.0');
            ");
        }

        // 9. AI Autonomous Training Dataset Table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS ai_training_dataset (
                id " . ($driver === 'sqlite' ? "INTEGER PRIMARY KEY AUTOINCREMENT" : "BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY") . ",
                prompt_text TEXT NOT NULL,
                normalized_tokens TEXT NOT NULL,
                suggested_account_name VARCHAR(150) NOT NULL,
                transaction_type VARCHAR(10) NOT NULL,
                suggested_category VARCHAR(50) NOT NULL,
                gst_rate " . ($driver === 'sqlite' ? "REAL" : "DECIMAL(5, 2)") . " NOT NULL DEFAULT 0.00,
                needs_user_review " . ($driver === 'sqlite' ? "INTEGER" : "TINYINT(1)") . " NOT NULL DEFAULT 0,
                review_options_json TEXT NULL,
                source VARCHAR(50) NOT NULL DEFAULT 'user_verified',
                confidence_score " . ($driver === 'sqlite' ? "REAL" : "DECIMAL(4, 2)") . " NOT NULL DEFAULT 0.95,
                use_count INT NOT NULL DEFAULT 1,
                last_used_at " . ($driver === 'sqlite' ? "DATETIME" : "DATETIME NULL") . ",
                created_at " . ($driver === 'sqlite' ? "DATETIME DEFAULT CURRENT_TIMESTAMP" : "TIMESTAMP DEFAULT CURRENT_TIMESTAMP") . ",
                updated_at " . ($driver === 'sqlite' ? "DATETIME DEFAULT CURRENT_TIMESTAMP" : "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP") . "
            );
        ");

        try {
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ai_train_account ON ai_training_dataset(suggested_account_name);");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ai_train_type ON ai_training_dataset(transaction_type);");
        } catch (\Throwable $e) {}

        // 10. Accounting Principles & Double-Entry Knowledge Repository Table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS accounting_principles (
                id " . ($driver === 'sqlite' ? "INTEGER PRIMARY KEY AUTOINCREMENT" : "BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY") . ",
                category VARCHAR(50) NOT NULL,
                principle_code VARCHAR(80) NOT NULL UNIQUE,
                title VARCHAR(150) NOT NULL,
                standard_ref VARCHAR(100) NOT NULL,
                statement TEXT NOT NULL,
                debit_rule TEXT NOT NULL,
                credit_rule TEXT NOT NULL,
                applicable_accounts_json TEXT,
                journal_schema_json TEXT,
                practical_implication TEXT,
                source VARCHAR(50) NOT NULL DEFAULT 'standard_seed',
                created_at " . ($driver === 'sqlite' ? "DATETIME DEFAULT CURRENT_TIMESTAMP" : "TIMESTAMP DEFAULT CURRENT_TIMESTAMP") . ",
                updated_at " . ($driver === 'sqlite' ? "DATETIME DEFAULT CURRENT_TIMESTAMP" : "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP") . "
            );
        ");

        try {
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_prin_cat ON accounting_principles(category);");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_prin_code ON accounting_principles(principle_code);");
        } catch (\Throwable $e) {}

        // 11. Business Profile columns in `users`
        self::ensureColumnExists($pdo, 'users', 'owner_name', 'TEXT', 'VARCHAR(255) NULL');
        self::ensureColumnExists($pdo, 'users', 'phone', 'TEXT', 'VARCHAR(25) NULL');
        self::ensureColumnExists($pdo, 'users', 'address', 'TEXT', 'TEXT NULL');
        self::ensureColumnExists($pdo, 'users', 'city', 'TEXT', 'VARCHAR(100) NULL');
        self::ensureColumnExists($pdo, 'users', 'state', 'TEXT', 'VARCHAR(100) NULL');
        self::ensureColumnExists($pdo, 'users', 'pincode', 'TEXT', 'VARCHAR(20) NULL');
        self::ensureColumnExists($pdo, 'users', 'pan_number', 'TEXT', 'VARCHAR(20) NULL');
        self::ensureColumnExists($pdo, 'users', 'bank_name', 'TEXT', 'VARCHAR(100) NULL');
        self::ensureColumnExists($pdo, 'users', 'bank_account_no', 'TEXT', 'VARCHAR(50) NULL');
        self::ensureColumnExists($pdo, 'users', 'bank_ifsc', 'TEXT', 'VARCHAR(20) NULL');
        self::ensureColumnExists($pdo, 'users', 'bank_branch', 'TEXT', 'VARCHAR(100) NULL');
        self::ensureColumnExists($pdo, 'users', 'upi_id', 'TEXT', 'VARCHAR(100) NULL');
        self::ensureColumnExists($pdo, 'users', 'logo_data', 'TEXT', 'LONGTEXT NULL');
        self::ensureColumnExists($pdo, 'users', 'invoice_terms', 'TEXT', 'TEXT NULL');
        self::ensureColumnExists($pdo, 'users', 'signature_title', 'TEXT', 'VARCHAR(100) NULL');

        // Self-heal SQLite tables if created earlier with non-INTEGER id
        if ($driver === 'sqlite') {
            try {
                $cols = $pdo->query("PRAGMA table_info(invoices)")->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($cols as $c) {
                    if ($c['name'] === 'id' && strtoupper($c['type']) !== 'INTEGER') {
                        $pdo->exec("DROP TABLE IF EXISTS invoice_items; DROP TABLE IF EXISTS invoices;");
                        break;
                    }
                }
            } catch (\Throwable $e) {}
        }

        // 12. Invoices & Billing Table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS invoices (
                id " . ($driver === 'sqlite' ? "INTEGER PRIMARY KEY AUTOINCREMENT" : "BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY") . ",
                user_id " . ($driver === 'sqlite' ? "INTEGER" : "BIGINT UNSIGNED") . " NOT NULL,
                invoice_number VARCHAR(50) NOT NULL,
                invoice_date DATE NOT NULL,
                due_date DATE,
                customer_name VARCHAR(255) NOT NULL,
                customer_phone VARCHAR(50),
                customer_email VARCHAR(255),
                customer_address TEXT,
                customer_state VARCHAR(100),
                customer_gstin VARCHAR(20),
                place_of_supply VARCHAR(100),
                subtotal " . ($driver === 'sqlite' ? "REAL" : "DECIMAL(15, 2)") . " NOT NULL DEFAULT 0.00,
                cgst_amount " . ($driver === 'sqlite' ? "REAL" : "DECIMAL(15, 2)") . " NOT NULL DEFAULT 0.00,
                sgst_amount " . ($driver === 'sqlite' ? "REAL" : "DECIMAL(15, 2)") . " NOT NULL DEFAULT 0.00,
                igst_amount " . ($driver === 'sqlite' ? "REAL" : "DECIMAL(15, 2)") . " NOT NULL DEFAULT 0.00,
                total_amount " . ($driver === 'sqlite' ? "REAL" : "DECIMAL(15, 2)") . " NOT NULL DEFAULT 0.00,
                payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
                payment_mode VARCHAR(50) NOT NULL DEFAULT 'credit',
                notes TEXT,
                terms TEXT,
                posted_to_ledger " . ($driver === 'sqlite' ? "INTEGER" : "TINYINT(1)") . " NOT NULL DEFAULT 0,
                transaction_group_id VARCHAR(100),
                created_at " . ($driver === 'sqlite' ? "DATETIME DEFAULT CURRENT_TIMESTAMP" : "TIMESTAMP DEFAULT CURRENT_TIMESTAMP") . "
            );
        ");

        // 13. Invoice Items Table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS invoice_items (
                id " . ($driver === 'sqlite' ? "INTEGER PRIMARY KEY AUTOINCREMENT" : "BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY") . ",
                invoice_id " . ($driver === 'sqlite' ? "INTEGER" : "BIGINT UNSIGNED") . " NOT NULL,
                item_description TEXT NOT NULL,
                hsn_code VARCHAR(20),
                quantity " . ($driver === 'sqlite' ? "REAL" : "DECIMAL(12, 2)") . " NOT NULL DEFAULT 1.00,
                unit VARCHAR(20) NOT NULL DEFAULT 'Pcs',
                unit_price " . ($driver === 'sqlite' ? "REAL" : "DECIMAL(15, 2)") . " NOT NULL DEFAULT 0.00,
                discount_percent " . ($driver === 'sqlite' ? "REAL" : "DECIMAL(5, 2)") . " NOT NULL DEFAULT 0.00,
                gst_rate " . ($driver === 'sqlite' ? "REAL" : "DECIMAL(5, 2)") . " NOT NULL DEFAULT 18.00,
                taxable_amount " . ($driver === 'sqlite' ? "REAL" : "DECIMAL(15, 2)") . " NOT NULL DEFAULT 0.00,
                cgst " . ($driver === 'sqlite' ? "REAL" : "DECIMAL(15, 2)") . " NOT NULL DEFAULT 0.00,
                sgst " . ($driver === 'sqlite' ? "REAL" : "DECIMAL(15, 2)") . " NOT NULL DEFAULT 0.00,
                igst " . ($driver === 'sqlite' ? "REAL" : "DECIMAL(15, 2)") . " NOT NULL DEFAULT 0.00,
                total_amount " . ($driver === 'sqlite' ? "REAL" : "DECIMAL(15, 2)") . " NOT NULL DEFAULT 0.00
            );
        ");

        try {
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_invoices_user ON invoices(user_id);");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_invoices_date ON invoices(invoice_date);");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_invoice_items_inv ON invoice_items(invoice_id);");
        } catch (\Throwable $e) {}

        // 14. Ensure Chart of Accounts is fully expanded with all core GAAP / Ind AS accounts
        self::ensureChartOfAccountsExpanded($pdo);
    }

    /**
     * Idempotently seeds standard GAAP & Ind AS accounts into Chart of Accounts
     */
    private static function ensureChartOfAccountsExpanded(PDO $pdo): void
    {
        $standardAccounts = [
            ['1290', 'Allowance for Doubtful Accounts', 'asset', 0, 'Contra-asset representing expected credit losses under Ind AS 109'],
            ['1410', 'Prepaid Expenses', 'asset', 0, 'Advance payments for services/rent extending into future accounting periods'],
            ['1420', 'Accrued Income & Unbilled Receivables', 'asset', 1, 'Revenue earned for services performed but not yet invoiced'],
            ['2050', 'Accrued Expenses & Outstanding Dues', 'liability', 0, 'Operating expenses incurred in current period but not yet paid'],
            ['2060', 'Unearned Revenue / Advances from Clients', 'liability', 1, 'Customer payments received in advance before delivery of services'],
            ['2070', 'Salaries & Wages Payable', 'liability', 0, 'Staff compensation accrued at month-end awaiting payroll disbursement'],
            ['2140', 'TDS Payable (Tax Deducted at Source)', 'liability', 0, 'Withholding tax deducted on vendor or salary payments to be remitted to ITD'],
            ['2150', 'Provident Fund (PF) & ESIC Payable', 'liability', 0, 'Employee & employer statutory pension/insurance deductions payable to EPFO/ESIC'],
            ['2160', 'Professional Tax (PT) Payable', 'liability', 0, 'State professional tax deducted from employee salaries'],
            ['2510', 'Term Loan / Bank Borrowings (Long-Term)', 'liability', 0, 'Secured business long-term debt financing from financial institutions'],
            ['3030', 'Owner Drawings & Distributions', 'equity', 0, 'Contra-equity account tracking capital withdrawals by partners/owners'],
            ['4040', 'Interest Income on Deposits', 'revenue', 0, 'Interest earned on bank fixed deposits and liquid reserves'],
            ['4050', 'Cash Discount Received', 'revenue', 0, 'Discounts earned for prompt settlement of supplier payables'],
            ['4060', 'Foreign Exchange Fluctuation Gain', 'revenue', 0, 'Realized and unrealized gains from foreign currency receivables/transactions'],
            ['5000', 'Cost of Goods Sold (COGS)', 'expense', 0, 'Direct expenditure incurred to produce or acquire merchandise sold'],
            ['5005', 'Purchases of Trading Goods & Stock', 'expense', 1, 'Purchases of raw materials and goods for resale'],
            ['5080', 'Salaries, Wages & Staff Benefits', 'expense', 0, 'Gross employee remuneration, bonuses, and staff welfare'],
            ['5085', 'Employer PF & ESIC Contribution', 'expense', 0, 'Mandatory employer contribution towards employee retirement and health benefits'],
            ['5090', 'Interest Expense on Loans & Overdraft', 'expense', 0, 'Finance costs and interest paid on borrowed capital'],
            ['5095', 'Bank Charges, Processing & Gateway Fees', 'expense', 1, 'Bank transaction fees, payment gateway cuts, and NEFT/RTGS charges'],
            ['5100', 'Bad Debts Written Off', 'expense', 0, 'Uncollectible accounts receivable recognized as an irrecoverable loss'],
            ['5110', 'Cash Discount Allowed to Customers', 'expense', 0, 'Incentive discounts granted to customers for early invoice settlement'],
            ['5120', 'Foreign Exchange Fluctuation Loss', 'expense', 0, 'Realized and unrealized exchange losses from foreign currency fluctuations']
        ];

        foreach ($standardAccounts as [$code, $name, $type, $gst, $desc]) {
            $check = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE code = ? OR name = ? LIMIT 1");
            $check->execute([$code, $name]);
            if (!$check->fetch()) {
                $ins = $pdo->prepare("INSERT INTO chart_of_accounts (code, name, type, gst_applicable, description) VALUES (?, ?, ?, ?, ?)");
                $ins->execute([$code, $name, $type, $gst, $desc]);
            }
        }
    }

    private static function ensureColumnExists(PDO $pdo, string $table, string $column, string $sqliteDef, string $mysqlDef): void
    {
        $driver = self::$driverUsed;
        try {
            if ($driver === 'sqlite') {
                $cols = $pdo->query("PRAGMA table_info({$table})")->fetchAll();
                $found = false;
                foreach ($cols as $col) {
                    if (strcasecmp($col['name'], $column) === 0) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$sqliteDef}");
                }
            } else {
                $check = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'")->fetch();
                if (!$check) {
                    $pdo->exec("ALTER TABLE `{$table}` ADD `{$column}` {$mysqlDef}");
                }
            }
        } catch (\Throwable $e) {
            // Ignore if already exists
        }
    }
}
