<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/database.php';
require_once dirname(__DIR__) . '/security.php';

function securityAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function securityRemoveTree(string $path): void
{
    if (!is_dir($path)) return;
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        $target = $path . DIRECTORY_SEPARATOR . $item;
        is_dir($target) ? securityRemoveTree($target) : unlink($target);
    }
    rmdir($path);
}

function securityParallelKeyCreateHashes(string $keyPath): array
{
    if (!function_exists('proc_open')) throw new RuntimeException('proc_open is required for the parallel key creation smoke test.');
    $securityPath = dirname(__DIR__) . '/security.php';
    $baseDirectory = dirname(__DIR__);
    $config = ['security' => ['masterKeyFile' => $keyPath, 'keyVersion' => 1]];
    $code = 'require ' . var_export($securityPath, true) . '; $config = ' . var_export($config, true) . '; echo hash("sha256", (new OpenBridgeKeyProvider($config, ' . var_export($baseDirectory, true) . '))->encryptionKey()) . PHP_EOL;';
    $command = [PHP_BINARY, '-r', $code];
    $processes = [];
    for ($index = 0; $index < 8; $index++) {
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not start a parallel key creation worker.');
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }
    $hashes = [];
    foreach ($processes as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) throw new RuntimeException('A parallel key creation worker failed: ' . trim((string) $stderr));
        $hashes[] = trim((string) $stdout);
    }
    return array_values(array_unique($hashes));
}

$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'openbridge-security-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700, true);

