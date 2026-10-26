<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/restCWP.php';
require_once dirname(__DIR__) . '/odataCWP.php';
require_once dirname(__DIR__) . '/database.php';
require_once dirname(__DIR__) . '/security.php';
require_once dirname(__DIR__, 2) . '/openbridge/openbridge.php';

use OpenBridge\Support\OpenBridgeResponseNormalizer;
use restCWP\restCWP;

function assertEngineHttp(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function latestMockRequest(string $logFile): array
{
    $lines = is_file($logFile)
        ? array_values(array_filter(explode(PHP_EOL, trim((string) file_get_contents($logFile)))))
        : [];
    assertEngineHttp($lines !== [], 'The mock FileMaker server did not receive a request.');
    $decoded = json_decode((string) end($lines), true);
    assertEngineHttp(is_array($decoded), 'The mock request log is invalid JSON.');
    return $decoded;
}

function queryPairs(string $query): array
{
    $pairs = [];
    foreach (explode('&', $query) as $part) {
        if ($part === '') {
            continue;
        }
        [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
        $pairs[rawurldecode($key)] = rawurldecode($value);
    }
    return $pairs;
}

function waitForMockServer(string $url, int $seconds = 5): void
{
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT => 1,
        ]);
        curl_exec($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($code > 0) {
            return;
        }
        usleep(100000);
    }
    throw new RuntimeException('The mock FileMaker server did not start.');
}

function phpBinary(): string
{
    $xampp = 'C:/xampp/php/php.exe';
    return is_file($xampp) ? $xampp : PHP_BINARY;
}

