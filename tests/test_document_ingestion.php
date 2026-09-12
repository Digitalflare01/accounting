<?php
declare(strict_types=1);

$baseUrl = 'http://localhost/accounting/api';

echo "====================================================================\n";
echo "TEST SUITE: DOCUMENT INGESTION, ANALYSIS & POSTING (PDF/DOC/CSV/TXT)\n";
echo "====================================================================\n\n";

$passed = 0;
$failed = 0;

function assertDoc(string $testName, bool $condition, string $details = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] $testName" . ($details ? " ($details)" : "") . "\n";
        $passed++;
    } else {
        echo "[FAIL] $testName" . ($details ? " - $details" : "") . "\n";
        $failed++;
    }
}

function req(string $method, string $url, array $data = null, string $token = null): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $headers = ['Content-Type: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    
    $response = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [
        'status' => $statusCode,
        'body' => json_decode((string)$response, true) ?: []
    ];
}

// 1. Authenticate Admin User
$auth = req('POST', "{$baseUrl}/auth/login", [
    'email' => 'admin@accounting.local',
    'password' => 'Password123!'
]);

assertDoc("Admin Login Status 200", $auth['status'] === 200);
$token = $auth['body']['token'] ?? '';
assertDoc("Valid JWT Auth Token Acquired", !empty($token));

// 2. Fetch Sample Documents Catalog
$samplesRes = req('GET', "{$baseUrl}/documents/samples", null, $token);
assertDoc("GET /documents/samples Status 200", $samplesRes['status'] === 200);
$samples = $samplesRes['body']['samples'] ?? [];
assertDoc("At least 3 sample documents available", count($samples) >= 3);

// 3. Test Sample Bank Statement Analysis
$analyzeSampleRes = req('POST', "{$baseUrl}/documents/analyze", [
    'sample_type' => 'bank_statement_csv'
], $token);

assertDoc("POST /documents/analyze with sample_type Status 200", $analyzeSampleRes['status'] === 200);
assertDoc("Response Success Flag True", ($analyzeSampleRes['body']['success'] ?? false) === true);

$fileInfo = $analyzeSampleRes['body']['file_info'] ?? [];
assertDoc("File Info Detected Document Type Bank Statement", ($fileInfo['document_type'] ?? '') === 'bank_statement');

$txs = $analyzeSampleRes['body']['transactions'] ?? [];
assertDoc("Extracted Transactions Count >= 6", count($txs) >= 6, "Found: " . count($txs));

$hasAws = false;
$hasLaptop = false;
$hasRent = false;
$hasClientPay = false;

foreach ($txs as $t) {
    $desc = strtolower($t['description'] ?? '');
    $acc = strtolower($t['suggested_account_name'] ?? '');
    if (str_contains($desc, 'aws')) {
        $hasAws = true;
        assertDoc("AWS row mapped to software or cloud account", 
            str_contains($acc, 'software') || str_contains($acc, 'cloud') || str_contains($acc, 'office expense'),
            "Mapped to: " . ($t['suggested_account_name'] ?? 'none')
        );
    }
    if (str_contains($desc, 'desktop') || str_contains($desc, 'laptop')) {
        $hasLaptop = true;
        assertDoc("Desktop/Laptop row mapped to Fixed Asset or Office Equipment", 
            str_contains($acc, 'computer') || str_contains($acc, 'fixed asset') || str_contains($acc, 'equipment'),
            "Mapped to: " . ($t['suggested_account_name'] ?? 'none')
        );
    }
    if (str_contains($desc, 'rent')) {
        $hasRent = true;
        assertDoc("Rent row mapped to Office Rent", 
            str_contains($acc, 'rent'),
            "Mapped to: " . ($t['suggested_account_name'] ?? 'none')
        );
    }
    if (str_contains($desc, 'client payment') || str_contains($desc, 'acuity')) {
        $hasClientPay = true;
        assertDoc("Client payment recognized as credit/revenue", 
            ($t['type'] ?? '') === 'credit',
            "Type: " . ($t['type'] ?? 'none')
        );
    }
}

assertDoc("Identified AWS hosting transaction", $hasAws);
assertDoc("Identified hardware purchase transaction", $hasLaptop);
assertDoc("Identified office rent transaction", $hasRent);
assertDoc("Identified client payment transaction", $hasClientPay);

// 4. Test Custom CSV File Upload via Base64
$customCsv = "Date,Description,Withdrawal,Deposit\n" .
             "2026-08-10,Domain renewal cloudflare,1200.00,\n" .
             "2026-08-14,Consulting fees received from Global Inc,,85000.00\n" .
             "2026-08-19,Tea coffee pantry supplies,850.00,\n";

$analyzeCsvRes = req('POST', "{$baseUrl}/documents/analyze", [
    'file_name' => 'august_ledger.csv',
    'file_data' => base64_encode($customCsv)
], $token);

assertDoc("Analyze custom base64 CSV Status 200", $analyzeCsvRes['status'] === 200);
$customTxs = $analyzeCsvRes['body']['transactions'] ?? [];
assertDoc("Parsed exactly 3 custom CSV transactions", count($customTxs) === 3);

// 5. Test TXT Vendor Invoice Analysis
$analyzeTxtRes = req('POST', "{$baseUrl}/documents/analyze", [
    'sample_type' => 'vendor_bill_txt'
], $token);

assertDoc("Analyze vendor bill TXT Status 200", $analyzeTxtRes['status'] === 200);
$txtTxs = $analyzeTxtRes['body']['transactions'] ?? [];
assertDoc("Parsed vendor bill line items (>= 3 items)", count($txtTxs) >= 3, "Count: " . count($txtTxs));

