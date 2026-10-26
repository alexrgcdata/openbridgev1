<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/restCWP.php';
require_once dirname(__DIR__) . '/odataCWP.php';
require_once dirname(__DIR__, 2) . '/openbridge/openbridge.php';

use OpenBridge\Support\OpenBridgeResponseNormalizer;
use odataCWP\odataCWP;
use restCWP\restCWP;

function assertContract(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$dataApiRaw = [
    'status' => ['http_code' => 200],
    'result' => [
        'response' => [
            'dataInfo' => ['foundCount' => 1, 'returnedCount' => 1],
            'data' => [[
                'fieldData' => ['First Name' => 'Alex', 'Invoice.Total' => '0012.50'],
                'portalData' => [],
                'recordId' => '42',
                'modId' => '3',
            ]],
        ],
        'messages' => [['code' => '0', 'message' => 'OK']],
    ],
];
$odataRaw = [
    'success' => true,
    'http_code' => 200,
    'latency_ms' => 12.5,
    'response' => [
        '@odata.count' => 1,
        'value' => [[
            'First Name' => 'Alex',
            'Invoice.Total' => '0012.50',
            'ROWID' => 42,
        ]],
    ],
];
$context = ['operation' => 'list', 'database' => 'Demo', 'resource' => 'Contacts'];
$dataApi = OpenBridgeResponseNormalizer::dataApi($dataApiRaw, $context);
$odata = OpenBridgeResponseNormalizer::oData($odataRaw, $context);
$expectedKeys = ['ok', 'transport', 'operation', 'database', 'resource', 'records', 'returnedCount', 'totalCount', 'nextLink', 'messages', 'meta', 'raw'];

assertContract(array_keys($dataApi) === $expectedKeys, 'Data API normalized keys changed.');
assertContract(array_keys($odata) === $expectedKeys, 'OData normalized keys changed.');
assertContract($dataApi['ok'] === true && $odata['ok'] === true, 'Successful fixtures were not normalized as successful.');
assertContract($dataApi['returnedCount'] === 1 && $odata['returnedCount'] === 1, 'Returned counts are incorrect.');
assertContract($dataApi['totalCount'] === 1 && $odata['totalCount'] === 1, 'Total counts are incorrect.');
assertContract($dataApi['records'][0]['fieldData']['First Name'] === 'Alex', 'Data API field name or value was changed.');
assertContract($dataApi['records'][0]['fieldData']['Invoice.Total'] === '0012.50', 'Data API number-looking text was coerced.');
assertContract($odata['records'][0]['First Name'] === 'Alex', 'OData field name or value was changed.');
assertContract($odata['records'][0]['Invoice.Total'] === '0012.50', 'OData number-looking text was coerced.');

$normalizedRest = new restCWP('', '', '', '', '', ['responseFormat' => restCWP::RESPONSE_NORMALIZED]);
$restFailure = $normalizedRest->getRecords();
assertContract(isset($restFailure['ok'], $restFailure['records']) && $restFailure['transport'] === 'data-api', 'restCWP normalized mode is not active.');

$odataClient = new odataCWP('', '', '', '', '');
$odataFailure = $odataClient->getRecords();
assertContract(isset($odataFailure['ok'], $odataFailure['records']) && $odataFailure['transport'] === 'odata', 'odataCWP did not return the normalized contract.');

$factoryDataApi = OpenBridge::dataApi(['host' => '', 'database' => '', 'layout' => '', 'username' => '', 'password' => '']);
$factoryOData = OpenBridge::odata(['host' => '', 'database' => '', 'table' => '', 'username' => '', 'password' => '']);
assertContract($factoryDataApi instanceof restCWP, 'OpenBridge::dataApi() returned the wrong class.');
assertContract($factoryOData instanceof odataCWP, 'OpenBridge::odata() returned the wrong class.');

echo "OpenBridge Data API/OData contract smoke test passed\n";
