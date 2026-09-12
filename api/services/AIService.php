<?php
declare(strict_types=1);

namespace App\Services;

require_once __DIR__ . '/AITrainingService.php';
require_once __DIR__ . '/AccountingPrinciplesService.php';

/**
 * AI Service Proxy Client
 * Forwards natural language queries to Python FastAPI microservice (Gemini API)
 * with a resilient internal fallback for high availability.
 */
class AIService
{
    private string $microserviceUrl;
    private int $timeoutSeconds;
    private static bool $geminiUnavailableInThisRequest = false;
    private static bool $pythonUnavailableInThisRequest = false;

    private ?\PDO $db = null;

    public function __construct(string $microserviceUrl = 'http://127.0.0.1:8001', int $timeoutSeconds = 5)
    {
        $this->microserviceUrl = rtrim($microserviceUrl, '/');
        $this->timeoutSeconds = $timeoutSeconds;
        try {
            $this->db = \App\Config\Database::getConnection();
        } catch (\Throwable $e) {
            $this->db = null;
        }
    }

    /**
     * Forwards text to Gemini API, Python microservice, or resilient internal parser
     */
    public function parseFinancialText(string $rawText): array
    {
        $cleaned = trim($rawText);
        if (empty($cleaned)) {
            return [
                'parsed_amount'          => 0.0,
                'transaction_type'       => 'credit',
                'suggested_category'     => 'general',
                'suggested_account_name' => 'Consulting Income',
                'target_account'         => ['name' => 'Consulting Income', 'type' => 'revenue'],
                'needs_user_review'      => false,
                'review_options'         => ['Consulting Income', 'Office Expense'],
                'gst_extracted'          => 0.0,
                'confidence_score'       => 0.0,
                'source'                 => 'empty_input'
            ];
        }

        // 1. Direct Google Gemini API call (if configured with active key)
        $geminiResult = $this->callGeminiApi($cleaned);
        if ($geminiResult !== null) {
            return $geminiResult;
        }

        // 2. Python FastAPI microservice (if running locally)
        $pythonResult = $this->callPythonMicroservice($cleaned);
        if ($pythonResult !== null) {
            return $pythonResult;
        }

        // 3. AUTONOMOUS LOCAL TRAINED DATASET BRAIN!
        // When Gemini is down, rate-limited, offline, or returns error, use our local trained database
        $localResult = AITrainingService::findLocalMatch($cleaned);
        if ($localResult !== null) {
            $targetType = ($localResult['transaction_type'] === 'credit') ? 'revenue' : (
                in_array($localResult['suggested_category'] ?? '', ['electronics', 'furniture']) ? 'asset' : 'expense'
            );
            $localResult['target_account'] = [
                'name' => $localResult['suggested_account_name'],
                'type' => $targetType
            ];
            return $localResult;
        }

        // 4. Resilient internal intelligent accounting parser with typo tolerance & Indian business semantics
        $fallbackResult = $this->internalHeuristicParser($cleaned);
        $fallbackResult['source'] = 'ai_heuristic_engine';

        // Auto-train local dataset with high confidence heuristic matches
        if (!empty($fallbackResult['suggested_account_name']) && ($fallbackResult['confidence_score'] ?? 0) >= 0.80) {
            AITrainingService::recordLearnedTransaction(
                $cleaned,
                $fallbackResult['suggested_account_name'],
                $fallbackResult['transaction_type'],
                $fallbackResult['suggested_category'] ?? null,
                (float)($fallbackResult['gst_extracted'] ?? 0),
                $fallbackResult['review_options'] ?? [$fallbackResult['suggested_account_name']],
                'heuristic_fallback',
                0.85
            );
        }

        return $fallbackResult;
    }

