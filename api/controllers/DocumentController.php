<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Middleware\Auth;
use App\Services\DocumentParserService;
use App\Services\AITrainingService;
use PDO;
use Exception;

require_once dirname(__DIR__) . '/services/DocumentParserService.php';
require_once dirname(__DIR__) . '/services/AITrainingService.php';
require_once __DIR__ . '/SubscriptionController.php';

class DocumentController
{
    private PDO $db;
    private DocumentParserService $parser;

    public function __construct()
    {
        $this->db = Database::getConnection();
        $this->parser = new DocumentParserService($this->db);
    }

    /**
     * POST /api/documents/analyze
     * Uploads and analyzes a document (PDF, DOC, DOCX, TXT, CSV, TSV, XLSX, or image)
     */
    public function analyze(array $requestData = []): void
    {
        $user = Auth::requireAuth();
        $userId = (int)$user['sub'];

        // Check subscription AI/query limits
        $aiLimit = SubscriptionController::checkAILimit($userId);
        if ($aiLimit !== null) {
            if (!headers_sent()) { @http_response_code(403); }
            echo json_encode(array_merge(['success' => false], $aiLimit));
            return;
        }

        $tempPath = null;
        $originalName = 'document.txt';
        $mimeType = null;

        try {
            // Option 1: Direct multipart/form-data file upload
            if (isset($_FILES['document']) && is_uploaded_file($_FILES['document']['tmp_name'])) {
                $file = $_FILES['document'];
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception("File upload error code: " . $file['error']);
                }
                if ($file['size'] > 25 * 1024 * 1024) {
                    throw new Exception("File size exceeds 25MB maximum limit.");
                }
                $tempPath = $file['tmp_name'];
                $originalName = $file['name'];
                $mimeType = $file['type'] ?? null;
            }
            // Option 2: Base64 data upload via JSON
            elseif (!empty($requestData['file_data']) && !empty($requestData['file_name'])) {
                $originalName = trim((string)$requestData['file_name']);
                $base64Str = (string)$requestData['file_data'];
                // Strip data URI prefix if present (e.g. data:application/pdf;base64,...)
                if (preg_match('/^data:([^;]+);base64,(.*)$/s', $base64Str, $m)) {
                    $mimeType = $m[1];
                    $base64Str = $m[2];
                }
                $binary = base64_decode($base64Str);
                if ($binary === false || strlen($binary) === 0) {
                    throw new Exception("Invalid base64 document data received.");
                }
                $tempPath = tempnam(sys_get_temp_dir(), 'doc_upload_');
                file_put_contents($tempPath, $binary);
            }
            // Option 3: Direct text paste
            elseif (!empty($requestData['text'])) {
                $originalName = $requestData['file_name'] ?? 'pasted_document.txt';
                $tempPath = tempnam(sys_get_temp_dir(), 'doc_upload_');
                file_put_contents($tempPath, (string)$requestData['text']);
            }
            // Option 4: Sample preset loader
            elseif (!empty($requestData['sample_type'])) {
                $sampleData = $this->getSampleContent((string)$requestData['sample_type']);
                $originalName = $sampleData['name'];
                $tempPath = tempnam(sys_get_temp_dir(), 'doc_sample_');
                file_put_contents($tempPath, $sampleData['content']);
            } else {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'No document provided. Please upload a file (PDF, DOCX, CSV, TXT, etc.), pass base64 data, or paste text.'
                ]);
                return;
            }

            // Execute parsing & financial analysis
            $analysisResult = $this->parser->analyzeDocument($tempPath, $originalName, $mimeType);

            echo json_encode($analysisResult);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Document analysis failed: ' . $e->getMessage()
            ]);
        } finally {
            // Clean up temporary file if created locally (and not PHP native tmp)
            if ($tempPath && str_contains($tempPath, 'doc_upload_') && file_exists($tempPath)) {
                @unlink($tempPath);
            }
            if ($tempPath && str_contains($tempPath, 'doc_sample_') && file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * POST /api/documents/batch-post
     * Takes candidate items approved by user and posts balanced double-entry vouchers to ledger
     */
    public function batchPost(array $data): void
    {
        $user = Auth::requireAuth();
        $userId = (int)$user['sub'];

        $items = $data['items'] ?? [];
        if (!is_array($items) || empty($items)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'No transaction items provided to post.']);
            return;
        }

        // 1. Subscription Quota Check
        $txLimit = SubscriptionController::checkTransactionLimit($userId);
        if ($txLimit !== null) {
            if (!headers_sent()) { @http_response_code(403); }
            echo json_encode(array_merge(['success' => false], $txLimit));
            return;
        }

        // Check if posting all items will exceed subscription capacity
        $subCtrl = new SubscriptionController();
        $isAdmin = ($user['role'] ?? '') === 'admin';
        $mySub = $subCtrl->getSubscriptionDataForUser($userId, $isAdmin);
        $remaining = $mySub['limits']['transactions_remaining'] ?? 999999;
        $itemsCount = count($items);

        if ($itemsCount > $remaining) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'upgrade_required' => true,
                'error' => "Batch posting {$itemsCount} transactions exceeds your remaining plan quota ({$remaining} transactions remaining). Please upgrade your plan to continue.",
                'limits' => $mySub['limits'] ?? []
            ]);
            return;
        }

        // 2. Pre-fetch Chart of Accounts lookup
        $coaStmt = $this->db->query("SELECT id, name, code, type, gst_applicable FROM chart_of_accounts WHERE is_active = 1");
        $allAccounts = $coaStmt->fetchAll(PDO::FETCH_ASSOC);
        $accountsById = [];
        $accountsByName = [];
        foreach ($allAccounts as $acc) {
            $accountsById[(int)$acc['id']] = $acc;
            $accountsByName[strtolower(trim($acc['name']))] = $acc;
        }

        $postedEntries = [];
        $entryGroupIds = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        try {
            $this->db->beginTransaction();

            foreach ($items as $idx => $item) {
                $amount = (float)($item['amount'] ?? 0);
                if ($amount <= 0) {
                    continue; // Skip zero or negative values
                }

                $type = strtolower(trim((string)($item['type'] ?? 'debit')));
                if (!in_array($type, ['debit', 'credit'])) {
                    $type = 'debit';
                }

                $date = trim((string)($item['date'] ?? date('Y-m-d')));
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    $date = date('Y-m-d');
                }

                $description = trim((string)($item['description'] ?? 'Document Ingestion Transaction'));
                $accountId = isset($item['account_id']) ? (int)$item['account_id'] : 0;
                $accountName = trim((string)($item['account_name'] ?? ($item['suggested_account_name'] ?? '')));

                // Resolve account
                $targetAccount = null;
                if ($accountId > 0 && isset($accountsById[$accountId])) {
                    $targetAccount = $accountsById[$accountId];
                } elseif (!empty($accountName) && isset($accountsByName[strtolower($accountName)])) {
                    $targetAccount = $accountsByName[strtolower($accountName)];
                    $accountId = (int)$targetAccount['id'];
                }

                // Auto-create account if not found
                if (!$targetAccount && !empty($accountName)) {
                    $accType = ($type === 'credit') ? 'revenue' : 'expense';
                    if (preg_match('/(asset|computer|hardware|laptop|desk|chair|furniture|equipment|vehicle)/i', $accountName)) {
                        $accType = 'asset';
                    }
                    $code = $this->generateNextAccountCode($accType);
                    $ins = $this->db->prepare("
                        INSERT INTO chart_of_accounts (code, name, type, gst_applicable, description, is_active)
                        VALUES (?, ?, ?, 1, 'Auto-created from document upload', 1)
                    ");
                    $ins->execute([$code, $accountName, $accType]);
                    $newId = (int)$this->db->lastInsertId();
                    $targetAccount = [
                        'id' => $newId,
                        'name' => $accountName,
                        'code' => $code,
                        'type' => $accType,
                        'gst_applicable' => 1
                    ];
                    $accountId = $newId;
                    $accountsById[$newId] = $targetAccount;
                    $accountsByName[strtolower($accountName)] = $targetAccount;
                }

                // Default fallback if still no account
                if (!$targetAccount) {
                    $fallbackName = ($type === 'credit') ? 'Consulting Income' : 'Office Expense';
                    $targetAccount = $accountsByName[strtolower($fallbackName)] ?? ($allAccounts[0] ?? null);
                    $accountId = $targetAccount ? (int)$targetAccount['id'] : 1;
                }

                // Determine Supply Type (Revenue/Credit = outward, Expense/Asset/Debit = inward)
                $supplyType = ($targetAccount['type'] === 'revenue' || $type === 'credit') ? 'outward' : 'inward';

                // GST breakdown
                $gstRate = isset($item['gst_rate']) ? (float)$item['gst_rate'] : 0.0;
                $gstAmount = (float)($item['gst_amount'] ?? 0.0);
                if ($gstAmount == 0 && $gstRate > 0) {
                    $gstAmount = round(($amount * $gstRate) / 100.0, 2);
                }

                $isInterstate = !empty($item['is_interstate']);
                $cgst = 0.0;
                $sgst = 0.0;
                $igst = 0.0;

                if ($gstAmount > 0) {
                    if ($isInterstate) {
                        $igst = $gstAmount;
                    } else {
                        $cgst = round($gstAmount / 2.0, 2);
                        $sgst = round($gstAmount - $cgst, 2);
                    }
                }

                $entryGroupId = 'doc_grp_' . bin2hex(random_bytes(12));
                $entryGroupIds[] = $entryGroupId;

                // 1. Primary Ledger Entry
                $stmt = $this->db->prepare("
                    INSERT INTO transactions 
                    (user_id, account_id, type, amount, date, description, gst_amount, cgst, sgst, igst, supply_type, status, raw_ai_input, entry_group_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'posted', ?, ?)
                ");
                $stmt->execute([
                    $userId,
                    $accountId,
                    $type,
                    $amount,
                    $date,
                    $description,
                    $gstAmount,
                    $cgst,
                    $sgst,
                    $igst,
                    $supplyType,
                    "Document Upload: " . ($item['source_file'] ?? 'document'),
                    $entryGroupId
                ]);
                $txId = (int)$this->db->lastInsertId();

                // 2. Balancing Double-Entry Leg (Bank Operating Account: ID 2 or chosen payment account)
                $paymentAccountId = isset($item['payment_account_id']) ? (int)$item['payment_account_id'] : 2;
                if ($paymentAccountId <= 0) $paymentAccountId = 2;

                if ($paymentAccountId !== $accountId) {
                    $counterType = ($type === 'debit') ? 'credit' : 'debit';
                    $counterDesc = ($type === 'debit' ? 'Bank Outflow: ' : 'Bank Inflow: ') . $description;

                    $counterStmt = $this->db->prepare("
                        INSERT INTO transactions 
                        (user_id, account_id, type, amount, date, description, gst_amount, cgst, sgst, igst, supply_type, status, raw_ai_input, entry_group_id)
                        VALUES (?, ?, ?, ?, ?, ?, 0.0, 0.0, 0.0, 0.0, 'inward', 'posted', NULL, ?)
                    ");
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

                if ($type === 'debit') {
                    $totalDebit += $amount;
                } else {
                    $totalCredit += $amount;
                }

                $postedEntries[] = [
                    'id' => $txId,
                    'entry_group_id' => $entryGroupId,
                    'account_name' => $targetAccount['name'],
                    'type' => $type,
                    'amount' => $amount,
                    'date' => $date,
                    'description' => $description
                ];

                // Auto-train AI model with verified user account selection
                if (!empty($description) && !empty($targetAccount['name'])) {
                    AITrainingService::recordLearnedTransaction(
                        $description,
                        $targetAccount['name'],
                        $type,
                        $targetAccount['type'],
                        $gstAmount,
                        [$targetAccount['name']],
                        'document_verified',
                        0.95
                    );
                }
            }

            $this->db->commit();

            echo json_encode([
                'success' => true,
                'message' => "Successfully posted " . count($postedEntries) . " transactions to necessary ledger accounts.",
                'posted_count' => count($postedEntries),
                'total_debit' => round($totalDebit, 2),
                'total_credit' => round($totalCredit, 2),
                'entry_group_ids' => array_values(array_unique($entryGroupIds)),
                'posted_entries' => $postedEntries
            ]);

        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Failed to post batch transactions: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * GET /api/documents/samples
     * Returns pre-configured sample document datasets for 1-click testing
     */
    public function getSamples(): void
    {
        $samples = [
            [
                'id' => 'bank_statement_csv',
                'title' => 'Bank Statement (HDFC Bank Statement - CSV)',
                'format' => 'csv',
                'description' => 'Multi-row statement containing cloud hosting, client payments, laptop purchases, office rent, salary and snacks with debits and credits.',
                'filename' => 'HDFC_Bank_Statement_Aug2026.csv',
                'sample_type' => 'bank_statement_csv'
            ],
            [
                'id' => 'vendor_bill_txt',
                'title' => 'Contractor & Vendor Invoice (TXT / Bill)',
                'format' => 'txt',
                'description' => 'Detailed vendor tax invoice with software development fees, consulting retainers, and 18% GST.',
                'filename' => 'DevAgency_Tax_Invoice_2026_09.txt',
                'sample_type' => 'vendor_bill_txt'
            ],
            [
                'id' => 'hardware_purchase_docx',
                'title' => 'Office Hardware & Equipment Bill (Word DOCX / Table)',
                'format' => 'docx',
                'description' => 'Purchase bill for Dell workstations, ergonomic chairs, and network equipment.',
                'filename' => 'Office_Hardware_Procurement.docx',
                'sample_type' => 'hardware_purchase_docx'
            ]
        ];

        echo json_encode(['success' => true, 'samples' => $samples]);
    }

    /**
     * Sample content generator for instant demonstration
     */
    private function getSampleContent(string $type): array
    {
        switch ($type) {
            case 'bank_statement_csv':
                $csv = "Txn Date,Narration,Chq/Ref No,Debit,Credit,Balance\n" .
                       "2026-08-01,Opening Balance Ref 001,,0,0,150000.00\n" .
                       "2026-08-03,AWS Cloud Infrastructure Hosting,REF99281,4500.00,,145500.00\n" .
                       "2026-08-05,Client Payment from Acuity Tech for Web Development,NEFT8812,,75000.00,220500.00\n" .
                       "2026-08-10,Bought 2 Dell Desktops for Developers,UPI7712,48000.00,,172500.00\n" .
                       "2026-08-15,Office Rent for August paid to Landlord,CHQ1001,25000.00,,147500.00\n" .
                       "2026-08-20,Commission received for subcontract project,IMPS4412,,32000.00,179500.00\n" .
                       "2026-08-25,Staff Salary for August 2026,SAL8811,45000.00,,134500.00\n" .
                       "2026-08-28,Swiggy and Chai Snacks for Office Pantry,UPI9918,1250.00,,133250.00\n";
                return ['name' => 'HDFC_Bank_Statement_Aug2026.csv', 'content' => $csv];

            case 'vendor_bill_txt':
                $txt = "========================================================\n" .
                       "TAX INVOICE / BILL OF SUPPLY\n" .
                       "From: Apex Cloud Solutions Pvt Ltd\n" .
                       "GSTIN: 27AABCA1234F1Z5\n" .
                       "Invoice No: ACS/2026/089\n" .
                       "Date: 2026-08-22\n" .
                       "========================================================\n" .
                       "Billed To: My Enterprise\n" .
                       "Particulars:\n" .
                       "1. Cloud Architecture & Software Development - 65000\n" .
                       "2. Database Tuning & Cyber Security Audit - 25000\n" .
                       "3. Domain Registration & SSL Certificate - 2500\n" .
                       "--------------------------------------------------------\n" .
                       "Subtotal: Rs. 92,500.00\n" .
                       "CGST (9%): Rs. 8,325.00\n" .
                       "SGST (9%): Rs. 8,325.00\n" .
                       "Grand Total: Rs. 109,150.00\n" .
                       "========================================================\n";
                return ['name' => 'Apex_Vendor_Invoice_089.txt', 'content' => $txt];

            case 'hardware_purchase_docx':
            default:
                $txt = "OFFICE EQUIPMENT & FURNITURE PURCHASE ORDER\n" .
                       "Vendor: Supreme Furniture & Electronics Mart\n" .
                       "Date: 2026-08-18\n\n" .
                       "Items:\n" .
                       "1. Ergonomic Mesh Office Chairs (5 Units) - 22500\n" .
                       "2. Wooden Executive Computer Desks - 18000\n" .
                       "3. LG 27 inch 4K Monitors (2 Units) - 36000\n" .
                       "Grand Total: Rs. 76,500\n";
                return ['name' => 'Office_Hardware_Order.txt', 'content' => $txt];
        }
    }

    /**
     * Generates statutory account code based on account type
     */
    private function generateNextAccountCode(string $type): string
    {
        $prefixes = [
            'asset'     => '18',
            'liability' => '28',
            'equity'    => '38',
            'revenue'   => '48',
            'expense'   => '58',
        ];
        $prefix = $prefixes[$type] ?? '58';
        $rand = rand(10, 99);
        return $prefix . (string)$rand;
    }
}
