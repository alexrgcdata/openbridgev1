<?php

declare(strict_types=1);

/** OpenBridge's single server-side SQLite persistence boundary. */
const OPENBRIDGE_DATABASE_SCHEMA_VERSION = 3;

function openBridgeDatabaseConfig(array $config = []): array
{
    $database = isset($config['database']) && is_array($config['database']) ? $config['database'] : [];
    if (isset($database['driver']) && $database['driver'] !== 'sqlite') {
        throw new InvalidArgumentException('OpenBridge system storage supports SQLite only.');
    }
    $configuredPath = trim((string) ($database['path'] ?? ''));
    if ($configuredPath === '') $configuredPath = trim((string) (getenv('OPENBRIDGE_DB_PATH') ?: ''));
    $path = $configuredPath !== '' ? $configuredPath : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'openbridge.sqlite';
    if (!preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', $path)) {
        $path = __DIR__ . DIRECTORY_SEPARATOR . $path;
    }
    return [
        'driver' => 'sqlite',
        'path' => str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path),
        'enabled' => (bool) ($database['enabled'] ?? true),
        'autoInitialize' => (bool) ($database['autoInitialize'] ?? true),
        'seedDemoAdmin' => (bool) ($database['seedDemoAdmin'] ?? false),
        'busyTimeoutMs' => max(1000, min(30000, (int) ($database['busyTimeoutMs'] ?? 5000))),
    ];
}

function openBridgeDatabasePdo(array $config = []): PDO
{
    $settings = openBridgeDatabaseConfig($config);
    if (!$settings['enabled']) throw new RuntimeException('OpenBridge SQLite storage is disabled.');
    if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('The PHP pdo_sqlite extension is required.');
    $directory = dirname($settings['path']);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('OpenBridge could not create its private database directory.');
    }
    if (!is_writable($directory)) throw new RuntimeException('The OpenBridge database directory is not writable by PHP.');
    $pdo = new PDO('sqlite:' . $settings['path'], null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA busy_timeout = ' . $settings['busyTimeoutMs']);
    openBridgeDatabaseRejectFutureSchema(openBridgeDatabaseSchemaVersion($pdo));
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    if ($settings['autoInitialize']) openBridgeDatabaseInitialize($pdo, $settings['seedDemoAdmin']);
    if (is_file($settings['path'])) @chmod($settings['path'], 0600);
    return $pdo;
}

