<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';

/** Owner-scoped encrypted transaction repository for OpenBridge audit history. */
final class OpenBridgeTransactionRepository
{
    private const MAX_BODY_BYTES = 20000000;
    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT = 500;
    private const STATUSES = ['success', 'failure', 'pending'];
    private const TRANSPORTS = ['data-api', 'odata', 'go', 'internal'];

    public function __construct(private PDO $pdo, private OpenBridgeCrypto $crypto) {}

    public function record(int $userId, array $input): array
    {
        $userId = $this->requireActiveAdmin($userId);
        $status = $this->validateChoice($input['status'] ?? null, self::STATUSES, 'Transaction status is invalid.');
        $transport = $this->validateChoice($input['transport'] ?? null, self::TRANSPORTS, 'Transaction transport is invalid.');
        $id = isset($input['id']) ? $this->validateUuid((string) $input['id']) : $this->uuid();
        $this->assertStorageKey();
        $rawRequest = $this->optionalBody($input['rawRequest'] ?? $input['request'] ?? null, 'request');
        $rawResponse = $this->optionalBody($input['rawResponse'] ?? $input['response'] ?? null, 'response');
        $payloadStored = $rawRequest !== null || $rawResponse !== null;
        $details = [
            'operation' => $this->optionalBoundText($input['operation'] ?? null, 'operation', 80),
            'resource' => $this->optionalBoundText($input['resource'] ?? null, 'resource', 255),
            'database' => $this->optionalBoundText($input['database'] ?? $input['databaseName'] ?? null, 'database', 255),
            'message' => $this->optionalBoundText($input['message'] ?? null, 'message', 4000, true),
            'responsePreview' => $this->optionalBoundText($input['responsePreview'] ?? null, 'responsePreview', 1000, true),
        ];
        $encrypted = $this->encryptDetails($id, $userId, $details);
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) $this->pdo->beginTransaction();
        try {
            $transaction = $this->pdo->prepare(
                'INSERT INTO openbridge_transactions
                 (id, user_id, status, transport, operation, resource, database_name, duration_ms, http_status, message, response_preview, completed_at)
                 VALUES (:id, :user_id, :status, :transport, :operation, NULL, NULL, :duration_ms, :http_status, NULL, NULL, :completed_at)'
            );
            $transaction->execute([
                'id' => $id,
                'user_id' => $userId,
                'status' => $status,
                'transport' => $transport,
                'operation' => 'request',
                'duration_ms' => $this->optionalNonNegativeInt($input['durationMs'] ?? $input['duration_ms'] ?? null, 'durationMs'),
                'http_status' => $this->optionalNonNegativeInt($input['httpStatus'] ?? $input['http_status'] ?? null, 'httpStatus'),
                'completed_at' => $status === 'pending' ? null : gmdate('Y-m-d H:i:s'),
            ]);
            $detail = $this->pdo->prepare(
                'INSERT INTO openbridge_transaction_details
                 (transaction_id, user_id, ciphertext, nonce, algorithm, key_version)
                 VALUES (:transaction_id, :user_id, :ciphertext, :nonce, :algorithm, :key_version)'
            );
            $detail->execute([
                'transaction_id' => $id,
                'user_id' => $userId,
                'ciphertext' => $encrypted['ciphertext'],
                'nonce' => $encrypted['nonce'],
                'algorithm' => $encrypted['algorithm'],
                'key_version' => $encrypted['keyVersion'],
            ]);
            if ($payloadStored) {
                (new OpenBridgeRepository($this->pdo, $this->crypto))->saveTransactionPayload($id, $userId, $rawRequest, $rawResponse);
            }
            if ($ownsTransaction) $this->pdo->commit();
            return ['id' => $id, 'recorded' => true, 'payloadStored' => $payloadStored];
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function list(int $userId, int $limit = self::DEFAULT_LIMIT): array
    {
        $userId = $this->requireActiveAdmin($userId);
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $statement = $this->pdo->prepare(
            'SELECT t.id, t.status, t.operation, t.transport, t.resource, t.database_name, t.duration_ms, t.created_at, t.message, t.response_preview,
                    d.ciphertext, d.nonce, d.algorithm, d.key_version,
                    CASE WHEN p.ciphertext IS NOT NULL AND p.encryption_version = \'server-aead-v1\' THEN 1 ELSE 0 END AS payload_stored
             FROM openbridge_transactions t
             LEFT JOIN openbridge_transaction_details d ON d.transaction_id = t.id AND d.user_id = t.user_id
             LEFT JOIN openbridge_transaction_payloads p ON p.transaction_id = t.id AND p.is_encrypted = 1
             WHERE t.user_id = :user_id
             ORDER BY t.created_at DESC, t.id DESC
             LIMIT :limit'
        );
        $statement->bindValue('user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();
        if (!is_array($rows)) return [];
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $items[] = $this->formatListRow($row, $userId);
        }
        return $items;
    }

    public function cleanup(int $userId, int $days): int
    {
        $userId = $this->requireActiveAdmin($userId);
        $days = max(1, min(3650, $days));
        $statement = $this->pdo->prepare("DELETE FROM openbridge_transactions WHERE user_id = :user_id AND created_at < datetime('now', :age)");
        $statement->execute(['user_id' => $userId, 'age' => '-' . $days . ' days']);
        return $statement->rowCount();
    }

    public function delete(int $userId, string $id): bool
    {
        $userId = $this->requireActiveAdmin($userId);
        $id = $this->validateUuid($id);
        $statement = $this->pdo->prepare('DELETE FROM openbridge_transactions WHERE user_id = :user_id AND id = :id');
        $statement->execute(['user_id' => $userId, 'id' => $id]);
        return $statement->rowCount() === 1;
    }

    public function migrateLegacy(int $userId, int $limit = self::DEFAULT_LIMIT): int
    {
        $userId = $this->requireActiveAdmin($userId);
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $this->assertStorageKey();
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) $this->pdo->beginTransaction();
        try {
            $select = $this->pdo->prepare(
                'SELECT t.id, t.operation, t.resource, t.database_name, t.message, t.response_preview
                 FROM openbridge_transactions t
                 LEFT JOIN openbridge_transaction_details d ON d.transaction_id = t.id AND d.user_id = t.user_id
                 WHERE t.user_id = :user_id AND d.transaction_id IS NULL
                 ORDER BY t.created_at ASC, t.id ASC
                 LIMIT :limit'
            );
            $select->bindValue('user_id', $userId, PDO::PARAM_INT);
            $select->bindValue('limit', $limit, PDO::PARAM_INT);
            $select->execute();
            $rows = $select->fetchAll();
            if (!is_array($rows) || !$rows) {
                if ($ownsTransaction) $this->pdo->commit();
                return 0;
            }
            $insert = $this->pdo->prepare(
                'INSERT INTO openbridge_transaction_details
                 (transaction_id, user_id, ciphertext, nonce, algorithm, key_version)
                 VALUES (:transaction_id, :user_id, :ciphertext, :nonce, :algorithm, :key_version)'
            );
            $clear = $this->pdo->prepare(
                "UPDATE openbridge_transactions
                 SET operation = 'request', resource = NULL, database_name = NULL, message = NULL, response_preview = NULL
                 WHERE user_id = :user_id AND id = :id"
            );
            $payloadSelect = $this->pdo->prepare(
                'SELECT raw_request, raw_response, request_json, response_json, is_encrypted
                 FROM openbridge_transaction_payloads
                 WHERE transaction_id = :id LIMIT 1'
            );
            $payloadDelete = $this->pdo->prepare('DELETE FROM openbridge_transaction_payloads WHERE transaction_id = :id');
            $payloadRepository = new OpenBridgeRepository($this->pdo, $this->crypto);
            $count = 0;
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $id = $this->validateUuid((string) $row['id']);
                $encrypted = $this->encryptDetails($id, $userId, [
                    'operation' => $this->optionalBoundText($row['operation'] ?? null, 'operation', 80),
                    'resource' => $this->optionalBoundText($row['resource'] ?? null, 'resource', 255),
                    'database' => $this->optionalBoundText($row['database_name'] ?? null, 'database', 255),
                    'message' => $this->optionalBoundText($row['message'] ?? null, 'message', 4000, true),
                    'responsePreview' => $this->optionalBoundText($row['response_preview'] ?? null, 'responsePreview', 1000, true),
                ]);
                $insert->execute([
                    'transaction_id' => $id,
                    'user_id' => $userId,
                    'ciphertext' => $encrypted['ciphertext'],
                    'nonce' => $encrypted['nonce'],
                    'algorithm' => $encrypted['algorithm'],
                    'key_version' => $encrypted['keyVersion'],
                ]);
                $payloadSelect->execute(['id' => $id]);
                $payload = $payloadSelect->fetch();
                if (is_array($payload) && (int) ($payload['is_encrypted'] ?? 0) !== 1) {
                    $request = $payload['raw_request'] ?? $payload['request_json'] ?? null;
                    $response = $payload['raw_response'] ?? $payload['response_json'] ?? null;
                    if ($request !== null || $response !== null) {
                        $payloadDelete->execute(['id' => $id]);
                        $payloadRepository->saveTransactionPayload($id, $userId, $request === null ? null : (string) $request, $response === null ? null : (string) $response);
                    }
                }
                $clear->execute(['user_id' => $userId, 'id' => $id]);
                $count++;
            }
            if ($ownsTransaction) $this->pdo->commit();
            return $count;
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private function formatListRow(array $row, int $userId): array
    {
        $item = [
            'id' => (string) $row['id'],
            'status' => (string) $row['status'],
            'operation' => (string) $row['operation'],
            'transport' => (string) $row['transport'],
            'resource' => $row['resource'],
            'database_name' => $row['database_name'],
            'duration_ms' => $row['duration_ms'] === null ? null : (int) $row['duration_ms'],
            'created_at' => (string) $row['created_at'],
            'message' => $row['message'],
            'response_preview' => $row['response_preview'],
            'payloadStored' => (int) $row['payload_stored'] === 1,
        ];
        if (empty($row['ciphertext'])) {
            $item['legacy'] = true;
            return $item;
        }
        $details = $this->decryptDetails((string) $row['id'], $userId, $row);
        $item['operation'] = $details['operation'];
        $item['resource'] = $details['resource'];
        $item['database_name'] = $details['database'];
        $item['message'] = $details['message'];
        $item['response_preview'] = $details['responsePreview'];
        $item['legacy'] = false;
        return $item;
    }

    private function encryptDetails(string $id, int $userId, array $details): array
    {
        return $this->crypto->encrypt(
            json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $this->detailsAad($id, $userId)
        );
    }

    private function decryptDetails(string $id, int $userId, array $row): array
    {
        $envelope = [
            'ciphertext' => $row['ciphertext'] ?? '',
            'nonce' => $row['nonce'] ?? '',
            'algorithm' => $row['algorithm'] ?? '',
            'key_version' => $row['key_version'] ?? null,
            'aad' => $this->detailsAad($id, $userId),
        ];
        $decoded = json_decode($this->crypto->decrypt($envelope, $envelope['aad']), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new RuntimeException('Encrypted transaction details are malformed.');
        return [
            'operation' => $this->optionalBoundText($decoded['operation'] ?? null, 'operation', 80),
            'resource' => $this->optionalBoundText($decoded['resource'] ?? null, 'resource', 255),
            'database' => $this->optionalBoundText($decoded['database'] ?? null, 'database', 255),
            'message' => $this->optionalBoundText($decoded['message'] ?? null, 'message', 4000),
            'responsePreview' => $this->optionalBoundText($decoded['responsePreview'] ?? null, 'responsePreview', 1000),
        ];
    }

    private function detailsAad(string $id, int $userId): string
    {
        return "openbridge-transaction-details:{$id}:user:{$userId}";
    }

    private function requireActiveAdmin(int $userId): int
    {
        if ($userId < 1) throw new InvalidArgumentException('A valid transaction owner is required.');
        $statement = $this->pdo->prepare("SELECT 1 FROM openbridge_users WHERE id = :id AND is_active = 1 AND role = 'admin'");
        $statement->execute(['id' => $userId]);
        if (!$statement->fetchColumn()) throw new RuntimeException('An active transaction owner is required.');
        return $userId;
    }

    private function assertStorageKey(): void
    {
        $this->crypto->assertStorageKey($this->pdo);
    }

    private function validateChoice(mixed $value, array $allowed, string $message): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) throw new InvalidArgumentException($message);
        return $value;
    }

