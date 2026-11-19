<?php

declare(strict_types=1);

/**
 * MCP endpoint: Streamable HTTP, authenticated like the Dolibarr REST API (DOLAPIKEY or Bearer).
 */

use DoliMcp\Dolibarr\Context;
use DoliMcp\Http\Request;
use DoliMcp\Http\Response;
use DoliMcp\Server;

ob_start();
@ini_set('display_errors', '0');

foreach (['NOCSRFCHECK', 'NOTOKENRENEWAL', 'NOREQUIREMENU', 'NOREQUIREHTML', 'NOREQUIREAJAX', 'NOLOGIN', 'NOSESSION', 'NODEFAULTVALUES'] as $name) {
    if (!defined($name)) {
        define($name, '1');
    }
}

$res = 0;
foreach (['../../main.inc.php', '../../../main.inc.php'] as $candidate) {
    if (!$res && file_exists($candidate)) {
        $res = include $candidate;
    }
}
if (!$res) {
    ob_end_clean();
    http_response_code(500);
    exit;
}

require_once __DIR__ . '/src/autoload.php';

function dolimcpSend(Response $response): never
{
    ob_end_clean();
    http_response_code($response->status);
    foreach ($response->headers as $name => $value) {
        header("$name: $value");
    }
    echo $response->body;
    exit;
}

function dolimcpError(int $status, int $code, string $message): never
{
    dolimcpSend(Response::json($status, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => $code, 'message' => $message]]));
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? null;
if ($origin !== null && parse_url($origin, PHP_URL_HOST) !== parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
    dolimcpError(403, -32002, 'Forbidden origin');
}

require_once DOL_DOCUMENT_ROOT . '/includes/restler/framework/Luracast/Restler/AutoLoader.php';
spl_autoload_register(Luracast\Restler\AutoLoader::instance());
require_once DOL_DOCUMENT_ROOT . '/api/class/api.class.php';
require_once DOL_DOCUMENT_ROOT . '/api/class/api_access.class.php';

try {
    (new DolibarrApiAccess())->__isAllowed();
} catch (Luracast\Restler\RestException $e) {
    dolimcpError(401, -32001, $e->getMessage());
}

if (!isModEnabled('dolimcp')) {
    dolimcpError(503, -32003, 'The dolimcp module is not enabled');
}

// Same derivation as DolibarrApiAccess::__isAllowed(): same order, isset checks, Bearer when empty.
$headers = array_change_key_case(getallheaders() ?: [], CASE_LOWER);
$apiKey = '';
if (isset($_GET['api_key'])) {
    $apiKey = $_GET['api_key'];
}
if (isset($_GET['DOLAPIKEY'])) {
    $apiKey = $_GET['DOLAPIKEY'];
}
if (isset($_SERVER['HTTP_DOLAPIKEY'])) {
    $apiKey = $_SERVER['HTTP_DOLAPIKEY'];
} elseif (empty($apiKey)) {
    $apiKey = preg_replace('/^Bearer\s+/i', '', empty($headers['authorization']) ? '' : $headers['authorization']);
}
$apiKey = dol_string_nounprintableascii((string) $apiKey, 1);

try {
    $context = new Context($db, DolibarrApiAccess::$user, $apiKey);
    $request = new Request($_SERVER['REQUEST_METHOD'] ?? 'GET', $headers, (string) file_get_contents('php://input'));
    $response = Server::protocol($context)->handle($request);
} catch (Throwable $e) {
    $response = Response::json(500, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32603, 'message' => 'Internal error']]);
}

dolimcpSend($response);
