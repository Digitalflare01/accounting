<?php
namespace App\Controllers;

use App\Config\Database;
use App\Middleware\Auth;
use PDO;

require_once __DIR__ . '/SubscriptionController.php';

class InvoiceController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * GET /api/invoices
     * Lists all invoices created by the authenticated user
     */
    public function list(): void
    {
        $payload = Auth::requireAuth();
        $userId = (int)$payload['sub'];

        $stmt = $this->db->prepare("
            SELECT i.*, 
                   (SELECT COUNT(*) FROM invoice_items WHERE invoice_id = i.id) AS item_count
            FROM invoices i
            WHERE i.user_id = ?
            ORDER BY i.invoice_date DESC, i.id DESC
        ");
        $stmt->execute([$userId]);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'invoices' => $invoices
        ]);
    }

    /**
     * GET /api/invoices/{id}
     * Returns full invoice details, line items, and seller business profile
     */
    public function show(int $id): void
    {
        $payload = Auth::requireAuth();
        $userId = (int)$payload['sub'];

        $stmt = $this->db->prepare("SELECT * FROM invoices WHERE id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$id, $userId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$invoice) {
            if (!headers_sent()) { @http_response_code(404); }
            echo json_encode(['success' => false, 'error' => 'Invoice not found.']);
            return;
        }

        // Fetch line items
        $itemStmt = $this->db->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC");
        $itemStmt->execute([$id]);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch seller's business profile
        $userStmt = $this->db->prepare("
            SELECT id, email, business_name, owner_name, phone, address, city, state,
                   pincode, gst_number, pan_number, bank_name, bank_account_no,
                   bank_ifsc, bank_branch, upi_id, logo_data, invoice_terms, signature_title
            FROM users WHERE id = ? LIMIT 1
        ");
        $userStmt->execute([$userId]);
        $business = $userStmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'invoice' => $invoice,
            'items' => $items,
            'business' => $business
        ]);
    }

    /**
     * POST /api/invoices
     * Creates a new professional bill/invoice with line items and optional automatic ledger posting
     */
    public function create(array $requestData): void
    {
        $payload = Auth::requireAuth();
        $userId = (int)$payload['sub'];

        $invLimit = SubscriptionController::checkInvoiceLimit($userId);
        if ($invLimit !== null) {
            if (!headers_sent()) { @http_response_code(403); }
            echo json_encode(array_merge(['success' => false], $invLimit));
            return;
        }

        $customerName = trim($requestData['customer_name'] ?? '');
        if (empty($customerName)) {
            if (!headers_sent()) { @http_response_code(400); }
            echo json_encode(['success' => false, 'error' => 'Customer name is required.']);
            return;
        }

        $items = $requestData['items'] ?? [];
        if (empty($items) || !is_array($items)) {
            if (!headers_sent()) { @http_response_code(400); }
            echo json_encode(['success' => false, 'error' => 'At least one line item is required.']);
            return;
        }

        // Fetch user business profile to determine state for GST calculation
        $userStmt = $this->db->prepare("SELECT state, business_name FROM users WHERE id = ? LIMIT 1");
        $userStmt->execute([$userId]);
        $userProfile = $userStmt->fetch(PDO::FETCH_ASSOC);
        $sellerState = strtolower(trim($userProfile['state'] ?? ''));

        $invoiceDate = !empty($requestData['invoice_date']) ? trim($requestData['invoice_date']) : date('Y-m-d');
        $dueDate = !empty($requestData['due_date']) ? trim($requestData['due_date']) : date('Y-m-d', strtotime('+15 days'));
        
        // Generate or sanitize invoice number
        $invoiceNumber = trim($requestData['invoice_number'] ?? '');
        if (empty($invoiceNumber)) {
            $invoiceNumber = 'INV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
        }

        $customerPhone = trim($requestData['customer_phone'] ?? '');
        $customerEmail = trim($requestData['customer_email'] ?? '');
        $customerAddress = trim($requestData['customer_address'] ?? '');
        $customerState = trim($requestData['customer_state'] ?? '');
        $customerGstin = strtoupper(trim($requestData['customer_gstin'] ?? ''));
        $placeOfSupply = trim($requestData['place_of_supply'] ?? ($customerState ?: $sellerState));
        $paymentStatus = in_array(strtolower($requestData['payment_status'] ?? ''), ['paid', 'unpaid', 'partial']) 
            ? strtolower($requestData['payment_status']) 
            : 'unpaid';
        $paymentMode = trim($requestData['payment_mode'] ?? 'credit');
        $notes = trim($requestData['notes'] ?? '');
        $terms = trim($requestData['terms'] ?? '');
        $postToLedger = !empty($requestData['post_to_ledger']) && ($requestData['post_to_ledger'] === true || $requestData['post_to_ledger'] === 1 || $requestData['post_to_ledger'] === '1' || $requestData['post_to_ledger'] === 'true');

        // Determine if supply is interstate or intrastate
        $custStateNorm = strtolower(trim($customerState ?: $placeOfSupply));
        $isInterstate = (!empty($sellerState) && !empty($custStateNorm) && $sellerState !== $custStateNorm);

        // Process line items and calculate totals
        $computedItems = [];
        $subtotal = 0.0;
        $totalCgst = 0.0;
        $totalSgst = 0.0;
        $totalIgst = 0.0;

        foreach ($items as $item) {
            $desc = trim($item['item_description'] ?? ($item['description'] ?? ''));
            if (empty($desc)) continue;

            $hsn = trim($item['hsn_code'] ?? ($item['hsn_sac'] ?? ''));
            $qty = max(0.001, (float)($item['quantity'] ?? 1.0));
            $unit = trim($item['unit'] ?? 'Pcs');
            $price = max(0.0, (float)($item['unit_price'] ?? 0.0));
            $discount = max(0.0, min(100.0, (float)($item['discount_percent'] ?? 0.0)));
            $gstRate = max(0.0, (float)($item['gst_rate'] ?? 18.0));

            $baseAmount = $qty * $price;
            $taxable = round($baseAmount * (1.0 - ($discount / 100.0)), 2);

            $cgst = 0.0;
            $sgst = 0.0;
            $igst = 0.0;

            if ($isInterstate) {
                $igst = round($taxable * ($gstRate / 100.0), 2);
            } else {
                $halfRate = $gstRate / 2.0;
                $cgst = round($taxable * ($halfRate / 100.0), 2);
                $sgst = round($taxable * ($halfRate / 100.0), 2);
            }

            $lineTotal = round($taxable + $cgst + $sgst + $igst, 2);

            $subtotal += $taxable;
            $totalCgst += $cgst;
            $totalSgst += $sgst;
            $totalIgst += $igst;

            $computedItems[] = [
                'item_description' => $desc,
                'hsn_code' => $hsn,
                'quantity' => $qty,
                'unit' => $unit,
                'unit_price' => $price,
                'discount_percent' => $discount,
                'gst_rate' => $gstRate,
                'taxable_amount' => $taxable,
                'cgst' => $cgst,
                'sgst' => $sgst,
                'igst' => $igst,
                'total_amount' => $lineTotal
            ];
        }

        if (empty($computedItems)) {
            if (!headers_sent()) { @http_response_code(400); }
            echo json_encode(['success' => false, 'error' => 'Please provide valid line items with descriptions.']);
            return;
        }

        $subtotal = round($subtotal, 2);
        $totalCgst = round($totalCgst, 2);
        $totalSgst = round($totalSgst, 2);
        $totalIgst = round($totalIgst, 2);
        $grandTotal = round($subtotal + $totalCgst + $totalSgst + $totalIgst, 2);

        $entryGroupId = 'inv_grp_' . bin2hex(random_bytes(8));

        $this->db->beginTransaction();
        try {
            // 1. Insert Invoice
            $invStmt = $this->db->prepare("
                INSERT INTO invoices (
                    user_id, invoice_number, invoice_date, due_date,
                    customer_name, customer_phone, customer_email, customer_address,
                    customer_state, customer_gstin, place_of_supply,
                    subtotal, cgst_amount, sgst_amount, igst_amount, total_amount,
                    payment_status, payment_mode, notes, terms,
                    posted_to_ledger, transaction_group_id
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?
                )
            ");
            $invStmt->execute([
                $userId, $invoiceNumber, $invoiceDate, $dueDate,
                $customerName, $customerPhone ?: null, $customerEmail ?: null, $customerAddress ?: null,
                $customerState ?: null, $customerGstin ?: null, $placeOfSupply ?: null,
                $subtotal, $totalCgst, $totalSgst, $totalIgst, $grandTotal,
                $paymentStatus, $paymentMode, $notes ?: null, $terms ?: null,
                $postToLedger ? 1 : 0, $postToLedger ? $entryGroupId : null
            ]);
            $invoiceId = (int)$this->db->lastInsertId();

            // 2. Insert Invoice Items
            $itemInsert = $this->db->prepare("
                INSERT INTO invoice_items (
                    invoice_id, item_description, hsn_code, quantity, unit,
                    unit_price, discount_percent, gst_rate,
                    taxable_amount, cgst, sgst, igst, total_amount
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?, ?
                )
            ");
            foreach ($computedItems as $ci) {
                $itemInsert->execute([
                    $invoiceId, $ci['item_description'], $ci['hsn_code'] ?: null, $ci['quantity'], $ci['unit'],
                    $ci['unit_price'], $ci['discount_percent'], $ci['gst_rate'],
                    $ci['taxable_amount'], $ci['cgst'], $ci['sgst'], $ci['igst'], $ci['total_amount']
                ]);
            }

            // 3. Post to Dedicated Accounts Ledger if checkbox ticked
            if ($postToLedger) {
                $txInsert = $this->db->prepare("
                    INSERT INTO transactions (
                        user_id, account_id, type, amount, date, description,
                        gst_amount, cgst, sgst, igst, supply_type, status,
                        raw_ai_input, entry_group_id
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, 'posted',
                        ?, ?
                    )
                ");

                // Determine Debit Account:
                // If paid via cash: Cash and Cash Equivalents (1010)
                // If paid via bank/upi: Bank Operating Account (1020)
                // If unpaid/credit: Accounts Receivable / Debtors (1100)
                if ($paymentStatus === 'paid') {
                    if (strtolower($paymentMode) === 'cash') {
                        $debitAccountId = $this->getAccountIdByCode('1010', 1);
                        $debitDesc = "Cash received: Invoice #{$invoiceNumber} from {$customerName}";
                    } else {
                        $debitAccountId = $this->getAccountIdByCode('1020', 2);
                        $debitDesc = "Bank receipt: Invoice #{$invoiceNumber} from {$customerName} via " . strtoupper($paymentMode);
                    }
                } else {
                    $debitAccountId = $this->getAccountIdByCode('1100', 3);
                    $debitDesc = "Receivable for Invoice #{$invoiceNumber} from {$customerName}";
                }

                // A. Debit Customer/Bank/Cash for Gross Invoice Amount
                $txInsert->execute([
                    $userId, $debitAccountId, 'debit', $grandTotal, $invoiceDate, $debitDesc,
                    0.0, 0.0, 0.0, 0.0, 'outward',
                    "Bill generated for {$customerName} (#{$invoiceNumber})", $entryGroupId
                ]);

                // B. Credit Sales Revenue Account for Taxable Amount
                $revenueAccountId = $this->getAccountIdByCode('4030', 18);
                $revDesc = "Sales Revenue: Invoice #{$invoiceNumber} to {$customerName}";
                $txInsert->execute([
                    $userId, $revenueAccountId, 'credit', $subtotal, $invoiceDate, $revDesc,
                    0.0, 0.0, 0.0, 0.0, 'outward',
                    null, $entryGroupId
                ]);

                // C. Credit Output GST Accounts
                if ($totalCgst > 0) {
                    $cgstAccId = $this->getAccountIdByCode('2110', 10);
                    $txInsert->execute([
                        $userId, $cgstAccId, 'credit', $totalCgst, $invoiceDate,
                        "Output CGST on Invoice #{$invoiceNumber}",
                        $totalCgst, $totalCgst, 0.0, 0.0, 'outward',
                        null, $entryGroupId
                    ]);
                }
                if ($totalSgst > 0) {
                    $sgstAccId = $this->getAccountIdByCode('2120', 11);
                    $txInsert->execute([
                        $userId, $sgstAccId, 'credit', $totalSgst, $invoiceDate,
                        "Output SGST on Invoice #{$invoiceNumber}",
                        $totalSgst, 0.0, $totalSgst, 0.0, 'outward',
                        null, $entryGroupId
                    ]);
                }
                if ($totalIgst > 0) {
                    $igstAccId = $this->getAccountIdByCode('2130', 12);
                    $txInsert->execute([
                        $userId, $igstAccId, 'credit', $totalIgst, $invoiceDate,
                        "Output IGST on Invoice #{$invoiceNumber}",
                        $totalIgst, 0.0, 0.0, $totalIgst, 'outward',
                        null, $entryGroupId
                    ]);
                }
            }

            $this->db->commit();

            // Fetch created invoice, line items, and business profile for immediate preview & print
            $invFetch = $this->db->prepare("SELECT * FROM invoices WHERE id = ? LIMIT 1");
            $invFetch->execute([$invoiceId]);
            $createdInvoice = $invFetch->fetch(PDO::FETCH_ASSOC);

            $itemFetch = $this->db->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC");
            $itemFetch->execute([$invoiceId]);
            $createdItems = $itemFetch->fetchAll(PDO::FETCH_ASSOC);

            $bizFetch = $this->db->prepare("
                SELECT id, email, business_name, owner_name, phone, address, city, state,
                       pincode, gst_number, pan_number, bank_name, bank_account_no,
                       bank_ifsc, bank_branch, upi_id, logo_data, invoice_terms, signature_title
                FROM users WHERE id = ? LIMIT 1
            ");
            $bizFetch->execute([$userId]);
            $business = $bizFetch->fetch(PDO::FETCH_ASSOC);

            if (!headers_sent()) { @http_response_code(201); }
            echo json_encode([
                'success' => true,
                'message' => 'Invoice generated successfully' . ($postToLedger ? ' and posted to accounts ledger.' : '.'),
                'invoice_id' => $invoiceId,
                'invoice_number' => $invoiceNumber,
                'total_amount' => $grandTotal,
                'posted_to_ledger' => $postToLedger,
                'payment_status' => $paymentStatus,
                'invoice_date' => $invoiceDate,
                'invoice' => $createdInvoice,
                'items' => $createdItems,
                'business' => $business
            ]);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            if (!headers_sent()) { @http_response_code(500); }
            echo json_encode(['success' => false, 'error' => 'Failed to create invoice: ' . $e->getMessage()]);
        }
    }

    /**
     * DELETE /api/invoices/{id}
     * Deletes invoice, its line items, and any posted ledger transactions
     */
    public function delete(int $id): void
    {
        $payload = Auth::requireAuth();
        $userId = (int)$payload['sub'];

        $stmt = $this->db->prepare("SELECT * FROM invoices WHERE id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$id, $userId]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$inv) {
            if (!headers_sent()) { @http_response_code(404); }
            echo json_encode(['success' => false, 'error' => 'Invoice not found.']);
            return;
        }

        $this->db->beginTransaction();
        try {
            // Delete associated transactions if posted
            if (!empty($inv['transaction_group_id'])) {
                $delTx = $this->db->prepare("DELETE FROM transactions WHERE entry_group_id = ? AND user_id = ?");
                $delTx->execute([$inv['transaction_group_id'], $userId]);
            }

            // Delete line items
            $delItems = $this->db->prepare("DELETE FROM invoice_items WHERE invoice_id = ?");
            $delItems->execute([$id]);

            // Delete invoice
            $delInv = $this->db->prepare("DELETE FROM invoices WHERE id = ?");
            $delInv->execute([$id]);

            $this->db->commit();

            echo json_encode(['success' => true, 'message' => 'Invoice and associated records deleted.']);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            if (!headers_sent()) { @http_response_code(500); }
            echo json_encode(['success' => false, 'error' => 'Failed to delete invoice: ' . $e->getMessage()]);
        }
    }

    private function getAccountIdByCode(string $code, int $defaultId): int
    {
        try {
            $stmt = $this->db->prepare("SELECT id FROM chart_of_accounts WHERE code = ? LIMIT 1");
            $stmt->execute([$code]);
            $val = $stmt->fetchColumn();
            return $val ? (int)$val : $defaultId;
        } catch (\Throwable $e) {
            return $defaultId;
        }
    }
}
