<?php
/**
 * Offline smoke tests for the protected beta Playground adapter.
 *
 * Run from the repository root with:
 *   C:\xampp\php\php.exe restCWP/tests/playground_smoke.php
 *
 * The connection is intentionally incomplete, so these assertions never make
 * a network request or require FileMaker Server.
 */

require_once dirname(__DIR__) . '/restCWP.php';

$connection = new \restCWP\restCWP('', '', '', '', '', [
    'transport' => \restCWP\restCWP::TRANSPORT_ODATA,
]);

$list = $connection->playground('list', [
    'transport' => \restCWP\restCWP::TRANSPORT_ODATA,
]);

if ($list['success'] !== false || $list['transport'] !== \restCWP\restCWP::TRANSPORT_ODATA) {
    throw new \RuntimeException('Incomplete OData Playground configuration should fail locally.');
}

if ($list['request'] !== null || empty($list['error'])) {
    throw new \RuntimeException('The failed local check should not expose a network request.');
}

$find = $connection->findODataRecords("Name eq 'Alex'");
if ($find['success'] !== false || $find['operation'] !== 'find') {
    throw new \RuntimeException('The friendly OData find method should preserve the operation result.');
}

$metadata = $connection->getODataMetadata();
if ($metadata['success'] !== false || $metadata['operation'] !== 'metadata') {
    throw new \RuntimeException('Incomplete OData metadata configuration should fail locally.');
}

$metadataParser = new \ReflectionMethod($connection, 'discoverODataTables');
$metadataParser->setAccessible(true);
$tables = $metadataParser->invoke(
    $connection,
    '<Schema><EntityContainer><EntitySet Name="Contacts"/><EntitySet Name="Inventory"/></EntityContainer></Schema>'
);
if ($tables !== ['Contacts', 'Inventory']) {
    throw new \RuntimeException('OData metadata discovery should return stable entity-set names.');
}

$urlBuilder = new \ReflectionMethod($connection, 'buildHealthUrl');
$urlBuilder->setAccessible(true);
$schemeConnection = new \restCWP\restCWP('https://example.test/', 'Demo', 'Contacts', 'api', 'secret');
if ($urlBuilder->invoke($schemeConnection, '/fmi/data/vLatest/productInfo') !== 'https://example.test/fmi/data/vLatest/productInfo') {
    throw new \RuntimeException('A host that already includes HTTPS must not receive a duplicated scheme.');
}

$invalid = $connection->playground('delete', []);
if ($invalid['success'] !== false || strpos($invalid['error'], 'only supports list, find, get, and script') === false) {
    throw new \RuntimeException('Unsupported Playground operations must be rejected.');
}

$capabilities = $connection->getCapabilities('odata');
foreach (['list_records', 'find_records', 'get_record', 'run_script'] as $operation) {
    if (empty($capabilities['operations'][$operation]['library'])) {
        throw new \RuntimeException('Expected OData library capability: ' . $operation);
    }
}

foreach (['create_record', 'update_record', 'delete_record'] as $operation) {
    if (empty($capabilities['operations'][$operation]['library'])) {
        throw new \RuntimeException('Expected OData mutation library capability: ' . $operation);
    }
}

$create = $connection->createODataRecord(['Name' => 'Alex']);
if ($create['success'] !== false || $create['operation'] !== 'create') {
    throw new \RuntimeException('Incomplete OData create configuration should fail locally.');
}
$update = $connection->updateODataRecord(1, ['Name' => 'Alex']);
if ($update['success'] !== false || $update['operation'] !== 'update') {
    throw new \RuntimeException('Incomplete OData update configuration should fail locally.');
}
$delete = $connection->deleteODataRecord(1);
if ($delete['success'] !== false || $delete['operation'] !== 'delete') {
    throw new \RuntimeException('Incomplete OData delete configuration should fail locally.');
}

$keyFormatter = new \ReflectionMethod($connection, 'odataKey');
$keyFormatter->setAccessible(true);
if ($keyFormatter->invoke($connection, '00123', 'string') !== "'00123'") {
    throw new \RuntimeException('Digit-only text keys must remain quoted when keyType is string.');
}
if ($keyFormatter->invoke($connection, 123, 'number') !== '123') {
    throw new \RuntimeException('Numeric OData keys must remain unquoted.');
}
if ($keyFormatter->invoke($connection, "O'Brien", 'string') !== "'O''Brien'") {
    throw new \RuntimeException('Apostrophes in OData string keys must be escaped by doubling.');
}

echo "restCWP Playground smoke test passed\n";
