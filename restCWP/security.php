<?php

declare(strict_types=1);

/** Installation-key loader for OpenBridge server-side authenticated encryption. */
final class OpenBridgeKeyProvider
{
    private const KEY_BYTES = 32;
    private const SUPPORTED_KEY_VERSION = 1;

    public function __construct(private array $config, private string $baseDirectory) {}

    public function keyVersion(): int
    {
        $version = (int) (($this->config['security']['keyVersion'] ?? 1));
        if ($version < 1 || $version > self::SUPPORTED_KEY_VERSION) {
            throw new RuntimeException('The configured OpenBridge key version is not supported by this package.');
        }
        return $version;
    }

    public function key(): string
    {
        return $this->encryptionKey();
    }

    public function encryptionKey(): string
    {
        return $this->loadKey(true);
    }

    public function decryptionKey(): string
    {
        return $this->loadKey(false);
    }

    public function validateEnvelopeKeyVersion(mixed $version): int
    {
        if (is_string($version) && preg_match('/^\d+$/', $version)) $version = (int) $version;
        if (!is_int($version) || $version < 1 || $version > $this->keyVersion()) {
            throw new RuntimeException('The encrypted OpenBridge value uses an unsupported key version.');
        }
        return $version;
    }

    private function loadKey(bool $createIfMissing): string
    {
        if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt') && !function_exists('openssl_encrypt')) {
            throw new RuntimeException('PHP sodium or OpenSSL is required for secure OpenBridge storage.');
        }
        $configured = $this->configuredMasterKey();
        if ($configured !== '') return $this->decodeKey($configured);

        $path = $this->keyPath();
        if (!$createIfMissing && !is_file($path)) throw new RuntimeException('The OpenBridge installation key is missing. Restore the protected key file or configured environment key to decrypt stored values.');
        if ($createIfMissing) {
            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException('OpenBridge could not create its private key directory.');
            }
            if (!is_writable($directory)) throw new RuntimeException('The OpenBridge private key directory is not writable by PHP.');
        }
        $handle = @fopen($path, $createIfMissing ? 'c+b' : 'rb');
        if ($handle === false) throw new RuntimeException($createIfMissing ? 'OpenBridge could not create its installation key.' : 'OpenBridge could not read its installation key.');
        try {
            if (!flock($handle, $createIfMissing ? LOCK_EX : LOCK_SH)) throw new RuntimeException('OpenBridge could not lock its installation key.');
            $contents = stream_get_contents($handle);
            if (!is_string($contents)) throw new RuntimeException('OpenBridge could not read its installation key.');
            if (trim($contents) !== '') return $this->decodeKeyFromFile($contents);
            if (!$createIfMissing) throw new RuntimeException('The OpenBridge installation key is missing. Restore the protected key file or configured environment key to decrypt stored values.');
            $encoded = base64_encode(random_bytes(self::KEY_BYTES));
            $contents = "<?php /* OpenBridge private installation key. */ exit; ?>\n" . $encoded . "\n";
            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $contents) !== strlen($contents)) {
                throw new RuntimeException('OpenBridge could not write its installation key.');
            }
            fflush($handle);
            @chmod($path, 0600);
            return $this->decodeKey($encoded);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function status(): array
    {
        $environment = $this->configuredMasterKey() !== '';
        return [
            'sodiumReady' => function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt'),
            'opensslReady' => function_exists('openssl_encrypt'),
            'cryptoReady' => function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt') || function_exists('openssl_encrypt'),
            'preferredAlgorithm' => function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')
                ? OpenBridgeCrypto::ALGORITHM_XCHACHA
                : OpenBridgeCrypto::ALGORITHM_AES_GCM,
            'keyConfigured' => $environment || is_file($this->keyPath()),
            'keySource' => $environment ? 'server-environment' : 'protected-server-file',
            'keyVersion' => $this->keyVersion(),
        ];
    }

    private function keyPath(): string
    {
        $configured = trim((string) ($this->config['security']['masterKeyFile'] ?? ''));
        $path = $configured !== '' ? $configured : $this->baseDirectory . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'openbridge.key.php';
        if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|[\\\\/])~', $path)) {
            $path = $this->baseDirectory . DIRECTORY_SEPARATOR . $path;
        }
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private function configuredMasterKey(): string
    {
        $configured = trim((string) ($this->config['security']['masterKey'] ?? ''));
        if ($configured !== '') return $configured;
        $environment = getenv('OPENBRIDGE_MASTER_KEY');
        return is_string($environment) ? trim($environment) : '';
    }

    private function decodeKeyFromFile(string $contents): string
    {
        $lines = preg_split('/\R/', trim($contents)) ?: [];
        return $this->decodeKey((string) end($lines));
    }

    private function decodeKey(string $encoded): string
    {
        $decoded = base64_decode(trim($encoded), true);
        if (!is_string($decoded) || strlen($decoded) !== self::KEY_BYTES) {
            throw new RuntimeException('The OpenBridge installation key must be exactly 32 random bytes encoded as Base64.');
        }
        return $decoded;
    }
}