    /**
     * Calls Google Gemini API directly using cURL and system settings
     */
    public function callGeminiApi(string $rawText): ?array
    {
        if (self::$geminiUnavailableInThisRequest) {
            return null;
        }

        $apiKey = $this->getGeminiApiKey();
        if (empty($apiKey) || strpos($apiKey, 'SampleGeminiKey') !== false || strlen($apiKey) < 20) {
            return null;
        }

        $model = $this->getGeminiModel();
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $coaContext = $this->getChartOfAccountsSummary();

        // Dynamically retrieve verified Few-Shot Exemplars from local training dataset to train Gemini
        $fewShotExemplars = AITrainingService::getFewShotExemplars($rawText, 4);
        $fewShotPrompt = AITrainingService::formatFewShotForGemini($fewShotExemplars);
        $principlesContext = AccountingPrinciplesService::getPrinciplesContextForAI();

        $systemPrompt = <<<PROMPT
You are a Senior Forensic Accountant and Indian Tax Compliance AI Specialist for ApexLedger AI.
Your job is to parse raw natural language financial descriptions into deterministic, structured accounting ledger JSON data.

{$principlesContext}

### STRICT INSTRUCTIONS:
1. You must output ONLY a valid, raw JSON object. No Markdown code fences (do NOT use ```json), no explanations.
2. The JSON object MUST strictly adhere to this exact schema:
{
  "parsed_amount": <number: float or integer>,
  "transaction_type": <string: "credit" or "debit">,
  "suggested_category": <string: category e.g. "commission", "consulting", "software_development", "electronics", "rent", "utilities", "salary">,
  "suggested_account_name": <string: specific target Chart of Accounts name, e.g. "Commission Income", "Software Development Services", "Office Rent", "Consulting Income", "Salaries & Wages Expense", "Fixed Asset - Computers">,
  "needs_user_review": <boolean: true if capital vs operational ambiguity like laptops/furniture, false otherwise>,
  "review_options": <array of strings: candidate Chart of Accounts names for user selection>,
  "gst_extracted": <number: numeric GST amount if mentioned or calculated, else 0>,
  "confidence_score": <number: float between 0.70 and 0.99>
}

{$fewShotPrompt}

### FINANCIAL CONVENTION & RULES:
- "parsed_amount": Extract numeric amount without currency symbols.
  - Indian colloquial terms: 1 lakh / lac = 100000, 1 crore / cr = 10000000, 50k = 50000.
- "transaction_type":
  - "credit": Money coming in, revenue, fees received, commission received, transfers in, client payments, sales of goods.
  - "debit": Money going out, expenses, asset purchases (e.g. buying laptops, desktops, furniture), bills (e.g. AWS, cloud hosting, electricity), supplier payments, salaries, commission paid.
- "suggested_account_name":
  - Parse colloquial terms, typos, and purchases:
    - "i buy office desktop" / "desktop" / "desktops" / "laptops" / "computers" / "macbook" / "monitors" -> "Fixed Asset - Computers" (with needs_user_review: true, review_options: ["Fixed Asset - Computers", "Office Expense"])
    - "furniture" / "desks" / "chairs" / "tables" -> "Office Equipment & Furniture" (with needs_user_review: true, review_options: ["Office Equipment & Furniture", "Office Expense"])
    - "receved commition for work transfer" / "commission" / "brokerage" / "referral" -> "Commission Income"
    - "commission paid" / "brokerage paid" -> "Commission & Brokerage Expense"
    - "salary paid" / "salaries" / "wages" / "payroll" -> "Salaries & Wages Expense"
    - "rent" -> "Office Rent"
    - "aws" / "cloud" / "hosting" / "saas" -> "Software Subscriptions & Cloud"
    - "consulting" / "advisory" -> "Consulting Income"
    - "software build" / "website" / "app dev" -> "Software Development Services"
    - "sold desktop" / "sold furniture" / "sale of hardware" -> "Sales of Hardware & Goods"
  - Current Chart of Accounts list:
    $coaContext
- "needs_user_review": true ONLY for capital asset vs operational expense ambiguity (e.g. Computers/Desktops or Furniture vs Office Expense).
PROMPT;

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => "Parse this transaction description: " . $rawText]
                    ]
                ]
            ],
            'systemInstruction' => [
                'parts' => [
                    ['text' => $systemPrompt]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'responseMimeType' => 'application/json'
            ]
        ];

        $candidateModels = array_unique([$model, 'gemini-2.5-flash', 'gemini-1.5-flash']);
        $response = false;
        $httpCode = 0;
        $activeModel = $model;

        foreach ($candidateModels as $curModel) {
            $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$curModel}:generateContent?key={$apiKey}";
            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_TIMEOUT, 12);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
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
                $activeModel = $curModel;
                break;
            }
        }

        if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
            $data = json_decode($response, true);
            $textPart = '';
            if (!empty($data['candidates'][0]['content']['parts'])) {
                foreach ($data['candidates'][0]['content']['parts'] as $part) {
                    if (isset($part['text']) && empty($part['thought'])) {
                        $textPart = $part['text'];
                        break;
                    }
                }
                if (empty($textPart)) {
                    $textPart = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
                }
            }

            $textPart = preg_replace('/^```(?:json)?/i', '', trim($textPart));
            $textPart = preg_replace('/```$/', '', trim($textPart));
            $parsed = json_decode(trim($textPart), true);
            if (is_array($parsed) && isset($parsed['parsed_amount']) && isset($parsed['transaction_type'])) {
                $parsed['source'] = 'google_gemini_api';
                $parsed['model'] = $model;
                $parsed['few_shot_trained'] = count($fewShotExemplars);
                if (empty($parsed['suggested_account_name'])) {
                    $parsed['suggested_account_name'] = $parsed['review_options'][0] ?? ($parsed['transaction_type'] === 'credit' ? 'Consulting Income' : 'Office Expense');
                }
                $targetType = ($parsed['transaction_type'] === 'credit') ? 'revenue' : (
                    in_array($parsed['suggested_category'] ?? '', ['electronics', 'furniture']) ? 'asset' : 'expense'
                );
                $parsed['target_account'] = [
                    'name' => $parsed['suggested_account_name'],
                    'type' => $targetType
                ];

                // Continuously train local dataset with Gemini's high-confidence results
                if (($parsed['confidence_score'] ?? 0) >= 0.85 && !empty($parsed['suggested_account_name'])) {
                    AITrainingService::recordLearnedTransaction(
                        $rawText,
                        $parsed['suggested_account_name'],
                        $parsed['transaction_type'],
                        $parsed['suggested_category'] ?? null,
                        (float)($parsed['gst_extracted'] ?? 0),
                        $parsed['review_options'] ?? [$parsed['suggested_account_name']],
                        'gemini_verified',
                        (float)$parsed['confidence_score']
                    );
                }

                return $parsed;
            }
        }

        self::$geminiUnavailableInThisRequest = true;
        return null;
    }

    /**
     * Forwards request to local Python FastAPI microservice if running
     */
    private function callPythonMicroservice(string $rawText): ?array
    {
        if (self::$pythonUnavailableInThisRequest) {
            return null;
        }

        $payload = json_encode(['text' => $rawText]);
        $endpoint = "{$this->microserviceUrl}/parse-transaction";

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'x-goog-api-key: ' . $this->getGeminiApiKey()
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
            $parsed = json_decode($response, true);
            if (is_array($parsed) && isset($parsed['parsed_amount'])) {
                $parsed['source'] = 'python_gemini_microservice';
                return $parsed;
            }
        }

