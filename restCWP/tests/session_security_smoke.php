<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/database.php';
require_once dirname(__DIR__) . '/security.php';

function assertSessionSecurity(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'openbridge_session_security_' . bin2hex(random_bytes(6)) . '.sqlite';
$pdo = openBridgeDatabasePdo([
    'database' => [
        'enabled' => true,
        'path' => $databasePath,
        'autoInitialize' => true,
        'seedDemoAdmin' => false,
    ],
]);
$handler = new OpenBridgeSqliteSessionHandler($pdo, 3600);

try {
    $sessionId = 'fixed-session-id-for-security-smoke';
    $sessionHash = openBridgeSessionHash($sessionId);
    $user = openBridgeDatabaseCreateUser($pdo, 'session-admin', 'session-admin@example.test', 'long-enough-password');

    $handler->write($sessionId, 'openbridge_authenticated|b:1;openbridge_user_id|i:1;');
    $rows = $pdo->query('SELECT session_hash FROM openbridge_sessions')->fetchAll(PDO::FETCH_COLUMN);
    assertSessionSecurity($rows === [$sessionHash], 'Session IDs must be stored only as SHA-256 hashes.');
    assertSessionSecurity(!in_array($sessionId, $rows, true), 'Raw session ID was stored in SQLite.');

    $pdo->prepare('UPDATE openbridge_sessions SET revoked_at = CURRENT_TIMESTAMP WHERE session_hash = :hash')
        ->execute(['hash' => $sessionHash]);
    assertSessionSecurity($handler->read($sessionId) === '', 'Revoked sessions must not be readable.');

    $handler->write($sessionId, 'openbridge_authenticated|b:1;openbridge_user_id|i:1;');
    $revokedAt = $pdo->query('SELECT revoked_at FROM openbridge_sessions WHERE session_hash = ' . $pdo->quote($sessionHash))->fetchColumn();
    assertSessionSecurity($revokedAt !== null && $revokedAt !== false && $revokedAt !== '', 'Writing a revoked session must not resurrect it.');

    $crypto = new OpenBridgeCrypto(new OpenBridgeKeyProvider([
        'security' => [
            'masterKey' => base64_encode(random_bytes(32)),
            'keyVersion' => 1,
        ],
    ], dirname(__DIR__)));
    assertSessionSecurity($crypto->decrypt($crypto->encrypt('', 'empty-session'), 'empty-session') === '', 'Empty anonymous session must round-trip through authenticated encryption.');
    $encryptedHandler = new OpenBridgeSqliteSessionHandler($pdo, 3600, $crypto);
    $encryptedSessionId = 'encrypted-session-id-for-security-smoke';
    $encryptedSessionHash = openBridgeSessionHash($encryptedSessionId);
    $serialized = 'openbridge_authenticated|b:1;openbridge_user_id|i:' . (int) $user['id'] . ';';

    assertSessionSecurity($encryptedHandler->write($encryptedSessionId, $serialized), 'Encrypted session write failed.');
    openBridgeDatabaseAttachSession($pdo, $encryptedSessionId, (int) $user['id'], '127.0.0.1', 'session-security-smoke');
    $stored = $pdo->query('SELECT session_data FROM openbridge_sessions WHERE session_hash = ' . $pdo->quote($encryptedSessionHash))->fetchColumn();
    assertSessionSecurity(is_string($stored) && str_starts_with($stored, 'aead:'), 'Encrypted handler must store an AEAD envelope.');
    assertSessionSecurity(!str_contains((string) $stored, 'openbridge_authenticated'), 'Encrypted handler stored plaintext session data.');
    assertSessionSecurity($encryptedHandler->read($encryptedSessionId) === $serialized, 'Encrypted session did not decrypt for its original session ID.');

    $copiedSessionId = 'copied-envelope-session-id';
    $pdo->prepare('INSERT INTO openbridge_sessions (session_hash, user_id, session_data, expires_at) VALUES (:hash, :user_id, :data, :expires_at)')
        ->execute([
            'hash' => openBridgeSessionHash($copiedSessionId),
            'user_id' => (int) $user['id'],
            'data' => $stored,
            'expires_at' => time() + 3600,
        ]);
    assertSessionSecurity($encryptedHandler->read($copiedSessionId) === '', 'Copied encrypted session envelope must not decrypt under a different session ID.');

    $legacySessionId = 'legacy-plaintext-session-id';
    $pdo->prepare('INSERT INTO openbridge_sessions (session_hash, user_id, session_data, expires_at) VALUES (:hash, :user_id, :data, :expires_at)')
        ->execute([
            'hash' => openBridgeSessionHash($legacySessionId),
            'user_id' => (int) $user['id'],
            'data' => $serialized,
            'expires_at' => time() + 3600,
        ]);
    assertSessionSecurity($encryptedHandler->read($legacySessionId) === '', 'Crypto-enabled handler must reject legacy plaintext session data.');

    $pdo->prepare('UPDATE openbridge_users SET is_active = 0 WHERE id = :id')->execute(['id' => (int) $user['id']]);
    assertSessionSecurity(!$encryptedHandler->validateId($encryptedSessionId), 'Encrypted handler must reject sessions for inactive admins.');
} finally {
    @unlink($databasePath);
}

echo "OpenBridge session security smoke test passed\n";