// 5B. Test Native DOCX File Analysis
$testDocxPath = __DIR__ . '/../scratch/sample_test.docx';
if (!file_exists($testDocxPath)) {
    $zip = new ZipArchive();
    if ($zip->open($testDocxPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:tbl><w:tr><w:tc><w:p><w:r><w:t>Item</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Amount</w:t></w:r></w:p></w:tc></w:tr><w:tr><w:tc><w:p><w:r><w:t>Ergonomic Chairs</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>24000</w:t></w:r></w:p></w:tc></w:tr><w:tr><w:tc><w:p><w:r><w:t>Dell Monitors</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>38000</w:t></w:r></w:p></w:tc></w:tr></w:tbl></w:body></w:document>';
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();
    }
}
$docxBase64 = base64_encode((string)file_get_contents($testDocxPath));
$analyzeDocxRes = req('POST', "{$baseUrl}/documents/analyze", [
    'file_name' => 'office_procurement.docx',
    'file_data' => $docxBase64
], $token);

assertDoc("Analyze DOCX via base64 Status 200", $analyzeDocxRes['status'] === 200);
$docxTxs = $analyzeDocxRes['body']['transactions'] ?? [];
assertDoc("Extracted 2 items from Word DOCX table", count($docxTxs) === 2);

// 5C. Test Native PDF File Analysis (Stream FlateDecode)
$testPdfPath = __DIR__ . '/../scratch/sample_test.pdf';
if (!file_exists($testPdfPath)) {
    $pdfText = "BT /F1 12 Tf (TAX INVOICE) Tj ET\nBT /F1 10 Tf (1. Cloud Server Hosting - 12500) Tj ET\nBT /F1 10 Tf (2. Office Internet Broadband - 3200) Tj ET\n";
    $cStream = gzcompress($pdfText);
    $pdfData = "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R >> endobj\n4 0 obj << /Length " . strlen($cStream) . " /Filter /FlateDecode >>\nstream\n{$cStream}\nendstream\nendobj\nxref\n0 5\n0000000000 65535 f \ntrailer << /Size 5 /Root 1 0 R >>\nstartxref\n350\n%%EOF\n";
    file_put_contents($testPdfPath, $pdfData);
}
$pdfBase64 = base64_encode((string)file_get_contents($testPdfPath));
$analyzePdfRes = req('POST', "{$baseUrl}/documents/analyze", [
    'file_name' => 'vendor_tax_bill.pdf',
    'file_data' => $pdfBase64
], $token);

assertDoc("Analyze PDF via base64 Status 200", $analyzePdfRes['status'] === 200);
$pdfTxs = $analyzePdfRes['body']['transactions'] ?? [];
assertDoc("Extracted items from PDF text streams", count($pdfTxs) >= 2);

// 6. Test Batch Posting Extracted Transactions to Ledger
$itemsToPost = [
    [
        'date' => '2026-08-10',
        'description' => 'Domain renewal cloudflare (Doc Ingest)',
        'type' => 'debit',
        'amount' => 1200.00,
        'account_name' => 'Software Subscriptions & Cloud',
        'gst_rate' => 18,
        'gst_amount' => 216.00,
        'is_interstate' => false,
        'payment_account_id' => 2
    ],
    [
        'date' => '2026-08-14',
        'description' => 'Consulting fees received from Global Inc (Doc Ingest)',
        'type' => 'credit',
        'amount' => 85000.00,
        'account_name' => 'Consulting Income',
        'gst_rate' => 18,
        'gst_amount' => 15300.00,
        'is_interstate' => false,
        'payment_account_id' => 2
    ]
];

$batchPostRes = req('POST', "{$baseUrl}/documents/batch-post", [
    'items' => $itemsToPost
], $token);

assertDoc("POST /documents/batch-post Status 200", $batchPostRes['status'] === 200);
assertDoc("Batch post reports 2 transactions posted", ($batchPostRes['body']['posted_count'] ?? 0) === 2);
assertDoc("Batch post returns entry group IDs", !empty($batchPostRes['body']['entry_group_ids']));

// 7. Verify In Ledger
$txListRes = req('GET', "{$baseUrl}/transactions", null, $token);
$allTxs = $txListRes['body']['data'] ?? [];
$foundCloudflare = false;
$foundGlobalInc = false;

foreach ($allTxs as $entry) {
    if (str_contains($entry['description'] ?? '', 'Domain renewal cloudflare')) {
        $foundCloudflare = true;
    }
    if (str_contains($entry['description'] ?? '', 'Consulting fees received from Global Inc')) {
        $foundGlobalInc = true;
    }
}

assertDoc("Domain renewal posted in ledger", $foundCloudflare);
assertDoc("Consulting fees posted in ledger", $foundGlobalInc);

// 8. Verify Trial Balance Equilibrium After Batch Post
$tbRes = req('GET', "{$baseUrl}/reports/trial-balance", null, $token);
assertDoc("Trial Balance Status 200", $tbRes['status'] === 200);
$tbBalanced = $tbRes['body']['data']['is_balanced'] ?? false;
$diff = $tbRes['body']['data']['difference'] ?? 0;
assertDoc("Trial Balance perfectly balanced after document batch post (Diff: ₹{$diff})", $tbBalanced);

echo "\n====================================================================\n";
echo "DOCUMENT INGESTION TEST SUMMARY: $passed Passed, $failed Failed\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}
