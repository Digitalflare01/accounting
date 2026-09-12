<?php
declare(strict_types=1);

// Enable error logging while returning clean JSON errors
error_reporting(E_ALL);
ini_set('display_errors', '0');

$startTime = microtime(true);

// Headers & CORS
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    if (!headers_sent()) { @http_response_code(200); }
    exit;
}

// Simple Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    
    if (file_exists($file)) {
        require_once $file;
    }
});

// Parse URI Path
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = preg_replace('#^/accounting#', '', $uri); // Strip subdirectory if hosted in WAMP
$uri = preg_replace('#^/api/#', '/', $uri);     // Normalize /api/... to /...
$uri = rtrim($uri, '/');
if (empty($uri)) {
    $uri = '/';
}

$method = $_SERVER['REQUEST_METHOD'];
$rawInput = file_get_contents('php://input');
$jsonBody = json_decode((string)$rawInput, true);
$requestBody = is_array($jsonBody) ? $jsonBody : ($_POST ?? []);
$queryParams = $_GET;

// Automatic Telemetry & Activity Logger
register_shutdown_function(function() use ($startTime, $method, $uri) {
    if ($method === 'OPTIONS') {
        return;
    }
    $durationMs = round((microtime(true) - $startTime) * 1000, 2);
    $statusCode = http_response_code() ?: 200;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

    $userId = null;
    $token = \App\Middleware\Auth::getBearerToken();
    if ($token) {
        $payload = \App\Middleware\Auth::verifyToken($token);
        if ($payload && isset($payload['sub'])) {
            $userId = (int)$payload['sub'];
        }
    }

    try {
        $db = \App\Config\Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO web_activity_logs (user_id, ip_address, method, endpoint, status_code, response_time_ms, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $ip, $method, '/api' . $uri, $statusCode, $durationMs, $ua]);
    } catch (\Throwable $e) {
        // Silently preserve main payload integrity
    }
});

