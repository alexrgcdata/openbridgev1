<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/database.php';
require_once dirname(__DIR__) . '/security.php';
require_once dirname(__DIR__) . '/connections.php';

function connectionAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function connectionReject(callable $call, string $message): void
{
    try { $call(); } catch (Throwable) { return; }
    throw new RuntimeException($message);
}

function connectionRemoveTree(string $path): void
{
    if (!is_dir($path)) return;
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        $target = $path . DIRECTORY_SEPARATOR . $item;
        is_dir($target) ? connectionRemoveTree($target) : unlink($target);
    }
    rmdir($path);
}

$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'openbridge-connections-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700, true);

try {
    $config = [
        'database' => ['path' => $temporary . '/openbridge.sqlite', 'autoInitialize' => true],
        'security' => ['masterKeyFile' => $temporary . '/private/openbridge.key.php', 'keyVersion' => 1],
    ];
    $pdo = openBridgeDatabasePdo($config);
    $crypto = new OpenBridgeCrypto(new OpenBridgeKeyProvider($config, dirname(__DIR__)));
    (new OpenBridgeKeyProvider($config, dirname(__DIR__)))->encryptionKey();
    $vault = new OpenBridgeRepository($pdo, $crypto);
    $connections = new OpenBridgeConnectionRepository($pdo, $crypto);
    $owner = openBridgeDatabaseCreateUser($pdo, 'connection_owner', 'connection-owner@example.invalid', 'correct-horse-battery-staple');
    $other = openBridgeDatabaseCreateUser($pdo, 'connection_other', 'connection-other@example.invalid', 'correct-horse-battery-staple');

    $firstProfile = ['baseUrl' => 'https://api-one.example.invalid', 'apiKey' => 'first-secret-key', 'FileMaker Field' => 'Preserve This Value'];
    $secondProfile = ['baseUrl' => 'https://api-two.example.invalid', 'token' => 'second-secret-token'];
    $first = $connections->save((int) $owner['id'], 'mock-provider', 'Mock Production', 'api-key', $firstProfile, 'production');
    $second = $connections->save((int) $owner['id'], 'mock-provider', 'Mock Test', 'api-key', $secondProfile, 'test');
    connectionAssert(($first['saved'] ?? false) === true && ($second['saved'] ?? false) === true, 'Mock provider connections were not saved.');
    connectionAssert((string) $first['id'] !== (string) $second['id'], 'Multiple provider instances reused one connection ID.');

    $ownerList = $connections->list((int) $owner['id']);
    connectionAssert(count($ownerList) === 2, 'Owner list did not include both mock provider instances.');
    connectionAssert($connections->list((int) $other['id']) === [], 'Another owner could see saved connections.');
    $encodedList = json_encode($ownerList, JSON_THROW_ON_ERROR);
    foreach (['first-secret-key', 'second-secret-token', 'baseUrl', 'secret_key', 'ciphertext'] as $needle) {
        connectionAssert(!str_contains($encodedList, $needle), 'Connection list leaked private profile or storage details.');
    }
    $storedText = json_encode($pdo->query('SELECT display_name, provider_id, auth_type, environment FROM openbridge_connections')->fetchAll(), JSON_THROW_ON_ERROR);
    connectionAssert(!str_contains($storedText, 'first-secret-key') && !str_contains($storedText, 'second-secret-token'), 'Connection metadata leaked credentials.');
    $ciphertext = implode('|', $pdo->query('SELECT ciphertext FROM openbridge_secrets')->fetchAll(PDO::FETCH_COLUMN));
    connectionAssert(!str_contains($ciphertext, 'first-secret-key') && !str_contains($ciphertext, 'second-secret-token'), 'Encrypted vault ciphertext exposed plaintext credentials.');
    connectionAssert($connections->loadPrivateProfile((int) $owner['id'], (string) $first['id']) === $firstProfile, 'Private profile did not round-trip exactly.');
    connectionAssert($connections->loadPrivateProfile((int) $other['id'], (string) $first['id']) === null, 'Cross-owner private profile loaded.');

    $updated = $connections->save((int) $owner['id'], 'mock-provider', 'Mock Production Renamed', 'api-key', $firstProfile + ['rotated' => true], 'production', (string) $first['id'], (int) $first['revision']);
    connectionAssert((int) $updated['revision'] === 2, 'Connection revision did not advance.');
    connectionReject(fn () => $connections->save((int) $owner['id'], 'mock-provider', 'Stale Update', 'api-key', $firstProfile, 'production', (string) $first['id'], (int) $first['revision']), 'Stale connection revision was accepted.');
    connectionReject(fn () => $connections->delete((int) $owner['id'], (string) $first['id'], 1), 'Stale delete revision was accepted.');

    connectionAssert($connections->delete((int) $owner['id'], (string) $first['id'], 2) === true, 'Connection delete failed.');
    connectionAssert($connections->loadPrivateProfile((int) $owner['id'], (string) $first['id']) === null, 'Deleted private profile still loaded.');
    $remainingSecret = $pdo->prepare("SELECT COUNT(*) FROM openbridge_secrets WHERE user_id = :owner AND secret_key LIKE 'connection:%'");
    $remainingSecret->execute(['owner' => $owner['id']]);
    connectionAssert((int) $remainingSecret->fetchColumn() === 1, 'Connection secret did not cascade with metadata delete.');

    $vault->saveSecret((int) $other['id'], 'filemaker_connection', [
        'host' => 'https://filemaker.example.invalid',
        'database' => 'Demo',
        'layout' => 'Contacts',
        'username' => 'fm-user',
        'password' => 'fm-secret-password',
    ]);
    $migration = file_get_contents(dirname(__DIR__) . '/migrations/003-connections.sql');
    connectionAssert(is_string($migration) && $migration !== '', 'Connections migration fixture is missing.');
    $pdo->exec($migration);
    $adopted = $connections->list((int) $other['id']);
    connectionAssert(count($adopted) === 1 && ($adopted[0]['provider_id'] ?? '') === 'filemaker', 'v2 FileMaker vault entry was not adopted into connections.');
    connectionAssert(($adopted[0]['auth_type'] ?? '') === 'filemaker-credentials', 'Adopted FileMaker connection has wrong auth type.');
    connectionAssert(!str_contains(json_encode($adopted, JSON_THROW_ON_ERROR), 'fm-secret-password'), 'Adopted connection list leaked FileMaker credentials.');
    connectionAssert($connections->loadPrivateProfile((int) $other['id'], (string) $adopted[0]['id'])['password'] === 'fm-secret-password', 'Adopted FileMaker vault profile did not load.');

    $legacy = new PDO('sqlite:' . $temporary . '/legacy.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $legacy->exec('PRAGMA foreign_keys = ON');
    $legacy->exec(file_get_contents(dirname(__DIR__) . '/database_schema.sql'));
    connectionAssert(openBridgeDatabaseSchemaVersion($legacy) === 2, 'Legacy fixture must start at schema v2.');
    $legacyOwner = openBridgeDatabaseCreateUser($legacy, 'legacy-owner', 'legacy@example.invalid', 'synthetic-legacy-password');
    (new OpenBridgeRepository($legacy, $crypto))->saveSecret((int) $legacyOwner['id'], 'filemaker_connection', $firstProfile);
    $beforeCiphertext = $legacy->query('SELECT ciphertext FROM openbridge_secrets')->fetchColumn();
    openBridgeDatabaseInitialize($legacy);
    connectionAssert(openBridgeDatabaseSchemaVersion($legacy) === OPENBRIDGE_DATABASE_SCHEMA_VERSION, 'Versioned initialization did not migrate v2.');
    connectionAssert($legacy->query('SELECT ciphertext FROM openbridge_secrets')->fetchColumn() === $beforeCiphertext, 'Migration rewrote existing vault ciphertext.');
    connectionAssert(count((new OpenBridgeConnectionRepository($legacy, $crypto))->list((int) $legacyOwner['id'])) === 1, 'Versioned initialization did not adopt the FileMaker connection.');
    unset($legacy);
    echo "OpenBridge connections smoke test passed\n";
} finally {
    unset($remainingSecret, $connections, $vault, $crypto, $pdo);
    gc_collect_cycles();
    connectionRemoveTree($temporary);
}