        self::$pythonUnavailableInThisRequest = true;
        return null;
    }

    /**
     * Normalizes common typos, Indian phonetic spelling, and colloquial business terms
     */
    public function normalizeFinancialText(string $text): string
    {
        $lower = ' ' . strtolower(trim($text)) . ' ';

        $replacements = [
            '/\b(receved|recieved|recvd|rcvd|resived|reseived|reseved|receivd)\b/i' => 'received',
            '/\b(commition|commision|comision|comission|commissn|commisssion|commishun|commisn)\b/i' => 'commission',
            '/\b(brokrage|brockerage|brokrage)\b/i' => 'brokerage',
            '/\b(referal|referel)\b/i' => 'referral',
            '/\b(incentiv|insentive)\b/i' => 'incentive',
            '/\b(salery|salry|selary)\b/i' => 'salary',
            '/\b(advertisment|advertizing|advertisng|advt)\b/i' => 'advertising',
            '/\b(electrcity|electic|electrity|bijli)\b/i' => 'electricity',
            '/\b(maintanance|maintainance|maintence)\b/i' => 'maintenance',
            '/\b(machinary|mashinery)\b/i' => 'machinery',
            '/\b(farniture|furnicher|furnature)\b/i' => 'furniture',
            '/\b(softwere|sofware)\b/i' => 'software',
            '/\b(expence|expenss|expens)\b/i' => 'expense',
            '/\b(stationery|stationary)\b/i' => 'stationery',
            '/\b(consalting|consultng|consultancy)\b/i' => 'consulting',
            '/\b(purchesed|perchased|purchsed|purched)\b/i' => 'purchased',
            '/\b(bying|byed)\b/i' => 'bought',
            '/\b(loptop|labtop|laptap)\b/i' => 'laptop',
            '/\b(computr|compter|computor)\b/i' => 'computer',
            '/\b(deskop|destop|desktp)\b/i' => 'desktop',
            '/\b(erganomic|ergonimic)\b/i' => 'ergonomic',
        ];

        $lower = preg_replace(array_keys($replacements), array_values($replacements), $lower);
        return trim($lower);
    }

    /**
     * High-precision internal rule-based parser adhering strictly to the Gemini prompt schema
     */
    private function internalHeuristicParser(string $text): array
    {
        $normalized = $this->normalizeFinancialText($text);
        $searchSpace = strtolower(trim($text)) . ' ' . $normalized;

        // 1. Amount Extraction with Multiplier support (e.g. "3 clients they paid 60000 each")
        $amountData = $this->extractAmountAndMultiplier($searchSpace);
        $amount = (float)$amountData['amount'];

        // 2. High-Precision Credit vs Debit Classification
        // A. Explicit Purchase / Expense / Outflow cues
        $hasDebitCue = (bool)preg_match(
            '/\b(buy|buys|buying|bought|purchase|purchases|purchased|purchasing|procure|procured|procuring|procurement|acquire|acquired|acquiring|acquisition|order|ordered|ordering|i\s*buy|we\s*buy|i\s*paid|we\s*paid|paid|pay|paying|payment|spent|spending|spends|expense|expenditure|cost|costs|costing|debited|deducted|charges|charge|fee\s*paid|fees\s*paid|recharge|subscription|amc|bill|bills|rent\s*paid|salary\s*paid|wages\s*paid)\b/i',
            $searchSpace
        );

        // B. Explicit Sale / Income / Inflow cues
        $hasCreditCue = (bool)preg_match(
            '/\b(receiv(?:e|ed|ing|es)?|receved|recieved|rcvd|rcv|receipt|sold|sale|sales|selling|sell|sells|earned|earning|earnings|income|revenue|gain|dividend|yield|credited|deposit|deposited|inflow|collection|collected|invoiced|invoice\s*sent|billed\s*client|client\s*billed|client(?:s)?\s*paid|customer(?:s)?\s*paid|they\s*paid|paid\s*(?:to\s*)?(?:me|us)|capital\s*infusion|owner\s*investment)\b/i',
            $searchSpace
        );

        // C. Specific Context cues (Items inherently indicating outflow vs inflow when action verbs are omitted)
        $hasOutflowItemContext = (bool)preg_match(
            '/\b(desktop|desktops|laptop|laptops|macbook|imac|computer|computers|pc\b|monitor|monitors|server|servers|printer|printers|scanner|ups|chair|chairs|desk|desks|table|furniture|office\s*rent|electricity|power\s*bill|broadband|wifi|internet\s*bill|aws|cloud\s*hosting|hosting\s*bill|repair|maintenance|servicing|cleaning|tea\s*and\s*snacks|stationery|courier|freight|petrol|diesel|cab\s*fare|taxi|salary|salaries|stipend)\b/i',
            $searchSpace
        );

        $hasInflowItemContext = (bool)preg_match(
            '/\b(consulting|advisory|retainer|software\s*development|web\s*development|app\s*development|commission|commition|brokerage|work\s*transfer|transfer\s*to\s*outside|interest\s*income|dividend|subcontract\s*income)\b/i',
            $searchSpace
        );

        // Classification Decision
        if ($hasDebitCue && !$hasCreditCue) {
            $type = 'debit';
        } elseif ($hasCreditCue && !$hasDebitCue) {
            $type = 'credit';
        } elseif ($hasDebitCue && $hasCreditCue) {
            // Priority resolution for inflows mentioning payment
            if (preg_match('/\b(client(?:s)?\s*paid|customer(?:s)?\s*paid|they\s*paid|paid\s*(?:to\s*)?(?:me|us)|sold|sale\s*of|received|receved|rcvd|income\s*from|commission\s*received)\b/i', $searchSpace)) {
                $type = 'credit';
            } else {
                $type = 'debit';
            }
        } elseif ($hasOutflowItemContext && !$hasInflowItemContext) {
            // Implicit outflow / purchase / expense (e.g. "office desktop 22000", "aws bill 18000")
            $type = 'debit';
        } elseif ($hasInflowItemContext && !$hasOutflowItemContext) {
            // Implicit inflow / service revenue
            $type = 'credit';
        } else {
            // General fallback
            $type = (preg_match('/\b(paid|spent|cost|fee|bill|charge|expense|debit|buy|bought|purchase)\b/i', $searchSpace)) ? 'debit' : 'credit';
        }

        // 3. Ambiguity Detection & Target Account Auto-Picking
        $suggestedCategory = 'general';
        $suggestedAccountName = '';
        $needsReview = false;
        $reviewOptions = [];
        $confidence = 0.95;

        if ($type === 'credit') {
            // A. Commission / Brokerage / Outside Work Transfer / Referral
            if (preg_match('/(commission|commition|brokerage|referral|incentive|work\s*transfer|transfer\s*to\s*outside|outside\s*work|subcontract|agency\s*fee|finder\s*fee|broker)/i', $searchSpace)) {
                $suggestedCategory = 'commission';
                $suggestedAccountName = 'Commission Income';
                $reviewOptions = ['Commission Income', 'Consulting Income', 'Software Development Services'];
                $confidence = 0.98;
            }
            // B. Interest / Investment Income
            elseif (preg_match('/(interest|bank\s*interest|fd\s*interest|fixed\s*deposit|savings\s*interest|dividend|yield)/i', $searchSpace)) {
                $suggestedCategory = 'interest';
                $suggestedAccountName = 'Interest & Investment Income';
                $reviewOptions = ['Interest & Investment Income', 'Consulting Income'];
                $confidence = 0.98;
            }
            // C. Rental Income
            elseif (preg_match('/(rent\s*received|rental\s*income|tenant|lease\s*income|sublet)/i', $searchSpace)) {
                $suggestedCategory = 'rent';
                $suggestedAccountName = 'Rental Income';
                $reviewOptions = ['Rental Income', 'Consulting Income'];
                $confidence = 0.98;
            }
            // D. Web / Ecommerce / Software Services Revenue
            elseif (preg_match('/(website|web\s*site|e\s*commerce|ecommerce|e-commerce|web\s*app|portal|coding|programming|development|saas product|platform|api\s*dev)/i', $searchSpace)) {
                $suggestedCategory = 'software_development';
                $suggestedAccountName = 'Software Development Services';
                $reviewOptions = ['Software Development Services', 'Consulting Income'];
                $confidence = 0.98;
            }
            // E. Consulting / Advisory Services
            elseif (preg_match('/(consulting|advisory|counseling|retainer|training|mentor)/i', $searchSpace)) {
                $suggestedCategory = 'services';
                $suggestedAccountName = 'Consulting Income';
                $reviewOptions = ['Consulting Income', 'Software Development Services'];
                $confidence = 0.96;
            }
            // F. Sales of Hardware & Goods (including sold computers/furniture/inventory)
            elseif (preg_match('/\b(hardware|goods|products?|sale|sold|retail|wholesale|merchandise|inventory|laptops?|desktops?|computers?|phones?|mobiles?|furniture|monitors?)\b/i', $searchSpace)) {
                $suggestedCategory = 'sales';
                $suggestedAccountName = 'Sales of Hardware & Goods';
                $reviewOptions = ['Sales of Hardware & Goods', 'Consulting Income'];
                $confidence = 0.96;
            }
            // G. Other General Revenue Fallback
            else {
                $suggestedCategory = 'revenue';
                $suggestedAccountName = 'Consulting Income';
                $reviewOptions = ['Consulting Income', 'Software Development Services', 'Commission Income'];
                $confidence = 0.90;
            }
        } else {
            // Debit / Expense / Asset Classification
            // A. Fixed Asset - Computers (Ambiguity: Asset vs Expense)
            if (preg_match('/\b(macbook|imac|mac\s*mini|laptop|laptops|notebook|notebooks|computer|computers|desktop|desktops|desktop\s*pc|pc\b|monitor|monitors|display|displays|screen|screens|workstation|workstations|all-in-one|server|servers|ipad|tablet|tablets|cpu|gpu|printer|printers|scanner|scanners|ups|hard\s*drive|ssd|ram|motherboard|electronics)\b/i', $searchSpace)) {
                $suggestedCategory = 'electronics';
                $suggestedAccountName = 'Fixed Asset - Computers';
                $needsReview = true;
                $reviewOptions = ['Fixed Asset - Computers', 'Office Expense'];
                $confidence = 0.96;
            }
            // B. Furniture & Equipment (Ambiguity: Asset vs Expense)
            elseif (preg_match('/\b(chairs?|desks?|tables?|furniture|furnishing|furnishings|sofa|cabinet|cabinets|cupboard|almirah|bookshelf|drawer|drawers|air\s*conditioner|ac\s*unit|cooler|projector|whiteboard)\b/i', $searchSpace)) {
                $suggestedCategory = 'furniture';
                $suggestedAccountName = 'Office Equipment & Furniture';
                $needsReview = true;
                $reviewOptions = ['Office Equipment & Furniture', 'Office Expense'];
                $confidence = 0.95;
            }
            // C. Cloud / SaaS / Software Subscriptions
            elseif (preg_match('/\b(aws|cloud|hosting|server\s*hosting|azure|gcp|digitalocean|github|gitlab|slack|zoom|notion|jira|saas|software|subscription|domain|godaddy|cloudflare)\b/i', $searchSpace)) {
                $suggestedCategory = 'software';
                if ($amount >= 100000) {
                    $suggestedAccountName = 'Software Subscriptions & Cloud';
                    $needsReview = true;
                    $reviewOptions = ['Software Subscriptions & Cloud', 'Fixed Asset - Computers'];
                    $confidence = 0.85;
                } else {
                    $suggestedAccountName = 'Software Subscriptions & Cloud';
                    $needsReview = false;
                    $reviewOptions = ['Software Subscriptions & Cloud', 'Office Expense'];
                    $confidence = 0.98;
                }
            }
            // D. Commission / Brokerage Expense
            elseif (preg_match('/(commission|commition|brokerage|referral\s*fee|finder\s*fee|agent\s*fee)/i', $searchSpace)) {
                $suggestedCategory = 'commission_expense';
                $suggestedAccountName = 'Commission & Brokerage Expense';
                $reviewOptions = ['Commission & Brokerage Expense', 'Office Expense'];
                $confidence = 0.98;
            }
            // E. Salary / Wages / Staff Payroll
            elseif (preg_match('/(salary|salaries|wages|payroll|stipend|staff\s*pay|bonus|remuneration|intern\s*pay)/i', $searchSpace)) {
                $suggestedCategory = 'salary';
                $suggestedAccountName = 'Salaries & Wages Expense';
                $reviewOptions = ['Salaries & Wages Expense', 'Office Expense'];
                $confidence = 0.98;
            }
            // F. Advertising & Marketing
            elseif (preg_match('/(advertising|advertisement|marketing|facebook\s*ads|google\s*ads|meta\s*ads|promotion|ad\s*campaign|branding|flyer|flyers|hoarding|influencer)/i', $searchSpace)) {
                $suggestedCategory = 'marketing';
                $suggestedAccountName = 'Advertising & Marketing';
                $reviewOptions = ['Advertising & Marketing', 'Office Expense'];
                $confidence = 0.98;
            }
            // G. Bank Charges & Processing Fees
            elseif (preg_match('/(bank\s*charge|bank\s*charges|bank\s*fee|bank\s*fees|processing\s*fee|processing\s*fees|gateway\s*charge|payment\s*gateway|transaction\s*fee|merchant\s*charge)/i', $searchSpace)) {
                $suggestedCategory = 'bank_charges';
                $suggestedAccountName = 'Bank Charges & Processing Fees';
                $reviewOptions = ['Bank Charges & Processing Fees', 'Office Expense'];
                $confidence = 0.98;
            }
            // H. Repairs & Maintenance
            elseif (preg_match('/(repair|repairs|maintenance|amc|service\s*charge|servicing|plumbing|electrician|ac\s*servicing)/i', $searchSpace)) {
                $suggestedCategory = 'repairs';
                $suggestedAccountName = 'Repairs & Maintenance';
                $reviewOptions = ['Repairs & Maintenance', 'Office Expense'];
                $confidence = 0.97;
            }
            // I. Printing & Stationery
            elseif (preg_match('/\b(stationery|printing|paper|cartridge|toner|pen|pens|notebook|notebooks|stapler)\b/i', $searchSpace)) {
                $suggestedCategory = 'stationery';
                $suggestedAccountName = 'Printing & Stationery';
                $reviewOptions = ['Printing & Stationery', 'Office Expense'];
                $confidence = 0.96;
            }
            // J. Office Refreshments & Pantry (Office Expense)
            elseif (preg_match('/\b(tea|coffee|snacks|pantry|cleaning|refreshments|lunch|dinner|water\s*can|food|refreshment)\b/i', $searchSpace)) {
                $suggestedCategory = 'office_expense';
                $suggestedAccountName = 'Office Expense';
                $reviewOptions = ['Office Expense', 'Printing & Stationery'];
                $confidence = 0.96;
            }
            // K. Courier & Shipping Charges
            elseif (preg_match('/(courier|postage|shipping|freight|delivery\s*charge|speed\s*post|logistics)/i', $searchSpace)) {
                $suggestedCategory = 'shipping';
                $suggestedAccountName = 'Courier & Shipping Charges';
                $reviewOptions = ['Courier & Shipping Charges', 'Office Expense'];
                $confidence = 0.96;
            }
            // L. Rent
            elseif (strpos($searchSpace, 'rent') !== false) {
                $suggestedCategory = 'rent';
                $suggestedAccountName = 'Office Rent';
                $needsReview = false;
                $reviewOptions = ['Office Rent'];
                $confidence = 0.98;
            }
            // M. Utilities
            elseif (preg_match('/(network|recharg|broadband|wifi|internet|phone|mobile|telecom|electric|power|water|utility|utilities|bill)/i', $searchSpace)) {
                $suggestedCategory = 'utilities';
                $suggestedAccountName = 'Utility Bills';
                $needsReview = false;
                $reviewOptions = ['Utility Bills', 'Office Expense'];
                $confidence = 0.95;
            }
            // N. Travel & Conveyance
            elseif (preg_match('/(travel|conveyance|flight|hotel|taxi|cab|uber|ola|fuel|petrol|diesel|toll|fare)/i', $searchSpace)) {
                $suggestedCategory = 'travel';
                $suggestedAccountName = 'Travel & Conveyance';
                $needsReview = false;
                $reviewOptions = ['Travel & Conveyance', 'Office Expense'];
                $confidence = 0.95;
            }
            // O. Legal & Professional Fees
            elseif (preg_match('/(legal|advocate|lawyer|ca\s*fee|auditor|audit|compliance|tax\s*filing|consultant\s*fee\s*paid)/i', $searchSpace)) {
                $suggestedCategory = 'professional_fees';
                $suggestedAccountName = 'Legal & Professional Fees';
                $needsReview = false;
                $reviewOptions = ['Legal & Professional Fees', 'Office Expense'];
                $confidence = 0.96;
            }
            // P. General Office Expense Fallback
            else {
                $suggestedCategory = 'office_expense';
                $suggestedAccountName = 'Office Expense';
                $needsReview = false;
                $reviewOptions = ['Office Expense', 'Utility Bills'];
                $confidence = 0.90;
            }
        }

        // Guarantee review_options has suggested_account_name first
        if (!empty($suggestedAccountName)) {
            if (!in_array($suggestedAccountName, $reviewOptions)) {
                array_unshift($reviewOptions, $suggestedAccountName);
            } else {
                // Move suggested account to index 0
                $reviewOptions = array_values(array_unique(array_merge([$suggestedAccountName], $reviewOptions)));
            }
        }

        // GST Extraction
        $gstExtracted = 0.0;
        if (preg_match('/(\d+)\s*%\s*gst/i', $searchSpace, $m) && $amount > 0) {
            $rate = (float)$m[1];
            $gstExtracted = round(($amount * $rate) / 100.0, 2);
        } elseif (preg_match('/gst\s*(?:of|is|amount)?\s*(?:rs\.?|₹)?\s*([\d,]+)/i', $searchSpace, $m)) {
            $gstExtracted = (float)str_replace(',', '', $m[1]);
        }

        $targetAccountType = ($type === 'credit') ? 'revenue' : (
            in_array($suggestedCategory, ['electronics', 'furniture']) ? 'asset' : 'expense'
        );

        return [
            'parsed_amount'          => (float)$amount,
            'transaction_type'       => $type,
            'suggested_category'     => $suggestedCategory,
            'suggested_account_name' => $suggestedAccountName,
            'target_account'         => [
                'name' => $suggestedAccountName,
                'type' => $targetAccountType
            ],
            'needs_user_review'      => $needsReview,
            'review_options'         => $reviewOptions,
            'gst_extracted'          => (float)$gstExtracted,
            'confidence_score'       => $confidence,
            'has_multiplier'         => $amountData['has_multiplier'] ?? false,
            'multiplier_details'     => [
                'quantity'    => $amountData['quantity'] ?? 1,
                'unit_amount' => $amountData['unit_amount'] ?? $amount
            ]
        ];
    }

    /**
     * Extracts monetary amount with full quantity-multiplier and Indian notation support
     */
    private function extractAmountAndMultiplier(string $lower): array
    {
        $countNouns = '(?:(?:[a-z-]+\s+){0,2}(?:clients?|customers?|people|persons?|items?|units?|months?|pcs?|pieces?|seats?|workstations?|desks?|chairs?|licenses?|licences?|packages?|users?|subscribers?|invoices?|bills?))';

        // Pattern 1: "{quantity} {count_noun} ... {unit_amount} each"
        // e.g. "3 clients they paid 60000 each", "5 ergonomic chairs for 4000 each"
        if (preg_match('/(\d+)\s*' . $countNouns . '\b.*?(?:paid|pay|for|at|of|billed|received)?\s*(?:rs\.?|inr|₹|\$)?\s*([\d,]+(?:\.\d+)?)\s*(lakh|lacs|lac|cr|crore|k)?\s*each/i', $lower, $m)) {
            $qty = (float)$m[1];
            $unit = (float)str_replace(',', '', $m[2]);
            $suffix = strtolower($m[3] ?? '');
            $unit = $this->applyIndianSuffix($unit, $suffix);
            return [
                'amount' => $qty * $unit,
                'quantity' => $qty,
                'unit_amount' => $unit,
                'has_multiplier' => true
            ];
        }

        // Pattern 2: "{unit_amount} each ... {quantity} {count_noun}"
        // e.g. "60000 each from 3 clients", "50k each for 2 months"
        if (preg_match('/(?:rs\.?|inr|₹|\$)?\s*([\d,]+(?:\.\d+)?)\s*(lakh|lacs|lac|cr|crore|k)?\s*each\b.*?(?:from|for|by|to|with)?\s*(\d+)\s*' . $countNouns . '?/i', $lower, $m)) {
            $unit = (float)str_replace(',', '', $m[1]);
            $suffix = strtolower($m[2] ?? '');
            $unit = $this->applyIndianSuffix($unit, $suffix);
            $qty = (float)$m[3];
            if ($qty > 0) {
                return [
                    'amount' => $qty * $unit,
                    'quantity' => $qty,
                    'unit_amount' => $unit,
                    'has_multiplier' => true
                ];
            }
        }

        // Pattern 3: "{quantity} {count_noun} each paid {unit_amount}"
        if (preg_match('/(\d+)\s*' . $countNouns . '\s*(?:each\s*paid|each\s*paying|each\s*for)\s*(?:rs\.?|inr|₹|\$)?\s*([\d,]+(?:\.\d+)?)\s*(lakh|lacs|lac|cr|crore|k)?/i', $lower, $m)) {
            $qty = (float)$m[1];
            $unit = (float)str_replace(',', '', $m[2]);
            $suffix = strtolower($m[3] ?? '');
            $unit = $this->applyIndianSuffix($unit, $suffix);
            return [
                'amount' => $qty * $unit,
                'quantity' => $qty,
                'unit_amount' => $unit,
                'has_multiplier' => true
            ];
        }

        // Standard Indian currency notation:
        // "1 lakh", "2.5 lacs", "1 cr", "50k"
        if (preg_match('/([\d\.]+)\s*(?:lakh|lacs|lac)\b/i', $lower, $m)) {
            $val = (float)$m[1] * 100000.0;
            return ['amount' => $val, 'quantity' => 1, 'unit_amount' => $val, 'has_multiplier' => false];
        }
        if (preg_match('/([\d\.]+)\s*(?:crore|cr)\b/i', $lower, $m)) {
            $val = (float)$m[1] * 10000000.0;
            return ['amount' => $val, 'quantity' => 1, 'unit_amount' => $val, 'has_multiplier' => false];
        }
        if (preg_match('/([\d\.]+)\s*k\b/i', $lower, $m)) {
            $val = (float)$m[1] * 1000.0;
            return ['amount' => $val, 'quantity' => 1, 'unit_amount' => $val, 'has_multiplier' => false];
        }

        // Plain numbers: Avoid picking count quantities (like "3" in "3 clients") if a real monetary amount exists
        preg_match_all('/(?:rs\.?|inr|₹|\$)?\s*([\d,]+(?:\.\d{1,2})?)/i', $lower, $allMatches, PREG_OFFSET_CAPTURE);
        if (!empty($allMatches[1])) {
            $candidates = [];
            foreach ($allMatches[1] as $matchTuple) {
                $valStr = str_replace(',', '', $matchTuple[0]);
                $val = (float)$valStr;
                $offset = $matchTuple[1];
                $endOffset = $offset + strlen($matchTuple[0]);
                $subAfter = substr($lower, $endOffset, 30);

                // Check if this number is followed by count nouns
                $isCountNoun = (bool)preg_match('/^\s*' . $countNouns . '\b/i', $subAfter);
                $candidates[] = [
                    'val' => $val,
                    'is_count' => $isCountNoun
                ];
            }

            // Prefer non-count numbers
            $nonCounts = array_values(array_filter($candidates, fn($c) => !$c['is_count'] && $c['val'] > 0));
            if (!empty($nonCounts)) {
                // If multiple, sort by value descending
                usort($nonCounts, fn($a, $b) => $b['val'] <=> $a['val']);
                return ['amount' => (float)$nonCounts[0]['val'], 'quantity' => 1, 'unit_amount' => (float)$nonCounts[0]['val'], 'has_multiplier' => false];
            }

            // Fallback to highest candidate
            usort($candidates, fn($a, $b) => $b['val'] <=> $a['val']);
            if (!empty($candidates)) {
                return ['amount' => (float)$candidates[0]['val'], 'quantity' => 1, 'unit_amount' => (float)$candidates[0]['val'], 'has_multiplier' => false];
            }
        }

        return ['amount' => 0.0, 'quantity' => 1, 'unit_amount' => 0.0, 'has_multiplier' => false];
    }

    /**
     * Applies multiplier suffix for Indian denominations
     */
    private function applyIndianSuffix(float $val, string $suffix): float
    {
        $suffix = strtolower($suffix);
        if (in_array($suffix, ['lakh', 'lacs', 'lac'])) {
            return $val * 100000.0;
        }
        if (in_array($suffix, ['crore', 'cr'])) {
            return $val * 10000000.0;
        }
        if ($suffix === 'k') {
            return $val * 1000.0;
        }
        return $val;
    }

    /**
     * AI Depreciation Solver
     * Solves depreciation for business owners struggling to identify statutory
     * rates, 180-day tax rules, and journal entries from natural language prompts.
     */
    public function resolveDepreciationPrompt(string $prompt): array
    {
        $lower = strtolower(trim($prompt));

        // 1. Extract Cost / Amount
        $cost = 0.0;
        if (preg_match('/([\d\.]+)\s*(?:lakh|lacs|lac)/i', $lower, $m)) {
            $cost = (float)$m[1] * 100000.0;
        } elseif (preg_match('/([\d\.]+)\s*(?:crore|cr)/i', $lower, $m)) {
            $cost = (float)$m[1] * 10000000.0;
        } elseif (preg_match('/([\d\.]+)\s*k\b/i', $lower, $m)) {
            $cost = (float)$m[1] * 1000.0;
        } elseif (preg_match('/(?:rs\.?|inr|for|\$)?\s*([\d,]+(?:\.\d{1,2})?)/i', $lower, $m)) {
            $cost = (float)str_replace(',', '', $m[1]);
        }
        if ($cost <= 0) {
            $cost = 100000.0; // Default illustrative benchmark
        }

        // 2. Identify Statutory Asset Classification
        $category = 'Computers & IT Hardware';
        $assetAccount = 'Fixed Asset - Computers';
        $wdvRate = 40.0; // Income Tax Act standard WDV %
        $usefulLifeYears = 3; // Companies Act Schedule II
        $statutoryBlock = 'Block 4: Computers including computer software & laptops';

        if (preg_match('/(chair|desk|table|furniture|fixture|sofa|interior|ac\b|air conditioner)/i', $lower)) {
            $category = 'Office Furniture & Fixtures';
            $assetAccount = 'Office Equipment & Furniture';
            $wdvRate = 10.0;
            $usefulLifeYears = 10;
            $statutoryBlock = 'Block 2: Furniture and fittings';
        } elseif (preg_match('/(car|vehicle|automobile|truck|van|bike)/i', $lower)) {
            $category = 'Commercial Vehicles';
            $assetAccount = 'Fixed Asset - Vehicles';
            $wdvRate = 15.0;
            $usefulLifeYears = 8;
            $statutoryBlock = 'Block 3: Motor cars (used for business operations)';
        } elseif (preg_match('/(machinery|plant|equipment|generator|tools)/i', $lower)) {
            $category = 'Plant & Machinery';
            $assetAccount = 'Office Equipment & Furniture';
            $wdvRate = 15.0;
            $usefulLifeYears = 15;
            $statutoryBlock = 'Block 1: General Plant & Machinery';
        } elseif (preg_match('/(building|premise|office space|warehouse)/i', $lower)) {
            $category = 'Commercial Buildings';
            $assetAccount = 'Commercial Building';
            $wdvRate = 10.0;
            $usefulLifeYears = 30;
            $statutoryBlock = 'Block 5: Commercial buildings (other than residential)';
        } elseif (preg_match('/(software|license|erp|crm|domain|trademark)/i', $lower)) {
            $category = 'Intangible Assets & Software Licenses';
            $assetAccount = 'Fixed Asset - Computers';
            $wdvRate = 25.0;
            $usefulLifeYears = 3;
            $statutoryBlock = 'Block 6: Intangible assets (Know-how, patents, copyrights, licenses)';
        }

        // 3. Indian Income Tax Act 180-Day Rule Analysis
        // Put to use after October 3 of Financial Year gets 50% of normal rate in Year 1
        $lessThan180Days = false;
        if (preg_match('/(october|november|december|january|february|march|oct|nov|dec|jan|feb|mar)/i', $lower) ||
            preg_match('/(?:after|in)\s*(?:oct|nov|dec|jan|feb|mar)/i', $lower)) {
            $lessThan180Days = true;
        }

        $effectiveWdvRateYear1 = $lessThan180Days ? ($wdvRate / 2.0) : $wdvRate;
        $depreciationYear1 = round(($cost * $effectiveWdvRateYear1) / 100.0, 2);
        $closingWdvYear1 = round($cost - $depreciationYear1, 2);

        // Year 2 & Year 3 Projections (WDV)
        $depreciationYear2 = round(($closingWdvYear1 * $wdvRate) / 100.0, 2);
        $closingWdvYear2 = round($closingWdvYear1 - $depreciationYear2, 2);

        $depreciationYear3 = round(($closingWdvYear2 * $wdvRate) / 100.0, 2);
        $closingWdvYear3 = round($closingWdvYear2 - $depreciationYear3, 2);

        // Straight-Line Method (SLM) under Companies Act
        $slmRatePercent = round(100.0 / $usefulLifeYears, 2);
        $slmAnnualDepreciation = round($cost / $usefulLifeYears, 2);

        $taxRuleExplanation = $lessThan180Days
            ? "Put to use for < 180 days in the Financial Year: Restricted to 50% of normal rate ({$effectiveWdvRateYear1}% instead of {$wdvRate}%) under Indian Income Tax Act Section 32."
            : "Put to use for ≥ 180 days: Full statutory rate of {$wdvRate}% is deductible under Indian Income Tax Act Section 32.";

        return [
            'success' => true,
            'query_parsed' => [
                'raw_prompt' => $prompt,
                'detected_cost' => $cost,
                'detected_category' => $category,
                'asset_account' => $assetAccount,
                'statutory_block' => $statutoryBlock
            ],
            'rates' => [
                'income_tax_wdv_rate' => $wdvRate,
                'companies_act_useful_life_years' => $usefulLifeYears,
                'companies_act_slm_rate' => $slmRatePercent
            ],
            'depreciation_computations' => [
                'income_tax_act_wdv' => [
                    'method_name' => 'Written Down Value (WDV) - Tax Rule',
                    'first_year_rate_applied' => $effectiveWdvRateYear1,
                    'is_half_rate_applied' => $lessThan180Days,
                    'year_1_depreciation' => $depreciationYear1,
                    'year_1_closing_nbv' => $closingWdvYear1,
                    'year_2_depreciation' => $depreciationYear2,
                    'year_2_closing_nbv' => $closingWdvYear2,
                    'year_3_depreciation' => $depreciationYear3,
                    'year_3_closing_nbv' => $closingWdvYear3,
                ],
                'companies_act_slm' => [
                    'method_name' => 'Straight Line Method (SLM) - Book Rule',
                    'useful_life_years' => $usefulLifeYears,
                    'annual_depreciation' => $slmAnnualDepreciation,
                    'monthly_depreciation' => round($slmAnnualDepreciation / 12.0, 2)
                ]
            ],
            'statutory_note' => $taxRuleExplanation,
            'suggested_journal_entry' => [
                'debit_account' => 'Depreciation & Amortization Expense',
                'debit_account_code' => '5070',
                'credit_account' => 'Accumulated Depreciation - Assets',
                'credit_account_code' => '1590',
                'amount' => $depreciationYear1,
                'narration' => "Being depreciation charged on {$category} (Cost: ₹" . number_format($cost, 2) . ") @ {$effectiveWdvRateYear1}% under WDV method."
            ]
        ];
    }

    /**
     * Retrieves Gemini API Key from system settings, environment, or .env file
     */
    public function getGeminiApiKey(): string
    {
        $key = getenv('GEMINI_API_KEY') ?: ($_ENV['GEMINI_API_KEY'] ?? '');
        if (empty($key) && $this->db) {
            try {
                $stmt = $this->db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'gemini_api_key' LIMIT 1");
                $stmt->execute();
                $val = $stmt->fetchColumn();
                if ($val) {
                    $key = (string)$val;
                }
            } catch (\Throwable $e) {
                // Ignore DB read failure
            }
        }

        // Check root .env if still empty
        if (empty($key)) {
            $envFile = dirname(__DIR__, 2) . '/.env';
            if (file_exists($envFile)) {
                $content = @file_get_contents($envFile);
                if ($content && preg_match('/^GEMINI_API_KEY\s*=\s*([^\r\n]+)/m', $content, $m)) {
                    $key = trim($m[1], " \t\n\r\0\x0B\"'");
                }
            }
        }

        return trim($key);
    }

    /**
     * Retrieves configured Gemini model or defaults to gemini-3.6-flash
     */
    public function getGeminiModel(): string
    {
        $model = 'gemini-3.6-flash';
        if ($this->db) {
            try {
                $stmt = $this->db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'gemini_model' LIMIT 1");
                $stmt->execute();
                $val = $stmt->fetchColumn();
                if ($val && trim($val) !== '') {
                    $model = trim((string)$val);
                }
            } catch (\Throwable $e) {
                // Ignore DB read failure
            }
        }
        return $model;
    }

    /**
     * Builds a concise Chart of Accounts summary string for Gemini prompt context
     */
    public function getChartOfAccountsSummary(): string
    {
        if (!$this->db) {
            return "Available accounts: Commission Income (revenue), Software Development Services (revenue), Consulting Income (revenue), Office Expense (expense), Office Rent (expense), Fixed Asset - Computers (asset)";
        }
        try {
            $stmt = $this->db->query("SELECT code, name, type FROM chart_of_accounts WHERE is_active = 1 ORDER BY type, code ASC");
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $parts = [];
            foreach ($rows as $r) {
                $parts[] = "{$r['code']} - {$r['name']} ({$r['type']})";
            }
            return implode(", ", $parts);
        } catch (\Throwable $e) {
            return "Commission Income (revenue), Software Development Services (revenue), Consulting Income (revenue), Office Expense (expense)";
        }
    }
}