$fixtures = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures';
$logFile = $fixtures . DIRECTORY_SEPARATOR . 'mock_filemaker_requests.jsonl';
@unlink($logFile);
$port = 19000 + random_int(0, 999);
$router = $fixtures . DIRECTORY_SEPARATOR . 'mock_filemaker_router.php';
$command = [phpBinary(), '-S', '127.0.0.1:' . $port, $router];
$process = proc_open($command, [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $pipes, __DIR__);

assertEngineHttp(is_resource($process), 'Unable to start the mock FileMaker server.');

try {
    $baseUrl = 'http://127.0.0.1:' . $port;
    waitForMockServer($baseUrl . '/fmi/odata/v4/Demo/$metadata');

    $engine = new restCWP($baseUrl, 'Demo', '', 'admin', 'secret', [
        'transport' => restCWP::TRANSPORT_ODATA,
        'timeout' => 3,
    ]);

    $metadata = $engine->getODataMetadata();
    assertEngineHttp($metadata['success'] === true, 'OData metadata should succeed against the mock server.');
    assertEngineHttp($metadata['discovered_tables'] === ['Contacts', 'Invoices'], 'OData metadata table discovery changed.');

    $list = $engine->listODataRecords([
        'table' => 'Contacts',
        'queryOptions' => [
            '$top' => 25,
            '$skip' => 2,
            '$count' => true,
            '$filter' => "First Name eq 'Alex & Co'",
        ],
    ]);
    assertEngineHttp($list['success'] === true, 'OData list should succeed against the mock server.');
    $request = latestMockRequest($logFile);
    assertEngineHttp($request['method'] === 'GET', 'OData list used the wrong HTTP method.');
    assertEngineHttp($request['path'] === '/fmi/odata/v4/Demo/Contacts', 'OData list used the wrong path.');
    assertEngineHttp(isset($request['headers']['Authorization']), 'OData list did not send Basic Authentication.');
    assertEngineHttp(str_contains((string) $request['query'], '$top='), 'OData system query keys should keep their leading dollar sign.');
    $query = queryPairs((string) $request['query']);
    assertEngineHttp($query['$top'] === '25', 'OData $top was not sent correctly.');
    assertEngineHttp($query['$skip'] === '2', 'OData $skip was not sent correctly.');
    assertEngineHttp($query['$count'] === 'true', 'OData $count boolean was not sent as true/false.');
    assertEngineHttp($query['$filter'] === "First Name eq 'Alex & Co'", 'OData list $filter was not sent correctly.');

    $find = $engine->findODataRecords("First Name eq 'Direct'", [
        'table' => 'Contacts',
        'queryOptions' => [
            '$filter' => "First Name eq 'Wrong'",
            '$top' => 5,
        ],
    ]);
    assertEngineHttp($find['success'] === true, 'OData find should succeed against the mock server.');
    $findRequest = latestMockRequest($logFile);
    $findQuery = queryPairs((string) $findRequest['query']);
    assertEngineHttp($findQuery['$filter'] === "First Name eq 'Direct'", 'Direct OData find filter should override queryOptions.');

    $record = $engine->getODataRecord("A/B O'Neil", ['table' => 'Contacts', 'keyType' => 'string']);
    assertEngineHttp($record['success'] === true, 'OData get should succeed against the mock server.');
    $recordRequest = latestMockRequest($logFile);
    assertEngineHttp(
        $recordRequest['uri'] === "/fmi/odata/v4/Demo/Contacts('A%2FB%20O''Neil')",
        'OData string key was not safely encoded in the request URI.'
    );

    $script = $engine->runODataScript('Touch', ['id' => 42]);
    assertEngineHttp($script['success'] === true, 'OData script should succeed against the mock server.');
    $scriptRequest = latestMockRequest($logFile);
    assertEngineHttp($scriptRequest['method'] === 'POST', 'OData script used the wrong HTTP method.');
    assertEngineHttp($scriptRequest['path'] === '/fmi/odata/v4/Demo/Script.Touch', 'OData script used the wrong path.');
    assertEngineHttp(
        $scriptRequest['body'] === '{"scriptParameterValue":{"id":42}}',
        'OData script parameter body changed.'
    );

    $health = $engine->healthCheck(restCWP::TRANSPORT_ODATA, ['database' => 'Demo', 'table' => 'Missing']);
    assertEngineHttp($health['ok'] === false, 'OData health should fail for an unavailable table.');
    assertEngineHttp(
        $health['checks']['layout_or_table']['message'] === 'Entity set Missing was not found.',
        'OData health did not surface the upstream error message.'
    );

    $normalized = OpenBridgeResponseNormalizer::oData($list, [
        'operation' => 'list',
        'database' => 'Demo',
        'resource' => 'Contacts',
    ]);
    assertEngineHttp($normalized['ok'] === true && $normalized['nextLink'] === 'Contacts?$skip=27', 'OData normalized pagination changed.');
    assertEngineHttp($normalized['records'][0]['Invoice.Total'] === '0012.50', 'OData normalization changed FileMaker values.');
} finally {
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_terminate($process);
    proc_close($process);
}
@unlink($logFile);

$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'openbridge_engine_http_' . bin2hex(random_bytes(6)) . '.sqlite';
$config = [
    'database' => [
        'enabled' => true,
        'path' => $databasePath,
        'autoInitialize' => true,
        'seedDemoAdmin' => false,
    ],
    'security' => [
        'masterKey' => base64_encode(random_bytes(32)),
        'keyVersion' => 1,
    ],
    'filemaker' => [
        'useVaultDefaults' => true,
    ],
];
$pdo = openBridgeDatabasePdo($config);
$user = openBridgeDatabaseCreateUser($pdo, 'release-admin', 'release-admin@example.test', 'long-enough-password');
$repository = new OpenBridgeRepository(
    $pdo,
    new OpenBridgeCrypto(new OpenBridgeKeyProvider($config, dirname(__DIR__)))
);
$repository->saveSecret((int) $user['id'], 'filemaker_connection', [
    'host' => 'https://filemaker.example.test',
    'database' => 'Prod',
    'layout' => 'People',
    'table' => 'Contacts',
    'username' => 'fm-user',
    'password' => 'fm-password',
    'transport' => 'odata',
]);
$method = new ReflectionMethod(OpenBridge::class, 'trustedVaultFileMaker');
$method->setAccessible(true);
$vaultDefaults = $method->invoke(null, $config, dirname(__DIR__));
assertEngineHttp(is_array($vaultDefaults), 'OpenBridge trusted vault defaults were not loaded.');
assertEngineHttp($vaultDefaults['host'] === 'https://filemaker.example.test', 'OpenBridge trusted vault host changed.');
assertEngineHttp($vaultDefaults['transport'] === 'odata', 'OpenBridge trusted vault transport changed.');
@unlink($databasePath);

echo "OpenBridge engine HTTP smoke test passed\n";
