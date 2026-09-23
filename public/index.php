<?php

// Convert any uncaught PHP warning/notice/error into the standard JSON error
// envelope instead of letting raw HTML (display_errors output) leak into an
// API response and break client-side JSON.parse(). The actual message is
// included in the response so the real root cause is visible to the caller
// instead of being silently swallowed or truncated.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function (\Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'error' => 1,
        'error_code' => 5999,
        'message' => 'Internal Server Error: ' . $e->getMessage() . ' (in ' . basename($e->getFile()) . ':' . $e->getLine() . ')',
        'response_time' => date('Y-m-d H:i:s'),
        'data' => (object)[]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
});

require __DIR__ . '/../vendor/autoload.php';

// Autoloader for App namespace
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $base_dir = __DIR__ . '/../src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

// Built-in web server static file pass-through
if (php_sapi_name() === 'cli-server') {
    $requestedFile = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($requestedFile) && strpos(realpath($requestedFile), realpath(__DIR__)) === 0) {
        return false;
    }
}

use App\Controller\SlikReaderController;

// Enable CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Origin, Content-Type, Accept, Authorization, X-Tenant-Key, X-User-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Normalize URI path
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Serve frontend SPA index.html on root
if ($requestUri === '/' || $requestUri === '/index.html') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');
    exit;
}

// Serve sample files if requested
if (strpos($requestUri, '/storage/samples/') === 0) {
    $sampleFile = __DIR__ . '/..' . $requestUri;
    if (file_exists($sampleFile)) {
        header('Content-Type: application/pdf');
        readfile($sampleFile);
        exit;
    }
}

// Strip '/backoffice' prefix if present
if (strpos($requestUri, '/backoffice') === 0) {
    $path = substr($requestUri, strlen('/backoffice'));
} else {
    $path = $requestUri;
}
$path = rtrim($path, '/') ?: '/';

$controller = new SlikReaderController();

// 1. POST /leads/upload/credit-checking
if ($method === 'POST' && $path === '/leads/upload/credit-checking') {
    $controller->handleUpload();
}

// 2. POST or GET /leads/list/pengajuan-slik
if (($method === 'POST' || $method === 'GET') && $path === '/leads/list/pengajuan-slik') {
    $controller->handleGetPengajuanSlik();
}

// 3. GET /leads/slik-reader/{lead_id}
if ($method === 'GET' && preg_match('#^/leads/slik-reader/([A-Za-z0-9_-]+)$#', $path, $matches)) {
    $controller->handleGetSummary($matches[1]);
}

// 4. GET /leads/slik-reader/result/{result_id}
if ($method === 'GET' && preg_match('#^/leads/slik-reader/result/([A-Za-z0-9_-]+)$#', $path, $matches)) {
    $controller->handleGetResult($matches[1]);
}

// 5. PUT /leads/slik-reader/result/{result_id}/approve
if ($method === 'PUT' && preg_match('#^/leads/slik-reader/result/([A-Za-z0-9_-]+)/approve$#', $path, $matches)) {
    $controller->handleApprove($matches[1]);
}

// 6. PUT /leads/slik-reader/result/{result_id}/reject
if ($method === 'PUT' && preg_match('#^/leads/slik-reader/result/([A-Za-z0-9_-]+)/reject$#', $path, $matches)) {
    $controller->handleReject($matches[1]);
}

// 7. GET /leads/slik-reader/file/{file_id}/download
if ($method === 'GET' && preg_match('#^/leads/slik-reader/file/([A-Za-z0-9_-]+)/download$#', $path, $matches)) {
    $controller->handleDownloadFile($matches[1]);
}

// 8. GET /leads/slik-reader/result/{result_id}/report
if ($method === 'GET' && preg_match('#^/leads/slik-reader/result/([A-Za-z0-9_-]+)/report$#', $path, $matches)) {
    $controller->handleReport($matches[1]);
}

// 9. GET /leads/credit/checking/{lead_id}
if ($method === 'GET' && preg_match('#^/leads/credit/checking/([A-Za-z0-9_-]+)$#', $path, $matches)) {
    $controller->handleGetCreditChecking($matches[1]);
}

// 10. PUT /leads/set-done/credit-checking/{lead_id}
if ($method === 'PUT' && preg_match('#^/leads/set-done/credit-checking/([A-Za-z0-9_-]+)$#', $path, $matches)) {
    $controller->handleSetDone($matches[1]);
}

// 11. PUT /leads/reject/pengajuan-slik/{lead_id}
if ($method === 'PUT' && preg_match('#^/leads/reject/pengajuan-slik/([A-Za-z0-9_-]+)$#', $path, $matches)) {
    $controller->handleRejectLead($matches[1]);
}

// 12. GET /leads/list/{lead_id}
if ($method === 'GET' && preg_match('#^/leads/list/([A-Za-z0-9_-]+)$#', $path, $matches)) {
    $controller->handleGetLeadDetail($matches[1]);
}

// 13. PUT /leads/no-slik/{lead_id}
if ($method === 'PUT' && preg_match('#^/leads/no-slik/([A-Za-z0-9_-]+)$#', $path, $matches)) {
    $controller->handleSetNoSlik($matches[1]);
}

// 14. PUT /leads/spouse/{lead_id}
if ($method === 'PUT' && preg_match('#^/leads/spouse/([A-Za-z0-9_-]+)$#', $path, $matches)) {
    $controller->handleUpdateSpouseInfo($matches[1]);
}

// 15. PUT /leads/penjamin/{lead_id}
if ($method === 'PUT' && preg_match('#^/leads/penjamin/([A-Za-z0-9_-]+)$#', $path, $matches)) {
    $controller->handleUpdatePenjaminInfo($matches[1]);
}

// 16. POST /leads/reset-slik/{lead_id}
if ($method === 'POST' && preg_match('#^/leads/reset-slik/([A-Za-z0-9_-]+)$#', $path, $matches)) {
    $controller->handleResetSlikData($matches[1]);
}

// Default 404
http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'error' => 1,
    'error_code' => 404,
    'message' => 'Endpoint tidak ditemukan: ' . $method . ' ' . $path,
    'response_time' => date('Y-m-d H:i:s'),
    'data' => (object)[]
]);