try {
    $config = [
        'database' => ['path' => $temporary . '/openbridge.sqlite', 'autoInitialize' => true],
        'security' => ['masterKeyFile' => $temporary . '/private/openbridge.key.php', 'keyVersion' => 1],
    ];
    $parallelHashes = securityParallelKeyCreateHashes($temporary . '/private/openbridge.key.php');
    securityAssert(count($parallelHashes) === 1 && $parallelHashes[0] !== '', 'Parallel key creation produced inconsistent installation keys.');

    $environmentKey = base64_encode(random_bytes(32));
    putenv('OPENBRIDGE_MASTER_KEY=' . $environmentKey);
    try {
        $environmentConfig = ['security' => ['masterKey' => '', 'masterKeyFile' => $temporary . '/private/unused-env-fallback.key.php', 'keyVersion' => 1]];
        $environmentKeys = new OpenBridgeKeyProvider($environmentConfig, dirname(__DIR__));
        securityAssert(hash_equals(hash('sha256', base64_decode($environmentKey, true)), hash('sha256', $environmentKeys->encryptionKey())), 'Empty configured masterKey did not fall back to OPENBRIDGE_MASTER_KEY.');
        securityAssert(($environmentKeys->status()['keySource'] ?? '') === 'server-environment', 'Environment key status was not reported.');
        securityAssert(!is_file($temporary . '/private/unused-env-fallback.key.php'), 'Environment key fallback created a file key.');
    } finally {
        putenv('OPENBRIDGE_MASTER_KEY');
    }

    $pdo = openBridgeDatabasePdo($config);
    securityAssert((int) $pdo->query('SELECT MAX(version) FROM openbridge_schema_migrations')->fetchColumn() === OPENBRIDGE_DATABASE_SCHEMA_VERSION, 'Current schema migration was not applied.');

    $user = openBridgeDatabaseCreateUser($pdo, 'security_test', 'security@example.invalid', 'correct-horse-battery-staple');
    $keys = new OpenBridgeKeyProvider($config, dirname(__DIR__));
    $crypto = new OpenBridgeCrypto($keys);
    $repository = new OpenBridgeRepository($pdo, $crypto);

    $profile = [
        'host' => 'https://filemaker.example.invalid', 'database' => 'Demo', 'layout' => 'Contacts',
        'table' => 'Contacts', 'username' => 'fm_user', 'password' => 'never-plaintext-password', 'transport' => 'data_api',
    ];
    $repository->saveSecret((int) $user['id'], 'filemaker_connection', $profile);
    $secretRow = $pdo->query("SELECT ciphertext, nonce, algorithm FROM openbridge_secrets WHERE secret_key = 'filemaker_connection'")->fetch();
    securityAssert(is_array($secretRow) && !str_contains(implode('|', $secretRow), $profile['password']), 'Credential plaintext leaked into SQLite.');
    securityAssert($repository->loadSecret((int) $user['id'], 'filemaker_connection') === $profile, 'Credential vault round-trip changed data.');

    unset($repository, $crypto, $keys);
    $pdo = null;
    $pdo = openBridgeDatabasePdo($config);
    $keys = new OpenBridgeKeyProvider($config, dirname(__DIR__));
    $crypto = new OpenBridgeCrypto($keys);
    $repository = new OpenBridgeRepository($pdo, $crypto);
    securityAssert($repository->loadSecret((int) $user['id'], 'filemaker_connection') === $profile, 'Credential vault did not survive a simulated PHP restart.');

    $originalCiphertext = (string) $secretRow['ciphertext'];
    $pdo->exec("UPDATE openbridge_secrets SET ciphertext = '" . substr($originalCiphertext, 0, -2) . "AA' WHERE secret_key = 'filemaker_connection'");
    $tamperRejected = false;
    try { $repository->loadSecret((int) $user['id'], 'filemaker_connection'); } catch (Throwable) { $tamperRejected = true; }
    securityAssert($tamperRejected, 'Tampered ciphertext was accepted.');
    $restore = $pdo->prepare("UPDATE openbridge_secrets SET ciphertext = :ciphertext WHERE secret_key = 'filemaker_connection'");
    $restore->execute(['ciphertext' => $originalCiphertext]);

    $pdo->exec("UPDATE openbridge_secrets SET key_version = 2 WHERE secret_key = 'filemaker_connection'");
    $versionRejected = false;
    try { $repository->loadSecret((int) $user['id'], 'filemaker_connection'); } catch (Throwable) { $versionRejected = true; }
    securityAssert($versionRejected, 'Unsupported key version was accepted.');
    $pdo->exec("UPDATE openbridge_secrets SET key_version = 1 WHERE secret_key = 'filemaker_connection'");

    $keyPath = $temporary . '/private/openbridge.key.php';
    $savedKeyFile = file_get_contents($keyPath);
    securityAssert(is_string($savedKeyFile) && $savedKeyFile !== '', 'Installation key file was not created.');
    unlink($keyPath);
    $missingKeyRejected = false;
    try { $repository->loadSecret((int) $user['id'], 'filemaker_connection'); } catch (Throwable) { $missingKeyRejected = true; }
    securityAssert($missingKeyRejected, 'Missing installation key was not rejected during decrypt.');
    securityAssert(!is_file($keyPath), 'Decrypt silently created a replacement installation key.');
    file_put_contents($keyPath, $savedKeyFile, LOCK_EX);
    @chmod($keyPath, 0600);
    securityAssert($repository->loadSecret((int) $user['id'], 'filemaker_connection') === $profile, 'Restored installation key did not decrypt saved data.');

    $unsupportedConfig = $config;
    $unsupportedConfig['security']['keyVersion'] = 2;
    $unsupportedRejected = false;
    try { (new OpenBridgeKeyProvider($unsupportedConfig, dirname(__DIR__)))->keyVersion(); } catch (Throwable) { $unsupportedRejected = true; }
    securityAssert($unsupportedRejected, 'Unsupported configured key version was accepted.');

    $transactionId = '12345678-1234-4234-8234-123456789abc';
    $insert = $pdo->prepare("INSERT INTO openbridge_transactions (id, user_id, status, transport, operation) VALUES (:id, :user, 'success', 'data-api', 'find')");
    $insert->execute(['id' => $transactionId, 'user' => $user['id']]);
    $repository->saveTransactionPayload(
        $transactionId,
        (int) $user['id'],
        json_encode(['query' => 'active', 'password' => 'request-secret'], JSON_THROW_ON_ERROR),
        json_encode(['data' => ['name' => 'Alex'], 'token' => 'response-secret'], JSON_THROW_ON_ERROR),
    );
    $payloadRow = $pdo->query("SELECT raw_request, raw_response, request_json, response_json, ciphertext FROM openbridge_transaction_payloads WHERE transaction_id = '$transactionId'")->fetch();
    securityAssert(is_array($payloadRow) && $payloadRow['raw_request'] === null && $payloadRow['raw_response'] === null, 'Raw payload columns contain plaintext.');
    securityAssert(!str_contains((string) $payloadRow['ciphertext'], 'request-secret') && !str_contains((string) $payloadRow['ciphertext'], 'response-secret'), 'Payload secret leaked into ciphertext storage.');
    $payload = $repository->loadTransactionPayload($transactionId, (int) $user['id']);
    securityAssert(($payload['request'] ?? '') === '{"query":"active","password":"[redacted]"}', 'Request redaction failed.');
    securityAssert(($payload['response'] ?? '') === '{"data":{"name":"Alex"},"token":"[redacted]"}', 'Response redaction failed.');

    $secondTransactionId = '22345678-1234-4234-8234-123456789abc';
    $insert->execute(['id' => $secondTransactionId, 'user' => $user['id']]);
    $repository->saveTransactionPayload($secondTransactionId, (int) $user['id'], '{"query":"second"}', '{"data":[]}');
    $firstEnvelope = $pdo->query("SELECT ciphertext, nonce, algorithm, key_version, aad FROM openbridge_transaction_payloads WHERE transaction_id = '$transactionId'")->fetch();
    securityAssert(is_array($firstEnvelope), 'First encrypted payload envelope was not stored.');
    $swap = $pdo->prepare('UPDATE openbridge_transaction_payloads SET ciphertext = :ciphertext, nonce = :nonce, algorithm = :algorithm, key_version = :key_version, aad = :aad WHERE transaction_id = :id');
    $swap->execute([
        'ciphertext' => $firstEnvelope['ciphertext'],
        'nonce' => $firstEnvelope['nonce'],
        'algorithm' => $firstEnvelope['algorithm'],
        'key_version' => $firstEnvelope['key_version'],
        'aad' => $firstEnvelope['aad'],
        'id' => $secondTransactionId,
    ]);
    $swappedContextRejected = false;
    try { $repository->loadTransactionPayload($secondTransactionId, (int) $user['id']); } catch (Throwable) { $swappedContextRejected = true; }
    securityAssert($swappedContextRejected, 'Swapped transaction payload context was accepted.');

    $_SESSION = [];
    $csrf = OpenBridgeCsrf::token();
    securityAssert(OpenBridgeCsrf::validate($csrf), 'Valid CSRF token was rejected.');
    securityAssert(!OpenBridgeCsrf::validate($csrf . 'x'), 'Invalid CSRF token was accepted.');

    echo 'OpenBridge security storage smoke test passed (' . $secretRow['algorithm'] . ").\n";
} finally {
    putenv('OPENBRIDGE_MASTER_KEY');
    unset($swap, $restore, $insert, $payloadRow, $firstEnvelope, $secretRow, $repository, $crypto, $keys, $user);
    $pdo = null;
    gc_collect_cycles();
    securityRemoveTree($temporary);
}
