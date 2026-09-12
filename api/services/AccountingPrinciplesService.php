<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use PDO;

/**
 * AccountingPrinciplesService
 * 
 * Central repository of accounting principles, standard double-entry rules,
 * and automated knowledge harvesting from the Google Gemini Database.
 */
class AccountingPrinciplesService
{
    private static ?PDO $db = null;

    private static function getDb(): PDO
    {
        if (self::$db === null) {
            self::$db = Database::getConnection();
        }
        return self::$db;
    }

    /**
     * Seeds foundational accounting principles and standard journal schemas
     */
    public static function ensureSeedPrinciples(): void
    {
        $db = self::getDb();
        $count = (int)$db->query("SELECT COUNT(*) FROM accounting_principles")->fetchColumn();
        if ($count >= 20) {
            return;
        }

        $seeds = [
            // --- 1. THE 3 GOLDEN RULES OF ACCOUNTING ---
            [
                'category' => 'golden_rules',
                'principle_code' => 'RULE_REAL_ACCOUNTS',
                'title' => 'Golden Rule 1: Real Accounts (Assets & Properties)',
                'standard_ref' => 'Traditional Accounting Framework / Conceptual Framework',
                'statement' => 'Real accounts represent tangible and intangible assets and property of the business (Cash, Bank, Land, Building, Computers, Machinery, Patents).',
                'debit_rule' => 'Debit what comes in (Asset increase)',
                'credit_rule' => 'Credit what goes out (Asset decrease / disposal)',
                'applicable_accounts' => ['Cash in Hand', 'Primary Corporate Checking Account', 'Fixed Asset - Computers', 'Office Equipment & Furniture'],
                'journal_schema' => [
                    'debit' => 'Asset Account (e.g., Computers, Machinery, Cash)',
                    'credit' => 'Cash / Bank / Vendor Account',
                    'example' => 'Purchased MacBook Pro for ₹1,00,000: Debit Computers ₹1,00,000 | Credit Bank ₹1,00,000'
                ],
                'practical_implication' => 'Increases Balance Sheet assets; capitalized on Balance Sheet rather than immediately expensed on P&L.',
                'source' => 'standard_seed'
            ],
            [
                'category' => 'golden_rules',
                'principle_code' => 'RULE_PERSONAL_ACCOUNTS',
                'title' => 'Golden Rule 2: Personal Accounts (Entities & Individuals)',
                'standard_ref' => 'Traditional Accounting Framework / Debtor-Creditor Law',
                'statement' => 'Personal accounts relate to natural persons, legal corporations, banks, customers (debtors), and suppliers (creditors).',
                'debit_rule' => 'Debit the receiver (The entity receiving the benefit or value)',
                'credit_rule' => 'Credit the giver (The entity delivering the benefit or value)',
                'applicable_accounts' => ['Accounts Receivable (Debtors)', 'Accounts Payable (Creditors)', 'Owner Capital', 'Working Capital Loan'],
                'journal_schema' => [
                    'debit' => 'Receiver / Debtor / Vendor (when settled)',
                    'credit' => 'Giver / Creditor / Customer (when advance received)',
                    'example' => 'Sold services on credit to Bluefin Ltd for ₹50,000: Debit Bluefin Ltd (Receiver) ₹50,000 | Credit Consulting Income ₹50,000'
                ],
                'practical_implication' => 'Regulates trade credit, accounts receivable, payables, and loan balance tracking.',
                'source' => 'standard_seed'
            ],
            [
                'category' => 'golden_rules',
                'principle_code' => 'RULE_NOMINAL_ACCOUNTS',
                'title' => 'Golden Rule 3: Nominal Accounts (Incomes, Expenses, P&L)',
                'standard_ref' => 'Ind AS 1 / Schedule III P&L Standards',
                'statement' => 'Nominal accounts record all operational expenses, commercial losses, revenues, gains, and financial fees. Nominal accounts are closed to Retained Earnings at fiscal year-end.',
                'debit_rule' => 'Debit all expenses and losses',
                'credit_rule' => 'Credit all incomes and gains',
                'applicable_accounts' => ['Office Rent', 'Salaries, Wages & Staff Benefits', 'Software Subscriptions & Cloud', 'Software Development Services', 'Consulting Income'],
                'journal_schema' => [
                    'debit' => 'Expense Account (e.g., Office Rent, Salaries, Electricity)',
                    'credit' => 'Revenue / Income Account (e.g., Software Development, Consulting)',
                    'example' => 'Paid Bandra office rent ₹45,000: Debit Office Rent (Expense) ₹45,000 | Credit Bank ₹45,000'
                ],
                'practical_implication' => 'Directly computes Net Operating Profit on the Profit & Loss Statement and updates Retained Earnings.',
                'source' => 'standard_seed'
            ],

            // --- 2. CORE ACCOUNTING PRINCIPLES & CONCEPTS ---
            [
                'category' => 'core_concept',
                'principle_code' => 'PRIN_DUAL_ASPECT',
                'title' => 'Dual Aspect Principle (Accounting Equation)',
                'standard_ref' => 'Fundamental Accounting Assumption / Pacioli Principle',
                'statement' => 'Every single transaction has dual corresponding debit and credit effects. The fundamental accounting equation always holds: Assets = Liabilities + Equity.',
                'debit_rule' => 'Total Debits must exactly equal Total Credits across all transaction entries.',
                'credit_rule' => 'Any debit entry must be counter-balanced by an identical credit entry.',
                'applicable_accounts' => ['All Chart of Accounts'],
                'journal_schema' => [
                    'formula' => 'Total Debits == Total Credits',
                    'equation' => 'Assets = Liabilities + Owner Equity + (Revenues - Expenses)'
                ],
                'practical_implication' => 'Guarantees the Trial Balance balances to ₹0.00 difference and the Balance Sheet is 100% mathematically balanced.',
                'source' => 'standard_seed'
            ],
            [
                'category' => 'core_concept',
                'principle_code' => 'PRIN_ACCRUAL',
                'title' => 'Accrual Principle & Time Period Assumption',
                'standard_ref' => 'Ind AS 1 / AS 1 / Section 128 Companies Act 2013',
                'statement' => 'Transactions and economic events are recognized when they occur (and not as cash or its equivalent is received or paid) and recorded in the accounting records of the periods to which they relate.',
                'debit_rule' => 'Debit expenses in the period incurred, regardless of cash disbursement.',
                'credit_rule' => 'Credit revenues in the period earned, regardless of invoice collection.',
                'applicable_accounts' => ['Accrued Expenses & Outstanding Dues', 'Prepaid Expenses', 'Accrued Income & Unbilled Receivables', 'Unearned Revenue / Advances from Clients'],
                'journal_schema' => [
                    'accrual_expense' => 'Debit: Expense Account | Credit: Accrued Expenses Payable',
                    'accrual_income' => 'Debit: Accrued Income Receivable | Credit: Revenue Account'
                ],
                'practical_implication' => 'Prevents artificial profit distortions caused by cash timing; mandates adjusting entries at month-end.',
                'source' => 'standard_seed'
            ],
            [
                'category' => 'core_concept',
                'principle_code' => 'PRIN_MATCHING',
                'title' => 'Matching Principle (Revenue-Expense Matching)',
                'standard_ref' => 'Ind AS 115 / Conceptual Framework',
                'statement' => 'All expenses incurred directly or indirectly in generating revenue during an accounting period must be matched against and recognized alongside that revenue in the same period.',
                'debit_rule' => 'Debit Cost of Goods Sold (COGS) and operational costs in the same period as the related sale.',
                'credit_rule' => 'Credit inventory / deferred cost accounts when revenue is recognized.',
                'applicable_accounts' => ['Cost of Goods Sold (COGS)', 'Purchases of Trading Goods & Stock', 'Sales of Hardware & Goods'],
                'journal_schema' => [
                    'debit' => 'Cost of Goods Sold (P&L)',
                    'credit' => 'Inventory / Purchases Account',
                    'example' => 'Sold inventory: Debit COGS ₹60,000 | Credit Inventory ₹60,000 (matched against ₹1,00,000 Sales revenue)'
                ],
                'practical_implication' => 'Ensures accurate Gross Profit calculation and prevents premature recognition of expenses.',
                'source' => 'standard_seed'
            ],
            [
                'category' => 'core_concept',
                'principle_code' => 'PRIN_CONSERVATISM',
                'title' => 'Prudence & Conservatism Principle',
                'standard_ref' => 'Ind AS 37 / Ind AS 109 / AS 29',
                'statement' => 'Do not anticipate future profits, but provide for all known liabilities, depreciations, and potential bad debt losses immediately. Assets and revenues must never be overstated.',
                'debit_rule' => 'Debit Bad Debts Expense or Provision for Doubtful Accounts immediately upon uncertainty.',
                'credit_rule' => 'Credit Allowance for Doubtful Accounts (Contra-Asset) or Accumulated Depreciation.',
                'applicable_accounts' => ['Allowance for Doubtful Accounts', 'Bad Debts Written Off', 'Depreciation & Amortization Expense'],
                'journal_schema' => [
                    'debit' => 'Bad Debts Expense / Depreciation Expense (P&L)',
                    'credit' => 'Allowance for Doubtful Accounts / Accumulated Depreciation (Contra-Asset)'
                ],
                'practical_implication' => 'Presents a prudent, defensible financial standing to banks, auditors, and investors.',
                'source' => 'standard_seed'
            ],
            [
                'category' => 'core_concept',
                'principle_code' => 'PRIN_REVENUE_RECOGNITION',
                'title' => 'Revenue Recognition Criteria (Ind AS 115 / IFRS 15)',
                'standard_ref' => 'Ind AS 115: Revenue from Contracts with Customers',
                'statement' => 'Revenue is recognized when a customer obtains control of a promised good or service, satisfied either over time (as service is rendered) or at a point in time (upon delivery).',
                'debit_rule' => 'Debit Cash / Accounts Receivable upon satisfying performance obligations.',
                'credit_rule' => 'Credit Revenue; if advance is received before service, credit Unearned Revenue.',
                'applicable_accounts' => ['Software Development Services', 'Consulting Income', 'Unearned Revenue / Advances from Clients'],
                'journal_schema' => [
                    'on_advance' => 'Debit: Bank | Credit: Unearned Revenue (Liability)',
                    'on_delivery' => 'Debit: Unearned Revenue (Liability) | Credit: Software Development Services (Revenue)'
                ],
                'practical_implication' => 'Prevents aggressive premature revenue reporting before deliverables are handed over.',
                'source' => 'standard_seed'
            ],

            // --- 3. STANDARD JOURNAL ENTRY SCHEMAS ---
            [
                'category' => 'standard_entry',
                'principle_code' => 'ENTRY_PREPAID_EXPENSES',
                'title' => 'Prepaid Expense Accounting & Periodic Amortization',
                'standard_ref' => 'Ind AS 1: Current Asset Recognition',
                'statement' => 'When services such as annual commercial rent, AWS cloud reservations, or insurance are paid in advance, they must be booked as an Asset and amortized monthly into P&L.',
                'debit_rule' => 'Initial: Debit Prepaid Expenses (Asset). Monthly: Debit Operating Expense (P&L).',
                'credit_rule' => 'Initial: Credit Bank (Asset). Monthly: Credit Prepaid Expenses (Asset).',
                'applicable_accounts' => ['Prepaid Expenses', 'Office Rent', 'Software Subscriptions & Cloud', 'Primary Corporate Checking Account'],
                'journal_schema' => [
                    'step_1_payment' => 'Debit Prepaid Expenses ₹1,20,000 | Credit Bank ₹1,20,000',
                    'step_2_monthly' => 'Debit Software Subscriptions ₹10,000 | Credit Prepaid Expenses ₹10,000'
                ],
                'practical_implication' => 'Maintains smooth monthly P&L expenses rather than taking a massive 1-month hit.',
                'source' => 'standard_seed'
            ],
            [
                'category' => 'standard_entry',
                'principle_code' => 'ENTRY_PAYROLL_STATUTORY',
                'title' => 'Payroll Processing with Statutory TDS, PF & ESIC Deductions',
                'standard_ref' => 'Payment of Wages Act / Employees Provident Fund Act / Section 192 Income Tax Act',
                'statement' => 'Gross salaries must be debited to expenses, while statutory withholdings (TDS, Employee PF, ESIC, Professional Tax) are credited to liabilities, leaving Net Salary payable.',
                'debit_rule' => 'Debit Salaries, Wages & Staff Benefits (Gross Amount). Debit Employer PF & ESIC Contribution.',
                'credit_rule' => 'Credit TDS Payable, Credit PF & ESIC Payable, Credit PT Payable, Credit Salaries Payable / Bank.',
                'applicable_accounts' => ['Salaries, Wages & Staff Benefits', 'Employer PF & ESIC Contribution', 'TDS Payable', 'Provident Fund (PF) & ESIC Payable', 'Professional Tax (PT) Payable', 'Salaries & Wages Payable'],
                'journal_schema' => [
                    'debit_line_1' => 'Salaries, Wages & Staff Benefits: Gross Salary ₹1,00,000',
                    'credit_line_1' => 'TDS Payable (Sec 192): ₹10,000',
                    'credit_line_2' => 'PF & ESIC Payable: ₹12,000',
                    'credit_line_3' => 'Professional Tax Payable: ₹200',
                    'credit_line_4' => 'Salaries & Wages Payable / Bank: Net ₹77,800'
                ],
                'practical_implication' => 'Separates business operating cost from government tax remittance liabilities.',
                'source' => 'standard_seed'
            ],
            [
                'category' => 'standard_entry',
                'principle_code' => 'ENTRY_GST_RCM',
                'title' => 'Reverse Charge Mechanism (RCM) Accounting',
                'standard_ref' => 'Section 9(3) & 9(4) Central Goods and Services Tax (CGST) Act',
                'statement' => 'Under RCM (e.g. advocate legal fees, goods transport agency, director fees), the recipient business is statutory liable to pay GST directly to the government.',
                'debit_rule' => 'Debit Legal & Professional Fees (Invoice value) + Debit Input GST - ITC (Eligible Credit).',
                'credit_rule' => 'Credit Supplier / Bank (Invoice Value) + Credit Output GST RCM Payable (Tax liability to be paid in cash).',
                'applicable_accounts' => ['Legal & Professional Fees', 'Input CGST (Tax Credit)', 'Input SGST (Tax Credit)', 'Output CGST (Tax Payable)', 'Output SGST (Tax Payable)'],
                'journal_schema' => [
                    'debit_1' => 'Legal & Professional Fees: ₹50,000',
                    'debit_2' => 'Input CGST (ITC): ₹4,500',
                    'debit_3' => 'Input SGST (ITC): ₹4,500',
                    'credit_1' => 'Bank / Advocate Payable: ₹50,000',
                    'credit_2' => 'Output CGST RCM Payable: ₹4,500',
                    'credit_3' => 'Output SGST RCM Payable: ₹4,500'
                ],
                'practical_implication' => 'Reflected in GSTR-3B Table 3.1(d) and Table 4(A)(3); requires cash challan payment before ITC credit can be utilized.',
                'source' => 'standard_seed'
            ],
            [
                'category' => 'standard_entry',
                'principle_code' => 'ENTRY_CONTRA_TRANSFER',
                'title' => 'Contra Entry: Inter-Account & Cash-Bank Fund Transfer',
                'standard_ref' => 'Bank Reconciliation & Internal Cash Controls',
                'statement' => 'A transaction involving purely internal transfers between cash and bank accounts, having zero net impact on P&L or total assets.',
                'debit_rule' => 'Debit Receiving Bank / Cash Account (Asset increase).',
                'credit_rule' => 'Credit Disbursing Bank / Cash Account (Asset decrease).',
                'applicable_accounts' => ['Cash in Hand', 'Primary Corporate Checking Account'],
                'journal_schema' => [
                    'cash_deposit' => 'Debit: Bank Account ₹25,000 | Credit: Cash in Hand ₹25,000',
                    'cash_withdrawal' => 'Debit: Cash in Hand ₹10,000 | Credit: Bank Account ₹10,000'
                ],
                'practical_implication' => 'Must not be classified as revenue or expense; recorded as Contra (C) in cash book.',
                'source' => 'standard_seed'
            ],
            [
                'category' => 'standard_entry',
                'principle_code' => 'ENTRY_DEPRECIATION_WDV',
                'title' => 'Written Down Value (WDV) & SLM Capital Asset Amortization',
                'standard_ref' => 'Ind AS 16 (Property, Plant and Equipment) / Companies Act 2013 Schedule II',
                'statement' => 'Tangible capital assets decline in value through wear, tear, and obsolescence. The annual depreciation charge is debited to P&L and credited to Accumulated Depreciation contra-asset.',
                'debit_rule' => 'Debit Depreciation & Amortization Expense (Nominal / P&L).',
                'credit_rule' => 'Credit Accumulated Depreciation - Assets (Contra-Asset on Balance Sheet).',
                'applicable_accounts' => ['Depreciation & Amortization Expense', 'Accumulated Depreciation - Assets', 'Fixed Asset - Computers', 'Office Equipment & Furniture'],
                'journal_schema' => [
                    'entry' => 'Debit: Depreciation & Amortization Expense ₹15,000 | Credit: Accumulated Depreciation ₹15,000'
                ],
                'practical_implication' => 'Reduces Net Book Value of Fixed Assets on Balance Sheet without reducing historical gross cost.',
                'source' => 'standard_seed'
            ],
            [
                'category' => 'standard_entry',
                'principle_code' => 'ENTRY_BAD_DEBTS_ALLOWANCE',
                'title' => 'Bad Debts Write-off & Provision for Doubtful Accounts',
                'standard_ref' => 'Ind AS 109: Expected Credit Loss (ECL) Model',
                'statement' => 'When a customer receivable becomes irrecoverable, it is written off. Prudent provision is made at fiscal year-end based on aging.',
                'debit_rule' => 'Debit Bad Debts Written Off (P&L Expense).',
                'credit_rule' => 'Credit Allowance for Doubtful Accounts or Accounts Receivable (Asset decrease).',
                'applicable_accounts' => ['Bad Debts Written Off', 'Allowance for Doubtful Accounts', 'Accounts Receivable'],
                'journal_schema' => [
                    'entry' => 'Debit: Bad Debts Written Off ₹20,000 | Credit: Accounts Receivable ₹20,000'
                ],
                'practical_implication' => 'Ensures trade receivables reflect realistic net realizable cash value.',
                'source' => 'standard_seed'
            ],
            [
                'category' => 'standard_entry',
                'principle_code' => 'ENTRY_LOAN_EMI_SPLIT',
                'title' => 'Bank Term Loan Repayment: Principal vs Interest Amortization',
                'standard_ref' => 'Ind AS 109 / Amortized Cost Method',
                'statement' => 'A monthly loan payment (EMI) consists of two distinct components: principal repayment (reduces liability) and finance cost (operating P&L expense).',
                'debit_rule' => 'Debit Term Loan (Liability - Principal portion). Debit Interest Expense on Loans (Nominal / P&L).',
                'credit_rule' => 'Credit Bank Account (Total EMI amount disbursed).',
                'applicable_accounts' => ['Term Loan / Bank Borrowings (Long-Term)', 'Interest Expense on Loans & Overdraft', 'Primary Corporate Checking Account'],
                'journal_schema' => [
                    'entry' => 'Debit Term Loan ₹35,000 | Debit Interest Expense ₹15,000 | Credit Bank ₹50,000'
                ],
                'practical_implication' => 'Prevents the common accounting error of expensing the full loan repayment on P&L.',
                'source' => 'standard_seed'
            ],
            [
                'category' => 'standard_entry',
                'principle_code' => 'ENTRY_CASH_DISCOUNT',
                'title' => 'Cash Discount Allowed vs Received (Prompt Settlement)',
                'standard_ref' => 'Commercial Settlement Standards',
                'statement' => 'Cash discount is an incentive granted for early invoice payment, distinct from trade discounts which are deducted on invoice face value.',
                'debit_rule' => 'Debit Cash Discount Allowed when granted to customer (Expense). Debit Creditor / Supplier upon prompt payment.',
                'credit_rule' => 'Credit Debtor / Customer when settlement received. Credit Cash Discount Received (Revenue / Gain).',
                'applicable_accounts' => ['Cash Discount Allowed to Customers', 'Cash Discount Received', 'Accounts Receivable', 'Accounts Payable', 'Primary Corporate Checking Account'],
                'journal_schema' => [
                    'discount_received' => 'Debit Accounts Payable ₹50,000 | Credit Bank ₹48,000 | Credit Cash Discount Received ₹2,000',
                    'discount_allowed' => 'Debit Bank ₹95,000 | Debit Cash Discount Allowed ₹5,000 | Credit Accounts Receivable ₹1,00,000'
                ],
                'practical_implication' => 'Records accurate financial costs of trade credit and early settlement discounts.',
                'source' => 'standard_seed'
            ]
        ];

        $stmt = $db->prepare("
            INSERT OR IGNORE INTO accounting_principles (
                category, principle_code, title, standard_ref, statement, debit_rule, credit_rule, 
                applicable_accounts_json, journal_schema_json, practical_implication, source
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($seeds as $s) {
            $stmt->execute([
                $s['category'],
                $s['principle_code'],
                $s['title'],
                $s['standard_ref'],
                $s['statement'],
                $s['debit_rule'],
                $s['credit_rule'],
                json_encode($s['applicable_accounts']),
                json_encode($s['journal_schema']),
                $s['practical_implication'],
                $s['source']
            ]);
        }
    }

    /**
     * Lists principles with optional category and search filter
     */
    public static function listPrinciples(?string $category = null, ?string $search = null): array
    {
        self::ensureSeedPrinciples();
        $db = self::getDb();

        $sql = "SELECT * FROM accounting_principles WHERE 1=1";
        $params = [];

        if (!empty($category)) {
            $sql .= " AND category = ?";
            $params[] = $category;
        }

        if (!empty($search)) {
            $sql .= " AND (title LIKE ? OR statement LIKE ? OR debit_rule LIKE ? OR credit_rule LIKE ? OR principle_code LIKE ?)";
            $term = '%' . trim($search) . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        $sql .= " ORDER BY CASE category 
            WHEN 'golden_rules' THEN 1 
            WHEN 'core_concept' THEN 2 
            WHEN 'standard_entry' THEN 3 
            ELSE 4 END, id ASC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function($r) {
            $r['applicable_accounts'] = json_decode($r['applicable_accounts_json'] ?? '[]', true);
            $r['journal_schema'] = json_decode($r['journal_schema_json'] ?? '{}', true);
            return $r;
        }, $rows);
    }

    /**
     * Collects and enriches accounting principles dynamically using Google Gemini Database Knowledge Base
     */
    public static function collectFromGoogleDatabase(string $topic = 'Indian Corporate & GST Accounting', int $count = 3): array
    {
        self::ensureSeedPrinciples();

        $ai = new AIService();
        $apiKey = $ai->getGeminiApiKey();

        if (empty($apiKey) || strpos($apiKey, 'SampleGeminiKey') !== false || strlen($apiKey) < 20) {
            return [
                'success' => false,
                'error' => 'Google Gemini API key not configured in system settings. Please configure credentials in API & Integrations.'
            ];
        }

        $model = $ai->getGeminiModel();
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $prompt = <<<PROMPT
You are the Chief Accounting Standard Setter and Google Senior Enterprise Accounting Database Intelligence Engine.
Your task is to provide exactly {$count} rigorous, GAAP / Ind AS / IFRS-compliant accounting principles, golden rules, or standard journal entry templates for the domain: "{$topic}".

Return ONLY a valid JSON array of objects with NO Markdown code blocks, adhering to this schema:
[
  {
    "category": "standard_entry" | "core_concept" | "tax_compliance" | "adjusting_entry",
    "principle_code": "ENTRY_... or PRIN_... in UPPER_SNAKE_CASE",
    "title": "Clear descriptive title",
    "standard_ref": "Specific standard reference e.g., Ind AS 16, Ind AS 115, Sec 194C, Companies Act 2013",
    "statement": "Rigorous conceptual accounting principle or standard statement",
    "debit_rule": "What account type gets debited and why",
    "credit_rule": "What account type gets credited and why",
    "applicable_accounts": ["Account Name 1", "Account Name 2"],
    "journal_schema": {
      "debit": "Account name and justification",
      "credit": "Account name and justification",
      "example": "Complete rupee example with numbers"
    },
    "practical_implication": "Impact on Trial Balance, P&L, Balance Sheet, and GST filing"
  }
]
PROMPT;

        $candidateModels = [$model, 'gemini-2.5-flash', 'gemini-1.5-flash'];
        $generatedData = null;
        $usedModel = $model;

        foreach ($candidateModels as $cand) {
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
                CURLOPT_TIMEOUT => 45,
                CURLOPT_SSL_VERIFYPEER => false
            ]);

            $rawRes = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && $rawRes) {
                $decoded = json_decode($rawRes, true);
                $candidates = $decoded['candidates'] ?? [];
                if (!empty($candidates[0]['content']['parts'])) {
                    foreach ($candidates[0]['content']['parts'] as $part) {
                        if (!empty($part['text']) && empty($part['thought'])) {
                            $cleanJson = trim($part['text']);
                            $cleanJson = preg_replace('/^```json\s*/i', '', $cleanJson);
                            $cleanJson = preg_replace('/^```\s*/i', '', $cleanJson);
                            $cleanJson = preg_replace('/\s*```$/', '', $cleanJson);
                            $parsed = json_decode($cleanJson, true);
                            if (is_array($parsed)) {
                                $generatedData = $parsed;
                                $usedModel = $cand;
                                break 2;
                            }
                        }
                    }
                }
            }
        }

