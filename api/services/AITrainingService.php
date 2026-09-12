<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use PDO;

/**
 * Autonomous AI Self-Training & Local Knowledge Base Engine
 * 
 * Provides:
 * 1. Automatic continuous self-learning from verified ledger transactions and Gemini predictions.
 * 2. Dynamic Few-Shot Exemplar injection to train Gemini in real-time on enterprise terminology.
 * 3. Autonomous offline semantic matcher when Gemini is unavailable, rate-limited, or fails.
 * 4. Gemini-assisted synthetic training data generation to proactively train and expand local models.
 */
class AITrainingService
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
     * Normalizes raw natural language financial prompt into clean indexable tokens
     */
    public static function normalizeTokens(string $text): string
    {
        $clean = strtolower(trim($text));
        // Replace numbers and currencies
        $clean = preg_replace('/(?:rs\.?|inr|₹)\s*[\d,]+(?:\.\d+)?/i', ' ', $clean);
        $clean = preg_replace('/[\d,]+(?:\.\d+)?\s*(?:lakhs?|lacs?|crores?|cr|k|thousands?)/i', ' ', $clean);
        $clean = preg_replace('/[\d,]+(?:\.\d+)?/', ' ', $clean);
        // Replace punctuation
        $clean = preg_replace('/[^\w\s]/', ' ', $clean);
        // Remove common stopwords while keeping accounting direction verbs
        $stopWords = ['the', 'a', 'an', 'and', 'for', 'of', 'to', 'in', 'at', 'by', 'on', 'with', 'from', 'as', 'is', 'was', 'my', 'our', 'office', 'company', 'business', 'worth'];
        $words = preg_split('/\s+/', $clean, -1, PREG_SPLIT_NO_EMPTY);
        $filtered = array_filter($words, fn($w) => !in_array($w, $stopWords) && strlen($w) > 1);
        return implode(' ', array_values($filtered));
    }

    /**
     * Records a learned transaction into the training dataset
     */
    public static function recordLearnedTransaction(
        string $promptText,
        string $accountName,
        string $transactionType,
        ?string $category = null,
        float $gstRate = 0.0,
        array $reviewOptions = [],
        string $source = 'user_verified',
        float $confidence = 0.98
    ): bool {
        $db = self::getDb();
        $prompt = trim($promptText);
        if (empty($prompt) || strlen($prompt) < 3) {
            return false;
        }

        $normalized = self::normalizeTokens($prompt);
        if (empty($normalized)) {
            $normalized = strtolower($prompt);
        }

        if (empty($category)) {
            $category = strtolower(str_replace([' ', '-', '&'], '_', $accountName));
        }

        if (empty($reviewOptions)) {
            $reviewOptions = [$accountName];
        }

        $reviewOptionsJson = json_encode(array_values(array_unique($reviewOptions)));
        $needsReview = (count($reviewOptions) > 1 && in_array('Office Expense', $reviewOptions)) ? 1 : 0;

        try {
            // Check if exact normalized pattern already exists
            $checkStmt = $db->prepare("SELECT id, use_count FROM ai_training_dataset WHERE normalized_tokens = ? OR LOWER(prompt_text) = LOWER(?) LIMIT 1");
            $checkStmt->execute([$normalized, $prompt]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $updateStmt = $db->prepare("
                    UPDATE ai_training_dataset 
                    SET suggested_account_name = ?,
                        transaction_type = ?,
                        suggested_category = ?,
                        gst_rate = ?,
                        needs_user_review = ?,
                        review_options_json = ?,
                        confidence_score = MAX(confidence_score, ?),
                        use_count = use_count + 1,
                        last_used_at = CURRENT_TIMESTAMP,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $updateStmt->execute([
                    $accountName,
                    $transactionType,
                    $category,
                    $gstRate,
                    $needsReview,
                    $reviewOptionsJson,
                    $confidence,
                    (int)$existing['id']
                ]);
                return true;
            } else {
                $insStmt = $db->prepare("
                    INSERT INTO ai_training_dataset 
                    (prompt_text, normalized_tokens, suggested_account_name, transaction_type, suggested_category, gst_rate, needs_user_review, review_options_json, source, confidence_score, use_count, last_used_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, CURRENT_TIMESTAMP)
                ");
                $insStmt->execute([
                    $prompt,
                    $normalized,
                    $accountName,
                    $transactionType,
                    $category,
                    $gstRate,
                    $needsReview,
                    $reviewOptionsJson,
                    $source,
                    $confidence
                ]);
                return true;
            }
        } catch (\Throwable $e) {
            error_log("AITrainingService::recordLearnedTransaction Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Retrieves the most relevant trained examples (Few-Shot Exemplars) to inject into Gemini prompts
     */
    public static function getFewShotExemplars(string $queryText, int $limit = 4): array
    {
        $db = self::getDb();
        self::ensureSeedDataset();

        $queryTokens = explode(' ', self::normalizeTokens($queryText));
        $queryTokens = array_filter($queryTokens, fn($t) => strlen($t) >= 3);

        $exemplars = [];

        if (!empty($queryTokens)) {
            // Find matches that share tokens
            $clauses = [];
            $params = [];
            foreach ($queryTokens as $tok) {
                $clauses[] = "normalized_tokens LIKE ?";
                $params[] = '%' . $tok . '%';
            }
            $where = implode(' OR ', $clauses);
            $stmt = $db->prepare("
                SELECT prompt_text, suggested_account_name, transaction_type, suggested_category, needs_user_review, review_options_json
                FROM ai_training_dataset
                WHERE {$where}
                ORDER BY use_count DESC, confidence_score DESC
                LIMIT ?
            ");
            $params[] = $limit;
            try {
                $stmt->execute($params);
                $exemplars = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {}
        }

        // If not enough matches, backfill with diverse popular exemplars
        if (count($exemplars) < $limit) {
            $needed = $limit - count($exemplars);
            $existingPrompts = array_column($exemplars, 'prompt_text');
            $placeholders = empty($existingPrompts) ? '1=1' : 'prompt_text NOT IN (' . implode(',', array_fill(0, count($existingPrompts), '?')) . ')';
            $stmt = $db->prepare("
                SELECT prompt_text, suggested_account_name, transaction_type, suggested_category, needs_user_review, review_options_json
                FROM ai_training_dataset
                WHERE {$placeholders}
                ORDER BY use_count DESC, id ASC
                LIMIT ?
            ");
            $params = array_merge($existingPrompts, [$needed]);
            try {
                $stmt->execute($params);
                $backfill = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $exemplars = array_merge($exemplars, $backfill);
            } catch (\Throwable $e) {}
        }

        return $exemplars;
    }

    /**
     * Formats few-shot examples into an explicit prompt section for Gemini
     */
    public static function formatFewShotForGemini(array $exemplars): string
    {
        if (empty($exemplars)) {
            return "";
        }

        $lines = ["\n### DYNAMIC FEW-SHOT VERIFIED EXAMPLES FROM LOCAL ENTERPRISE DATASET (TRAINED SAMPLES):"];
        foreach ($exemplars as $i => $ex) {
            $reviewOpts = json_decode($ex['review_options_json'] ?? '[]', true) ?: [$ex['suggested_account_name']];
            $needsReview = (bool)$ex['needs_user_review'];
            $sampleOutput = json_encode([
                'parsed_amount' => 50000,
                'transaction_type' => $ex['transaction_type'],
                'suggested_category' => $ex['suggested_category'],
                'suggested_account_name' => $ex['suggested_account_name'],
                'needs_user_review' => $needsReview,
                'review_options' => $reviewOpts,
                'gst_extracted' => 0,
                'confidence_score' => 0.96
            ]);
            $lines[] = ($i + 1) . '. User Input: "' . addslashes($ex['prompt_text']) . '"';
            $lines[] = '   Target JSON: ' . $sampleOutput;
        }
        $lines[] = "Use the above enterprise examples as grounded precedent for terminology, account classification, and capital asset ambiguity.\n";
        return implode("\n", $lines);
    }

    /**
     * AUTONOMOUS OFFLINE BRAIN:
     * When Gemini fails, is disabled, or rate-limited, this method queries our trained dataset
     * to return an accurate structured response.
     */
    public static function findLocalMatch(string $queryText, float $minSimilarity = 0.50): ?array
    {
        $db = self::getDb();
        self::ensureSeedDataset();

        $query = trim($queryText);
        $normQuery = self::normalizeTokens($query);
        $queryWords = preg_split('/\s+/', $normQuery, -1, PREG_SPLIT_NO_EMPTY);

        // 1. Check exact match
        $stmt = $db->prepare("
            SELECT * FROM ai_training_dataset 
            WHERE LOWER(prompt_text) = LOWER(?) OR normalized_tokens = ? 
            ORDER BY use_count DESC, confidence_score DESC 
            LIMIT 1
        ");
        $stmt->execute([$query, $normQuery]);
        $exact = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($exact) {
            return self::buildMatchResult($query, $exact, 1.0);
        }

        // 2. Scan all trained patterns for semantic token overlap and phonetic similarity
        $allPatternsStmt = $db->query("SELECT * FROM ai_training_dataset ORDER BY use_count DESC");
        $patterns = $allPatternsStmt->fetchAll(PDO::FETCH_ASSOC);

        $bestScore = 0.0;
        $bestMatch = null;

        // Key high-importance financial root words
        $keyNouns = [
            'desktop' => 'Fixed Asset - Computers',
            'desktops' => 'Fixed Asset - Computers',
            'computer' => 'Fixed Asset - Computers',
            'computers' => 'Fixed Asset - Computers',
            'laptop' => 'Fixed Asset - Computers',
            'laptops' => 'Fixed Asset - Computers',
            'macbook' => 'Fixed Asset - Computers',
            'monitor' => 'Fixed Asset - Computers',
            'monitors' => 'Fixed Asset - Computers',
            'printer' => 'Fixed Asset - Computers',
            'furniture' => 'Office Equipment & Furniture',
            'chair' => 'Office Equipment & Furniture',
            'chairs' => 'Office Equipment & Furniture',
            'desk' => 'Office Equipment & Furniture',
            'desks' => 'Office Equipment & Furniture',
            'table' => 'Office Equipment & Furniture',
            'rent' => 'Office Rent',
            'coworking' => 'Office Rent',
            'commission' => 'Commission Income',
            'commition' => 'Commission Income',
            'brokerage' => 'Commission Income',
            'referral' => 'Commission Income',
            'consulting' => 'Consulting Income',
            'advisory' => 'Consulting Income',
            'software' => 'Software Development Services',
            'website' => 'Software Development Services',
            'aws' => 'Software Subscriptions & Cloud',
            'cloud' => 'Software Subscriptions & Cloud',
            'hosting' => 'Software Subscriptions & Cloud',
            'domain' => 'Software Subscriptions & Cloud',
            'salary' => 'Salaries & Wages Expense',
            'salaries' => 'Salaries & Wages Expense',
            'wages' => 'Salaries & Wages Expense',
            'payroll' => 'Salaries & Wages Expense',
            'wifi' => 'Utility Bills',
            'broadband' => 'Utility Bills',
            'electricity' => 'Utility Bills',
            'recharge' => 'Utility Bills',
            'swiggy' => 'Office Expense',
            'zomato' => 'Office Expense',
            'lunch' => 'Office Expense',
            'snacks' => 'Office Expense',
            'tea' => 'Office Expense',
            'petrol' => 'Travel & Conveyance',
            'uber' => 'Travel & Conveyance',
            'ola' => 'Travel & Conveyance',
            'flight' => 'Travel & Conveyance',
            'courier' => 'Courier & Shipping Charges',
            'shipping' => 'Courier & Shipping Charges',
            'stationery' => 'Printing & Stationery',
            'legal' => 'Legal & Professional Fees',
            'ca' => 'Legal & Professional Fees',
            'audit' => 'Legal & Professional Fees',
        ];

        // Check if query contains any high-importance keywords
        $foundKeywords = [];
        $lowQuery = strtolower($query);
        foreach ($keyNouns as $kw => $targetAcc) {
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/i', $lowQuery)) {
                $foundKeywords[$kw] = $targetAcc;
            }
        }

        foreach ($patterns as $pat) {
            $patTokens = preg_split('/\s+/', $pat['normalized_tokens'], -1, PREG_SPLIT_NO_EMPTY);
            if (empty($patTokens)) continue;

            // Token Jaccard similarity
            $intersection = array_intersect($queryWords, $patTokens);
            $union = array_unique(array_merge($queryWords, $patTokens));
            $jaccard = count($union) > 0 ? (count($intersection) / count($union)) : 0;

            // Substring similarity
            $simPercent = 0.0;
            similar_text($normQuery, $pat['normalized_tokens'], $simPercent);
            $subSim = $simPercent / 100.0;

            // Weighted score
            $score = ($jaccard * 0.6) + ($subSim * 0.4);

            // Boost if key noun matches
            foreach ($foundKeywords as $kw => $targetAcc) {
                if (strcasecmp($pat['suggested_account_name'], $targetAcc) === 0) {
                    $score += 0.45;
                }
            }

            // Direction alignment (debit/purchase vs credit/revenue)
            $isPurchase = (bool)preg_match('/\b(?:buy|bought|purchase|purchased|spend|spent|paid|procure|expense)\b/i', $lowQuery);
            $isReceipt = (bool)preg_match('/\b(?:received|receved|got|earned|collected|inward|revenue|fees)\b/i', $lowQuery);
            if ($isPurchase && $pat['transaction_type'] === 'debit') {
                $score += 0.15;
            } elseif ($isReceipt && $pat['transaction_type'] === 'credit') {
                $score += 0.15;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $pat;
            }
        }

        if ($bestMatch && $bestScore >= $minSimilarity) {
            return self::buildMatchResult($query, $bestMatch, min(0.99, $bestScore));
        }

        return null;
    }

    /**
     * Constructs a compliant parse result from a matched training record
     */
    private static function buildMatchResult(string $rawText, array $record, float $score): array
    {
        $amount = self::extractAmount($rawText);
        $reviewOpts = json_decode($record['review_options_json'] ?? '[]', true) ?: [$record['suggested_account_name']];

        // Check if direction needs adjustment based on explicit prompt indicators
        $type = $record['transaction_type'];
        $low = strtolower($rawText);
        if (preg_match('/\b(?:buy|bought|purchase|purchased|paid|procure)\b/i', $low)) {
            $type = 'debit';
        } elseif (preg_match('/\b(?:received|receved|earned|got|fees|consulting|commission received)\b/i', $low)) {
            $type = 'credit';
        }

        $gstExtracted = 0.0;
        if (preg_match('/(\d+(?:\.\d+)?)\s*%\s*gst/i', $rawText, $gm)) {
            $rate = (float)$gm[1];
            $gstExtracted = round(($amount * $rate) / 100.0, 2);
        }

        return [
            'parsed_amount'          => $amount,
            'transaction_type'       => $type,
            'suggested_category'     => $record['suggested_category'],
            'suggested_account_name' => $record['suggested_account_name'],
            'needs_user_review'      => (bool)$record['needs_user_review'],
            'review_options'         => $reviewOpts,
            'gst_extracted'          => $gstExtracted,
            'confidence_score'       => round(min(0.98, $score), 2),
            'source'                 => 'local_trained_dataset',
            'matched_pattern'        => $record['prompt_text'],
            'training_id'            => (int)$record['id']
        ];
    }

    /**
     * Extracts numeric amount with Indian multipliers (lakh, crore, k)
     */
    public static function extractAmount(string $text): float
    {
        $clean = strtolower($text);

        // 1. Look for colloquial multipliers: e.g. "1.5 lakh", "2 crore", "50k"
        if (preg_match('/(?:rs\.?|inr|₹)?\s*([\d,]+(?:\.\d+)?)\s*(lakhs?|lacs?|crores?|cr|k|thousands?)/i', $clean, $m)) {
            $val = (float)str_replace(',', '', $m[1]);
            $unit = strtolower($m[2]);
            if (str_starts_with($unit, 'la') || $unit === 'lakh' || $unit === 'lac') {
                return $val * 100000;
            }
            if (str_starts_with($unit, 'cr') || $unit === 'crore') {
                return $val * 10000000;
            }
            if ($unit === 'k' || str_starts_with($unit, 'th')) {
                return $val * 1000;
            }
        }

        // 2. Fallback to raw currency numbers: e.g. "₹45,000", "Rs 50000"
        if (preg_match('/(?:rs\.?|inr|₹)\s*([\d,]+(?:\.\d+)?)/i', $clean, $m)) {
            return (float)str_replace(',', '', $m[1]);
        }

        // 3. Fallback to highest isolated integer/float
        if (preg_match_all('/\b([\d,]+(?:\.\d+)?)\b/', $clean, $matches)) {
            $max = 0.0;
            foreach ($matches[1] as $numStr) {
                // exclude GST percentage like 18 or 28 if it appears next to %
                if (preg_match('/' . preg_quote($numStr, '/') . '\s*%/', $clean)) {
                    continue;
                }
                $v = (float)str_replace(',', '', $numStr);
                if ($v > $max && $v < 1000000000) {
                    $max = $v;
                }
            }
            if ($max > 0) {
                return $max;
            }
        }

        return 0.0;
    }

    /**
     * Uses Gemini to automatically synthesize fresh training variations for accounts
     */
    public static function synthesizeTrainingDataWithGemini(?string $targetAccount = null): array
    {
        $db = self::getDb();
        $aiService = new AIService();
        $apiKey = $aiService->getGeminiApiKey();

        if (empty($apiKey) || strlen($apiKey) < 20) {
            return [
                'success' => false,
                'error' => 'Valid Google Gemini API Key is required for synthetic dataset generation.'
            ];
        }

        // Get list of active accounts
        $sql = "SELECT id, name, type FROM chart_of_accounts WHERE is_active = 1";
        if (!empty($targetAccount)) {
            $sql .= " AND name = " . $db->quote($targetAccount);
        } else {
            $sql .= " ORDER BY type ASC LIMIT 12";
        }
        $accounts = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        $addedCount = 0;

        foreach ($accounts as $acc) {
            $accName = $acc['name'];
            $accType = $acc['type'];
            $txType = ($accType === 'revenue') ? 'credit' : 'debit';

            $prompt = <<<PROMPT
You are an expert AI dataset generator for Indian accounting and bookkeeping.
Generate 4 varied, realistic natural language sentences that Indian business owners or accountants might type into a chat or search bar for the account: "{$accName}" (Type: {$txType}).

Guidelines:
- Include Indian business colloquialisms (e.g., lakh, k, crore, vendor names, invoices, transfers).
- Include common typos or shorthand (e.g. "desktp", "receved", "commition", "pd").
- Vary the sentence structure (declarative, brief notes, conversational).

Respond ONLY with a JSON array of strings:
["sample prompt 1", "sample prompt 2", "sample prompt 3", "sample prompt 4"]
PROMPT;

            $model = $aiService->getGeminiModel();
            $candidateModels = array_unique([$model, 'gemini-2.5-flash', 'gemini-1.5-flash']);
            $payload = [
                'contents' => [
                    ['role' => 'user', 'parts' => [['text' => $prompt]]]
                ],
                'generationConfig' => [
                    'temperature' => 0.7,
                    'responseMimeType' => 'application/json'
                ]
            ];

            $response = false;
            $httpCode = 0;

            foreach ($candidateModels as $curModel) {
                $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$curModel}:generateContent?key={$apiKey}";
                $ch = curl_init($endpoint);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
                curl_setopt($ch, CURLOPT_TIMEOUT, 15);
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
                    break;
                }
            }

            if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
                $resData = json_decode($response, true);
                $text = '';
                if (!empty($resData['candidates'][0]['content']['parts'])) {
                    foreach ($resData['candidates'][0]['content']['parts'] as $p) {
                        if (isset($p['text']) && empty($p['thought'])) {
                            $text = $p['text'];
                            break;
                        }
                    }
                    if (empty($text)) {
                        $text = $resData['candidates'][0]['content']['parts'][0]['text'] ?? '';
                    }
                }
                $text = preg_replace('/^```(?:json)?/i', '', trim($text));
                $text = preg_replace('/```$/', '', trim($text));
                $phrases = json_decode(trim($text), true);

                if (is_array($phrases)) {
                    $category = strtolower(str_replace([' ', '-', '&'], '_', $accName));
                    $reviewOpts = (in_array($accName, ['Fixed Asset - Computers', 'Office Equipment & Furniture'])) 
                        ? [$accName, 'Office Expense'] 
                        : [$accName];

                    foreach ($phrases as $phrase) {
                        if (is_string($phrase) && strlen(trim($phrase)) >= 5) {
                            $saved = self::recordLearnedTransaction(
                                trim($phrase),
                                $accName,
                                $txType,
                                $category,
                                0.0,
                                $reviewOpts,
                                'gemini_synthesized',
                                0.95
                            );
                            if ($saved) {
                                $addedCount++;
                                $results[] = ['phrase' => trim($phrase), 'account' => $accName];
                            }
                        }
                    }
                }
            }
        }

        return [
            'success' => true,
            'added_count' => $addedCount,
            'samples' => array_slice($results, 0, 10)
        ];
    }

    /**
     * Statistics for the Admin AI training dashboard
     */
    public static function getStats(): array
    {
        $db = self::getDb();
        self::ensureSeedDataset();

        $total = (int)$db->query("SELECT COUNT(*) FROM ai_training_dataset")->fetchColumn();
        $userVerified = (int)$db->query("SELECT COUNT(*) FROM ai_training_dataset WHERE source = 'user_verified'")->fetchColumn();
        $geminiSynthesized = (int)$db->query("SELECT COUNT(*) FROM ai_training_dataset WHERE source = 'gemini_synthesized'")->fetchColumn();
        $bootstrapped = (int)$db->query("SELECT COUNT(*) FROM ai_training_dataset WHERE source = 'seed_bootstrapped'")->fetchColumn();

        $topAccountsStmt = $db->query("
            SELECT suggested_account_name, transaction_type, COUNT(*) as pattern_count, SUM(use_count) as total_uses
            FROM ai_training_dataset
            GROUP BY suggested_account_name, transaction_type
            ORDER BY total_uses DESC, pattern_count DESC
            LIMIT 8
        ");
        $topAccounts = $topAccountsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Offline readiness: percentage of active Chart of Accounts that have at least 1 trained pattern
        $totalActiveAccounts = (int)$db->query("SELECT COUNT(*) FROM chart_of_accounts WHERE is_active = 1")->fetchColumn();
        $coveredAccounts = (int)$db->query("
            SELECT COUNT(DISTINCT c.id) 
            FROM chart_of_accounts c
            JOIN ai_training_dataset t ON LOWER(c.name) = LOWER(t.suggested_account_name)
            WHERE c.is_active = 1
        ")->fetchColumn();

        $readinessPct = ($totalActiveAccounts > 0) ? round(($coveredAccounts / $totalActiveAccounts) * 100, 1) : 100.0;

        return [
            'total_patterns' => $total,
            'user_verified' => $userVerified,
            'gemini_synthesized' => $geminiSynthesized,
            'seed_bootstrapped' => $bootstrapped,
            'covered_accounts' => $coveredAccounts,
            'total_active_accounts' => $totalActiveAccounts,
            'offline_readiness_percentage' => $readinessPct,
            'top_accounts' => $topAccounts
        ];
    }

    /**
     * Paginated list of trained dataset records
     */
    public static function listDataset(int $limit = 50, int $offset = 0, ?string $search = null): array
    {
        $db = self::getDb();
        self::ensureSeedDataset();

        $where = "1=1";
        $params = [];
        if (!empty($search)) {
            $where .= " AND (prompt_text LIKE ? OR suggested_account_name LIKE ? OR normalized_tokens LIKE ?)";
            $s = '%' . trim($search) . '%';
            $params = [$s, $s, $s];
        }

        $countStmt = $db->prepare("SELECT COUNT(*) FROM ai_training_dataset WHERE {$where}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql = "SELECT * FROM ai_training_dataset WHERE {$where} ORDER BY use_count DESC, id DESC LIMIT {$limit} OFFSET {$offset}";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'records' => $records
        ];
    }

    /**
     * Deletes a training pattern
     */
    public static function deletePattern(int $id): bool
    {
        $db = self::getDb();
        $stmt = $db->prepare("DELETE FROM ai_training_dataset WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Automatically seeds the base dataset if empty
     */
    public static function ensureSeedDataset(): void
    {
        $db = self::getDb();
        try {
            $count = (int)$db->query("SELECT COUNT(*) FROM ai_training_dataset")->fetchColumn();
            if ($count > 0) {
                return;
            }
        } catch (\Throwable $e) {
            return;
        }

        $seeds = [
            // Computers / Desktops / Laptops (Capital Asset vs Office Expense)
            ["i buy office desktop for 35k", "Fixed Asset - Computers", "debit", "electronics", 18.0, 1, ["Fixed Asset - Computers", "Office Expense"]],
            ["bought 2 macbook pros for 2.5 lakh", "Fixed Asset - Computers", "debit", "electronics", 18.0, 1, ["Fixed Asset - Computers", "Office Expense"]],
            ["purchased dell laptop for developer 65,000", "Fixed Asset - Computers", "debit", "electronics", 18.0, 1, ["Fixed Asset - Computers", "Office Expense"]],
            ["bought 3 monitors and computer screens 45000", "Fixed Asset - Computers", "debit", "electronics", 18.0, 1, ["Fixed Asset - Computers", "Office Expense"]],
            ["i buy printer and scanner for office 15k", "Fixed Asset - Computers", "debit", "electronics", 18.0, 1, ["Fixed Asset - Computers", "Office Expense"]],
            ["ordered lenovo desktop computer 42000", "Fixed Asset - Computers", "debit", "electronics", 18.0, 1, ["Fixed Asset - Computers", "Office Expense"]],
            ["purchased server rack and storage for 1.8 lakh", "Fixed Asset - Computers", "debit", "electronics", 18.0, 1, ["Fixed Asset - Computers", "Office Expense"]],

            // Furniture & Equipment
            ["bought 5 ergonomic office chairs 25000", "Office Equipment & Furniture", "debit", "furniture", 18.0, 1, ["Office Equipment & Furniture", "Office Expense"]],
            ["purchased conference table and executive desk 55k", "Office Equipment & Furniture", "debit", "furniture", 18.0, 1, ["Office Equipment & Furniture", "Office Expense"]],
            ["daikin ac 1.5 ton installed for office 42000", "Office Equipment & Furniture", "debit", "furniture", 28.0, 1, ["Office Equipment & Furniture", "Office Expense"]],
            ["office reception sofa and wooden table 30k", "Office Equipment & Furniture", "debit", "furniture", 18.0, 1, ["Office Equipment & Furniture", "Office Expense"]],

            // Commission & Brokerage
            ["receved commition for work transfer 50k", "Commission Income", "credit", "commission", 18.0, 0, ["Commission Income"]],
            ["received brokerage fees 25000 from client", "Commission Income", "credit", "commission", 18.0, 0, ["Commission Income"]],
            ["referral commission received 12,500", "Commission Income", "credit", "commission", 18.0, 0, ["Commission Income"]],
            ["paid commission to sales agent 15000", "Commission & Brokerage Expense", "debit", "commission_expense", 18.0, 0, ["Commission & Brokerage Expense"]],
            ["brokerage paid for new office lease 20k", "Commission & Brokerage Expense", "debit", "commission_expense", 18.0, 0, ["Commission & Brokerage Expense"]],

            // Consulting & Software Services
            ["received consulting fees 75000 with 18% gst", "Consulting Income", "credit", "consulting", 18.0, 0, ["Consulting Income"]],
            ["client advisory retainer 1 lakh received", "Consulting Income", "credit", "consulting", 18.0, 0, ["Consulting Income"]],
            ["tax consulting revenue received 35k", "Consulting Income", "credit", "consulting", 18.0, 0, ["Consulting Income"]],
            ["built web app for client received 2 lakh", "Software Development Services", "credit", "software_development", 18.0, 0, ["Software Development Services"]],
            ["ecommerce website milestone payment 1.5 lac", "Software Development Services", "credit", "software_development", 18.0, 0, ["Software Development Services"]],
            ["api integration charges received 60,000", "Software Development Services", "credit", "software_development", 18.0, 0, ["Software Development Services"]],

            // Subscriptions & Cloud
            ["aws cloud hosting bill paid 18,500", "Software Subscriptions & Cloud", "debit", "software", 18.0, 0, ["Software Subscriptions & Cloud"]],
            ["github copilot and enterprise licenses 12000", "Software Subscriptions & Cloud", "debit", "software", 18.0, 0, ["Software Subscriptions & Cloud"]],
            ["google workspace email subscription 4500", "Software Subscriptions & Cloud", "debit", "software", 18.0, 0, ["Software Subscriptions & Cloud"]],
            ["zoom and slack annual subscription 32k", "Software Subscriptions & Cloud", "debit", "software", 18.0, 0, ["Software Subscriptions & Cloud"]],

            // Rent & Utilities
            ["paid monthly office rent 45000", "Office Rent", "debit", "rent", 0.0, 0, ["Office Rent"]],
            ["coworking space dedicated desk rent 22000", "Office Rent", "debit", "rent", 18.0, 0, ["Office Rent"]],
            ["airtel broadband and fiber wifi bill 2499", "Utility Bills", "debit", "utilities", 18.0, 0, ["Utility Bills"]],
            ["electricity bill paid 8500", "Utility Bills", "debit", "utilities", 0.0, 0, ["Utility Bills"]],
            ["office mobile phone recharges 1500", "Utility Bills", "debit", "utilities", 18.0, 0, ["Utility Bills"]],

            // Salaries & HR
            ["paid staff salaries for august 4.5 lakh", "Salaries & Wages Expense", "debit", "salary", 0.0, 0, ["Salaries & Wages Expense"]],
            ["developer monthly salary 80000 transferred", "Salaries & Wages Expense", "debit", "salary", 0.0, 0, ["Salaries & Wages Expense"]],
            ["office intern stipend 15000 paid", "Salaries & Wages Expense", "debit", "salary", 0.0, 0, ["Salaries & Wages Expense"]],

            // Office Expenses, Food, Petty Cash
            ["swiggy team lunch order 1850", "Office Expense", "debit", "office_expense", 5.0, 0, ["Office Expense"]],
            ["pantry coffee tea and snacks 2200", "Office Expense", "debit", "office_expense", 0.0, 0, ["Office Expense"]],
            ["printer paper reams and stationery 1600", "Printing & Stationery", "debit", "stationery", 12.0, 0, ["Printing & Stationery"]],
            ["visiting cards and brochures printed 3500", "Printing & Stationery", "debit", "stationery", 12.0, 0, ["Printing & Stationery"]],
            ["blue dart courier charges for legal documents 850", "Courier & Shipping Charges", "debit", "shipping", 18.0, 0, ["Courier & Shipping Charges"]],

            // Travel & Professional
            ["petrol conveyance for client visit 1200", "Travel & Conveyance", "debit", "travel", 0.0, 0, ["Travel & Conveyance"]],
            ["uber cab for meeting 450", "Travel & Conveyance", "debit", "travel", 5.0, 0, ["Travel & Conveyance"]],
            ["chartered accountant audit fees paid 25000", "Legal & Professional Fees", "debit", "legal", 18.0, 0, ["Legal & Professional Fees"]],
            ["legal consultant fee for contract draft 18k", "Legal & Professional Fees", "debit", "legal", 18.0, 0, ["Legal & Professional Fees"]],
            ["bank wire transfer processing fee 350", "Bank Charges & Processing Fees", "debit", "bank_charges", 18.0, 0, ["Bank Charges & Processing Fees"]],

            // Sales
            ["sold old computer hardware 12000", "Sales of Hardware & Goods", "credit", "sales", 18.0, 0, ["Sales of Hardware & Goods"]],
            ["sale of spare equipment 25000", "Sales of Hardware & Goods", "credit", "sales", 18.0, 0, ["Sales of Hardware & Goods"]],
        ];

        $ins = $db->prepare("
            INSERT INTO ai_training_dataset 
            (prompt_text, normalized_tokens, suggested_account_name, transaction_type, suggested_category, gst_rate, needs_user_review, review_options_json, source, confidence_score, use_count, last_used_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'seed_bootstrapped', 0.98, 2, CURRENT_TIMESTAMP)
        ");

        foreach ($seeds as $s) {
            $norm = self::normalizeTokens($s[0]);
            $optsJson = json_encode($s[6]);
            try {
                $ins->execute([$s[0], $norm, $s[1], $s[2], $s[3], $s[4], $s[5], $optsJson]);
            } catch (\Throwable $e) {}
        }
    }
}