    private function validateUuid(string $id): string
    {
        $id = strtolower(trim($id));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id)) {
            throw new InvalidArgumentException('A valid transaction ID is required.');
        }
        return $id;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    private function optionalBoundText(mixed $value, string $label, int $maxBytes, bool $redact = false): ?string
    {
        if ($value === null) return null;
        if (!is_scalar($value)) throw new InvalidArgumentException("{$label} must be a scalar value.");
        $text = (string) $value;
        if ($redact) $text = $this->crypto->redactPayload($text) ?? '';
        if (strlen($text) > $maxBytes) throw new LengthException("{$label} exceeds the {$maxBytes} byte limit.");
        return $text;
    }

    private function optionalBody(mixed $value, string $label): ?string
    {
        if ($value === null) return null;
        if (!is_string($value)) throw new InvalidArgumentException('Retained bodies must be strings.');
        $body = $value;
        if (strlen($body) > self::MAX_BODY_BYTES) throw new LengthException("The retained {$label} exceeds the 20 MB application limit.");
        return $body;
    }

    private function optionalNonNegativeInt(mixed $value, string $label): ?int
    {
        if ($value === null || $value === '') return null;
        if (!is_int($value) && !(is_string($value) && preg_match('/^\d+$/', $value))) {
            throw new InvalidArgumentException("{$label} must be a non-negative integer.");
        }
        $integer = (int) $value;
        if ($integer < 0) throw new InvalidArgumentException("{$label} must be a non-negative integer.");
        return $integer;
    }
}
