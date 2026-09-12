-- ============================================================================
-- HYBRID AI ACCOUNTING & TAX PREPARATION WEB APPLICATION
-- MODULE 1: SEED DATA
-- Standard Indian Accounting Ledger & Chart of Accounts + Admin Telemetry
-- ============================================================================

USE `accounting_db`;

-- ----------------------------------------------------------------------------
-- 1. SEED DEFAULT USERS (ADMIN & CLIENTS)
-- Password: "Password123!" (hashed via bcrypt)
-- ----------------------------------------------------------------------------
INSERT INTO `users` (`id`, `email`, `password_hash`, `gst_number`, `business_name`, `role`, `status`, `tier`, `last_login_at`)
VALUES 
(
    1,
    'admin@accounting.local',
    '$2y$10$4yfVVH9eyh1r0U6Kryt3yurIGzUROvnQeQh8zO5z.pjZU7zJiaDzK', -- Password123!
    '27ABCDE1234F1Z5',
    'Apex Technologies & Advisory LLP',
    'admin',
    'active',
    'enterprise',
    CURRENT_TIMESTAMP
),
(
    2,
    'founder@techflow.in',
    '$2y$10$4yfVVH9eyh1r0U6Kryt3yurIGzUROvnQeQh8zO5z.pjZU7zJiaDzK',
    '29XYZAB5678C1Z2',
    'Techflow Software Solutions Pvt Ltd',
    'user',
    'active',
    'pro',
    CURRENT_TIMESTAMP
),
(
    3,
    'finance@zenithconsulting.com',
    '$2y$10$4yfVVH9eyh1r0U6Kryt3yurIGzUROvnQeQh8zO5z.pjZU7zJiaDzK',
    '33AAAAA0000A1Z5',
    'Zenith Tax & Advisory Partners',
    'user',
    'active',
    'starter',
    CURRENT_TIMESTAMP
),
(
    4,
    'support@dormantcorp.com',
    '$2y$10$4yfVVH9eyh1r0U6Kryt3yurIGzUROvnQeQh8zO5z.pjZU7zJiaDzK',
    NULL,
    'Dormant Holdings LLC',
    'user',
    'suspended',
    'free',
    NULL
)
ON DUPLICATE KEY UPDATE 
    `role` = VALUES(`role`), 
    `status` = VALUES(`status`), 
    `tier` = VALUES(`tier`);