function openBridgeDatabaseInitialize(PDO $pdo, bool $seedDemoAdmin = false): void
{
    // Normal requests inspect the current schema without taking a write lock.
    $currentVersion = openBridgeDatabaseSchemaVersion($pdo);
    openBridgeDatabaseRejectFutureSchema($currentVersion);
    if (!$seedDemoAdmin && $currentVersion === OPENBRIDGE_DATABASE_SCHEMA_VERSION
        && $pdo->query('SELECT 1 FROM openbridge_installation WHERE id = 1')->fetchColumn()) return;

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->exec('BEGIN IMMEDIATE');
    try {
        $version = openBridgeDatabaseSchemaVersion($pdo);
        openBridgeDatabaseRejectFutureSchema($version);
        if ($version < 1) {
            $schema = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'database_schema.sql');
            if (!is_string($schema) || trim($schema) === '') throw new RuntimeException('The OpenBridge SQLite schema file is missing.');
            $pdo->exec($schema);
        }
        $version = openBridgeDatabaseSchemaVersion($pdo);
        openBridgeDatabaseRejectFutureSchema($version);
        if ($version < 2) {
            openBridgeDatabaseMigrateVersion2($pdo);
        }
        if (openBridgeDatabaseSchemaVersion($pdo) < 3) {
            $migration = file_get_contents(__DIR__ . '/migrations/003-connections.sql');
            if (!is_string($migration) || $migration === '') throw new RuntimeException('The OpenBridge schema v3 migration is missing.');
            $pdo->exec($migration);
        }
        openBridgeDatabaseRejectFutureSchema(openBridgeDatabaseSchemaVersion($pdo));
        $installation = $pdo->prepare('INSERT OR IGNORE INTO openbridge_installation (id, installation_id) VALUES (1, :installation_id)');
        $installation->execute(['installation_id' => bin2hex(random_bytes(16))]);
        if ($seedDemoAdmin) {
            $statement = $pdo->prepare('INSERT OR IGNORE INTO openbridge_users (username, email, password_hash, display_name) VALUES (:username, :email, :password_hash, :display_name)');
            $exists = $pdo->prepare('SELECT 1 FROM openbridge_users WHERE username = :username LIMIT 1');
            foreach ([['alex', 'alex@openbridge.local', 'Alex Demo', 'pass'], ['demo', 'demo@openbridge.local', 'OpenBridge Demo', 'pass'], ['admin', 'admin@openbridge.local', 'OpenBridge Design Admin', '1234']] as [$username, $email, $displayName, $password]) {
                $exists->execute(['username' => $username]);
                if ($exists->fetchColumn()) continue;
                $statement->execute(['username' => $username, 'email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'display_name' => $displayName]);
            }
        }
        if ($ownsTransaction) $pdo->exec('COMMIT');
    } catch (Throwable $error) {
        if ($ownsTransaction) {
            try { $pdo->exec('ROLLBACK'); } catch (Throwable) {}
        }
        throw $error;
    }
}

function openBridgeDatabaseSchemaVersion(PDO $pdo): int
{
    $migrationTable = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'openbridge_schema_migrations'")->fetchColumn();
    return $migrationTable ? (int) $pdo->query('SELECT COALESCE(MAX(version), 0) FROM openbridge_schema_migrations')->fetchColumn() : 0;
}

function openBridgeDatabaseRejectFutureSchema(int $version): void
{
    if ($version > OPENBRIDGE_DATABASE_SCHEMA_VERSION) {
        throw new RuntimeException('This OpenBridge database was created by a newer package. Upgrade OpenBridge before opening it.');
    }
}

/** Add authenticated server-encryption columns to an existing v1 database. */
function openBridgeDatabaseMigrateVersion2(PDO $pdo): void
{
    $columns = $pdo->query('PRAGMA table_info(openbridge_transaction_payloads)')->fetchAll();
    $existing = [];
    foreach ($columns as $column) {
        if (isset($column['name'])) $existing[(string) $column['name']] = true;
    }
    foreach ([
        'ciphertext' => 'TEXT',
        'nonce' => 'TEXT',
        'algorithm' => 'TEXT',
        'key_version' => 'INTEGER',
        'aad' => 'TEXT',
    ] as $name => $type) {
        if (!isset($existing[$name])) {
            $pdo->exec('ALTER TABLE openbridge_transaction_payloads ADD COLUMN ' . $name . ' ' . $type);
        }
    }
    $pdo->exec('INSERT OR IGNORE INTO openbridge_schema_migrations (version) VALUES (2)');
}

/** Create a private SQLite backup file without exposing the live database path through an API. */
function openBridgeDatabaseBackup(PDO $pdo, string $destinationDirectory, ?string $fileName = null): string
{
    $directory = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $destinationDirectory);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('OpenBridge could not create the database backup directory.');
    }
    if (!is_writable($directory)) throw new RuntimeException('The OpenBridge database backup directory is not writable by PHP.');
    $name = $fileName !== null && trim($fileName) !== '' ? basename(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($fileName))) : 'openbridge-backup-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.sqlite';
    if (!preg_match('/^[A-Za-z0-9._-]+\.sqlite$/', $name)) throw new RuntimeException('The OpenBridge database backup file name is invalid.');
    $path = $directory . DIRECTORY_SEPARATOR . $name;
    if (is_file($path)) throw new RuntimeException('The OpenBridge database backup file already exists.');
    $pdo->exec('VACUUM INTO ' . $pdo->quote($path));
    @chmod($path, 0600);
    return $path;
}

function openBridgeDatabaseJson(mixed $value): ?string
{
    if ($value === null) return null;
    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    return is_string($encoded) ? $encoded : null;
}

function openBridgeTryDatabasePdo(array $config = []): ?PDO
{
    try { return openBridgeDatabasePdo($config); } catch (Throwable) { return null; }
}

function openBridgeSessionHash(string $sessionId): string { return hash('sha256', $sessionId); }

