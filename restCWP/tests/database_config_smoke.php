<?php

declare(strict_types=1);

/** Live temporary-file SQLite integration smoke test. */
require_once dirname(__DIR__) . '/database.php';

$testDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'openbridge-sqlite-' . bin2hex(random_bytes(6));
$testPath = $testDirectory . DIRECTORY_SEPARATOR . 'openbridge.sqlite';
$config = ['database' => ['enabled' => true, 'path' => $testPath, 'autoInitialize' => true, 'seedDemoAdmin' => true]];

try {
    $settings = openBridgeDatabaseConfig($config);
    if ($settings['driver'] !== 'sqlite' || $settings['path'] !== $testPath) throw new RuntimeException('SQLite configuration did not preserve the packaged database path.');
    $pdo = openBridgeDatabasePdo($config);
    if (!is_file($testPath)) throw new RuntimeException('SQLite database file was not created.');
    if ((int) $pdo->query('SELECT COUNT(*) FROM openbridge_users')->fetchColumn() !== 3) throw new RuntimeException('Demo users were not seeded.');
    foreach (['alex', 'demo'] as $username) {
        $user = openBridgeDatabaseFindUser($pdo, $username);
        if ($user === null || !password_verify('pass', (string) $user['password_hash'])) throw new RuntimeException("Demo login {$username}/pass is invalid.");
    }
    $designAdmin = openBridgeDatabaseFindUser($pdo, 'admin');
    if ($designAdmin === null || !password_verify('1234', (string) $designAdmin['password_hash'])) throw new RuntimeException('Design Lab admin/admin is invalid.');
    $created = openBridgeDatabaseCreateUser($pdo, 'owner', 'owner@example.test', 'long-demo-password', 'Owner');
    if ((string) $created['username'] !== 'owner') throw new RuntimeException('SQLite user creation failed.');
    openBridgeDatabaseInitialize($pdo, true);
    if (openBridgeDatabaseUserCount($pdo) !== 4) throw new RuntimeException('Repeated SQLite initialization changed user data.');
    if ((int) $pdo->query('SELECT MAX(version) FROM openbridge_schema_migrations')->fetchColumn() !== OPENBRIDGE_DATABASE_SCHEMA_VERSION) throw new RuntimeException('SQLite schema version is not current.');
    $session = new OpenBridgeSqliteSessionHandler($pdo, 3600);
    if (!$session->write('test-session', 'payload') || $session->read('test-session') !== 'payload') throw new RuntimeException('SQLite session persistence failed.');
    $tables = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name LIKE 'openbridge_%'")->fetchColumn();
    if ($tables < 15) throw new RuntimeException('The complete OpenBridge SQLite schema was not created.');
    $diagnostics = openBridgeDatabaseDiagnostics($pdo, $config);
    if (($diagnostics['ready'] ?? false) !== true) throw new RuntimeException('SQLite diagnostics did not report ready.');
    if (($diagnostics['integrity'] ?? '') !== 'ok') throw new RuntimeException('SQLite integrity check failed.');
    if (($diagnostics['foreignKeyIssues'] ?? 1) !== 0) throw new RuntimeException('SQLite foreign-key diagnostics failed.');
    if (($diagnostics['writeRollback'] ?? false) !== true) throw new RuntimeException('SQLite reversible-write diagnostic failed.');
    $diagnosticRows = (int) $pdo->query("SELECT COUNT(*) FROM openbridge_metadata WHERE source = 'database_diagnostics'")->fetchColumn();
    if ($diagnosticRows !== 0) throw new RuntimeException('SQLite diagnostics retained a temporary test row.');
    $backupDirectory = $testDirectory . DIRECTORY_SEPARATOR . 'backups';
    $backupPath = openBridgeDatabaseBackup($pdo, $backupDirectory, 'openbridge-smoke-backup.sqlite');
    if (!is_file($backupPath) || filesize($backupPath) <= 0) throw new RuntimeException('SQLite backup file was not created.');
    $backupPdo = new PDO('sqlite:' . $backupPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ((int) $backupPdo->query("SELECT COUNT(*) FROM openbridge_users WHERE username = 'owner'")->fetchColumn() !== 1) throw new RuntimeException('SQLite backup did not preserve user data.');
    $backupPdo = null;
    $futureRejected = false;
    $pdo->exec('INSERT INTO openbridge_schema_migrations (version) VALUES (999)');
    try { openBridgeDatabaseInitialize($pdo, true); } catch (Throwable) { $futureRejected = true; }
    if (!$futureRejected) throw new RuntimeException('Future SQLite schema version was accepted.');
    if ((int) $pdo->query("SELECT COUNT(*) FROM openbridge_users WHERE username = 'owner'")->fetchColumn() !== 1) throw new RuntimeException('Future-version rejection damaged existing user data.');
    $pdo = null;
    echo "OpenBridge SQLite integration smoke test passed\n";
} finally {
    if (isset($backupPdo)) $backupPdo = null;
    foreach ([$testPath . '-shm', $testPath . '-wal', $testPath] as $file) if (is_file($file)) @unlink($file);
    if (isset($backupPath) && is_file($backupPath)) @unlink($backupPath);
    if (isset($backupDirectory) && is_dir($backupDirectory)) @rmdir($backupDirectory);
    if (is_dir($testDirectory)) @rmdir($testDirectory);
}
