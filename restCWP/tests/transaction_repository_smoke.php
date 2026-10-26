<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/database.php';
require_once dirname(__DIR__) . '/security.php';
require_once dirname(__DIR__) . '/transactions.php';

function transactionAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function transactionRemoveTree(string $path): void
{
    if (!is_dir($path)) return;
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        $target = $path . DIRECTORY_SEPARATOR . $item;
        is_dir($target) ? transactionRemoveTree($target) : unlink($target);
    }
    rmdir($path);
}

$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'openbridge-transactions-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700, true);

try {
    $config = [
        'database' => ['path' => $temporary . '/openbridge.sqlite', 'autoInitialize' => true],
        'security' => ['masterKeyFile' => $temporary . '/private/openbridge.key.php', 'keyVersion' => 1],
    ];
    $pdo = openBridgeDatabasePdo($config);
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS openbridge_transaction_details (
            transaction_id TEXT PRIMARY KEY REFERENCES openbridge_transactions(id) ON DELETE CASCADE,
            user_id INTEGER NOT NULL REFERENCES openbridge_users(id) ON DELETE CASCADE,
            ciphertext TEXT NOT NULL,
            nonce TEXT NOT NULL,
            algorithm TEXT NOT NULL,
            key_version INTEGER NOT NULL
        )'
    );
    $userOne = openBridgeDatabaseCreateUser($pdo, 'owner_one', 'owner-one@example.invalid', 'correct-horse-battery-staple');
    $userTwo = openBridgeDatabaseCreateUser($pdo, 'owner_two', 'owner-two@example.invalid', 'correct-horse-battery-staple');
    $crypto = new OpenBridgeCrypto(new OpenBridgeKeyProvider($config, dirname(__DIR__)));
    $repository = new OpenBridgeTransactionRepository($pdo, $crypto);
    (new OpenBridgeKeyProvider($config, dirname(__DIR__)))->encryptionKey();

    foreach ([['transport' => 'odata'], ['status' => 'success', 'transport' => 'smtp']] as $invalidInput) {
        $invalidRejected = false;
        try { $repository->record((int) $userOne['id'], $invalidInput); } catch (Throwable) { $invalidRejected = true; }
        transactionAssert($invalidRejected, 'Invalid transaction status or transport was not rejected.');
    }
    $nonScalarRejected = false;
    try { $repository->record((int) $userOne['id'], ['status' => 'success', 'transport' => 'internal', 'operation' => ['find']]); } catch (Throwable) { $nonScalarRejected = true; }
    transactionAssert($nonScalarRejected, 'Non-scalar transaction details were not rejected.');
    $longSummaryRejected = false;
    try { $repository->record((int) $userOne['id'], ['status' => 'success', 'transport' => 'internal', 'operation' => str_repeat('a', 81)]); } catch (Throwable) { $longSummaryRejected = true; }
    transactionAssert($longSummaryRejected, 'Oversized transaction summary was not rejected.');
    $oversizedRejected = false;
    try {
        $repository->record((int) $userOne['id'], ['status' => 'success', 'transport' => 'internal', 'rawRequest' => str_repeat('x', 20000001)]);
    } catch (Throwable) {
        $oversizedRejected = true;
    }
    transactionAssert($oversizedRejected, 'Oversized transaction body was not rejected.');
    transactionAssert((int) $pdo->query('SELECT COUNT(*) FROM openbridge_transactions')->fetchColumn() === 0, 'Rejected transaction input left stored rows.');

    $recorded = $repository->record((int) $userOne['id'], [
        'status' => 'success',
        'transport' => 'odata',
        'operation' => 'Find Contacts',
        'resource' => 'Contacts',
        'database' => 'Client Demo',
        'durationMs' => 42,
        'httpStatus' => 200,
        'message' => 'Completed with token=summary-secret',
        'responsePreview' => 'Visible response preview api_key=preview-secret',
        'rawRequest' => '{"query":"active","password":"request-secret"}',
        'rawResponse' => '{"data":[{"name":"Alex"}],"token":"response-secret"}',
    ]);
    transactionAssert(($recorded['recorded'] ?? false) === true && ($recorded['payloadStored'] ?? false) === true, 'Transaction was not recorded with payload.');
    $id = (string) $recorded['id'];
    transactionAssert(preg_match('/^[0-9a-f-]{36}$/', $id) === 1, 'Transaction ID is not a safe UUID.');

    $plainRow = $pdo->query("SELECT operation, resource, database_name, message, response_preview FROM openbridge_transactions WHERE id = '$id'")->fetch();
    transactionAssert(is_array($plainRow) && $plainRow['operation'] === 'request', 'Plain summary operation was not normalized.');
    transactionAssert($plainRow['resource'] === null && $plainRow['database_name'] === null && $plainRow['message'] === null && $plainRow['response_preview'] === null, 'Sensitive summary fields remained in plaintext.');
    $detailCiphertext = (string) $pdo->query("SELECT ciphertext FROM openbridge_transaction_details WHERE transaction_id = '$id'")->fetchColumn();
    $payloadCiphertext = (string) $pdo->query("SELECT ciphertext FROM openbridge_transaction_payloads WHERE transaction_id = '$id'")->fetchColumn();
    foreach (['Find Contacts', 'Contacts', 'Client Demo', 'request-secret', 'response-secret'] as $needle) {
        transactionAssert(!str_contains($detailCiphertext . $payloadCiphertext, $needle), 'Plaintext leaked into encrypted transaction storage.');
    }

    $userOneList = $repository->list((int) $userOne['id']);
    transactionAssert(count($userOneList) === 1, 'Owner one list did not return one transaction.');
    transactionAssert(($userOneList[0]['operation'] ?? null) === 'Find Contacts', 'Encrypted operation did not round-trip.');
    transactionAssert(($userOneList[0]['resource'] ?? null) === 'Contacts', 'Encrypted resource did not round-trip.');
    transactionAssert(($userOneList[0]['database_name'] ?? null) === 'Client Demo', 'Encrypted database name did not round-trip.');
    transactionAssert(($userOneList[0]['message'] ?? null) === 'Completed with token=[redacted]', 'Credential-like summary message was not redacted.');
    transactionAssert(($userOneList[0]['response_preview'] ?? null) === 'Visible response preview api_key=[redacted]', 'Credential-like response preview was not redacted.');
    transactionAssert(($userOneList[0]['payloadStored'] ?? false) === true && ($userOneList[0]['legacy'] ?? true) === false, 'Encrypted list flags are wrong.');
    transactionAssert($repository->list((int) $userTwo['id']) === [], 'Owner two could see owner one transactions.');

    $browserEnvelopeId = '32345678-1234-4234-8234-123456789abc';
    $browserEnvelope = $repository->record((int) $userOne['id'], ['id' => $browserEnvelopeId, 'status' => 'success', 'transport' => 'internal', 'operation' => 'Browser Envelope']);
    transactionAssert(($browserEnvelope['payloadStored'] ?? true) === false, 'Payload flag was set without server payload.');
    $browserPayload = $pdo->prepare(
        "INSERT INTO openbridge_transaction_payloads (transaction_id, is_encrypted, encryption_version, ciphertext, nonce, algorithm, key_version)
         VALUES (:id, 1, 'browser-aes-gcm-v1', :ciphertext, :nonce, 'aes-256-gcm', 1)"
    );
    $browserPayload->execute(['id' => $browserEnvelopeId, 'ciphertext' => base64_encode('browser-only'), 'nonce' => base64_encode('123456789012')]);
    $browserListed = array_values(array_filter($repository->list((int) $userOne['id'], 10), fn (array $item): bool => $item['id'] === $browserEnvelopeId));
    transactionAssert(count($browserListed) === 1 && ($browserListed[0]['payloadStored'] ?? true) === false, 'Browser envelope was counted as server-readable payload.');
    transactionAssert($repository->delete((int) $userOne['id'], $browserEnvelopeId) === true, 'Browser envelope fixture delete failed.');

    $inactiveUser = openBridgeDatabaseCreateUser($pdo, 'inactive_owner', 'inactive@example.invalid', 'correct-horse-battery-staple');
    $pdo->exec('UPDATE openbridge_users SET is_active = 0 WHERE id = ' . (int) $inactiveUser['id']);
    $inactiveRejected = false;
    try { $repository->list((int) $inactiveUser['id']); } catch (Throwable) { $inactiveRejected = true; }
    transactionAssert($inactiveRejected, 'Inactive transaction owner was accepted.');
    $nonAdmin = openBridgeDatabaseCreateUser($pdo, 'viewer_owner', 'viewer@example.invalid', 'correct-horse-battery-staple');
    $pdo->exec("UPDATE openbridge_users SET role = 'viewer' WHERE id = " . (int) $nonAdmin['id']);
    $nonAdminRejected = false;
    try { $repository->record((int) $nonAdmin['id'], ['status' => 'success', 'transport' => 'internal']); } catch (Throwable) { $nonAdminRejected = true; }
    transactionAssert($nonAdminRejected, 'Non-admin transaction owner was accepted.');

    $tamper = $pdo->prepare('UPDATE openbridge_transaction_details SET ciphertext = :ciphertext WHERE transaction_id = :id');
    $tamper->execute(['ciphertext' => substr($detailCiphertext, 0, -2) . 'AA', 'id' => $id]);
    $tamperRejected = false;
    try { $repository->list((int) $userOne['id']); } catch (Throwable) { $tamperRejected = true; }
    transactionAssert($tamperRejected, 'Tampered encrypted transaction details were accepted.');
    $restore = $pdo->prepare('UPDATE openbridge_transaction_details SET ciphertext = :ciphertext WHERE transaction_id = :id');
    $restore->execute(['ciphertext' => $detailCiphertext, 'id' => $id]);

    $second = $repository->record((int) $userTwo['id'], ['status' => 'failure', 'transport' => 'internal', 'operation' => 'Other Owner']);
    transactionAssert($repository->delete((int) $userTwo['id'], $id) === false, 'Cross-owner delete succeeded.');
    transactionAssert($repository->delete((int) $userTwo['id'], (string) $second['id']) === true, 'Owner delete failed.');

    $legacyId = '12345678-1234-4234-8234-123456789abc';
    $legacyInsert = $pdo->prepare(
        "INSERT INTO openbridge_transactions
         (id, user_id, status, transport, operation, resource, database_name, duration_ms, http_status, message, response_preview, created_at, completed_at)
         VALUES (:id, :user_id, 'success', 'data-api', 'Legacy Find', 'Legacy Contacts', 'Legacy DB', 12, 200, 'legacy message', 'legacy preview', datetime('now', '-10 days'), CURRENT_TIMESTAMP)"
    );
    $legacyInsert->execute(['id' => $legacyId, 'user_id' => $userOne['id']]);
    $payloadInsert = $pdo->prepare(
        'INSERT INTO openbridge_transaction_payloads (transaction_id, raw_request, raw_response, request_json, response_json, is_encrypted)
         VALUES (:id, :request, :response, NULL, NULL, 0)'
    );
    $payloadInsert->execute(['id' => $legacyId, 'request' => 'password=legacy-secret', 'response' => 'token=legacy-token']);
    transactionAssert($repository->migrateLegacy((int) $userTwo['id']) === 0, 'Legacy migration touched another owner.');
    transactionAssert($repository->migrateLegacy((int) $userOne['id']) === 1, 'Legacy migration did not convert owner row.');
    $legacyPlain = $pdo->query("SELECT operation, resource, database_name, message, response_preview FROM openbridge_transactions WHERE id = '$legacyId'")->fetch();
    transactionAssert(is_array($legacyPlain) && $legacyPlain['operation'] === 'request' && $legacyPlain['resource'] === null && $legacyPlain['message'] === null, 'Legacy plaintext summary was not cleared.');
    $legacyPayload = $pdo->query("SELECT raw_request, raw_response, is_encrypted, ciphertext FROM openbridge_transaction_payloads WHERE transaction_id = '$legacyId'")->fetch();
    transactionAssert(is_array($legacyPayload) && $legacyPayload['raw_request'] === null && $legacyPayload['raw_response'] === null && (int) $legacyPayload['is_encrypted'] === 1, 'Legacy payload was not converted to encrypted storage.');
    transactionAssert(!str_contains((string) $legacyPayload['ciphertext'], 'legacy-secret') && !str_contains((string) $legacyPayload['ciphertext'], 'legacy-token'), 'Legacy payload plaintext leaked after migration.');
    $legacyListed = array_values(array_filter($repository->list((int) $userOne['id']), fn (array $item): bool => $item['id'] === $legacyId));
    transactionAssert(count($legacyListed) === 1 && ($legacyListed[0]['operation'] ?? null) === 'Legacy Find' && ($legacyListed[0]['legacy'] ?? true) === false, 'Migrated legacy details did not round-trip.');

    $legacyVisibleId = '22345678-1234-4234-8234-123456789abc';
    $legacyInsert->execute(['id' => $legacyVisibleId, 'user_id' => $userOne['id']]);
    $legacyVisible = array_values(array_filter($repository->list((int) $userOne['id'], 10), fn (array $item): bool => $item['id'] === $legacyVisibleId));
    transactionAssert(count($legacyVisible) === 1 && ($legacyVisible[0]['legacy'] ?? false) === true && ($legacyVisible[0]['operation'] ?? null) === 'Legacy Find', 'Unmigrated legacy row was not marked as legacy.');

    $invalidConfig = $config;
    $invalidConfig['security']['masterKey'] = 'not-valid-base64';
    $invalidRepository = new OpenBridgeTransactionRepository($pdo, new OpenBridgeCrypto(new OpenBridgeKeyProvider($invalidConfig, dirname(__DIR__))));
    $beforeInvalid = (int) $pdo->query('SELECT COUNT(*) FROM openbridge_transactions')->fetchColumn();
    $invalidRejected = false;
    try { $invalidRepository->record((int) $userOne['id'], ['status' => 'success', 'transport' => 'internal', 'operation' => 'Should Not Persist']); } catch (Throwable) { $invalidRejected = true; }
    transactionAssert($invalidRejected, 'Invalid encryption key was accepted.');
    $afterInvalid = (int) $pdo->query('SELECT COUNT(*) FROM openbridge_transactions')->fetchColumn();
    transactionAssert($beforeInvalid === $afterInvalid, 'Encryption failure left a plaintext transaction row.');

    $savedKeyFile = file_get_contents($temporary . '/private/openbridge.key.php');
    transactionAssert(is_string($savedKeyFile) && $savedKeyFile !== '', 'Installation key file is missing before decrypt test.');
    unlink($temporary . '/private/openbridge.key.php');
    $missingKeyRejected = false;
    try { $repository->list((int) $userOne['id']); } catch (Throwable) { $missingKeyRejected = true; }
    transactionAssert($missingKeyRejected, 'Missing installation key did not stop encrypted transaction reads.');
    $missingKeyMigrationRejected = false;
    try { $repository->migrateLegacy((int) $userOne['id']); } catch (Throwable) { $missingKeyMigrationRejected = true; }
    transactionAssert($missingKeyMigrationRejected, 'Missing installation key did not stop legacy migration.');
    transactionAssert(!is_file($temporary . '/private/openbridge.key.php'), 'Legacy migration recreated a missing installation key.');
    $missingKeyWriteRejected = false;
    try { $repository->record((int) $userOne['id'], ['status' => 'success', 'transport' => 'internal', 'operation' => 'Should Not Create Key']); } catch (Throwable) { $missingKeyWriteRejected = true; }
    transactionAssert($missingKeyWriteRejected, 'Missing installation key did not stop encrypted transaction writes.');
    transactionAssert(!is_file($temporary . '/private/openbridge.key.php'), 'Transaction write recreated a missing installation key.');
    file_put_contents($temporary . '/private/openbridge.key.php', $savedKeyFile, LOCK_EX);

    transactionAssert($repository->cleanup((int) $userTwo['id'], 1) === 0, 'Retention cleanup touched another owner.');
    transactionAssert($repository->cleanup((int) $userOne['id'], 1) === 2, 'Retention cleanup did not delete only old owner rows.');
    $invalidOwnerRejected = false;
    try { $repository->delete(0, $id); } catch (Throwable) { $invalidOwnerRejected = true; }
    transactionAssert($invalidOwnerRejected, 'Invalid owner delete was not rejected.');

    echo "OpenBridge transaction repository smoke test passed\n";
} finally {
    unset($legacyVisible, $legacyPayload, $legacyPlain, $legacyInsert, $payloadInsert, $restore, $tamper, $repository, $invalidRepository, $crypto, $inactiveUser, $nonAdmin, $pdo);
    unset($browserPayload);
    gc_collect_cycles();
    transactionRemoveTree($temporary);
}