-- ----------------------------------------------------------------------------
-- 2. SEED SUBSCRIPTION PLANS
-- ----------------------------------------------------------------------------
INSERT INTO `subscription_plans` (`id`, `slug`, `name`, `price_monthly`, `price_annual`, `max_transactions`, `ai_queries_limit`, `features_json`, `is_active`)
VALUES
(1, 'free', 'Free Starter', 0.00, 0.00, 50, 20, '["Basic Double-Entry Ledger", "Community Support", "Manual Transaction Entry"]', 1),
(2, 'starter', 'Starter Solo', 799.00, 7990.00, 500, 200, '["AI Transaction Parsing", "GSTR-1 & 3B Reports", "Standard Depreciation Solver", "Email Support"]', 1),
(3, 'pro', 'Pro Accountant', 1999.00, 19990.00, 5000, 2000, '["All Starter Features", "Full AI Disambiguation Modal", "Automated WDV Depreciation Journals", "Balance Sheet & P&L Analytics", "Priority Support"]', 1),
(4, 'enterprise', 'Enterprise Tax Prep', 4999.00, 49990.00, 999999, 999999, '["Unlimited Transactions", "Unlimited Gemini AI Ingestion", "Multi-User Role Controls", "Custom GSTR API Integrations", "Dedicated Account Manager"]', 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `price_monthly` = VALUES(`price_monthly`);

-- ----------------------------------------------------------------------------
-- 3. SEED SUBSCRIPTIONS
-- ----------------------------------------------------------------------------
INSERT INTO `subscriptions` (`id`, `user_id`, `plan_id`, `billing_cycle`, `status`, `current_period_start`, `current_period_end`, `amount_paid`, `coupon_id`)
VALUES
(1, 1, 4, 'annual', 'active', '2026-01-01', '2026-12-31', 49990.00, NULL),
(2, 2, 3, 'monthly', 'active', '2026-09-01', '2026-09-30', 1999.00, 1),
(3, 3, 2, 'monthly', 'active', '2026-09-01', '2026-09-30', 799.00, NULL),
(4, 4, 1, 'monthly', 'canceled', '2026-08-01', '2026-08-31', 0.00, NULL)
ON DUPLICATE KEY UPDATE `status` = VALUES(`status`), `amount_paid` = VALUES(`amount_paid`);

-- ----------------------------------------------------------------------------
-- 4. SEED COUPONS
-- ----------------------------------------------------------------------------
INSERT INTO `coupons` (`id`, `code`, `discount_type`, `discount_value`, `min_order_amount`, `max_uses`, `used_count`, `valid_from`, `valid_until`, `is_active`)
VALUES
(1, 'WELCOME50', 'percentage', 50.00, 500.00, 500, 42, '2026-01-01', '2026-12-31', 1),
(2, 'TAXPRO2026', 'percentage', 25.00, 1000.00, 200, 18, '2026-01-01', '2026-12-31', 1),
(3, 'FLAT500',    'fixed',      500.00, 1500.00, 100, 9,  '2026-06-01', '2026-11-30', 1),
(4, 'DIWALI2026', 'percentage', 30.00, 799.00,  1000, 0,  '2026-10-15', '2026-11-15', 1),
(5, 'EARLYBIRD',  'percentage', 100.00, 0.00,    50,   50, '2026-01-01', '2026-06-30', 0)
ON DUPLICATE KEY UPDATE `discount_value` = VALUES(`discount_value`), `is_active` = VALUES(`is_active`);

-- ----------------------------------------------------------------------------
-- 5. SEED COUPON REDEMPTIONS
-- ----------------------------------------------------------------------------
INSERT INTO `coupon_redemptions` (`id`, `coupon_id`, `user_id`, `discount_applied`, `order_amount`, `redeemed_at`)
VALUES
(1, 1, 2, 999.50, 1999.00, '2026-09-01 10:15:00'),
(2, 2, 3, 199.75, 799.00,  '2026-09-01 14:30:00'),
(3, 3, 1, 500.00, 49990.00,'2026-01-01 09:00:00')
ON DUPLICATE KEY UPDATE `discount_applied` = VALUES(`discount_applied`);

-- ----------------------------------------------------------------------------
-- 6. SEED SYSTEM SETTINGS & ANNOUNCEMENTS
-- ----------------------------------------------------------------------------
INSERT INTO `system_settings` (`setting_key`, `setting_value`)
VALUES
('announcement_text', 'FY 2026-27 Tax Compliance Alert: Ensure all GST Inward Input Tax Credit matches GSTR-2B before filing monthly returns.'),
('announcement_active', '1'),
('maintenance_mode', '0'),
('support_email', 'admin@accounting.local'),
('gemini_api_key', ''),
('gemini_api_name', 'Gemini API Key'),
('gemini_project_name', 'projects/1062607909799'),
('gemini_project_number', '1062607909799'),
('gemini_model', 'gemini-3.6-flash'),
('gemini_temperature', '0.1'),
('gemini_fallback_enabled', '1')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

-- ----------------------------------------------------------------------------
-- 7. SEED INITIAL WEB ACTIVITY TELEMETRY
-- ----------------------------------------------------------------------------
INSERT INTO `web_activity_logs` (`user_id`, `ip_address`, `method`, `endpoint`, `status_code`, `response_time_ms`, `user_agent`, `created_at`)
VALUES
(1, '127.0.0.1', 'POST', '/api/auth/login', 200, 45.2, 'Mozilla/5.0 Chrome/128.0', CURRENT_TIMESTAMP),
(1, '127.0.0.1', 'POST', '/api/ai/parse', 200, 312.4, 'Mozilla/5.0 Chrome/128.0', CURRENT_TIMESTAMP),
(1, '127.0.0.1', 'POST', '/api/transactions', 201, 28.6, 'Mozilla/5.0 Chrome/128.0', CURRENT_TIMESTAMP),
(2, '192.168.1.15', 'POST', '/api/ai/depreciation', 200, 185.7, 'Mozilla/5.0 Firefox/130.0', CURRENT_TIMESTAMP),
(1, '127.0.0.1', 'GET',  '/api/reports/profit-loss', 200, 52.1, 'Mozilla/5.0 Chrome/128.0', CURRENT_TIMESTAMP),
(1, '127.0.0.1', 'GET',  '/api/reports/balance-sheet', 200, 48.9, 'Mozilla/5.0 Chrome/128.0', CURRENT_TIMESTAMP),
(3, '10.0.0.4', 'GET',   '/api/reports/gst', 200, 64.3, 'Mozilla/5.0 Safari/605.1', CURRENT_TIMESTAMP),
(4, '172.16.0.8', 'POST', '/api/auth/login', 401, 19.8, 'Mozilla/5.0 Chrome/127.0', CURRENT_TIMESTAMP);

-- ----------------------------------------------------------------------------
-- 8. SEED CHART OF ACCOUNTS
-- Standard Double-Entry COA matching Assets, Liabilities, Equity, Revenue, Expense
-- ----------------------------------------------------------------------------
INSERT INTO `chart_of_accounts` (`id`, `code`, `name`, `type`, `gst_applicable`, `description`) VALUES
-- ASSETS (1000 - 1999)
(1,  '1010', 'Cash and Cash Equivalents',      'asset',     0, 'Liquid currency and petty cash reserves'),
(2,  '1020', 'Bank Operating Account',         'asset',     0, 'Current bank account for business operations'),
(3,  '1100', 'Accounts Receivable (Debtors)',  'asset',     0, 'Invoices billed to clients awaiting settlement'),
(4,  '1510', 'Fixed Asset - Computers',        'asset',     1, 'Hardware, servers, laptops, and IT infrastructure'),
(5,  '1520', 'Office Equipment & Furniture',   'asset',     1, 'Office desks, air conditioners, furniture'),
(6,  '1710', 'Input CGST (Tax Credit)',        'asset',     0, 'Central GST Input Tax Credit receivable'),
(7,  '1720', 'Input SGST (Tax Credit)',        'asset',     0, 'State GST Input Tax Credit receivable'),
(8,  '1730', 'Input IGST (Tax Credit)',        'asset',     0, 'Integrated GST Input Tax Credit receivable'),

-- LIABILITIES (2000 - 2999)
(9,  '2010', 'Accounts Payable (Creditors)',   'liability', 0, 'Supplier invoices payable'),
(10, '2110', 'Output CGST (Tax Payable)',      'liability', 0, 'Central GST collected on taxable supplies'),
(11, '2120', 'Output SGST (Tax Payable)',      'liability', 0, 'State GST collected on taxable supplies'),
(12, '2130', 'Output IGST (Tax Payable)',      'liability', 0, 'Integrated GST collected on interstate supplies'),
(13, '2200', 'Working Capital Loan',          'liability', 0, 'Short-term credit line from bank'),

-- EQUITY (3000 - 3999)
(14, '3010', 'Owner Capital',                  'equity',    0, 'Initial capital infused by founding partners'),
(15, '3020', 'Retained Earnings',              'equity',    0, 'Cumulative net profits retained in business'),

-- REVENUE (4000 - 4999)
(16, '4010', 'Software Development Services',  'revenue',   1, 'SaaS, custom engineering, and technology services'),
(17, '4020', 'Consulting Income',              'revenue',   1, 'Advisory, financial, and strategic consulting fees'),
(18, '4030', 'Sales of Hardware & Goods',      'revenue',   1, 'Direct wholesale or retail supply of products'),
(27, '4040', 'Commission Income',              'revenue',   1, 'Commission, brokerage, referral, and agency fees received'),
(28, '4050', 'Interest & Investment Income',   'revenue',   0, 'Bank interest, FD returns, and financial yields'),

-- EXPENSES (5000 - 5999)
(19, '5010', 'Office Expense',                 'expense',   1, 'General office operating expenses, tea, stationery'),
(20, '5020', 'Office Rent',                    'expense',   1, 'Commercial office premise leasing charges'),
(21, '5030', 'Software Subscriptions & Cloud', 'expense',   1, 'AWS, GCP, GitHub, Slack, and digital tool fees'),
(22, '5040', 'Travel & Conveyance',            'expense',   1, 'Client site visits, airfare, local transit'),
(23, '5050', 'Legal & Professional Fees',      'expense',   1, 'Auditor, accounting, and compliance retainers'),
(24, '5060', 'Utility Bills',                  'expense',   0, 'Electricity, broadband, and maintenance'),
(25, '5070', 'Depreciation & Amortization Expense', 'expense', 0, 'Periodic write-off of tangible and intangible capital assets'),
(26, '1590', 'Accumulated Depreciation - Assets', 'asset',     0, 'Contra-asset account tracking cumulative asset depreciation'),
(29, '5080', 'Salaries & Wages Expense',       'expense',   0, 'Employee salaries, contractor wages, and staff payroll'),
(30, '5090', 'Advertising & Marketing',        'expense',   1, 'Digital ads, marketing campaigns, and promotional expenses'),
(31, '5100', 'Bank Charges & Processing Fees', 'expense',   1, 'Payment gateway, bank service charges, and processing fees'),
(32, '5110', 'Repairs & Maintenance',          'expense',   1, 'Office and equipment servicing, AMC, and repairs'),
(33, '5120', 'Commission & Brokerage Expense', 'expense',   1, 'Commission paid to agents, brokers, and partners')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- ----------------------------------------------------------------------------
-- 9. SEED INITIAL BALANCED DOUBLE-ENTRY TRANSACTIONS FOR REPORTING
-- ----------------------------------------------------------------------------
INSERT INTO `transactions` 
(`id`, `user_id`, `account_id`, `type`, `amount`, `date`, `description`, `gst_amount`, `cgst`, `sgst`, `igst`, `supply_type`, `status`, `raw_ai_input`) 
VALUES
(1, 1, 14, 'credit', 500000.00, '2026-08-01', 'Initial Partner Capital contribution', 0.00, 0.00, 0.00, 0.00, 'inward', 'posted', 'Infused capital 5 lakhs into business account'),
(2, 1, 2,  'debit',  500000.00, '2026-08-01', 'Bank Deposit: Capital infusion', 0.00, 0.00, 0.00, 0.00, 'inward', 'posted', NULL),
(3, 1, 17, 'credit', 200000.00, '2026-08-10', 'Corporate Advisory Services provided to Zeta Corp', 36000.00, 18000.00, 18000.00, 0.00, 'outward', 'posted', 'Billed client 2 lakhs plus 18 percent GST for advisory'),
(4, 1, 2,  'debit',  200000.00, '2026-08-10', 'Bank Receipt: Corporate Advisory settlement', 0.00, 0.00, 0.00, 0.00, 'inward', 'posted', NULL),
(5, 1, 16, 'credit', 150000.00, '2026-08-20', 'Custom Fullstack Portal Development for Bluefin Ltd', 27000.00, 0.00, 0.00, 27000.00, 'outward', 'posted', 'Received 1.5 lakhs from interstate client for software build'),
(6, 1, 2,  'debit',  150000.00, '2026-08-20', 'Bank Receipt: Software portal settlement', 0.00, 0.00, 0.00, 0.00, 'inward', 'posted', NULL),
(7, 1, 4,  'debit',  100000.00, '2026-08-25', 'Apple MacBook Pro M3 Max 36GB RAM Development Workstation', 18000.00, 9000.00, 9000.00, 0.00, 'inward', 'posted', 'Bought a Macbook for 1 lakh'),
(8, 1, 2,  'credit', 100000.00, '2026-08-25', 'Bank Payment: Apple Store Workstation', 0.00, 0.00, 0.00, 0.00, 'inward', 'posted', NULL),
(9, 1, 20, 'debit',   45000.00, '2026-09-01', 'September 2026 Office Rent for Bandra Kurla Complex Suite', 8100.00, 4050.00, 4050.00, 0.00, 'inward', 'posted', 'Paid office rent 45000 with 18% GST'),
(10, 1, 2, 'credit',  45000.00, '2026-09-01', 'Bank Payment: Commercial Lease September', 0.00, 0.00, 0.00, 0.00, 'inward', 'posted', NULL),
(11, 1, 21, 'debit',  18000.00, '2026-09-05', 'AWS and Google Cloud Platform monthly server bills', 3240.00, 0.00, 0.00, 3240.00, 'inward', 'posted', 'Paid cloud hosting bills 18000 interstate'),
(12, 1, 2,  'credit', 18000.00, '2026-09-05', 'Bank Payment: AWS & GCP Cloud Servers', 0.00, 0.00, 0.00, 0.00, 'inward', 'posted', NULL)
ON DUPLICATE KEY UPDATE `amount` = VALUES(`amount`);
