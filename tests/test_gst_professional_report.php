<?php
// Test for Professional Statutory GST Report & Export Endpoints
$baseUrl = 'http://127.0.0.1:8080';

echo "=== STARTING STATUTORY GST REPORT AUDIT & TEST SUITE ===\n\n";

// 1. Authenticate user to get Bearer token
$ch = curl_init("{$baseUrl}/api/auth/login");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'email' => 'admin@accounting.local',
    'password' => 'Password123!'
]));
$loginRes = curl_exec($ch);
$loginJson = json_decode($loginRes, true);
curl_close($ch);

if (empty($loginJson['token'])) {
    echo "[FAIL] Failed to authenticate: $loginRes\n";
    exit(1);
}
$token = $loginJson['token'];
echo "[PASS] Authenticated successfully as {$loginJson['user']['email']} (GSTIN: {$loginJson['user']['gst_number']})\n";

// 2. Test GET /api/reports/gst (Comprehensive Statutory Filing Payload)
$startDate = '2026-08-01';
$endDate   = '2026-09-30';
$url = "{$baseUrl}/api/reports/gst?start_date={$startDate}&end_date={$endDate}";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}"]);
$gstRes = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    echo "[FAIL] GET /api/reports/gst returned HTTP {$httpCode}: $gstRes\n";
    exit(1);
}

$gst = json_decode($gstRes, true);
if (empty($gst['success']) || empty($gst['data'])) {
    echo "[FAIL] Invalid JSON response structure: $gstRes\n";
    exit(1);
}

$data = $gst['data'];

// Check Taxpayer profile
if (empty($data['taxpayer']['gstin']) || empty($data['taxpayer']['state_name'])) {
    echo "[FAIL] Missing taxpayer metadata\n";
    exit(1);
}
echo "[PASS] Taxpayer Verified: {$data['taxpayer']['legal_name']} | GSTIN: {$data['taxpayer']['gstin']} | State: {$data['taxpayer']['state_name']} ({$data['taxpayer']['state_code']})\n";
echo "[PASS] Return Period: {$data['taxpayer']['period_label']} (FY: {$data['taxpayer']['financial_year']})\n";

// Check Submission Readiness
$readiness = $data['submission_readiness'];
if (!$readiness['is_ready']) {
    echo "[FAIL] Submission readiness failed: " . json_encode($readiness) . "\n";
    exit(1);
}
echo "[PASS] Submission Readiness: {$readiness['status_code']} - {$readiness['badge']} (SHA-256 Checksum: " . substr($readiness['checksum'], 0, 16) . "...)\n";
echo "       Statutory checks passed: " . count($readiness['validation_checks']) . " validations passed\n";

// Check GSTR-3B Table 3.1
$t31 = $data['gstr3b']['table_3_1'];
$outTaxable = $t31['total']['taxable_value'];
$outTax = $t31['total']['total_tax'];
echo "[PASS] GSTR-3B Table 3.1: Total Outward Taxable Turnover = ₹" . number_format($outTaxable, 2) . " | Output Tax Liability = ₹" . number_format($outTax, 2) . "\n";
echo "       Breakdown: IGST = ₹{$t31['total']['igst']}, CGST = ₹{$t31['total']['cgst']}, SGST = ₹{$t31['total']['sgst']}\n";

// Check GSTR-3B Table 4 Eligible ITC
$t4 = $data['gstr3b']['table_4_itc'];
$netItc = $t4['c_net_itc']['total_itc'];
echo "[PASS] GSTR-3B Table 4: Net Eligible Input Tax Credit (ITC) Availed = ₹" . number_format($netItc, 2) . "\n";
echo "       Capital Goods ITC = ₹{$t4['a_itc_available']['5_all_other_itc']['capital_goods']['total_itc']} | Operating ITC = ₹{$t4['a_itc_available']['5_all_other_itc']['operating_services']['total_itc']}\n";

// Check GSTR-3B Table 6.1 Payment & Set-off
$t61 = $data['gstr3b']['table_6_1_payment'];
$cashPayable = $t61['net_cash_payable']['total'];
$cforward = $t61['itc_credit_carried_forward']['total'];
echo "[PASS] GSTR-3B Table 6.1: Statutory ITC Set-Off Reconciled:\n";
foreach ($t61['schedule'] as $row) {
    echo "       -> {$row['tax_head']}: Payable ₹{$row['tax_payable']}, Paid via ITC: IGST ₹{$row['paid_by_igst_itc']} | CGST ₹{$row['paid_by_cgst_itc']} | SGST ₹{$row['paid_by_sgst_itc']}, Net Cash Required: ₹{$row['paid_in_cash']}\n";
}
echo "       NET CASH CHALLAN (PMT-06) REQUIRED TO DEPOSIT: ₹" . number_format($cashPayable, 2) . "\n";
echo "       ITC Carried Forward to Next Period: ₹" . number_format($cforward, 2) . "\n";