try {
    // Route matching
    $routeKey = "{$method} {$uri}";

    // Handle parameterized routes first
    if (preg_match('#^PUT /admin/users/(\d+)$#', $routeKey, $matches)) {
        $admin = new \App\Controllers\AdminController();
        $admin->updateUser((int)$matches[1], $requestBody);
        exit;
    }
    if (preg_match('#^PUT /admin/coupons/(\d+)/toggle$#', $routeKey, $matches)) {
        $admin = new \App\Controllers\AdminController();
        $admin->toggleCoupon((int)$matches[1]);
        exit;
    }
    if (preg_match('#^DELETE /admin/coupons/(\d+)$#', $routeKey, $matches)) {
        $admin = new \App\Controllers\AdminController();
        $admin->deleteCoupon((int)$matches[1]);
        exit;
    }
    if (preg_match('#^DELETE /transactions/(\d+)$#', $routeKey, $matches)) {
        $tx = new \App\Controllers\TransactionController();
        $tx->delete((int)$matches[1]);
        exit;
    }
    if (preg_match('#^DELETE /accounts/(\d+)$#', $routeKey, $matches)) {
        $tx = new \App\Controllers\TransactionController();
        $tx->deleteAccount((int)$matches[1]);
        exit;
    }
    if (preg_match('#^GET /invoices/(\d+)$#', $routeKey, $matches)) {
        $inv = new \App\Controllers\InvoiceController();
        $inv->show((int)$matches[1]);
        exit;
    }
    if (preg_match('#^DELETE /invoices/(\d+)$#', $routeKey, $matches)) {
        $inv = new \App\Controllers\InvoiceController();
        $inv->delete((int)$matches[1]);
        exit;
    }

    switch ($routeKey) {
        case 'GET /health':
            echo json_encode([
                'status' => 'healthy',
                'service' => 'Accounting Core PHP REST API',
                'timestamp' => date('c'),
                'db_driver' => \App\Config\Database::getDriver()
            ]);
            break;

        // --- AUTH ROUTES ---
        case 'POST /auth/login':
            $auth = new \App\Controllers\AuthController();
            $auth->login($requestBody);
            break;

        case 'POST /auth/register':
            $auth = new \App\Controllers\AuthController();
            $auth->register($requestBody);
            break;

        case 'POST /auth/admin-register':
            $auth = new \App\Controllers\AuthController();
            $auth->registerAdmin($requestBody);
            break;

        case 'PUT /auth/profile':
            $auth = new \App\Controllers\AuthController();
            $auth->updateProfile($requestBody);
            break;

        case 'GET /auth/profile':
        case 'GET /auth/me':
            $auth = new \App\Controllers\AuthController();
            $auth->me();
            break;

        // --- SUBSCRIPTIONS & PLANS ---
        case 'GET /subscriptions/my':
            $sub = new \App\Controllers\SubscriptionController();
            $sub->getMySubscription();
            break;

        case 'GET /subscriptions/plans':
            $sub = new \App\Controllers\SubscriptionController();
            $sub->getPublicPlans();
            break;

        case 'POST /subscriptions/upgrade':
            $sub = new \App\Controllers\SubscriptionController();
            $sub->upgrade($requestBody);
            break;

        case 'POST /subscriptions/validate-coupon':
            $sub = new \App\Controllers\SubscriptionController();
            $sub->validateCoupon($requestBody);
            break;

        // --- INVOICING & BILLING ROUTES ---
        case 'GET /invoices':
            $inv = new \App\Controllers\InvoiceController();
            $inv->list();
            break;

        case 'POST /invoices':
            $inv = new \App\Controllers\InvoiceController();
            $inv->create($requestBody);
            break;

        case 'POST /invoices/delete':
            $inv = new \App\Controllers\InvoiceController();
            $inv->delete((int)($requestBody['id'] ?? $requestBody['invoice_id'] ?? 0));
            break;

        // --- SMART DOCUMENT INGESTION & POSTING ---
        case 'POST /documents/analyze':
            $doc = new \App\Controllers\DocumentController();
            $doc->analyze($requestBody);
            break;

        case 'POST /documents/batch-post':
            $doc = new \App\Controllers\DocumentController();
            $doc->batchPost($requestBody);
            break;

        case 'GET /documents/samples':
            $doc = new \App\Controllers\DocumentController();
            $doc->getSamples();
            break;

        // --- AI ROUTES ---
        case 'POST /ai/parse':
            $tx = new \App\Controllers\TransactionController();
            $tx->parseNaturalLanguage($requestBody);
            break;

        case 'POST /ai/understand':
            $tx = new \App\Controllers\TransactionController();
            $tx->understandCompoundPrompt($requestBody);
            break;

        case 'POST /ai/auto-post':
            $tx = new \App\Controllers\TransactionController();
            $tx->autoPostCompoundPrompt($requestBody);
            break;

        case 'POST /ai/solve-case-study':
            $tx = new \App\Controllers\TransactionController();
            $tx->solveCaseStudy($requestBody);
            break;

        case 'POST /ai/case-study/post':
            $tx = new \App\Controllers\TransactionController();
            $tx->postCaseStudy($requestBody);
            break;

        case 'POST /ai/depreciation':
            \App\Middleware\Auth::requireAuth();
            $ai = new \App\Services\AIService();
            $prompt = trim($requestBody['prompt'] ?? '');
            if (empty($prompt)) {
                if (!headers_sent()) { @http_response_code(400); }
                echo json_encode(['success' => false, 'error' => 'Prompt text is required.']);
                break;
            }
            $deprResult = $ai->resolveDepreciationPrompt($prompt);
            echo json_encode($deprResult);
            break;

        // --- TRANSACTIONS & COA ---
        case 'POST /transactions/depreciation':
            $tx = new \App\Controllers\TransactionController();
            $tx->postDepreciation($requestBody);
            break;

        case 'POST /transactions':
            $tx = new \App\Controllers\TransactionController();
            $tx->store($requestBody);
            break;

        case 'GET /transactions':
            $tx = new \App\Controllers\TransactionController();
            $tx->list();
            break;

        case 'POST /transactions/delete':
            $tx = new \App\Controllers\TransactionController();
            $ids = $requestBody['ids'] ?? $requestBody['transaction_ids'] ?? null;
            if (is_array($ids)) {
                $tx->deleteMultiple($ids);
            } else {
                $tx->delete((int)($requestBody['id'] ?? $requestBody['transaction_id'] ?? 0));
            }
            break;

        case 'POST /transactions/clear-all':
            $tx = new \App\Controllers\TransactionController();
            $tx->clearAll();
            break;

        case 'GET /accounts':
            $tx = new \App\Controllers\TransactionController();
            $tx->getAccounts();
            break;

        case 'POST /accounts':
            $tx = new \App\Controllers\TransactionController();
            $tx->createAccount($requestBody);
            break;

        case 'POST /accounts/delete':
            $tx = new \App\Controllers\TransactionController();
            $tx->deleteAccount((int)($requestBody['id'] ?? $requestBody['account_id'] ?? 0));
            break;

        // --- REPORTING ---
        case 'GET /reports/profit-loss':
            $rep = new \App\Controllers\ReportController();
            $rep->profitAndLoss($queryParams);
            break;

        case 'GET /reports/balance-sheet':
            $rep = new \App\Controllers\ReportController();
            $rep->balanceSheet($queryParams);
            break;

        case 'GET /reports/trial-balance':
            $rep = new \App\Controllers\ReportController();
            $rep->trialBalance($queryParams);
            break;

        case 'GET /reports/cash-flow':
            $rep = new \App\Controllers\ReportController();
            $rep->cashFlow($queryParams);
            break;

        case 'GET /reports/principles':
            $rep = new \App\Controllers\ReportController();
            $rep->getAccountingPrinciples($queryParams);
            break;

        case 'POST /reports/principles/collect-google':
            $rep = new \App\Controllers\ReportController();
            $rep->collectPrinciplesFromGoogle($requestBody);
            break;

        case 'GET /reports/gst':
            $rep = new \App\Controllers\ReportController();
            $rep->gstReport($queryParams);
            break;

        case 'GET /reports/gst/export-json':
            $rep = new \App\Controllers\ReportController();
            $rep->exportGstJson($queryParams);
            break;

        case 'GET /reports/gst/export-csv':
            $rep = new \App\Controllers\ReportController();
            $rep->exportGstCsv($queryParams);
            break;

        // --- ADMIN PORTAL & DASHBOARD ENDPOINTS ---
        case 'GET /admin/dashboard':
            $admin = new \App\Controllers\AdminController();
            $admin->dashboardOverview();
            break;

        case 'GET /admin/activity':
            $admin = new \App\Controllers\AdminController();
            $admin->getActivityAnalytics($queryParams);
            break;

        case 'GET /admin/users':
            $admin = new \App\Controllers\AdminController();
            $admin->getUsers($queryParams);
            break;

        case 'POST /admin/users/update':
            $admin = new \App\Controllers\AdminController();
            $targetId = (int)($requestBody['user_id'] ?? 0);
            $admin->updateUser($targetId, $requestBody);
            break;

        case 'GET /admin/subscriptions':
            $admin = new \App\Controllers\AdminController();
            $admin->getSubscriptions($queryParams);
            break;

        case 'POST /admin/subscriptions/assign':
            $admin = new \App\Controllers\AdminController();
            $admin->assignSubscription($requestBody);
            break;

        case 'GET /admin/coupons':
            $admin = new \App\Controllers\AdminController();
            $admin->getCoupons();
            break;

        case 'POST /admin/coupons':
            $admin = new \App\Controllers\AdminController();
            $admin->createCoupon($requestBody);
            break;

        case 'POST /admin/coupons/toggle':
            $admin = new \App\Controllers\AdminController();
            $admin->toggleCoupon((int)($requestBody['coupon_id'] ?? 0));
            break;

        case 'POST /admin/coupons/delete':
            $admin = new \App\Controllers\AdminController();
            $admin->deleteCoupon((int)($requestBody['coupon_id'] ?? 0));
            break;

        case 'GET /admin/system':
            $admin = new \App\Controllers\AdminController();
            $admin->getSystemDiagnostics();
            break;

        case 'GET /admin/announcement':
            $admin = new \App\Controllers\AdminController();
            $admin->getAnnouncement();
            break;

        case 'POST /admin/announcement':
            $admin = new \App\Controllers\AdminController();
            $admin->updateAnnouncement($requestBody);
            break;

        // --- API INTEGRATIONS & SERVICE CREDENTIALS ---
        case 'GET /admin/integrations':
            $admin = new \App\Controllers\AdminController();
            $admin->getApiIntegrations();
            break;

        case 'POST /admin/integrations':
            $admin = new \App\Controllers\AdminController();
            $admin->updateApiIntegrations($requestBody);
            break;

        case 'POST /admin/integrations/test-mail':
            $admin = new \App\Controllers\AdminController();
            $admin->testMailApi($requestBody);
            break;

        case 'POST /admin/integrations/test-whatsapp':
            $admin = new \App\Controllers\AdminController();
            $admin->testWhatsAppApi($requestBody);
            break;

        case 'POST /admin/integrations/test-gemini':
            $admin = new \App\Controllers\AdminController();
            $admin->testGeminiApi($requestBody);
            break;

        // --- AI AUTONOMOUS TRAINING & KNOWLEDGE BASE ---
        case 'GET /admin/ai-training/stats':
            $admin = new \App\Controllers\AdminController();
            $admin->getAITrainingStats();
            break;

        case 'GET /admin/ai-training/dataset':
            $admin = new \App\Controllers\AdminController();
            $admin->getAITrainingDataset($queryParams);
            break;

        case 'POST /admin/ai-training/generate':
            $admin = new \App\Controllers\AdminController();
            $admin->generateAITrainingData($requestBody);
            break;

        case 'POST /admin/ai-training/add':
            $admin = new \App\Controllers\AdminController();
            $admin->addAITrainingPattern($requestBody);
            break;

        case 'POST /admin/ai-training/delete':
            $admin = new \App\Controllers\AdminController();
            $admin->deleteAITrainingPattern((int)($requestBody['id'] ?? 0));
            break;

        case 'POST /admin/ai-training/test-offline':
            $admin = new \App\Controllers\AdminController();
            $admin->testAIOfflineFallback($requestBody);
            break;

        default:
            if (!headers_sent()) { @http_response_code(404); }
            echo json_encode([
                'success' => false,
                'error' => "Endpoint not found: {$method} {$uri}",
                'available_endpoints' => [
                    'POST /api/auth/login',
                    'POST /api/auth/register',
                    'GET /api/auth/me',
                    'POST /api/ai/parse',
                    'POST /api/ai/depreciation',
                    'POST /api/transactions',
                    'GET /api/transactions',
                    'GET /api/reports/profit-loss',
                    'GET /api/reports/balance-sheet',
                    'GET /api/reports/gst',
                    'GET /api/reports/gst/export-json',
                    'GET /api/reports/gst/export-csv',
                    'GET /api/admin/dashboard',
                    'GET /api/admin/activity',
                    'GET /api/admin/users',
                    'GET /api/admin/subscriptions',
                    'GET /api/admin/coupons',
                    'GET /api/admin/system'
                ]
            ]);
            break;
    }
} catch (\Throwable $e) {
    if (!headers_sent()) { @http_response_code(500); }
    echo json_encode([
        'success' => false,
        'error' => 'Server Error: ' . $e->getMessage()
    ]);
}