/** Authenticated encryption and conservative credential/payload redaction. */
final class OpenBridgeCrypto
{
    public const ALGORITHM_XCHACHA = 'xchacha20-poly1305-ietf';
    public const ALGORITHM_AES_GCM = 'aes-256-gcm';

    public function __construct(private OpenBridgeKeyProvider $keys) {}

    /** Never silently replace a lost installation key when encrypted data exists. */
    public function assertStorageKey(PDO $pdo): void
    {
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
        $queries = [
            'openbridge_secrets' => "SELECT *, 'openbridge-secret:user:' || user_id || ':' || secret_key AS aad FROM openbridge_secrets LIMIT 1",
            'openbridge_transaction_details' => "SELECT *, 'openbridge-transaction-details:' || transaction_id || ':user:' || user_id AS aad FROM openbridge_transaction_details LIMIT 1",
            'openbridge_transaction_payloads' => "SELECT p.*, 'openbridge-transaction:' || p.transaction_id || ':user:' || COALESCE(t.user_id,0) AS aad FROM openbridge_transaction_payloads p JOIN openbridge_transactions t ON t.id = p.transaction_id WHERE p.ciphertext IS NOT NULL LIMIT 1",
        ];
        foreach ($queries as $table => $sql) {
            if (!in_array($table, $tables, true)) continue;
            $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $this->decrypt($row, (string) $row['aad']);
                return;
            }
        }
        if (in_array('openbridge_sessions', $tables, true)) {
            $row = $pdo->query("SELECT session_hash, session_data FROM openbridge_sessions WHERE session_data LIKE 'aead:%' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $envelope = json_decode(substr($row['session_data'], 5), true, 512, JSON_THROW_ON_ERROR);
                $this->decrypt($envelope, 'openbridge-session:' . $row['session_hash']);
            }
        }
    }

    public function encrypt(string $plaintext, string $aad): array
    {
        if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            $algorithm = self::ALGORITHM_XCHACHA;
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $aad, $nonce, $this->keys->encryptionKey());
        } else {
            $algorithm = self::ALGORITHM_AES_GCM;
            $nonce = random_bytes(12);
            $tag = '';
            $encrypted = openssl_encrypt($plaintext, 'aes-256-gcm', $this->keys->encryptionKey(), OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16);
            if (!is_string($encrypted) || strlen($tag) !== 16) throw new RuntimeException('OpenBridge AES-GCM encryption failed.');
            $ciphertext = $encrypted . $tag;
        }
        return [
            'ciphertext' => base64_encode($ciphertext),
            'nonce' => base64_encode($nonce),
            'algorithm' => $algorithm,
            'keyVersion' => $this->keys->keyVersion(),
            'aad' => $aad,
        ];
    }

    public function decrypt(array $envelope, string $expectedAad): string
    {
        $algorithm = (string) ($envelope['algorithm'] ?? '');
        if (!in_array($algorithm, [self::ALGORITHM_XCHACHA, self::ALGORITHM_AES_GCM], true) || !hash_equals($expectedAad, (string) ($envelope['aad'] ?? ''))) {
            throw new RuntimeException('The encrypted OpenBridge envelope is invalid.');
        }
        $this->keys->validateEnvelopeKeyVersion($envelope['key_version'] ?? $envelope['keyVersion'] ?? null);
        $nonce = base64_decode((string) ($envelope['nonce'] ?? ''), true);
        $ciphertext = base64_decode((string) ($envelope['ciphertext'] ?? ''), true);
        $expectedNonceBytes = $algorithm === self::ALGORITHM_XCHACHA ? 24 : 12;
        if (!is_string($nonce) || strlen($nonce) !== $expectedNonceBytes || !is_string($ciphertext)) {
            throw new RuntimeException('The encrypted OpenBridge payload is malformed.');
        }
        if ($algorithm === self::ALGORITHM_XCHACHA) {
            if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) throw new RuntimeException('PHP sodium is required to decrypt this OpenBridge value.');
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, $expectedAad, $nonce, $this->keys->decryptionKey());
        } else {
            if (!function_exists('openssl_decrypt') || strlen($ciphertext) < 16) throw new RuntimeException('PHP OpenSSL is required to decrypt this OpenBridge value.');
            $tag = substr($ciphertext, -16);
            $encrypted = substr($ciphertext, 0, -16);
            $plaintext = openssl_decrypt($encrypted, 'aes-256-gcm', $this->keys->decryptionKey(), OPENSSL_RAW_DATA, $nonce, $tag, $expectedAad);
        }
        if (!is_string($plaintext)) throw new RuntimeException('OpenBridge could not authenticate or decrypt this value.');
        return $plaintext;
    }

    public function redactPayload(?string $value): ?string
    {
        if ($value === null || $value === '') return $value;
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return json_encode($this->redactValue($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        return preg_replace('/\b(password|passphrase|token|authorization|cookie|secret|api[-_]?key)\b\s*[:=]\s*[^\s,;]+/i', '$1=[redacted]', $value) ?? '[redacted]';
    }

    private function redactValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match('/password|passphrase|token|authorization|cookie|secret|api[-_]?key/i', $key)) return '[redacted]';
        if (!is_array($value)) return $value;
        $result = [];
        foreach ($value as $childKey => $childValue) $result[$childKey] = $this->redactValue($childValue, (string) $childKey);
        return $result;
    }
}

