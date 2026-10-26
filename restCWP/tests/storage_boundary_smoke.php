<?php
declare(strict_types=1);

// Isolated SQLite tests; no installed database, credentials or FileMaker server.
require_once dirname(__DIR__, 2) . '/openbridge/openbridge.php';
require_once dirname(__DIR__) . '/database.php';
require_once dirname(__DIR__) . '/security.php';

function boundaryAssert(bool $value, string $message): void
{
    if (!$value) throw new RuntimeException($message);
}
function boundaryReject(callable $call, string $message): void
{
    try { $call(); } catch (RuntimeException | InvalidArgumentException) { return; }
    throw new RuntimeException($message);
}

$directory = sys_get_temp_dir() . '/openbridge-boundary-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$path = $directory . '/system.sqlite';
$oldEnvironment = getenv('OPENBRIDGE_DB_PATH');
$pdo = null;
$repository = null;
try {
    boundaryReject(fn () => openBridgeDatabaseConfig(['database' => ['driver' => 'pgsql']]), 'Unsupported database driver was silently accepted.');
    putenv('OPENBRIDGE_DB_PATH=' . $path);
    boundaryAssert(openBridgeDatabaseConfig(['database' => ['path' => '']])['path'] === str_replace('/', DIRECTORY_SEPARATOR, $path), 'Empty path did not use the environment override.');
    $config = [
        'database' => ['driver' => 'sqlite', 'path' => $path, 'seedDemoAdmin' => false],
        'security' => ['masterKey' => base64_encode(random_bytes(32))],
        'filemaker' => ['useVaultDefaults' => false],
    ];
    $defaults = new ReflectionMethod(OpenBridge::class, 'trustedVaultFileMaker');
    $defaults->setAccessible(true);
    boundaryAssert($defaults->invoke(null, $config, dirname(__DIR__)) === null, 'Explicit standalone mode did not skip the vault.');
    boundaryAssert(!is_file($path), 'Standalone mode created a SQLite database.');
    $config['filemaker']['useVaultDefaults'] = true;
    boundaryAssert($defaults->invoke(null, $config, dirname(__DIR__)) === null && !is_file($path), 'A library call initialized the dashboard database.');

    $pdo = openBridgeDatabasePdo($config);
    boundaryAssert(openBridgeDatabaseUserCount($pdo) === 0, 'Default database created demonstration users.');
    boundaryAssert((int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() === 1, 'Foreign keys are disabled.');
    boundaryAssert((int) $pdo->query('PRAGMA busy_timeout')->fetchColumn() === 5000, 'Busy timeout missing.');
    $before = (int) $pdo->query('SELECT total_changes()')->fetchColumn();
    openBridgeDatabaseInitialize($pdo);
    boundaryAssert((int) $pdo->query('SELECT total_changes()')->fetchColumn() === $before, 'Current schema initialization wrote application data.');
    $first = openBridgeDatabaseCreateUser($pdo, 'owner-one', 'one@example.invalid', 'synthetic-long-password');
    $repository = new OpenBridgeRepository($pdo, new OpenBridgeCrypto(new OpenBridgeKeyProvider($config, dirname(__DIR__))));
    $profile = ['host' => 'https://example.invalid', 'database' => 'Test', 'table' => 'First Name', 'username' => 'test', 'password' => 'synthetic-only'];
    $repository->saveSecret((int) $first['id'], 'filemaker_connection', $profile);
    boundaryAssert($defaults->invoke(null, $config, dirname(__DIR__)) === $profile, 'Saved defaults changed names or values.');
    $second = openBridgeDatabaseCreateUser($pdo, 'owner-two', 'two@example.invalid', 'synthetic-long-password');
    $repository->saveSecret((int) $second['id'], 'filemaker_connection', $profile + ['marker' => 'second']);
    boundaryReject(fn () => $defaults->invoke(null, $config, dirname(__DIR__)), 'Ambiguous defaults silently selected an account.');
    $config['filemaker']['vaultUserId'] = (int) $first['id'];
    boundaryAssert($defaults->invoke(null, $config, dirname(__DIR__)) === $profile, 'Explicit vault owner was not honored.');
    $config['filemaker']['vaultUserId'] = 999999;
    boundaryReject(fn () => $defaults->invoke(null, $config, dirname(__DIR__)), 'Unavailable owner silently fell back to another account.');
    $config['filemaker']['vaultUserId'] = (int) $first['id'];
    $config['security']['masterKey'] = base64_encode(random_bytes(32));
    boundaryReject(fn () => $defaults->invoke(null, $config, dirname(__DIR__)), 'Decryption error was silently swallowed.');
    echo "OpenBridge storage boundary smoke test passed\n";
} finally {
    $repository = null;
    $pdo = null;
    $oldEnvironment === false ? putenv('OPENBRIDGE_DB_PATH') : putenv('OPENBRIDGE_DB_PATH=' . $oldEnvironment);
    foreach ([$path . '-wal', $path . '-shm', $path] as $file) if (is_file($file)) unlink($file);
    rmdir($directory);
}