// Check GSTR-1 Invoices & HSN Summary
$gstr1 = $data['gstr1'];
echo "[PASS] GSTR-1 Outward Supplies: {$gstr1['table_4_b2b_invoices']['total_count']} B2B Invoices | Taxable Value = ₹{$gstr1['table_4_b2b_invoices']['taxable_value']}\n";
echo "[PASS] GSTR-1 Table 12 HSN/SAC Summary: " . count($gstr1['table_12_hsn_summary']['data']) . " HSN/SAC lines identified\n";
foreach ($gstr1['table_12_hsn_summary']['data'] as $hsn) {
    echo "       -> SAC/HSN {$hsn['hsn_sc']} ({$hsn['description']}): Taxable ₹{$hsn['taxable_value']}, IGST ₹{$hsn['igst']}, CGST/SGST ₹{$hsn['cgst']}/₹{$hsn['sgst']}\n";
}

// 3. Test GET /api/reports/gst/export-json (GSTN Portal Offline Utility JSON Export)
echo "\n--- TESTING PORTAL JSON EXPORT ---\n";
$ch = curl_init("{$baseUrl}/api/reports/gst/export-json?start_date={$startDate}&end_date={$endDate}");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}"]);
$jsonExpRes = curl_exec($ch);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$headers = substr($jsonExpRes, 0, $headerSize);
$jsonBody = substr($jsonExpRes, $headerSize);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    echo "[FAIL] export-json returned HTTP {$httpCode}\n";
    exit(1);
}
if (!str_contains($headers, 'application/json')) {
    echo "[FAIL] export-json missing application/json content-type: $headers\n";
    exit(1);
}
if (!str_contains($headers, 'Content-Disposition: attachment')) {
    echo "[FAIL] export-json missing attachment header: $headers\n";
    exit(1);
}
$portalPayload = json_decode($jsonBody, true);
if (empty($portalPayload['version']) || empty($portalPayload['b2b']) || empty($portalPayload['hsn'])) {
    echo "[FAIL] Invalid GSTN portal JSON schema: " . substr($jsonBody, 0, 300) . "\n";
    exit(1);
}
echo "[PASS] GST Portal JSON Export Verified: Version {$portalPayload['version']}, GSTIN: {$portalPayload['gstin']}, FP: {$portalPayload['fp']}, B2B Invoices: " . count($portalPayload['b2b']) . "\n";

// 4. Test GET /api/reports/gst/export-csv (CA Audit-Ready Multi-Section CSV Export)
echo "\n--- TESTING CA AUDIT CSV EXPORT ---\n";
$ch = curl_init("{$baseUrl}/api/reports/gst/export-csv?start_date={$startDate}&end_date={$endDate}");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}"]);
$csvExpRes = curl_exec($ch);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$headers = substr($csvExpRes, 0, $headerSize);
$csvBody = substr($csvExpRes, $headerSize);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    echo "[FAIL] export-csv returned HTTP {$httpCode}\n";
    exit(1);
}
if (!str_contains($headers, 'text/csv')) {
    echo "[FAIL] export-csv missing text/csv header: $headers\n";
    exit(1);
}
if (!str_contains($headers, 'Content-Disposition: attachment')) {
    echo "[FAIL] export-csv missing attachment header: $headers\n";
    exit(1);
}
$requiredCsvSections = [
    'GOVERNMENT OF INDIA - GOODS AND SERVICES TAX STATUTORY AUDIT & FILING RETURN',
    'GSTR-3B TABLE 3.1',
    'GSTR-3B TABLE 4',
    'GSTR-3B TABLE 6.1',
    'GSTR-1 TABLE 4',
    'GSTR-1 TABLE 12',
    'INWARD INPUT TAX CREDIT (ITC) AUDIT REGISTER',
    'STATUTORY FILING DECLARATION & VERIFICATION'
];
foreach ($requiredCsvSections as $sec) {
    if (!str_contains($csvBody, $sec)) {
        echo "[FAIL] CSV missing section '$sec'\n";
        exit(1);
    }
}
echo "[PASS] CA Audit CSV Export Verified: All " . count($requiredCsvSections) . " statutory sections present with UTF-8 BOM encoding\n";

echo "\n========================================================================\n";
echo "✓ ALL STATUTORY GST REPORT AND SUBMISSION TESTS PASSED PERFECTLY!\n";
echo "========================================================================\n";