/** Central repository for encrypted credentials and retained transaction bodies. */
final class OpenBridgeRepository
{
    public function __construct(private PDO $pdo, private OpenBridgeCrypto $crypto) {}

    /**
     * Resolve the trusted server-side default, independently of browser sessions.
     * Selection and SQL stay in the repository, not in the public FileMaker include.
     * An explicit invalid owner or ambiguous defaults must never switch accounts.
     */
    public function loadDefaultFileMaker(array $fileMaker = []): ?array
    {
        $userId = null;
        $explicit = array_key_exists('vaultUserId', $fileMaker);
        if ($explicit) {
            $userId = filter_var($fileMaker['vaultUserId'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($userId === false) throw new RuntimeException('The configured FileMaker vault owner is invalid.');
        } else {
            foreach (['primary_filemaker_connection_user_id', 'primary_filemaker_owner_user_id'] as $key) {
                $statement = $this->pdo->prepare('SELECT meta_value FROM openbridge_metadata WHERE user_id IS NULL AND meta_key = :key');
                $statement->execute(['key' => $key]);
                $values = $statement->fetchAll(PDO::FETCH_COLUMN);
                if (!$values) continue;
                if (count($values) !== 1) throw new RuntimeException('Multiple primary FileMaker defaults are configured.');
                $value = json_decode((string) $values[0], true);
                $candidate = is_array($value) ? ($value['userId'] ?? $value['user_id'] ?? $value['id'] ?? null) : $value;
                $userId = filter_var($candidate, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($userId === false) throw new RuntimeException('The saved primary FileMaker owner is invalid.');
                $explicit = true;
                break;
            }
        }
        if (!$explicit) {
            $rows = $this->pdo->query(
                "SELECT s.user_id FROM openbridge_secrets s
                 JOIN openbridge_users u ON u.id = s.user_id
                 WHERE s.secret_key = 'filemaker_connection' AND u.is_active = 1 AND u.role = 'admin'
                 ORDER BY s.user_id LIMIT 2"
            )->fetchAll(PDO::FETCH_COLUMN);
            if (!$rows) return null;
            if (count($rows) !== 1) throw new RuntimeException('Select a FileMaker vaultUserId in trusted PHP configuration; multiple defaults exist.');
            $userId = (int) $rows[0];
        }
        $active = $this->pdo->prepare("SELECT 1 FROM openbridge_users WHERE id = :id AND is_active = 1 AND role = 'admin'");
        $active->execute(['id' => $userId]);
        if (!$active->fetchColumn()) throw new RuntimeException('The configured FileMaker vault owner is unavailable.');
        $secret = $this->loadSecret((int) $userId, 'filemaker_connection');
        if ($secret === null && $explicit) throw new RuntimeException('The selected FileMaker connection has not been saved.');
        return $secret;
    }

    public function saveSecret(int $userId, string $secretKey, array $value): void
    {
        $this->crypto->assertStorageKey($this->pdo);
        $aad = "openbridge-secret:user:{$userId}:{$secretKey}";
        $encrypted = $this->crypto->encrypt(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $aad);
        $statement = $this->pdo->prepare(
            'INSERT INTO openbridge_secrets (user_id, secret_key, ciphertext, nonce, algorithm, key_version)
             VALUES (:user_id, :secret_key, :ciphertext, :nonce, :algorithm, :key_version)
             ON CONFLICT (user_id, secret_key) DO UPDATE SET ciphertext = EXCLUDED.ciphertext, nonce = EXCLUDED.nonce,
               algorithm = EXCLUDED.algorithm, key_version = EXCLUDED.key_version, updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'user_id' => $userId, 'secret_key' => $secretKey, 'ciphertext' => $encrypted['ciphertext'],
            'nonce' => $encrypted['nonce'], 'algorithm' => $encrypted['algorithm'], 'key_version' => $encrypted['keyVersion'],
        ]);
    }

    public function loadSecret(int $userId, string $secretKey): ?array
    {
        $statement = $this->pdo->prepare('SELECT ciphertext, nonce, algorithm, key_version FROM openbridge_secrets WHERE user_id = :user_id AND secret_key = :secret_key LIMIT 1');
        $statement->execute(['user_id' => $userId, 'secret_key' => $secretKey]);
        $row = $statement->fetch();
        if (!is_array($row)) return null;
        $aad = "openbridge-secret:user:{$userId}:{$secretKey}";
        $plaintext = $this->crypto->decrypt($row + ['aad' => $aad], $aad);
        $decoded = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : null;
    }

    public function deleteSecret(int $userId, string $secretKey): void
    {
        $statement = $this->pdo->prepare('DELETE FROM openbridge_secrets WHERE user_id = :user_id AND secret_key = :secret_key');
        $statement->execute(['user_id' => $userId, 'secret_key' => $secretKey]);
    }

    public function hasSecret(int $userId, string $secretKey): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM openbridge_secrets WHERE user_id = :user_id AND secret_key = :secret_key LIMIT 1');
        $statement->execute(['user_id' => $userId, 'secret_key' => $secretKey]);
        return (bool) $statement->fetchColumn();
    }

    public function saveTransactionPayload(string $transactionId, ?int $userId, ?string $request, ?string $response): void
    {
        $this->crypto->assertStorageKey($this->pdo);
        $payload = ['request' => $this->crypto->redactPayload($request), 'response' => $this->crypto->redactPayload($response)];
        $owner = $userId ?? 0;
        $aad = "openbridge-transaction:{$transactionId}:user:{$owner}";
        $encrypted = $this->crypto->encrypt(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $aad);
        $statement = $this->pdo->prepare(
            'INSERT INTO openbridge_transaction_payloads
             (transaction_id, raw_request, raw_response, request_json, response_json, is_encrypted, encryption_version, ciphertext, nonce, algorithm, key_version, aad)
             VALUES (:id, NULL, NULL, NULL, NULL, 1, :version, :ciphertext, :nonce, :algorithm, :key_version, :aad)'
        );
        $statement->execute([
            'id' => $transactionId, 'version' => 'server-aead-v1', 'ciphertext' => $encrypted['ciphertext'],
            'nonce' => $encrypted['nonce'], 'algorithm' => $encrypted['algorithm'], 'key_version' => $encrypted['keyVersion'], 'aad' => $encrypted['aad'],
        ]);
    }

    public function loadTransactionPayload(string $transactionId, int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.ciphertext, p.nonce, p.algorithm, p.key_version, p.aad
             FROM openbridge_transaction_payloads p JOIN openbridge_transactions t ON t.id = p.transaction_id
             WHERE p.transaction_id = :id AND t.user_id = :user_id LIMIT 1'
        );
        $statement->execute(['id' => $transactionId, 'user_id' => $userId]);
        $row = $statement->fetch();
        if (!is_array($row) || empty($row['ciphertext'])) return null;
        $aad = "openbridge-transaction:{$transactionId}:user:{$userId}";
        $row['aad'] = $aad;
        $plaintext = $this->crypto->decrypt($row, $aad);
        $decoded = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : null;
    }

    public function cleanupTransactions(int $days): int
    {
        $days = max(1, min(3650, $days));
        $statement = $this->pdo->prepare("DELETE FROM openbridge_transactions WHERE created_at < datetime('now', :age)");
        $statement->execute(['age' => '-' . $days . ' days']);
        return $statement->rowCount();
    }
}

/** Per-session CSRF tokens for authenticated browser state changes. */
final class OpenBridgeCsrf
{
    public static function token(): string
    {
        if (empty($_SESSION['openbridge_csrf_token'])) $_SESSION['openbridge_csrf_token'] = bin2hex(random_bytes(32));
        return (string) $_SESSION['openbridge_csrf_token'];
    }

    public static function validate(?string $provided): bool
    {
        $expected = (string) ($_SESSION['openbridge_csrf_token'] ?? '');
        return $expected !== '' && is_string($provided) && hash_equals($expected, $provided);
    }
}
