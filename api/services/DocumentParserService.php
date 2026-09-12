<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use PDO;
use ZipArchive;

require_once dirname(__DIR__) . '/config/Database.php';
require_once __DIR__ . '/AIService.php';

/**
 * Intelligent Multi-Format Financial Document Ingestion & Extraction Engine
 * Supports: PDF, DOC, DOCX, TXT, CSV, TSV, XLSX, Markdown, and Images
 */
class DocumentParserService
{
    private PDO $db;
    private AIService $aiService;

    public function __construct(?PDO $db = null, ?AIService $aiService = null)
    {
        $this->db = $db ?? Database::getConnection();
        $this->aiService = $aiService ?? new AIService();
    }

    /**
     * Main entry point: Extracts text/tables from file and parses candidate transactions
     */
    public function analyzeDocument(string $filePath, string $originalName, ?string $mimeType = null): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new \RuntimeException("Document file not accessible: {$filePath}");
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $fileSize = filesize($filePath) ?: 0;

        $rawText = '';
        $tabularRows = null;
        $detectionMode = 'heuristic';

        switch ($extension) {
            case 'csv':
            case 'tsv':
                $tabularRows = $this->extractCsvOrTsv($filePath, $extension);
                $detectionMode = 'tabular_delimited';
                break;

            case 'xlsx':
                $tabularRows = $this->extractXlsx($filePath);
                $detectionMode = 'tabular_spreadsheet';
                break;

            case 'docx':
                $docxData = $this->extractDocx($filePath);
                $rawText = $docxData['text'];
                $tabularRows = !empty($docxData['tables']) ? $docxData['tables'] : null;
                $detectionMode = 'word_document';
                break;

            case 'doc':
                $rawText = $this->extractDocBinary($filePath);
                $detectionMode = 'legacy_word';
                break;

            case 'pdf':
                $pdfData = $this->extractPdf($filePath);
                $rawText = $pdfData['text'];
                $detectionMode = $pdfData['source'] ?? 'pdf_stream_decoder';
                break;

            case 'txt':
            case 'md':
            case 'log':
            case 'json':
                $rawText = (string)file_get_contents($filePath);
                $detectionMode = 'plain_text';
                break;

            case 'png':
            case 'jpg':
            case 'jpeg':
            case 'webp':
                $imgData = $this->extractImageViaGemini($filePath, $mimeType ?: "image/{$extension}");
                if ($imgData !== null && !empty($imgData['transactions'])) {
                    return $this->formatAnalysisResult(
                        $imgData['transactions'],
                        $originalName,
                        $extension,
                        $fileSize,
                        'multimodal_vision',
                        $imgData['document_type'] ?? 'receipt_or_invoice',
                        $imgData['raw_text'] ?? ''
                    );
                }
                $rawText = "Scanned receipt / bill photo: {$originalName}";
                $detectionMode = 'image_ocr_fallback';
                break;

            default:
                $rawText = (string)file_get_contents($filePath);
                $detectionMode = 'generic_fallback';
                break;
        }

        // 1. If tabular rows were extracted (CSV, TSV, XLSX, or Word Tables)
        if (!empty($tabularRows) && count($tabularRows) >= 2) {
            $parsedTransactions = $this->parseTabularRows($tabularRows);
            $docType = $this->classifyDocumentType($parsedTransactions, $originalName, $rawText);
            return $this->formatAnalysisResult(
                $parsedTransactions,
                $originalName,
                $extension,
                $fileSize,
                $detectionMode,
                $docType,
                $this->summarizeTabularPreview($tabularRows)
            );
        }

        // 2. Multimodal Gemini parsing for PDF if text extraction was sparse or API key exists
        $apiKey = $this->aiService->getGeminiApiKey();
        if (!empty($apiKey) && ($extension === 'pdf') && (strlen(trim($rawText)) < 150 || str_contains($rawText, 'Scan') || str_contains($rawText, 'Invoice'))) {
            $geminiDoc = $this->extractDocumentViaGemini($filePath, 'application/pdf');
            if ($geminiDoc !== null && !empty($geminiDoc['transactions'])) {
                return $this->formatAnalysisResult(
                    $geminiDoc['transactions'],
                    $originalName,
                    $extension,
                    $fileSize,
                    'gemini_multimodal_pdf',
                    $geminiDoc['document_type'] ?? 'tax_invoice',
                    $rawText
                );
            }
        }

        // 3. Unstructured Text Parsing (PDF text streams, TXT, DOC, DOCX narrative)
        $parsedTransactions = $this->parseTextTransactions($rawText, $originalName);
        $docType = $this->classifyDocumentType($parsedTransactions, $originalName, $rawText);

