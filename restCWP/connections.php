<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';

/** Shared storage for reviewed adapters. No network requests or browser secret retrieval. */
final class OpenBridgeConnectionRepository
{
    private OpenBridgeRepository $vault;
    public function __construct(private PDO $pdo, OpenBridgeCrypto $crypto)
    {
        $this->vault = new OpenBridgeRepository($pdo, $crypto);
    }

    public function list(int $userId): array
    {
        $this->requireOwner($userId);
        $query = $this->pdo->prepare("SELECT id, provider_id, display_name, environment, auth_type, revision, created_at, updated_at,
            CASE WHEN provider_id = 'filemaker' AND secret_key = 'filemaker_connection' THEN 1 ELSE 0 END AS is_default
            FROM openbridge_connections WHERE user_id = :owner ORDER BY created_at, id");
        $query->execute(['owner' => $userId]);
        return $query->fetchAll(); // No token, host, account name, profile or ciphertext.
    }

    /** Trusted adapter entry point; the entire private profile is encrypted. */
    public function save(int $userId, string $providerId, string $name, string $authType, array $privateProfile, string $environment = 'production', ?string $id = null, ?int $expectedRevision = null): array
    {
        $this->requireOwner($userId);
        if (!preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $providerId)
            || trim($name) === '' || strlen($name) > 255
            || !in_array($authType, ['filemaker-credentials','api-key','oauth2','basic','none'], true)
            || !in_array($environment, ['test','production'], true)) throw new InvalidArgumentException('Connection metadata is invalid.');
        if (strlen(json_encode($privateProfile, JSON_THROW_ON_ERROR)) > 65536) throw new InvalidArgumentException('Connection credentials exceed the 64 KB limit.');
        if ($id !== null && !self::validId($id)) throw new InvalidArgumentException('Connection ID is invalid.');
        if ($id === null && $expectedRevision !== null) throw new InvalidArgumentException('A new connection has no existing revision.');
        $this->pdo->beginTransaction();
        try {
            $existing = null;
            if ($id !== null) {
                $existing = $this->row($userId, $id);
                if ($existing === null) throw new RuntimeException('Connection not found.');
                if ($expectedRevision === null || (int) $existing['revision'] !== $expectedRevision) throw new RuntimeException('Connection changed. Reload before saving.');
                if ($existing['provider_id'] !== $providerId || $existing['auth_type'] !== $authType) throw new InvalidArgumentException('Create a new connection to change its provider or authentication type.');
                // Missing/tampered old keys must not be hidden by a new save.
                $this->vault->loadSecret($userId, (string) $existing['secret_key']);
            }
            $id = $id ?? self::uuid();
            $secretKey = $existing['secret_key'] ?? ('connection:' . $id);
            $this->vault->saveSecret($userId, $secretKey, $privateProfile);
            if ($existing === null) {
                $statement = $this->pdo->prepare('INSERT INTO openbridge_connections (id,user_id,provider_id,display_name,environment,auth_type,secret_key) VALUES (:id,:owner,:provider,:name,:environment,:auth,:secret)');
                $statement->execute(['id' => $id, 'owner' => $userId, 'provider' => $providerId, 'name' => $name, 'environment' => $environment, 'auth' => $authType, 'secret' => $secretKey]);
            } else {
                $statement = $this->pdo->prepare('UPDATE openbridge_connections SET display_name = :name, environment = :environment, revision = revision + 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :owner AND revision = :revision');
                $statement->execute(['name' => $name, 'environment' => $environment, 'id' => $id, 'owner' => $userId, 'revision' => $expectedRevision]);
                if ($statement->rowCount() !== 1) throw new RuntimeException('Connection changed. Reload before saving.');
            }
            $revision = ($expectedRevision ?? 0) + 1;
            $this->pdo->commit();
            return ['id' => $id, 'revision' => $revision, 'saved' => true];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /** Preserve the existing FileMaker secret contract used by standalone factories. */
    public function saveFileMaker(int $userId, array $profile): array
    {
        $this->requireOwner($userId);
        if (strlen(json_encode($profile, JSON_THROW_ON_ERROR)) > 65536) throw new InvalidArgumentException('FileMaker profile is too large.');
        $this->pdo->beginTransaction();
        try {
            $this->vault->loadSecret($userId, 'filemaker_connection');
            $this->vault->saveSecret($userId, 'filemaker_connection', $profile);
            $id = self::uuid();
            $statement = $this->pdo->prepare("INSERT INTO openbridge_connections (id,user_id,provider_id,display_name,auth_type,secret_key)
                VALUES (:id,:owner,'filemaker','FileMaker','filemaker-credentials','filemaker_connection')
                ON CONFLICT(user_id,secret_key) DO UPDATE SET revision = revision + 1, updated_at = CURRENT_TIMESTAMP");
            $statement->execute(['id' => $id, 'owner' => $userId]);
            $query = $this->pdo->prepare("SELECT id,revision FROM openbridge_connections WHERE user_id = :owner AND secret_key = 'filemaker_connection'");
            $query->execute(['owner' => $userId]);
            $result = $query->fetch();
            $this->pdo->commit();
            return ['id' => $result['id'], 'revision' => (int) $result['revision'], 'saved' => true];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /** Server adapters only: never return this method's result to the browser. */
    public function loadPrivateProfile(int $userId, string $id): ?array
    {
        $this->requireOwner($userId);
        $row = $this->row($userId, $id);
        return $row === null ? null : $this->vault->loadSecret($userId, (string) $row['secret_key']);
    }

    public function delete(int $userId, string $id, int $expectedRevision): bool
    {
        $this->requireOwner($userId);
        $this->pdo->beginTransaction();
        try {
            $row = $this->row($userId, $id);
            if ($row === null) { $this->pdo->rollBack(); return false; }
            if ((int) $row['revision'] !== $expectedRevision) throw new RuntimeException('Connection changed. Reload before deleting.');
            $this->vault->deleteSecret($userId, (string) $row['secret_key']); // FK cascades the metadata.
            $this->pdo->commit();
            return true;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private function row(int $userId, string $id): ?array
    {
        if (!self::validId($id)) throw new InvalidArgumentException('Connection ID is invalid.');
        $query = $this->pdo->prepare('SELECT * FROM openbridge_connections WHERE id = :id AND user_id = :owner');
        $query->execute(['id' => $id, 'owner' => $userId]);
        $row = $query->fetch();
        return is_array($row) ? $row : null;
    }
    private function requireOwner(int $userId): void
    {
        $query = $this->pdo->prepare("SELECT 1 FROM openbridge_users WHERE id = :id AND is_active = 1 AND role = 'admin'");
        $query->execute(['id' => $userId]);
        if ($userId < 1 || !$query->fetchColumn()) throw new RuntimeException('An active connection owner is required.');
    }
    private static function validId(string $id): bool { return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $id) === 1; }
    private static function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        return substr($hex,0,8) . '-' . substr($hex,8,4) . '-4' . substr($hex,13,3) . '-8' . substr($hex,17,3) . '-' . substr($hex,20,12);
    }
}