final class OpenBridgeSqliteSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    public function __construct(private PDO $pdo, private int $lifetimeSeconds, private ?OpenBridgeCrypto $crypto = null) {}
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function validateId(string $id): bool
    {
        $statement = $this->pdo->prepare("SELECT 1 FROM openbridge_sessions s
            WHERE s.session_hash = :hash AND s.revoked_at IS NULL AND s.expires_at > :now
            AND (s.user_id IS NULL OR EXISTS (SELECT 1 FROM openbridge_users u WHERE u.id = s.user_id AND u.is_active = 1 AND u.role = 'admin'))");
        $statement->execute(['hash' => openBridgeSessionHash($id), 'now' => time()]);
        return (bool) $statement->fetchColumn();
    }
    public function read(string $id): string
    {
        if (!$this->validateId($id)) return '';
        $statement = $this->pdo->prepare('SELECT session_data FROM openbridge_sessions WHERE session_hash = :hash');
        $statement->execute(['hash' => openBridgeSessionHash($id)]);
        $value = $statement->fetchColumn();
        if (!is_string($value)) return '';
        if ($this->crypto === null) return $value;
        // Prior plaintext sessions are deliberately invalidated, requiring a new login.
        if (!str_starts_with($value, 'aead:')) return '';
        try {
            $envelope = json_decode(substr($value, 5), true, 512, JSON_THROW_ON_ERROR);
            return is_array($envelope) ? $this->crypto->decrypt($envelope, 'openbridge-session:' . openBridgeSessionHash($id)) : '';
        } catch (Throwable) { return ''; }
    }
    public function write(string $id, string $data): bool
    {
        if ($this->crypto !== null) {
            try {
                $this->crypto->assertStorageKey($this->pdo);
                $data = 'aead:' . json_encode($this->crypto->encrypt($data, 'openbridge-session:' . openBridgeSessionHash($id)), JSON_THROW_ON_ERROR);
            } catch (Throwable) { return false; } // No plaintext fallback.
        }
        $statement = $this->pdo->prepare("INSERT INTO openbridge_sessions (session_hash, session_data, expires_at) VALUES (:hash, :data, :expires_at)
            ON CONFLICT (session_hash) DO UPDATE SET session_data = excluded.session_data, last_seen_at = CURRENT_TIMESTAMP, expires_at = excluded.expires_at
            WHERE openbridge_sessions.revoked_at IS NULL AND openbridge_sessions.expires_at > :now
            AND (openbridge_sessions.user_id IS NULL OR EXISTS (SELECT 1 FROM openbridge_users u WHERE u.id = openbridge_sessions.user_id AND u.is_active = 1 AND u.role = 'admin'))");
        $statement->execute(['hash' => openBridgeSessionHash($id), 'data' => $data, 'expires_at' => time() + $this->lifetimeSeconds, 'now' => time()]);
        return $statement->rowCount() === 1;
    }
    public function updateTimestamp(string $id, string $data): bool
    {
        if (!$this->validateId($id)) return false;
        return $this->write($id, $data);
    }
    public function destroy(string $id): bool
    {
        // A tombstone prevents an in-flight request from recreating a logged-out session.
        $statement = $this->pdo->prepare("INSERT INTO openbridge_sessions (session_hash, session_data, expires_at, revoked_at)
            VALUES (:hash, '', :expiry, CURRENT_TIMESTAMP)
            ON CONFLICT(session_hash) DO UPDATE SET session_data = '', revoked_at = CURRENT_TIMESTAMP, expires_at = :expiry");
        return $statement->execute(['hash' => openBridgeSessionHash($id), 'expiry' => time() + $this->lifetimeSeconds]);
    }
    public function gc(int $max_lifetime): int|false
    {
        $statement = $this->pdo->prepare('DELETE FROM openbridge_sessions WHERE expires_at < :now');
        $statement->execute(['now' => time() - max(300, $this->lifetimeSeconds)]);
        return $statement->rowCount();
    }
}

/** A bounded login throttle cannot be reset by dropping the browser cookie. */
function openBridgeDatabaseLoginAttempt(PDO $pdo, string $address): bool
{
    $hash = hash('sha256', 'login:' . $address);
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare("INSERT INTO openbridge_auth_attempts(identifier_hash,attempts,window_ends_at)
            VALUES (:hash,1,:expiry) ON CONFLICT(identifier_hash) DO UPDATE SET
            attempts = CASE WHEN window_ends_at <= :now THEN 1 ELSE attempts + 1 END,
            window_ends_at = CASE WHEN window_ends_at <= :now THEN :expiry ELSE window_ends_at END");
        $statement->execute(['hash' => $hash, 'expiry' => time() + 300, 'now' => time()]);
        $read = $pdo->prepare('SELECT attempts FROM openbridge_auth_attempts WHERE identifier_hash = :hash');
        $read->execute(['hash' => $hash]);
        $allowed = (int) $read->fetchColumn() <= 10;
        $pdo->exec('DELETE FROM openbridge_auth_attempts WHERE window_ends_at < ' . (time() - 86400));
        $pdo->commit();
        return $allowed;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function openBridgeDatabaseUserCount(PDO $pdo): int { return (int) $pdo->query('SELECT COUNT(*) FROM openbridge_users WHERE is_active = 1')->fetchColumn(); }
function openBridgeDatabaseHasConfiguredAdministrator(PDO $pdo): bool
{
    return (int) $pdo->query('SELECT COUNT(*) FROM openbridge_users WHERE is_active = 1')->fetchColumn() > 0;
}
function openBridgeDatabaseFindUser(PDO $pdo, string $username): ?array
{
    $statement = $pdo->prepare('SELECT id, username, email, password_hash, display_name, role, is_active FROM openbridge_users WHERE lower(username) = lower(:username) LIMIT 1');
    $statement->execute(['username' => $username]);
    $user = $statement->fetch();
    return is_array($user) ? $user : null;
}
function openBridgeDatabaseCreateUser(PDO $pdo, string $username, string $email, string $password, string $displayName = ''): array
{
    $statement = $pdo->prepare('INSERT INTO openbridge_users (username, email, password_hash, display_name, role) VALUES (:username, :email, :password_hash, :display_name, :role)');
    $statement->execute(['username' => $username, 'email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'display_name' => $displayName !== '' ? $displayName : $username, 'role' => 'admin']);
    $user = openBridgeDatabaseFindUser($pdo, $username);
    if ($user === null) throw new RuntimeException('OpenBridge could not create the administrator account.');
    return $user;
}
function openBridgeDatabaseTouchLogin(PDO $pdo, int $userId): void
{
    $statement = $pdo->prepare('UPDATE openbridge_users SET last_login_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
    $statement->execute(['id' => $userId]);
}
function openBridgeDatabaseAttachSession(PDO $pdo, string $sessionId, int $userId, ?string $ipAddress, ?string $userAgent): void
{
    $statement = $pdo->prepare('UPDATE openbridge_sessions SET user_id = :user_id, ip_address = :ip_address, user_agent = :user_agent, last_seen_at = CURRENT_TIMESTAMP WHERE session_hash = :hash');
    $statement->execute(['user_id' => $userId, 'ip_address' => $ipAddress !== '' ? $ipAddress : null, 'user_agent' => $userAgent !== '' ? substr($userAgent, 0, 1000) : null, 'hash' => openBridgeSessionHash($sessionId)]);
}
function openBridgeDatabaseLogError(PDO $pdo, string $source, string $message, array $context = [], ?int $userId = null, ?string $transactionId = null): void
{
    $statement = $pdo->prepare('INSERT INTO openbridge_error_logs (user_id, transaction_id, source, message, context) VALUES (:user_id, :transaction_id, :source, :message, :context)');
    $statement->execute(['user_id' => $userId, 'transaction_id' => $transactionId, 'source' => substr($source, 0, 120), 'message' => substr($message, 0, 4000), 'context' => openBridgeDatabaseJson($context) ?? '{}']);
}

/** Run bounded diagnostics without exposing the database path or retaining test rows. */
function openBridgeDatabaseDiagnostics(PDO $pdo, array $config = []): array
{
    $settings = openBridgeDatabaseConfig($config);
    $quickCheck = (string) $pdo->query('PRAGMA quick_check')->fetchColumn();
    $foreignKeyIssues = count($pdo->query('PRAGMA foreign_key_check')->fetchAll());
    $journalMode = (string) $pdo->query('PRAGMA journal_mode')->fetchColumn();
    $tableCount = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name LIKE 'openbridge_%'")->fetchColumn();
    $schemaVersion = (int) $pdo->query('SELECT COALESCE(MAX(version), 0) FROM openbridge_schema_migrations')->fetchColumn();
    $counts = $pdo->query(
        'SELECT
            (SELECT COUNT(*) FROM openbridge_users) AS users,
            (SELECT COUNT(*) FROM openbridge_sessions) AS sessions,
            (SELECT COUNT(*) FROM openbridge_transactions) AS transactions,
            (SELECT COUNT(*) FROM openbridge_metric_events) AS metric_events,
            (SELECT COUNT(*) FROM openbridge_error_logs) AS errors,
            (SELECT COUNT(*) FROM openbridge_workspaces) AS workspaces,
            (SELECT COUNT(*) FROM openbridge_metadata) AS metadata'
    )->fetch();
    $writeRollback = false;
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare('INSERT INTO openbridge_metadata (user_id, meta_key, meta_value, source) VALUES (NULL, :key, :value, :source)');
        $statement->execute([
            'key' => 'diagnostic_' . bin2hex(random_bytes(8)),
            'value' => '{"temporary":true}',
            'source' => 'database_diagnostics',
        ]);
        $writeRollback = $statement->rowCount() === 1;
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
    return [
        'ready' => $quickCheck === 'ok' && $foreignKeyIssues === 0 && $tableCount >= 18
            && $schemaVersion === OPENBRIDGE_DATABASE_SCHEMA_VERSION
            && (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN ('openbridge_connections','openbridge_transaction_details','openbridge_auth_attempts')")->fetchColumn() === 3
            && $writeRollback,
        'integrity' => $quickCheck,
        'foreignKeyIssues' => $foreignKeyIssues,
        'journalMode' => $journalMode,
        'tableCount' => $tableCount,
        'schemaVersion' => $schemaVersion,
        'writeRollback' => $writeRollback,
        'directoryWritable' => is_writable(dirname($settings['path'])),
        'databaseBytes' => is_file($settings['path']) ? (int) filesize($settings['path']) : 0,
        'counts' => is_array($counts) ? array_map('intval', $counts) : [],
    ];
}