        return $this->formatAnalysisResult(
            $parsedTransactions,
            $originalName,
            $extension,
            $fileSize,
            $detectionMode,
            $docType,
            substr($rawText, 0, 1000)
        );
    }

    /**
     * CSV and TSV Extraction
     */
    private function extractCsvOrTsv(string $filePath, string $extension): array
    {
        $rows = [];
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return [];
        }

        // Detect delimiter: sniff first few lines
        $delimiter = ($extension === 'tsv') ? "\t" : ',';
        $firstLine = fgets($handle);
        if ($firstLine !== false && $extension !== 'tsv') {
            $commaCount = substr_count($firstLine, ',');
            $semicolonCount = substr_count($firstLine, ';');
            $tabCount = substr_count($firstLine, "\t");
            if ($semicolonCount > $commaCount && $semicolonCount > $tabCount) {
                $delimiter = ';';
            } elseif ($tabCount > $commaCount && $tabCount > $semicolonCount) {
                $delimiter = "\t";
            }
            rewind($handle);
        } else {
            rewind($handle);
        }

        while (($data = fgetcsv($handle, 4096, $delimiter)) !== false) {
            // Filter out empty rows
            $hasData = false;
            foreach ($data as $cell) {
                if (trim((string)$cell) !== '') {
                    $hasData = true;
                    break;
                }
            }
            if ($hasData) {
                $rows[] = array_map('trim', $data);
            }
        }
        fclose($handle);

        return $rows;
    }

    /**
     * DOCX XML Extraction using ZipArchive
     */
    private function extractDocx(string $filePath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            return ['text' => (string)file_get_contents($filePath), 'tables' => []];
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if (!$xml) {
            return ['text' => '', 'tables' => []];
        }

        $tables = [];
        // Extract XML tables if present (<w:tbl>)
        if (preg_match_all('/<w:tbl\b[^>]*>(.*?)<\/w:tbl>/is', $xml, $tblMatches)) {
            foreach ($tblMatches[1] as $tblXml) {
                if (preg_match_all('/<w:tr\b[^>]*>(.*?)<\/w:tr>/is', $tblXml, $trMatches)) {
                    $curTableRows = [];
                    foreach ($trMatches[1] as $trXml) {
                        $cells = [];
                        if (preg_match_all('/<w:tc\b[^>]*>(.*?)<\/w:tc>/is', $trXml, $tcMatches)) {
                            foreach ($tcMatches[1] as $tcXml) {
                                $textOnly = trim(strip_tags(str_replace(['<w:p', '</w:p>'], ["\n<w:p", "\n"], $tcXml)));
                                $cells[] = preg_replace('/\s+/', ' ', $textOnly);
                            }
                        }
                        if (!empty($cells) && array_filter($cells, fn($c) => trim((string)$c) !== '')) {
                            $curTableRows[] = $cells;
                        }
                    }
                    if (count($curTableRows) >= 2) {
                        $tables = array_merge($tables, $curTableRows);
                    }
                }
            }
        }

        // Clean paragraph text
        $text = str_replace(['</w:p>', '</w:tr>'], ["\n", "\n"], $xml);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/\n\s*\n+/", "\n", $text);

        return [
            'text' => trim($text),
            'tables' => $tables
        ];
    }

    /**
     * Binary DOC extraction (legacy Word 97-2003)
     */
    private function extractDocBinary(string $filePath): string
    {
        $content = (string)file_get_contents($filePath);
        if (empty($content)) {
            return '';
        }

        // Filter printable sequences
        $text = '';
        if (preg_match_all('/[\x20-\x7E\r\n\t]{4,}/', $content, $matches)) {
            $text = implode("\n", $matches[0]);
        }

        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
        $text = preg_replace("/\n\s*\n+/", "\n", $text);
        return trim($text);
    }

    /**
     * XLSX Extraction without third-party dependencies using ZipArchive
     */
    private function extractXlsx(string $filePath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            return [];
        }

        // 1. Read Shared Strings (xl/sharedStrings.xml)
        $sharedStrings = [];
        $stringsXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($stringsXml) {
            if (preg_match_all('/<si\b[^>]*>(.*?)<\/si>/is', $stringsXml, $siMatches)) {
                foreach ($siMatches[1] as $si) {
                    $sharedStrings[] = trim(strip_tags($si));
                }
            }
        }

        // 2. Read first worksheet (xl/worksheets/sheet1.xml)
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if (!$sheetXml) {
            return [];
        }

        $rows = [];
        if (preg_match_all('/<row\b[^>]*>(.*?)<\/row>/is', $sheetXml, $rowMatches)) {
            foreach ($rowMatches[1] as $rowXml) {
                $cells = [];
                if (preg_match_all('/<c\b([^>]*)>(.*?)<\/c>/is', $rowXml, $cMatches, PREG_SET_ORDER)) {
                    foreach ($cMatches as $c) {
                        $attrs = $c[1];
                        $valXml = $c[2];
                        $isSharedString = (strpos($attrs, 't="s"') !== false);

                        $val = '';
                        if (preg_match('/<v>(.*?)<\/v>/is', $valXml, $vMatch)) {
                            $val = $vMatch[1];
                            if ($isSharedString) {
                                $idx = (int)$val;
                                $val = $sharedStrings[$idx] ?? $val;
                            }
                        } elseif (preg_match('/<t>(.*?)<\/t>/is', $valXml, $tMatch)) {
                            $val = $tMatch[1];
                        }
                        $cells[] = trim((string)$val);
                    }
                }
                if (!empty($cells) && array_filter($cells, fn($c) => trim((string)$c) !== '')) {
                    $rows[] = $cells;
                }
            }
        }

        return $rows;
    }

    /**
     * Pure PHP PDF stream extractor (decompresses FlateDecode streams and parses text operators)
     */
    public function extractPdf(string $filePath): array
    {
        $content = (string)file_get_contents($filePath);
        if (empty($content)) {
            return ['text' => '', 'source' => 'empty_pdf'];
        }

        $allText = [];

        // 1. Scan for all streams: stream ... endstream
        if (preg_match_all('/stream[\r\n]+(.*?)[\r\n]+endstream/s', $content, $matches)) {
            foreach ($matches[1] as $streamData) {
                $decompressed = null;

                // Attempt zlib decompress (FlateDecode)
                if (function_exists('gzuncompress')) {
                    $decompressed = @gzuncompress($streamData);
                }
                if ($decompressed === false || $decompressed === null) {
                    if (function_exists('gzinflate')) {
                        $decompressed = @gzinflate($streamData);
                    }
                }

                $textSource = ($decompressed !== false && !empty($decompressed)) ? $decompressed : $streamData;
                $extractedFromStream = $this->parsePdfTextOperators($textSource);
                if (!empty($extractedFromStream)) {
                    $allText[] = $extractedFromStream;
                }
            }
        }

        // 2. Also scan for uncompressed text outside streams
        $outsideText = $this->parsePdfTextOperators($content);
        if (!empty($outsideText)) {
            $allText[] = $outsideText;
        }

        $fullText = implode("\n", $allText);
        $fullText = preg_replace("/[ \t]+/", ' ', $fullText);
        $fullText = preg_replace("/\n\s*\n+/", "\n", $fullText);

        return [
            'text' => trim($fullText),
            'source' => 'pdf_stream_decoder'
        ];
    }

    /**
     * Decodes text operators inside PDF content streams: (text) Tj, [(text) -50 (more)] TJ, etc.
     */
    private function parsePdfTextOperators(string $stream): string
    {
        $result = [];

        // Pattern A: [(...) ... (...)] TJ (array text showing)
        if (preg_match_all('/\[(.*?)\]\s*TJ/s', $stream, $tjMatches)) {
            foreach ($tjMatches[1] as $tjGroup) {
                if (preg_match_all('/\((.*?)\)/s', $tjGroup, $strMatches)) {
                    $line = '';
                    foreach ($strMatches[1] as $s) {
                        $line .= $this->unescapePdfString($s);
                    }
                    if (trim($line) !== '') {
                        $result[] = trim($line);
                    }
                }
            }
        }

        // Pattern B: (text) Tj or ' or "
        if (preg_match_all('/\(((?:[^\\\\\)]|\\\\.)*)\)\s*(?:Tj|\'|")/s', $stream, $tMatches)) {
            foreach ($tMatches[1] as $s) {
                $decoded = $this->unescapePdfString($s);
                if (trim($decoded) !== '') {
                    $result[] = trim($decoded);
                }
            }
        }

        // Pattern C: BT ... ET text blocks
        if (preg_match_all('/BT[\r\n]+(.*?)[\r\n]+ET/s', $stream, $btMatches)) {
            foreach ($btMatches[1] as $btContent) {
                if (preg_match_all('/\(((?:[^\\\\\)]|\\\\.)*)\)/s', $btContent, $innerMatches)) {
                    $innerStr = '';
                    foreach ($innerMatches[1] as $in) {
                        $innerStr .= $this->unescapePdfString($in) . ' ';
                    }
                    if (trim($innerStr) !== '') {
                        $result[] = trim($innerStr);
                    }
                }
            }
        }

        return implode("\n", array_unique($result));
    }

    /**
     * Unescapes PDF literal strings (\(, \), \\, \ddd octal)
     */
    private function unescapePdfString(string $str): string
    {
        $str = preg_replace_callback('/\\\\([0-7]{1,3})/', function ($m) {
            return chr(octdec($m[1]));
        }, $str);

        $replacements = [
            '\\\\' => '\\',
            '\\('  => '(',
            '\\)'  => ')',
            '\\n'  => "\n",
            '\\r'  => "\r",
            '\\t'  => "\t",
            '\\b'  => "\b",
            '\\f'  => "\f",
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $str);
    }

    /**
     * Google Gemini Multimodal Document Parser (PDF / TXT / DOC)
     */
    public function extractDocumentViaGemini(string $filePath, string $mimeType = 'application/pdf'): ?array
    {
        $apiKey = $this->aiService->getGeminiApiKey();
        if (empty($apiKey) || strlen($apiKey) < 20) {
            return null;
        }

        $base64 = base64_encode((string)file_get_contents($filePath));
        $model = $this->aiService->getGeminiModel();
        $coaContext = $this->aiService->getChartOfAccountsSummary();

        $prompt = <<<PROMPT
You are an expert Indian Corporate Forensic Accountant and Tax Preparation AI.
Extract ALL financial transactions, bank statement rows, or invoice line items from this document into structured accounting ledger data.

Available Chart of Accounts:
{$coaContext}

### Schema Requirement:
Output ONLY valid JSON matching this schema:
{
  "document_type": "bank_statement" | "tax_invoice" | "expense_log",
  "transactions": [
    {
      "date": "YYYY-MM-DD",
      "description": "Clear narration or line item description",
      "type": "debit" | "credit",
      "amount": <positive number float>,
      "suggested_account_name": "Accurate Chart of Accounts name e.g. Office Rent, Fixed Asset - Computers, Software Subscriptions & Cloud, Consulting Income",
      "gst_rate": <0 | 5 | 12 | 18 | 28>,
      "gst_amount": <number float>,
      "is_interstate": <boolean>,
      "confidence_score": <float between 0.85 and 0.99>
    }
  ]
}

Rules:
- For Bank Statements: Deposits / Inflows are "credit". Withdrawals / Expenses / Asset Purchases are "debit".
- For Invoices: If we are the seller, items are "credit" (Revenue). If we are the buyer / vendor bill, items are "debit" (Expense/Asset).
- Parse dates to YYYY-MM-DD format.
- Output ONLY valid JSON. No markdown code blocks.
PROMPT;

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        [
                            'inlineData' => [
                                'mimeType' => $mimeType,
                                'data' => $base64
                            ]
                        ],
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'responseMimeType' => 'application/json'
            ]
        ];

        $candidateModels = array_unique([$model, 'gemini-2.5-flash', 'gemini-1.5-flash']);
        foreach ($candidateModels as $curModel) {
            $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$curModel}:generateContent?key={$apiKey}";
            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json',
                'x-goog-api-key: ' . $apiKey
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
                $data = json_decode($response, true);
                $textPart = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
                $cleanJson = preg_replace('/^```(?:json)?/i', '', trim($textPart));
                $cleanJson = preg_replace('/```$/', '', trim($cleanJson));
                $parsed = json_decode($cleanJson, true);
                if (is_array($parsed) && isset($parsed['transactions']) && is_array($parsed['transactions'])) {
                    $parsed['transactions'] = $this->enrichTransactionsWithAccounts($parsed['transactions']);
                    return $parsed;
                }
            }
        }

        return null;
    }

    /**
     * Image Extraction via Gemini Vision
     */
    public function extractImageViaGemini(string $filePath, string $mimeType): ?array
    {
        return $this->extractDocumentViaGemini($filePath, $mimeType);
    }

    /**
     * Parses Tabular Rows (CSV, TSV, XLSX, Word Tables) into Transactions
     */
    public function parseTabularRows(array $rows): array
    {
        if (count($rows) < 2) {
            return [];
        }

        // 1. Identify header row (usually row 0, or first row with text headers)
        $headerIndex = -1;
        $colMap = [
            'date' => -1,
            'description' => -1,
            'debit' => -1,
            'credit' => -1,
            'amount' => -1,
            'type' => -1,
            'balance' => -1,
            'ref' => -1,
            'tax' => -1,
        ];

        foreach ($rows as $idx => $r) {
            $mapped = $this->detectHeaderColumns($r);
            if (($mapped['description'] !== -1 || $mapped['date'] !== -1) && ($mapped['amount'] !== -1 || $mapped['debit'] !== -1 || $mapped['credit'] !== -1)) {
                $headerIndex = $idx;
                $colMap = $mapped;
                break;
            }
        }

        // Fallback header detection if no exact keywords found
        if ($headerIndex === -1) {
            $colMap = $this->guessColumnsFromData($rows);
            $headerIndex = 0; // treat row 0 as header or data
        }

        $transactions = [];
        $today = date('Y-m-d');

        for ($i = $headerIndex + 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            if (empty($row) || count($row) < 2) {
                continue;
            }

            // Extract Date
            $rawDate = ($colMap['date'] !== -1 && isset($row[$colMap['date']])) ? $row[$colMap['date']] : '';
            $date = $this->normalizeDate($rawDate) ?: $today;

            // Extract Narration / Description
            $rawDesc = ($colMap['description'] !== -1 && isset($row[$colMap['description']])) ? $row[$colMap['description']] : '';
            if (empty($rawDesc)) {
                // Pick longest non-numeric cell
                foreach ($row as $ci => $cell) {
                    if ($ci !== $colMap['date'] && !is_numeric(str_replace([',', ' '], '', $cell)) && strlen($cell) > strlen($rawDesc)) {
                        $rawDesc = $cell;
                    }
                }
            }
            if (empty(trim($rawDesc))) {
                $rawDesc = "Transaction on " . $date;
            }

            // Extract Amount & Type (Debit vs Credit)
            $type = 'debit';
            $amount = 0.0;

            if ($colMap['debit'] !== -1 && $colMap['credit'] !== -1) {
                $debitVal = $this->cleanAmount($row[$colMap['debit']] ?? '');
                $creditVal = $this->cleanAmount($row[$colMap['credit']] ?? '');

                if ($creditVal > 0 && $debitVal == 0) {
                    $type = 'credit';
                    $amount = $creditVal;
                } elseif ($debitVal > 0) {
                    $type = 'debit';
                    $amount = $debitVal;
                } else {
                    continue; // No monetary value in this row
                }
            } elseif ($colMap['amount'] !== -1 && isset($row[$colMap['amount']])) {
                $amount = $this->cleanAmount($row[$colMap['amount']]);
                if ($amount == 0) {
                    continue;
                }

                // Check type column if present
                if ($colMap['type'] !== -1 && isset($row[$colMap['type']])) {
                    $typeStr = strtolower(trim($row[$colMap['type']]));
                    if (str_contains($typeStr, 'cr') || str_contains($typeStr, 'credit') || str_contains($typeStr, 'deposit')) {
                        $type = 'credit';
                    } else {
                        $type = 'debit';
                    }
                } else {
                    // Infer from description
                    $inferred = $this->aiService->parseFinancialText($rawDesc);
                    $type = $inferred['transaction_type'] ?? 'debit';
                }
            } else {
                // Look for any numeric float in the row
                foreach ($row as $ci => $cell) {
                    if ($ci === $colMap['date']) continue;
                    $val = $this->cleanAmount($cell);
                    if ($val > 0) {
                        $amount = $val;
                        break;
                    }
                }
                if ($amount <= 0) continue;
                $inferred = $this->aiService->parseFinancialText($rawDesc);
                $type = $inferred['transaction_type'] ?? 'debit';
            }

            // Extract Tax/GST rate if available
            $gstRate = 0;
            if ($colMap['tax'] !== -1 && isset($row[$colMap['tax']])) {
                $gstRate = (int)preg_replace('/[^\d]/', '', $row[$colMap['tax']]);
            }
            if (!in_array($gstRate, [0, 5, 12, 18, 28])) {
                $gstRate = 18; // Standard default for corporate billing in India
            }

            $transactions[] = [
                'id' => 'row_' . ($i + 1),
                'date' => $date,
                'description' => trim($rawDesc),
                'type' => $type,
                'amount' => round($amount, 2),
                'gst_rate' => $gstRate,
                'gst_amount' => round(($amount * $gstRate) / 100.0, 2),
                'is_interstate' => false,
                'payment_account_id' => 2, // Bank Operating Account
                'selected' => true
            ];
        }

        return $this->enrichTransactionsWithAccounts($transactions);
    }

    /**
     * Parses free-form text into transactions (e.g. Invoices, Bills, Statement lines)
     */
    public function parseTextTransactions(string $text, string $fileName): array
    {
        $lines = explode("\n", $text);
        $transactions = [];
        $today = date('Y-m-d');

        // Pattern 1: Check if text is a single invoice with header & total
        $invoiceCheck = $this->detectInvoiceStructure($text);
        if ($invoiceCheck !== null && !empty($invoiceCheck['items'])) {
            return $this->enrichTransactionsWithAccounts($invoiceCheck['items']);
        }

        // Pattern 2: Multi-line transaction logs (e.g. "12/08/2026 Paid AWS hosting 4500", "15-Aug Bought Dell Monitor 18000")
        foreach ($lines as $idx => $line) {
            $trimmed = trim($line);
            if (strlen($trimmed) < 6) continue;

            // Must contain a monetary amount
            if (!preg_match('/(?:rs\.?|inr|₹|\$)?\s*([\d,]+(?:\.\d{1,2})?)/i', $trimmed)) {
                continue;
            }

            // Extract date if present
            $date = $this->extractDateFromText($trimmed) ?: $today;

            // Run through AI Financial Parser
            $aiResult = $this->aiService->parseFinancialText($trimmed);
            $amount = (float)($aiResult['parsed_amount'] ?? 0);

            if ($amount > 0) {
                $type = $aiResult['transaction_type'] ?? 'debit';
                $gstAmount = (float)($aiResult['gst_extracted'] ?? 0);
                $gstRate = ($amount > 0 && $gstAmount > 0) ? (int)round(($gstAmount / $amount) * 100) : 18;
                if (!in_array($gstRate, [0, 5, 12, 18, 28])) $gstRate = 18;

                $transactions[] = [
                    'id' => 'line_' . ($idx + 1),
                    'date' => $date,
                    'description' => $trimmed,
                    'type' => $type,
                    'amount' => $amount,
                    'suggested_account_name' => $aiResult['suggested_account_name'] ?? ($type === 'credit' ? 'Consulting Income' : 'Office Expense'),
                    'review_options' => $aiResult['review_options'] ?? [],
                    'gst_rate' => $gstRate,
                    'gst_amount' => round(($amount * $gstRate) / 100.0, 2),
                    'is_interstate' => false,
                    'payment_account_id' => 2,
                    'confidence_score' => $aiResult['confidence_score'] ?? 0.90,
                    'selected' => true
                ];
            }
        }

        return $this->enrichTransactionsWithAccounts($transactions);
    }

    /**
     * Detects if text represents a single invoice/bill and extracts line items & grand totals
     */
    private function detectInvoiceStructure(string $text): ?array
    {
        $hasInvoiceKeyword = (bool)preg_match('/(tax invoice|bill of supply|invoice|quotation|purchase order|cash receipt|retail invoice)/i', $text);
        if (!$hasInvoiceKeyword) {
            return null;
        }

        $today = date('Y-m-d');
        $invoiceDate = $this->extractDateFromText($text) ?: $today;

        // Detect buyer vs seller (inward bill vs outward invoice)
        $isInwardBill = (bool)preg_match('/\b(billed to|purchased from|vendor|supplier|payable|due to)\b/i', $text);
        $type = $isInwardBill ? 'debit' : 'credit';

        $items = [];
        $lines = explode("\n", $text);

        foreach ($lines as $idx => $line) {
            $l = trim($line);
            // Match numbered line items e.g. "1. Software Development Retainer 80,000" or "Web Hosting Charges - 5000"
            if (preg_match('/^(?:\d+[\.\)]\s*)?([A-Za-z0-9\s\-_&,]+?)\s*[-:]?\s*(?:rs\.?|inr|₹|\$)?\s*([\d,]+(?:\.\d{1,2})?)$/i', $l, $m)) {
                $desc = trim($m[1]);
                $amt = (float)str_replace(',', '', $m[2]);
                if ($amt > 0 && !preg_match('/^(subtotal|total|cgst|sgst|igst|tax|balance|discount|round)/i', $desc)) {
                    $items[] = [
                        'id' => 'inv_item_' . ($idx + 1),
                        'date' => $invoiceDate,
                        'description' => $desc,
                        'type' => $type,
                        'amount' => $amt,
                        'gst_rate' => 18,
                        'gst_amount' => round($amt * 0.18, 2),
                        'is_interstate' => false,
                        'payment_account_id' => 2,
                        'selected' => true
                    ];
                }
            }
        }

        // If no discrete items matched, but a grand total exists
        if (empty($items) && preg_match('/(?:grand\s*total|net\s*amount|total\s*payable|total\s*amount|total)\s*[:=]?\s*(?:rs\.?|inr|₹|\$)?\s*([\d,]+(?:\.\d{1,2})?)/i', $text, $tm)) {
            $grandTotal = (float)str_replace(',', '', $tm[1]);
            if ($grandTotal > 0) {
                // Extract invoice title or vendor name
                $vendor = 'Vendor Bill / Invoice';
                if (preg_match('/(?:from|m\/s|vendor|supplier)\s*[:=]?\s*([A-Za-z0-9\s\.\-]{3,40})/i', $text, $vm)) {
                    $vendor = trim($vm[1]);
                }
                $items[] = [
                    'id' => 'inv_total_1',
                    'date' => $invoiceDate,
                    'description' => "Invoice: {$vendor}",
                    'type' => $type,
                    'amount' => $grandTotal,
                    'gst_rate' => 18,
                    'gst_amount' => round($grandTotal * 0.18, 2),
                    'is_interstate' => false,
                    'payment_account_id' => 2,
                    'selected' => true
                ];
            }
        }

        return !empty($items) ? ['items' => $items, 'date' => $invoiceDate] : null;
    }

    /**
     * Enriches transactions with matched Chart of Accounts details
     */
    private function enrichTransactionsWithAccounts(array $transactions): array
    {
        // Load all active accounts once
        $accountsStmt = $this->db->query("SELECT id, name, code, type, gst_applicable FROM chart_of_accounts WHERE is_active = 1 ORDER BY type, code ASC");
        $allAccounts = $accountsStmt->fetchAll(PDO::FETCH_ASSOC);

        $accountsByName = [];
        foreach ($allAccounts as $acc) {
            $accountsByName[strtolower($acc['name'])] = $acc;
        }

        $enriched = [];
        foreach ($transactions as $tx) {
            $desc = $tx['description'] ?? '';
            $type = $tx['type'] ?? 'debit';
            $amount = (float)($tx['amount'] ?? 0);

            // Determine suggested account if not already provided
            $suggestedName = $tx['suggested_account_name'] ?? '';
            $reviewOptions = $tx['review_options'] ?? [];

            if (empty($suggestedName)) {
                $ai = $this->aiService->parseFinancialText($desc);
                $suggestedName = $ai['suggested_account_name'] ?? ($type === 'credit' ? 'Consulting Income' : 'Office Expense');
                $reviewOptions = $ai['review_options'] ?? [];
            }

            // Find matching account ID
            $targetAcc = $accountsByName[strtolower($suggestedName)] ?? null;

            // Fallback to type defaults if not found
            if (!$targetAcc) {
                if ($type === 'credit') {
                    $targetAcc = $accountsByName['consulting income'] ?? ($accountsByName['software development services'] ?? null);
                } else {
                    $targetAcc = $accountsByName['office expense'] ?? null;
                }
            }

            // Ensure review options has valid list
            if (empty($reviewOptions)) {
                $reviewOptions = ($type === 'credit')
                    ? ['Consulting Income', 'Software Development Services', 'Commission Income', 'Sales of Hardware & Goods']
                    : ['Office Expense', 'Fixed Asset - Computers', 'Software Subscriptions & Cloud', 'Salaries & Wages Expense', 'Office Rent'];
            }

            $tx['target_account'] = $targetAcc;
            $tx['suggested_account_name'] = $targetAcc ? $targetAcc['name'] : $suggestedName;
            $tx['account_id'] = $targetAcc ? (int)$targetAcc['id'] : 0;
            $tx['review_options'] = $reviewOptions;
            $tx['confidence_score'] = $tx['confidence_score'] ?? 0.92;

            $enriched[] = $tx;
        }

        return $enriched;
    }

    /**
     * Detects header mapping from column strings
     */
    private function detectHeaderColumns(array $row): array
    {
        $map = [
            'date' => -1,
            'description' => -1,
            'debit' => -1,
            'credit' => -1,
            'amount' => -1,
            'type' => -1,
            'balance' => -1,
            'ref' => -1,
            'tax' => -1,
        ];

        foreach ($row as $idx => $cell) {
            $c = strtolower(trim((string)$cell));

            if ($map['date'] === -1 && preg_match('/\b(date|txn\s*date|value\s*date|booking\s*date|trans\s*date)\b/i', $c)) {
                $map['date'] = $idx;
            } elseif ($map['description'] === -1 && preg_match('/\b(description|narration|particulars|details|remarks|transaction\s*details|summary|note)\b/i', $c)) {
                $map['description'] = $idx;
            } elseif ($map['debit'] === -1 && preg_match('/\b(debit|withdrawal|dr|paid\s*out|outflow|expense)\b/i', $c)) {
                $map['debit'] = $idx;
            } elseif ($map['credit'] === -1 && preg_match('/\b(credit|deposit|cr|paid\s*in|inflow|receipt|income)\b/i', $c)) {
                $map['credit'] = $idx;
            } elseif ($map['amount'] === -1 && preg_match('/\b(amount|net\s*amount|total|txn\s*amount)\b/i', $c)) {
                $map['amount'] = $idx;
            } elseif ($map['type'] === -1 && preg_match('/\b(type|dr\/cr|cr\/dr|transaction\s*type)\b/i', $c)) {
                $map['type'] = $idx;
            } elseif ($map['balance'] === -1 && preg_match('/\b(balance|running\s*balance|avail\s*bal)\b/i', $c)) {
                $map['balance'] = $idx;
            } elseif ($map['ref'] === -1 && preg_match('/\b(ref|chq|cheque|reference|utr|txn\s*id)\b/i', $c)) {
                $map['ref'] = $idx;
            } elseif ($map['tax'] === -1 && preg_match('/\b(tax|gst|gst\s*rate|vat)\b/i', $c)) {
                $map['tax'] = $idx;
            }
        }

        return $map;
    }

    /**
     * Fallback column guesser based on cell values
     */
    private function guessColumnsFromData(array $rows): array
    {
        $map = [
            'date' => -1,
            'description' => -1,
            'debit' => -1,
            'credit' => -1,
            'amount' => -1,
            'type' => -1,
            'balance' => -1,
            'ref' => -1,
            'tax' => -1,
        ];

        // Sample up to 5 rows
        $sample = array_slice($rows, 0, 5);
        $colCount = count($sample[0] ?? []);

        for ($c = 0; $c < $colCount; $c++) {
            $isDate = true;
            $isNum = true;
            $avgLen = 0;

            foreach ($sample as $r) {
                $val = trim((string)($r[$c] ?? ''));
                if (!$this->normalizeDate($val)) $isDate = false;
                if (!is_numeric(str_replace([',', ' '], '', $val))) $isNum = false;
                $avgLen += strlen($val);
            }
            $avgLen /= max(count($sample), 1);

            if ($isDate && $map['date'] === -1) {
                $map['date'] = $c;
            } elseif ($isNum && $map['amount'] === -1) {
                $map['amount'] = $c;
            } elseif (!$isNum && $avgLen > 6 && $map['description'] === -1) {
                $map['description'] = $c;
            }
        }

        return $map;
    }

    /**
     * Cleans string to float amount strictly for numeric and currency strings
     */
    private function cleanAmount(string $val): float
    {
        $val = trim($val);
        if (preg_match('/^(?:rs\.?|inr|₹|\$)?\s*([\d,]+(?:\.\d{1,2})?)\s*$/i', $val, $m)) {
            return (float)str_replace(',', '', $m[1]);
        }
        $test = str_replace([',', ' '], '', $val);
        if (is_numeric($test)) {
            return (float)$test;
        }
        return 0.0;
    }

    /**
     * Normalizes diverse date formats to YYYY-MM-DD
     */
    private function normalizeDate(string $raw): ?string
    {
        $cleaned = trim($raw);
        if (empty($cleaned)) return null;

        // ISO format: 2026-09-12
        if (preg_match('/^(\d{4})[-\/\.](\d{1,2})[-\/\.](\d{1,2})$/', $cleaned, $m)) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }

        // Indian / UK format: 12/09/2026 or 12-09-2026
        if (preg_match('/^(\d{1,2})[-\/\.](\d{1,2})[-\/\.](\d{4})$/', $cleaned, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        // Named month format: 12-Sep-2026 or 12 September 2026
        $time = strtotime($cleaned);
        if ($time !== false && $time > 0) {
            return date('Y-m-d', $time);
        }

        return null;
    }

    /**
     * Extracts first valid date from arbitrary text string
     */
    private function extractDateFromText(string $text): ?string
    {
        // 12/09/2026 or 12-09-2026
        if (preg_match('/\b(\d{1,2}[-\/\.]\d{1,2}[-\/\.]\d{4})\b/', $text, $m)) {
            return $this->normalizeDate($m[1]);
        }
        // 2026-09-12
        if (preg_match('/\b(\d{4}[-\/\.]\d{1,2}[-\/\.]\d{1,2})\b/', $text, $m)) {
            return $this->normalizeDate($m[1]);
        }
        // 12-Aug-2026 or 12 Aug 2026
        if (preg_match('/\b(\d{1,2}[-\s](?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*[-\s]\d{4})\b/i', $text, $m)) {
            return $this->normalizeDate($m[1]);
        }
        return null;
    }

    /**
     * Classifies document type
     */
    private function classifyDocumentType(array $transactions, string $fileName, string $rawText): string
    {
        $fn = strtolower($fileName);
        $txText = strtolower($rawText);

        if (str_contains($fn, 'statement') || str_contains($txText, 'account statement') || str_contains($txText, 'bank statement') || count($transactions) > 3) {
            return 'bank_statement';
        }
        if (str_contains($fn, 'invoice') || str_contains($fn, 'bill') || str_contains($txText, 'tax invoice') || str_contains($txText, 'bill of supply')) {
            return 'tax_invoice';
        }
        if (str_contains($fn, 'salary') || str_contains($fn, 'payroll')) {
            return 'payroll_sheet';
        }
        return 'transaction_log';
    }

    /**
     * Summarizes first few rows of tabular data for UI preview
     */
    private function summarizeTabularPreview(array $rows): string
    {
        $previewRows = array_slice($rows, 0, 5);
        $lines = [];
        foreach ($previewRows as $r) {
            $lines[] = implode(' | ', $r);
        }
        return implode("\n", $lines);
    }

    /**
     * Formats final unified analysis response
     */
    private function formatAnalysisResult(
        array $transactions,
        string $originalName,
        string $extension,
        int $fileSize,
        string $detectionMode,
        string $docType,
        string $preview
    ): array {
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        $totalGst = 0.0;

        foreach ($transactions as $tx) {
            $amt = (float)($tx['amount'] ?? 0);
            $gst = (float)($tx['gst_amount'] ?? 0);
            if (($tx['type'] ?? 'debit') === 'debit') {
                $totalDebit += $amt;
            } else {
                $totalCredit += $amt;
            }
            $totalGst += $gst;
        }

        // Fetch all active Chart of Accounts so frontend can populate selection dropdowns
        $allAccountsStmt = $this->db->query("SELECT id, name, code, type, gst_applicable FROM chart_of_accounts WHERE is_active = 1 ORDER BY type, code ASC");
        $allAccounts = $allAccountsStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'success' => true,
            'file_info' => [
                'name' => $originalName,
                'extension' => $extension,
                'size_bytes' => $fileSize,
                'size_formatted' => $this->formatBytes($fileSize),
                'detection_mode' => $detectionMode,
                'document_type' => $docType,
            ],
            'summary' => [
                'total_transactions' => count($transactions),
                'total_debit' => round($totalDebit, 2),
                'total_credit' => round($totalCredit, 2),
                'total_gst' => round($totalGst, 2),
            ],
            'transactions' => $transactions,
            'all_accounts' => $allAccounts,
            'raw_preview' => $preview
        ];
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }
}
