<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Middleware\Auth;
use App\Services\AIService;
use App\Services\AITrainingService;
use PDO;
use Exception;

require_once dirname(__DIR__) . '/services/AITrainingService.php';
require_once dirname(__DIR__) . '/services/CompoundEntryEngine.php';
require_once __DIR__ . '/SubscriptionController.php';

class TransactionController
{
    private PDO $db;
    private AIService $aiService;

    public function __construct()
    {
        $this->db = Database::getConnection();
        $this->aiService = new AIService();
    }

    /**
     * POST /api/ai/parse
     * Receives natural language string, authenticates user, forwards to AI microservice
     */
    public function parseNaturalLanguage(array $requestData): void
    {
        $user = Auth::requireAuth();
        $userId = (int)$user['sub'];

        $aiLimit = SubscriptionController::checkAILimit($userId);
        if ($aiLimit !== null) {
            if (!headers_sent()) { @http_response_code(403); }
            echo json_encode(array_merge(['success' => false], $aiLimit));
            return;
        }

        $text = trim($requestData['text'] ?? '');
        if (empty($text)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Natural language text is required.'
            ]);
            return;
        }

        $parsed = $this->aiService->parseFinancialText($text);

        // Ensure review_options is populated with valid Chart of Accounts candidate names
        if (empty($parsed['review_options'])) {
            $parsed['review_options'] = ($parsed['transaction_type'] === 'credit')
                ? ['Commission Income', 'Consulting Income', 'Software Development Services', 'Sales of Hardware & Goods']
                : ['Fixed Asset - Computers', 'Office Equipment & Furniture', 'Software Subscriptions & Cloud', 'Office Expense'];
        }

        // Auto-identify target account
        $suggestedAccName = $parsed['suggested_account_name'] ?? ($parsed['review_options'][0] ?? '');
        if (!empty($suggestedAccName) && !in_array($suggestedAccName, $parsed['review_options'])) {
            array_unshift($parsed['review_options'], $suggestedAccName);
        }
        $targetAccount = null;

        if (!empty($suggestedAccName)) {
            $stmt = $this->db->prepare("SELECT id, name, code, type, gst_applicable FROM chart_of_accounts WHERE LOWER(name) = LOWER(?) LIMIT 1");
            $stmt->execute([$suggestedAccName]);
            $targetAccount = $stmt->fetch(PDO::FETCH_ASSOC);

            // If account does not exist in Chart of Accounts, auto-create it instantly!
            if (!$targetAccount) {
                $accType = ($parsed['transaction_type'] === 'credit') ? 'revenue' : (
                    in_array($parsed['suggested_category'] ?? '', ['electronics', 'furniture']) ? 'asset' : 'expense'
                );
                $code = $this->generateNextAccountCode($accType);
                $ins = $this->db->prepare("
                    INSERT INTO chart_of_accounts (code, name, type, gst_applicable, description, is_active)
                    VALUES (?, ?, ?, 1, 'Auto-created by AI Transaction Identifier', 1)
                ");
                $ins->execute([$code, $suggestedAccName, $accType]);
                $newId = (int)$this->db->lastInsertId();
                $targetAccount = [
                    'id' => $newId,
                    'code' => $code,
                    'name' => $suggestedAccName,
                    'type' => $accType,
                    'gst_applicable' => 1,
                    'auto_created' => true
                ];
            }
        }

        // Map review_options to actual database Chart of Accounts IDs
        $mappedOptions = [];
        if (!empty($parsed['review_options'])) {
            $inClause = implode(',', array_fill(0, count($parsed['review_options']), '?'));
            $stmt = $this->db->prepare("SELECT id, name, code, type, gst_applicable FROM chart_of_accounts WHERE name IN ($inClause)");
            $stmt->execute($parsed['review_options']);
            $mappedOptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Ensure targetAccount is at top of mappedOptions
        if ($targetAccount) {
            $foundInMapped = false;
            foreach ($mappedOptions as $mo) {
                if (strcasecmp((string)$mo['name'], (string)$targetAccount['name']) === 0) {
                    $foundInMapped = true;
                    break;
                }
            }
            if (!$foundInMapped) {
                array_unshift($mappedOptions, $targetAccount);
            }
        }

        // Fetch all active accounts so frontend always has complete selection options
        $allAccountsStmt = $this->db->query("SELECT id, name, code, type, gst_applicable FROM chart_of_accounts WHERE is_active = 1 ORDER BY type, code ASC");
        $allAccounts = $allAccountsStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => $parsed,
            'target_account' => $targetAccount,
            'resolved_accounts' => $mappedOptions,
            'all_accounts' => $allAccounts
        ]);
    }

    /**
     * POST /api/ai/understand
     * Analyzes single financial prompt, identifies all necessary accounts, verifies balance & rules
     */
    public function understandCompoundPrompt(array $requestData): void
    {
        $user = Auth::requireAuth();
        $userId = (int)$user['sub'];

        $prompt = trim($requestData['prompt'] ?? ($requestData['text'] ?? ''));
        if (empty($prompt)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Financial prompt text is required.']);
            return;
        }

        $engine = new \App\Services\CompoundEntryEngine($this->db);
        $result = $engine->processPrompt($prompt, false, $userId, $requestData);

        if (!$result['success']) {
            http_response_code(400);
        }

        echo json_encode($result);
    }

    /**
     * POST /api/ai/auto-post
     * Analyzes single financial prompt, auto-creates any missing accounts, verifies, and posts directly to ledger books
     */
    public function autoPostCompoundPrompt(array $requestData): void
    {
        $user = Auth::requireAuth();
        $userId = (int)$user['sub'];

        $txLimit = SubscriptionController::checkTransactionLimit($userId);
        if ($txLimit !== null) {
            if (!headers_sent()) { @http_response_code(403); }
            echo json_encode(array_merge(['success' => false], $txLimit));
            return;
        }

        $prompt = trim($requestData['prompt'] ?? ($requestData['text'] ?? ''));
        if (empty($prompt)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Financial prompt text is required.']);
            return;
        }

        $engine = new \App\Services\CompoundEntryEngine($this->db);
        $result = $engine->processPrompt($prompt, true, $userId, $requestData);

        if (!$result['success'] || empty($result['is_posted'])) {
            http_response_code(400);
        }

        echo json_encode($result);
    }

    /**
     * POST /api/ai/solve-case-study
     * Solves full corporate accounting problems / case studies (e.g. TechFlow Solutions)
     */
    public function solveCaseStudy(array $requestData): void
    {
        $user = Auth::requireAuth();
        $userId = (int)$user['sub'];

        $prompt = trim($requestData['prompt'] ?? ($requestData['text'] ?? ''));
        if (empty($prompt)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Case study prompt text is required.']);
            return;
        }

        $engine = new \App\Services\AccountingCaseStudyEngine($this->db);
        $result = $engine->solve($prompt, false, $userId);

        if (!$result['success']) {
            http_response_code(400);
        }

        echo json_encode($result);
    }

    /**
     * POST /api/ai/case-study/post
     * Solves and atomically posts all case study vouchers into SQLite database
     */
    public function postCaseStudy(array $requestData): void
    {
        $user = Auth::requireAuth();
        $userId = (int)$user['sub'];

        $txLimit = SubscriptionController::checkTransactionLimit($userId);
        if ($txLimit !== null) {
            if (!headers_sent()) { @http_response_code(403); }
            echo json_encode(array_merge(['success' => false], $txLimit));
            return;
        }

        $prompt = trim($requestData['prompt'] ?? ($requestData['text'] ?? ''));
        if (empty($prompt)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Case study prompt text is required.']);
            return;
        }

        $engine = new \App\Services\AccountingCaseStudyEngine($this->db);
        $result = $engine->solve($prompt, true, $userId);

        if (!$result['success'] || empty($result['is_posted'])) {
            http_response_code(400);
        }

        echo json_encode($result);
    }

    /**
     * POST /api/transactions
     * Stores final deterministic transaction in SQL (supports both single transaction and compound multi-entry arrays)
     */
    public function store(array $data): void
    {
        $user = Auth::requireAuth();
        $userId = (int)$user['sub'];

        $txLimit = SubscriptionController::checkTransactionLimit($userId);
        if ($txLimit !== null) {
            if (!headers_sent()) { @http_response_code(403); }
            echo json_encode(array_merge(['success' => false], $txLimit));
            return;
        }

        // Compound / Multi-Leg Posting Support
        if (!empty($data['entries']) && is_array($data['entries'])) {
            $engine = new \App\Services\CompoundEntryEngine($this->db);
            $res = $engine->resolveAndSetupAccounts($data['entries'], true);
            $ver = $engine->verifyCorrectness($res['legs']);

            if (!$ver['is_balanced']) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error'   => 'Cannot post transaction: Debits and Credits must balance. Difference: ₹' . $ver['difference'],
                    'verification' => $ver
                ]);
                return;
            }

            $rawInput = trim($data['raw_ai_input'] ?? ($data['prompt'] ?? ($data['description'] ?? '')));
            $voucherDate = trim($data['date'] ?? date('Y-m-d'));
            $narration = trim($data['narration'] ?? ($data['description'] ?? 'Compound Journal Voucher'));

            $postRes = $engine->postToBooks($userId, $res['legs'], $rawInput, $voucherDate, $narration);

            if (!$postRes['success']) {
                http_response_code(500);
            }

            echo json_encode([
                'success'        => $postRes['success'],
                'message'        => $postRes['message'] ?? 'Compound transaction posted successfully to ledger.',
                'entry_group_id' => $postRes['entry_group_id'] ?? null,
                'entries'        => $postRes['entries'] ?? [],
                'verification'   => $ver,
                'new_accounts'   => $res['new_accounts'] ?? []
            ]);
            return;
        }

        $amount      = (float)($data['amount'] ?? 0);
        $type        = strtolower(trim($data['type'] ?? 'debit'));
        $date        = trim($data['date'] ?? date('Y-m-d'));
        $description = trim($data['description'] ?? '');
        $accountId   = isset($data['account_id']) ? (int)$data['account_id'] : 0;
        $accountName = trim($data['account_name'] ?? '');
        $rawAiInput  = trim($data['raw_ai_input'] ?? '');
        $isInterstate = !empty($data['is_interstate']);

        if ($amount <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Amount must be greater than zero.']);
            return;
        }

        if (!in_array($type, ['credit', 'debit'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Type must be credit or debit.']);
            return;
        }

        // 1. Direct match by account_id
        if ($accountId > 0) {
            $check = $this->db->prepare("SELECT id, name, type FROM chart_of_accounts WHERE id = ? LIMIT 1");
            $check->execute([$accountId]);
            $accRow = $check->fetch();
            if ($accRow) {
                $accountName = $accRow['name'];
            } else {
                $accountId = 0;
            }
        }

        // 2. Direct match by name or code
        if ($accountId <= 0 && !empty($accountName)) {
            $stmt = $this->db->prepare("SELECT id, name, type FROM chart_of_accounts WHERE name = ? OR code = ? LIMIT 1");
            $stmt->execute([$accountName, $accountName]);
            $acc = $stmt->fetch();
            if ($acc) {
                $accountId = (int)$acc['id'];
                $accountName = $acc['name'];
            }
        }

        // 3. Intelligent alias / category keyword mapping
        if ($accountId <= 0) {
            $categoryMap = [
                'website'                 => 'Software Development Services',
                'ecommerce'               => 'Software Development Services',
                'software_development'    => 'Software Development Services',
                'services'                => ($type === 'credit' ? 'Software Development Services' : 'Office Expense'),
                'consulting'              => 'Consulting Income',
                'sales'                   => 'Sales of Hardware & Goods',
                'utilities'               => 'Utility Bills',
                'utility'                 => 'Utility Bills',
                'network'                 => 'Utility Bills',
                'recharge'                => 'Utility Bills',
                'recharg'                 => 'Utility Bills',
                'broadband'               => 'Utility Bills',
                'wifi'                    => 'Utility Bills',
                'internet'                => 'Utility Bills',
                'phone'                   => 'Utility Bills',
                'mobile'                  => 'Utility Bills',
                'general'                 => 'Office Expense',
                'office_expense'          => 'Office Expense',
                'office_supplies'         => 'Office Expense',
                'expense'                 => 'Office Expense',
                'expence'                 => 'Office Expense',
                'electronics'             => 'Fixed Asset - Computers',
                'computer'                => 'Fixed Asset - Computers',
                'hardware'                => 'Fixed Asset - Computers',
                'furniture'               => 'Office Equipment & Furniture',
                'rent'                    => 'Office Rent',
                'software'                => ($type === 'credit' ? 'Software Development Services' : 'Software Subscriptions & Cloud'),
                'commission'              => ($type === 'credit' ? 'Commission Income' : 'Commission & Brokerage Expense'),
                'commission_income'       => 'Commission Income',
                'commission_expense'      => 'Commission & Brokerage Expense',
                'brokerage'               => ($type === 'credit' ? 'Commission Income' : 'Commission & Brokerage Expense'),
                'work_transfer'           => 'Commission Income',
                'salary'                  => 'Salaries & Wages Expense',
                'salaries'                => 'Salaries & Wages Expense',
                'payroll'                 => 'Salaries & Wages Expense',
                'marketing'               => 'Advertising & Marketing',
                'advertising'             => 'Advertising & Marketing',
                'bank_charges'            => 'Bank Charges & Processing Fees',
                'bank_fee'                => 'Bank Charges & Processing Fees',
                'repairs'                 => 'Repairs & Maintenance',
                'maintenance'             => 'Repairs & Maintenance',
                'shipping'                => 'Courier & Shipping Charges',
                'courier'                 => 'Courier & Shipping Charges',
                'stationery'              => 'Printing & Stationery',
                'interest'                => 'Interest & Investment Income',
                'travel'                  => 'Travel & Conveyance',
                'legal'                   => 'Legal & Professional Fees',
            ];

            $lookupKey = strtolower(trim($accountName ?: ($data['suggested_category'] ?? '')));
            $resolvedTarget = $categoryMap[$lookupKey] ?? null;

            if ($resolvedTarget) {
                $stmt = $this->db->prepare("SELECT id, name FROM chart_of_accounts WHERE name = ? LIMIT 1");
                $stmt->execute([$resolvedTarget]);
                $acc = $stmt->fetch();
                if ($acc) {
                    $accountId = (int)$acc['id'];
                    $accountName = $acc['name'];
                }
            }
        }

        // 4. Instant On-The-Fly Account Creation if account does not exist
        if ($accountId <= 0 && !empty($accountName)) {
            $accType = ($type === 'credit') ? 'revenue' : 'expense';
            if (preg_match('/(asset|computer|hardware|laptop|desk|chair|furniture|equipment)/i', $accountName)) {
                $accType = 'asset';
            }
            $code = $this->generateNextAccountCode($accType);
            $ins = $this->db->prepare("
                INSERT INTO chart_of_accounts (code, name, type, gst_applicable, description, is_active)
                VALUES (?, ?, ?, 1, 'Auto-created from transaction post', 1)
            ");
            $ins->execute([$code, $accountName, $accType]);
            $accountId = (int)$this->db->lastInsertId();
        }

        // 5. Safe default fallback (guarantees transaction can always be posted cleanly)
        if ($accountId <= 0) {
            $defaultName = ($type === 'credit') ? 'Software Development Services' : 'Office Expense';
            $stmt = $this->db->prepare("SELECT id, name FROM chart_of_accounts WHERE name = ? LIMIT 1");
            $stmt->execute([$defaultName]);
            $acc = $stmt->fetch();
            if ($acc) {
                $accountId = (int)$acc['id'];
                $accountName = $acc['name'];
            }
        }

        // Verify account exists
        $stmt = $this->db->prepare("SELECT id, name, type, gst_applicable FROM chart_of_accounts WHERE id = ? LIMIT 1");
        $stmt->execute([$accountId]);
        $account = $stmt->fetch();
        if (!$account) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Chart of Account not found.']);
            return;
        }

        // Determine Supply Type:
        // Revenue (credit) = outward supply (GSTR-1)
        // Asset / Expense (debit) = inward supply (GSTR-3B)
        $supplyType = ($account['type'] === 'revenue' || $type === 'credit') ? 'outward' : 'inward';

        // Calculate GST breakdown
        $gstAmount = (float)($data['gst_amount'] ?? 0);
        if ($gstAmount == 0 && isset($data['gst_rate']) && (float)$data['gst_rate'] > 0) {
            $rate = (float)$data['gst_rate'];
            $gstAmount = round(($amount * $rate) / 100.0, 2);
        }

        $cgst = 0.0;
        $sgst = 0.0;
        $igst = 0.0;

        if ($gstAmount > 0) {
            if ($isInterstate) {
                $igst = $gstAmount;
            } else {
                $cgst = round($gstAmount / 2.0, 2);
                $sgst = round($gstAmount - $cgst, 2); // handle odd cents
            }
        }

        try {
            $this->db->beginTransaction();

            $entryGroupId = 'grp_' . bin2hex(random_bytes(16));

            // 1. Primary Ledger Entry
            $sql = "INSERT INTO transactions 
                (user_id, account_id, type, amount, date, description, gst_amount, cgst, sgst, igst, supply_type, status, raw_ai_input, entry_group_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'posted', ?, ?)";
            
            $insertStmt = $this->db->prepare($sql);
            $insertStmt->execute([
                $userId,
                $accountId,
                $type,
                $amount,
                $date,
                $description ?: ($account['name'] . ' transaction'),
                $gstAmount,
                $cgst,
                $sgst,
                $igst,
                $supplyType,
                $rawAiInput,
                $entryGroupId
            ]);

            $txId = (int)$this->db->lastInsertId();

            // 2. Balancing Double-Entry Ledger Posting (Bank Operating Account: ID 2)
            $paymentAccountId = isset($data['payment_account_id']) ? (int)$data['payment_account_id'] : 2;
            if ($paymentAccountId !== $accountId) {
                // If primary was debit, payment is credit; if primary was credit, receipt is debit
                $counterType = ($type === 'debit') ? 'credit' : 'debit';
                $counterDesc = ($type === 'debit' ? 'Bank Outflow: ' : 'Bank Inflow: ') . ($description ?: $account['name']);

                $counterStmt = $this->db->prepare("INSERT INTO transactions 
                    (user_id, account_id, type, amount, date, description, gst_amount, cgst, sgst, igst, supply_type, status, raw_ai_input, entry_group_id)
                    VALUES (?, ?, ?, ?, ?, ?, 0.0, 0.0, 0.0, 0.0, 'inward', 'posted', NULL, ?)");
                
                $counterStmt->execute([
                    $userId,
                    $paymentAccountId,
                    $counterType,
                    $amount,
                    $date,
                    $counterDesc,
                    $entryGroupId
                ]);
            }

            $this->db->commit();

            // Automatic Self-Training: Record user-verified transaction to permanent AI training dataset
            if (!empty($rawAiInput)) {
                AITrainingService::recordLearnedTransaction(
                    $rawAiInput,
                    $account['name'],
                    $type,
                    $data['suggested_category'] ?? null,
                    (float)($data['gst_rate'] ?? 0),
                    $data['review_options'] ?? [$account['name']],
                    'user_verified',
                    0.99
                );
            }

            echo json_encode([
                'success' => true,
                'message' => 'Transaction successfully posted to ledger & recorded to AI training dataset.',
                'transaction' => [
                    'id' => $txId,
                    'account_id' => $accountId,
                    'account_name' => $account['name'],
                    'account_type' => $account['type'],
                    'type' => $type,
                    'amount' => $amount,
                    'date' => $date,
                    'description' => $description,
                    'gst_amount' => $gstAmount,
                    'cgst' => $cgst,
                    'sgst' => $sgst,
                    'igst' => $igst,
                    'supply_type' => $supplyType,
                    'status' => 'posted',
                    'entry_group_id' => $entryGroupId
                ]
            ]);
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Database failure while saving transaction: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * GET /api/transactions
     * Lists user transactions with joined COA details
     */
    public function list(): void
    {
        $user = Auth::requireAuth();
        $userId = (int)$user['sub'];

        $sql = "SELECT 
                    t.id, 
                    t.user_id, 
                    t.account_id, 
                    t.type, 
                    t.amount, 
                    t.date, 
                    t.description, 
                    t.gst_amount, 
                    t.cgst, 
                    t.sgst, 
                    t.igst, 
                    t.supply_type, 
                    t.status, 
                    t.raw_ai_input,
                    t.entry_group_id,
                    t.created_at,
                    coa.code AS account_code,
                    coa.name AS account_name,
                    coa.type AS account_type
                FROM transactions t
                JOIN chart_of_accounts coa ON t.account_id = coa.id
                WHERE t.user_id = ?
                ORDER BY t.date DESC, t.id DESC
                LIMIT 100";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        $transactions = $stmt->fetchAll();

        echo json_encode([
            'success' => true,
            'count' => count($transactions),
            'data' => $transactions
        ]);
    }

    /**
     * GET /api/accounts
     * Returns full Chart of Accounts
     */
    public function getAccounts(): void
    {
        Auth::requireAuth();

        $stmt = $this->db->query("SELECT id, code, name, type, gst_applicable, description FROM chart_of_accounts WHERE is_active = 1 ORDER BY code ASC");
        $accounts = $stmt->fetchAll();

        echo json_encode([
            'success' => true,
            'data' => $accounts
        ]);
    }

    /**
     * POST /api/transactions/depreciation
     * Deterministically posts a balanced depreciation journal entry:
     * - Debit: Depreciation & Amortization Expense (Account 5070)
     * - Credit: Accumulated Depreciation - Assets (Account 1590)
     */
    public function postDepreciation(array $data): void
    {
        $user = Auth::requireAuth();
        $userId = (int)$user['sub'];

        $txLimit = SubscriptionController::checkTransactionLimit($userId);
        if ($txLimit !== null) {
            if (!headers_sent()) { @http_response_code(403); }
            echo json_encode(array_merge(['success' => false], $txLimit));
            return;
        }

        $amount = (float)($data['amount'] ?? 0);
        $date = trim($data['date'] ?? date('Y-m-d'));
        $description = trim($data['description'] ?? 'Periodic depreciation entry');
        $rawPrompt = trim($data['raw_prompt'] ?? '');

        if ($amount <= 0) {
            if (!headers_sent()) { @http_response_code(400); }
            echo json_encode(['success' => false, 'error' => 'Depreciation amount must be greater than zero.']);
            return;
        }

        // Resolve Depreciation Expense Account (5070)
        $stmtExp = $this->db->prepare("SELECT id, name FROM chart_of_accounts WHERE code = '5070' OR name LIKE '%Depreciation%Expense%' LIMIT 1");
        $stmtExp->execute();
        $expAccount = $stmtExp->fetch();
        $deprExpId = $expAccount ? (int)$expAccount['id'] : 25;

        // Resolve Accumulated Depreciation Contra-Asset Account (1590)
        $stmtAcc = $this->db->prepare("SELECT id, name FROM chart_of_accounts WHERE code = '1590' OR name LIKE '%Accumulated%Depreciation%' LIMIT 1");
        $stmtAcc->execute();
        $accAccount = $stmtAcc->fetch();
        $accumDeprId = $accAccount ? (int)$accAccount['id'] : 26;

        try {
            $this->db->beginTransaction();

            $entryGroupId = 'depr_' . bin2hex(random_bytes(16));

            // 1. Debit: Depreciation & Amortization Expense (P&L write-off)
            $stmt1 = $this->db->prepare("
                INSERT INTO transactions 
                (user_id, account_id, type, amount, date, description, gst_amount, cgst, sgst, igst, supply_type, status, raw_ai_input, entry_group_id)
                VALUES (?, ?, 'debit', ?, ?, ?, 0.0, 0.0, 0.0, 0.0, 'inward', 'posted', ?, ?)
            ");
            $stmt1->execute([
                $userId,
                $deprExpId,
                $amount,
                $date,
                $description,
                $rawPrompt,
                $entryGroupId
            ]);
            $debitTxId = (int)$this->db->lastInsertId();

            // 2. Credit: Accumulated Depreciation - Assets (Balance Sheet write-down)
            $stmt2 = $this->db->prepare("
                INSERT INTO transactions 
                (user_id, account_id, type, amount, date, description, gst_amount, cgst, sgst, igst, supply_type, status, raw_ai_input, entry_group_id)
                VALUES (?, ?, 'credit', ?, ?, ?, 0.0, 0.0, 0.0, 0.0, 'inward', 'posted', NULL, ?)
            ");
            $stmt2->execute([
                $userId,
                $accumDeprId,
                $amount,
                $date,
                "Accumulated Depreciation provision: " . $description,
                $entryGroupId
            ]);
            $creditTxId = (int)$this->db->lastInsertId();

            $this->db->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Depreciation journal entry posted successfully.',
                'journal_entry' => [
                    'debit_tx_id' => $debitTxId,
                    'debit_account' => 'Depreciation & Amortization Expense',
                    'credit_tx_id' => $creditTxId,
                    'credit_account' => 'Accumulated Depreciation - Assets',
                    'amount' => $amount,
                    'date' => $date,
                    'description' => $description,
                    'entry_group_id' => $entryGroupId
                ]
            ]);
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if (!headers_sent()) { @http_response_code(500); }
            echo json_encode([
                'success' => false,
                'error' => 'Failed to post depreciation: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Finds all linked transaction IDs for a given set of transaction IDs.
     * Ensures all balanced double-entry legs, contra accounts, and group postings
     * are collected so they can be deleted together.
     *
     * @param int[] $targetIds
     * @param int $userId
     * @param bool $isAdmin
     * @return int[]
     */
    private function resolveAllLinkedTransactionIds(array $targetIds, int $userId, bool $isAdmin): array
    {
        if (empty($targetIds)) {
            return [];
        }

        $in = implode(',', array_fill(0, count($targetIds), '?'));
        if ($isAdmin) {
            $stmt = $this->db->prepare("SELECT id, user_id, entry_group_id, account_id, type, amount, date, description FROM transactions WHERE id IN ($in)");
            $stmt->execute($targetIds);
        } else {
            $stmt = $this->db->prepare("SELECT id, user_id, entry_group_id, account_id, type, amount, date, description FROM transactions WHERE id IN ($in) AND user_id = ?");
            $stmt->execute(array_merge($targetIds, [$userId]));
        }

        $initialRows = $stmt->fetchAll();
        if (empty($initialRows)) {
            return [];
        }

        $allIdsToDelete = [];
        $groupsToFind = [];

        foreach ($initialRows as $row) {
            $id = (int)$row['id'];
            $rowUserId = (int)$row['user_id'];
            $allIdsToDelete[$id] = true;

            if (!empty($row['entry_group_id'])) {
                $groupsToFind[$rowUserId][] = $row['entry_group_id'];
            } else {
                // Heuristic fallback for legacy un-grouped transactions:
                // Find counterpart leg (same user, same date, same amount, opposite type)
                $oppType = ($row['type'] === 'debit') ? 'credit' : 'debit';
                $amt = (float)$row['amount'];
                $date = $row['date'];
                $desc = $row['description'];

                // Clean description for matching
                $cleanDesc = trim((string)preg_replace('/^(Bank Outflow:\s*|Bank Inflow:\s*|Bank Payment:\s*|Bank Receipt:\s*|Bank Deposit:\s*|Accumulated Depreciation provision:\s*)/i', '', $desc));

                $heurStmt = $this->db->prepare("
                    SELECT id, description, account_id FROM transactions 
                    WHERE user_id = ? 
                      AND date = ? 
                      AND ABS(amount - ?) < 0.01 
                      AND type = ?
                      AND id != ?
                ");
                $heurStmt->execute([$rowUserId, $date, $amt, $oppType, $id]);
                $candidates = $heurStmt->fetchAll();

                foreach ($candidates as $cand) {
                    $candId = (int)$cand['id'];
                    $candDesc = $cand['description'];
                    $candAccId = (int)$cand['account_id'];

                    $isBankLeg = ($candAccId === 2 || stripos($candDesc, 'Bank ') === 0);
                    $isDeprLeg = ($candAccId === 25 || $candAccId === 26 || stripos($candDesc, 'Depreciation') !== false);
                    $candClean = trim((string)preg_replace('/^(Bank Outflow:\s*|Bank Inflow:\s*|Bank Payment:\s*|Bank Receipt:\s*|Bank Deposit:\s*|Accumulated Depreciation provision:\s*)/i', '', $candDesc));
                    $descMatches = (!empty($cleanDesc) && !empty($candClean) && (stripos($candClean, $cleanDesc) !== false || stripos($cleanDesc, $candClean) !== false));
                    $idAdjacent = abs($candId - $id) <= 2;

                    if ($isBankLeg || $isDeprLeg || $descMatches || $idAdjacent) {
                        $allIdsToDelete[$candId] = true;
                    }
                }
            }
        }

        // Fetch all postings belonging to the identified entry_group_ids
        foreach ($groupsToFind as $uId => $groups) {
            $uniqueGroups = array_values(array_unique(array_filter($groups)));
            if (!empty($uniqueGroups)) {
                $inG = implode(',', array_fill(0, count($uniqueGroups), '?'));
                $grpStmt = $this->db->prepare("SELECT id FROM transactions WHERE user_id = ? AND entry_group_id IN ($inG)");
                $grpStmt->execute(array_merge([$uId], $uniqueGroups));
                $grpRows = $grpStmt->fetchAll();
                foreach ($grpRows as $gr) {
                    $allIdsToDelete[(int)$gr['id']] = true;
                }
            }
        }

        return array_values(array_map('intval', array_keys($allIdsToDelete)));
    }

    /**
     * DELETE /api/transactions/{id} or POST /api/transactions/delete
     * Safely deletes an already entered transaction and ALL linked balanced postings
     */
    public function delete(int $transactionId): void
    {
        $this->deleteMultiple([$transactionId]);
    }

    /**
     * Deletes multiple transactions and all postings based on each entry
     *
     * @param int[] $transactionIds
     */
    public function deleteMultiple(array $transactionIds): void
    {
        $user = Auth::requireAuth();
        $userId = (int)$user['sub'];
        $role = $user['role'] ?? 'user';
        $isAdmin = ($role === 'admin');

        $cleanIds = array_values(array_filter(array_map('intval', $transactionIds), fn($id) => $id > 0));

        if (empty($cleanIds)) {
            if (!headers_sent()) { @http_response_code(400); }
            echo json_encode(['success' => false, 'error' => 'Valid transaction ID(s) required.']);
            return;
        }

        $allLinkedIds = $this->resolveAllLinkedTransactionIds($cleanIds, $userId, $isAdmin);

        if (empty($allLinkedIds)) {
            if (!headers_sent()) { @http_response_code(404); }
            echo json_encode(['success' => false, 'error' => 'Transaction not found or access denied.']);
            return;
        }

        try {
            $this->db->beginTransaction();

            $in = implode(',', array_fill(0, count($allLinkedIds), '?'));
            if ($isAdmin) {
                $delStmt = $this->db->prepare("DELETE FROM transactions WHERE id IN ($in)");
                $delStmt->execute($allLinkedIds);
            } else {
                $delStmt = $this->db->prepare("DELETE FROM transactions WHERE id IN ($in) AND user_id = ?");
                $delStmt->execute(array_merge($allLinkedIds, [$userId]));
            }

            $this->db->commit();

            $primaryId = $cleanIds[0];
            $count = count($allLinkedIds);
            $message = ($count > 1)
                ? "Transaction #{$primaryId} and all {$count} linked balanced postings based on that entry were removed from the ledger."
                : "Transaction #{$primaryId} deleted successfully.";

            echo json_encode([
                'success' => true,
                'message' => $message,
                'deleted_id' => $primaryId,
                'deleted_ids' => $allLinkedIds,
                'deleted_count' => $count
            ]);
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if (!headers_sent()) { @http_response_code(500); }
            echo json_encode([
                'success' => false,
                'error' => 'Database failure while deleting transactions: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * POST /api/transactions/clear-all
     * Deletes all transactions for the current user (resets account transactions)
     */
    public function clearAll(): void
    {
        $user = Auth::requireAuth();
        $userId = (int)$user['sub'];

        $stmt = $this->db->prepare("DELETE FROM transactions WHERE user_id = ?");
        $stmt->execute([$userId]);
        $count = $stmt->rowCount();

        echo json_encode([
            'success' => true,
            'message' => "Successfully cleared {$count} transactions from your ledger.",
            'deleted_count' => $count
        ]);
    }

    /**
     * DELETE /api/accounts/{id} or POST /api/accounts/delete
     * Deletes or deactivates an account in chart_of_accounts
     */
    public function deleteAccount(int $accountId): void
    {
        Auth::requireAuth();

        if ($accountId <= 0) {
            if (!headers_sent()) { @http_response_code(400); }
            echo json_encode(['success' => false, 'error' => 'Valid account ID is required.']);
            return;
        }

        // Check if there are active transactions referencing this account
        $stmtTx = $this->db->prepare("SELECT COUNT(*) FROM transactions WHERE account_id = ?");
        $stmtTx->execute([$accountId]);
        $txCount = (int)$stmtTx->fetchColumn();

        if ($txCount > 0) {
            $stmt = $this->db->prepare("UPDATE chart_of_accounts SET is_active = 0 WHERE id = ?");
            $stmt->execute([$accountId]);
            echo json_encode([
                'success' => true,
                'message' => "Account #{$accountId} deactivated (has {$txCount} linked ledger entries).",
                'deactivated' => true
            ]);
        } else {
            $stmt = $this->db->prepare("DELETE FROM chart_of_accounts WHERE id = ?");
            $stmt->execute([$accountId]);
            echo json_encode([
                'success' => true,
                'message' => "Account #{$accountId} deleted successfully.",
                'deleted' => true
            ]);
        }
    }

    /**
     * Generates next sequential account code for a given account type
     */
    public function generateNextAccountCode(string $type): string
    {
        $baseCodes = [
            'asset'     => 1530,
            'liability' => 2210,
            'equity'    => 3030,
            'revenue'   => 4040,
            'expense'   => 5080,
        ];
        $type = strtolower($type);
        $candidate = $baseCodes[$type] ?? 5080;

        $isSqlite = ($this->db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite');
        $codeCondition = $isSqlite ? "code GLOB '[0-9][0-9][0-9][0-9]'" : "LENGTH(code) = 4";

        $stmt = $this->db->prepare("
            SELECT code FROM chart_of_accounts 
            WHERE type = ? AND {$codeCondition}
            ORDER BY CAST(code AS INTEGER) DESC LIMIT 1
        ");
        $stmt->execute([$type]);
        $highest = $stmt->fetchColumn();

        if ($highest) {
            $candidate = max($candidate, (int)$highest + 10);
        }

        // Loop until unique
        while (true) {
            $chk = $this->db->prepare("SELECT id FROM chart_of_accounts WHERE code = ?");
            $chk->execute([(string)$candidate]);
            if (!$chk->fetch()) {
                break;
            }
            $candidate += 10;
        }

        return (string)$candidate;
    }

    /**
     * POST /api/accounts
     * Creates a new Chart of Accounts item on demand
     */
    public function createAccount(array $data): void
    {
        Auth::requireAuth();

        $name = trim($data['name'] ?? '');
        $type = strtolower(trim($data['type'] ?? 'expense'));
        $gstApplicable = !empty($data['gst_applicable']) ? 1 : 0;
        $description = trim($data['description'] ?? '');

        if (empty($name)) {
            if (!headers_sent()) { @http_response_code(400); }
            echo json_encode(['success' => false, 'error' => 'Account name is required.']);
            return;
        }

        if (!in_array($type, ['asset', 'liability', 'equity', 'revenue', 'expense'])) {
            if (!headers_sent()) { @http_response_code(400); }
            echo json_encode(['success' => false, 'error' => 'Invalid account type. Allowed: asset, liability, equity, revenue, expense.']);
            return;
        }

        // Check if account name already exists (case-insensitive)
        $stmt = $this->db->prepare("SELECT id, name, code, type, gst_applicable, description FROM chart_of_accounts WHERE LOWER(name) = LOWER(?) LIMIT 1");
        $stmt->execute([$name]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            echo json_encode([
                'success' => true,
                'message' => "Account '{$existing['name']}' already exists.",
                'account' => $existing,
                'created' => false
            ]);
            return;
        }

        $code = trim($data['code'] ?? '');
        if (empty($code)) {
            $code = $this->generateNextAccountCode($type);
        } else {
            // Check if code already taken
            $codeChk = $this->db->prepare("SELECT id FROM chart_of_accounts WHERE code = ? LIMIT 1");
            $codeChk->execute([$code]);
            if ($codeChk->fetch()) {
                $code = $this->generateNextAccountCode($type);
            }
        }

        $ins = $this->db->prepare("
            INSERT INTO chart_of_accounts (code, name, type, gst_applicable, description, is_active)
            VALUES (?, ?, ?, ?, ?, 1)
        ");
        $ins->execute([
            $code,
            $name,
            $type,
            $gstApplicable,
            $description ?: 'Custom user-created account'
        ]);
        $newId = (int)$this->db->lastInsertId();

        $newAccount = [
            'id' => $newId,
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'gst_applicable' => $gstApplicable,
            'description' => $description
        ];

        echo json_encode([
            'success' => true,
            'message' => "Target Account '{$name}' ({$code}) created successfully.",
            'account' => $newAccount,
            'created' => true
        ]);
    }
}