        if (empty($generatedData) || !is_array($generatedData)) {
            return [
                'success' => false,
                'error' => 'Failed to parse structured accounting principles from Google Gemini response.'
            ];
        }

        $db = self::getDb();
        $stmt = $db->prepare("
            INSERT INTO accounting_principles (
                category, principle_code, title, standard_ref, statement, debit_rule, credit_rule, 
                applicable_accounts_json, journal_schema_json, practical_implication, source
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(principle_code) DO UPDATE SET
                title = excluded.title,
                statement = excluded.statement,
                debit_rule = excluded.debit_rule,
                credit_rule = excluded.credit_rule,
                applicable_accounts_json = excluded.applicable_accounts_json,
                journal_schema_json = excluded.journal_schema_json,
                practical_implication = excluded.practical_implication,
                updated_at = CURRENT_TIMESTAMP
        ");

        $insertedCount = 0;
        foreach ($generatedData as $item) {
            if (empty($item['principle_code']) || empty($item['title'])) {
                continue;
            }

            $stmt->execute([
                $item['category'] ?? 'standard_entry',
                strtoupper(trim($item['principle_code'])),
                trim($item['title']),
                trim($item['standard_ref'] ?? 'GAAP / Ind AS'),
                trim($item['statement'] ?? ''),
                trim($item['debit_rule'] ?? ''),
                trim($item['credit_rule'] ?? ''),
                json_encode($item['applicable_accounts'] ?? []),
                json_encode($item['journal_schema'] ?? []),
                trim($item['practical_implication'] ?? ''),
                'google_database'
            ]);
            $insertedCount++;

            // Also ingest into AI training dataset if example prompt exists
            if (!empty($item['journal_schema']['example']) && !empty($item['applicable_accounts'][0])) {
                AITrainingService::recordLearnedTransaction(
                    $item['journal_schema']['example'],
                    $item['applicable_accounts'][0],
                    'debit',
                    $item['category'] ?? 'standard_entry',
                    18.0,
                    $item['applicable_accounts'],
                    'google_gemini_harvested',
                    0.96
                );
            }
        }

        return [
            'success' => true,
            'collected_count' => $insertedCount,
            'model' => $usedModel,
            'topic' => $topic,
            'source' => 'google_database'
        ];
    }

    /**
     * Prepares grounding accounting principles context for the Gemini system prompt
     */
    public static function getPrinciplesContextForAI(): string
    {
        self::ensureSeedPrinciples();
        return <<<PROMPT
### CORE ACCOUNTING PRINCIPLES & GOLDEN RULES:
1. REAL ACCOUNTS (Assets/Properties/Equipments): Debit what comes in (Asset increase), Credit what goes out.
   - Computers, hardware, laptops, office furniture are BALANCE SHEET CAPITAL ASSETS, NOT EXPENSES.
2. PERSONAL ACCOUNTS (Persons/Banks/Debtors/Creditors): Debit the receiver, Credit the giver.
3. NOMINAL ACCOUNTS (Expenses/Incomes/Losses/Gains): Debit all expenses and losses, Credit all revenues and gains.
4. ACCRUAL PRINCIPLE: Record revenues when earned and expenses when incurred, regardless of cash timing.
5. MATCHING PRINCIPLE: Match direct costs to the revenues generated in the same fiscal period.
6. DUAL ASPECT: Every entry must balance perfectly across Debit and Credit.
PROMPT;
    }
}
