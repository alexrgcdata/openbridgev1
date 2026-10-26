<?php

declare(strict_types=1);

/**
 * Minimal JSON endpoint for the OpenBridge beta Connection Health screen and
 * the protected, read-oriented API Playground.
 *
 * Copy openbridge.config.example.php to openbridge.config.php, keep that file
 * outside version control, configure an exact allowed origin, and explicitly
 * enable the route. Every non-preflight action also requires the configured
 * X-OpenBridge-Token header. The Playground exposes only list, find, get, and
 * script, with an optional script explicitly run after a successful request.
 * It does not expose create, update, delete, upload, or arbitrary URLs.
 */

use restCWP\restCWP;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

function openBridgeRespond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Demo administrator account. The password is "pass". Production installs
// should replace the demo users through the first-run setup/security flow.
const OPENBRIDGE_DEMO_ADMIN_USERNAME = 'alex';
const OPENBRIDGE_DEMO_ADMIN_PASSWORD_HASH = '$2y$10$LFeUrCmUjJBp8gB/jXI0VucWxKvL8fNcS6FJCalzLz1LLCXfHQWnm';

/** Start the hardened PHP admin session used by the React control panel. */
function openBridgeStartAdminSession(?PDO $databasePdo = null, int $sessionLifetime = 3600, ?OpenBridgeCrypto $crypto = null, bool $trustProxyHttps = false): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($trustProxyHttps && (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) $sessionLifetime);
    session_name('openbridge_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    if ($databasePdo !== null) {
        $GLOBALS['openBridgeSessionHandler'] = new OpenBridgeSqliteSessionHandler($databasePdo, $sessionLifetime, $crypto);
        session_set_save_handler($GLOBALS['openBridgeSessionHandler'], true);
    }
    session_start();
}

/** Return true while the server-side administrator session is valid. */
function openBridgeAdminAuthenticated(array $api, ?PDO $databasePdo = null): bool
{
    $sessionLifetime = isset($api['adminSessionSeconds']) && is_numeric($api['adminSessionSeconds'])
        ? max(300, min(86400, (int) $api['adminSessionSeconds']))
        : 3600;
    $authenticatedAt = (int) ($_SESSION['openbridge_authenticated_at'] ?? 0);
    if (empty($_SESSION['openbridge_authenticated'])) {
        return false;
    }
    if ($authenticatedAt <= 0 || (time() - $authenticatedAt) > $sessionLifetime) {
        return false;
    }
    $userId = (int) ($_SESSION['openbridge_user_id'] ?? 0);
    if ($databasePdo === null) {
        return $userId === 0;
    }
    if ($userId < 1) {
        return false;
    }
    $statement = $databasePdo->prepare("SELECT 1 FROM openbridge_users WHERE id = :id AND is_active = 1 AND role = 'admin' LIMIT 1");
    $statement->execute(['id' => $userId]);
    return (bool) $statement->fetchColumn();
}

/** Make a bounded server-to-server JSON request. Secrets are never returned or logged. */
function openBridgeGoHttp(string $url, string $method = 'GET', ?array $payload = null, array $headers = []): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'PHP cURL is not installed.'];
    }
    $curl = curl_init($url);
    $requestHeaders = array_merge(['Accept: application/json'], $headers);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $requestHeaders,
    ]);
    if ($payload !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
        $requestHeaders[] = 'Content-Type: application/json';
        curl_setopt($curl, CURLOPT_HTTPHEADER, $requestHeaders);
    }
    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    $body = is_string($raw) ? json_decode($raw, true) : null;
    return [
        'ok' => $error === '' && $status >= 200 && $status < 300,
        'status' => $status,
        'body' => is_array($body) ? $body : null,
        'error' => $error,
    ];
}

/** Authenticate PHP to Go and cache the short-lived demo token in the PHP session. */
function openBridgeGoToken(array $go): ?string
{
    $cached = isset($_SESSION['openbridge_go_token']) ? (string) $_SESSION['openbridge_go_token'] : '';
    if ($cached !== '') {
        return $cached;
    }
    $baseUrl = rtrim((string) ($go['baseUrl'] ?? ''), '/');
    $username = (string) ($go['username'] ?? '');
    $password = (string) ($go['password'] ?? '');
    if ($baseUrl === '' || $username === '' || $password === '') {
        return null;
    }
    $response = openBridgeGoHttp($baseUrl . '/auth/token', 'POST', [
        'username' => $username,
        'password' => $password,
    ]);
    $token = is_array($response['body'] ?? null) ? (string) ($response['body']['access_token'] ?? '') : '';
    if (!$response['ok'] || $token === '') {
        return null;
    }
    $_SESSION['openbridge_go_token'] = $token;
    return $token;
}

/** Return a compact FileMaker diagnostic from an OData XML error response. */
function openBridgeODataError(array $metadata): ?array
{
    $raw = isset($metadata['raw_response']) && is_string($metadata['raw_response'])
        ? $metadata['raw_response']
        : '';
    if ($raw === '') {
        return null;
    }

    $code = null;
    $message = null;
    if (preg_match('/<(?:[A-Za-z0-9_-]+:)?code>([^<]+)<\/(?:[A-Za-z0-9_-]+:)?code>/i', $raw, $match)) {
        $code = trim(html_entity_decode($match[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }
    if (preg_match('/<(?:[A-Za-z0-9_-]+:)?message[^>]*>([^<]+)<\/(?:[A-Za-z0-9_-]+:)?message>/i', $raw, $match)) {
        $message = trim(html_entity_decode($match[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }
    if ($code !== null && $message !== null) {
        $message = preg_replace('/^\(' . preg_quote($code, '/') . '\)\s*:\s*/', '', $message) ?: $message;
    }

    return $code !== null || $message !== null
        ? ['code' => $code, 'message' => $message]
        : null;
}

$configPath = __DIR__ . '/openbridge.config.php';
if (!is_file($configPath)) {
    openBridgeRespond(503, ['error' => 'OpenBridge server configuration is not installed.']);
}

$config = require $configPath;
if (!is_array($config)) {
    openBridgeRespond(500, ['error' => 'OpenBridge server configuration is invalid.']);
}

function openBridgeCurrentUserId(): ?int
{
    $userId = (int) ($_SESSION['openbridge_user_id'] ?? 0);
    return $userId > 0 ? $userId : null;
}

function openBridgeUuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
}

function openBridgeBoundText(mixed $value, int $maximum): string
{
    return mb_substr(trim((string) $value), 0, $maximum, 'UTF-8');
}

function openBridgeExactText(mixed $value, int $maximum): string
{
    if (!is_string($value) || strlen($value) > $maximum) {
        openBridgeRespond(422, ['error' => 'Connection fields must be strings within their size limit.']);
    }
    return $value;
}

function openBridgeDatabaseRequired(?PDO $pdo): PDO
{
    if ($pdo === null) {
        openBridgeRespond(503, ['error' => 'OpenBridge SQLite storage is unavailable. Check PHP pdo_sqlite and /db write permissions.']);
    }
    return $pdo;
}

function openBridgeRequireAdminUserId(array $api, ?PDO $pdo): int
{
    if ($pdo === null || !openBridgeAdminAuthenticated($api, $pdo)) {
        openBridgeRespond(401, ['error' => 'A signed-in OpenBridge administrator is required.']);
    }
    $userId = openBridgeCurrentUserId();
    if ($userId === null) {
        openBridgeRespond(401, ['error' => 'A signed-in OpenBridge administrator is required.']);
    }
    return $userId;
}

function openBridgePublicActionAllowedForToken(string $action): bool
{
    return in_array($action, ['health', 'playground', 'schema_explore', 'transport_test'], true);
}

function openBridgeNonSecretSettings(array $settings): array
{
    $allowed = [
        'backendUrl', 'serverUrl', 'database', 'defaultLayout', 'transport', 'logCapacity',
        'storeResponses', 'responseRetentionDays', 'theme', 'connectionName', 'primaryConnectorId',
    ];
    $clean = [];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $settings)) {
            $clean[$key] = $settings[$key];
        }
    }
    return $clean;
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/connections.php';
require_once __DIR__ . '/transactions.php';
$databaseSettings = openBridgeDatabaseConfig($config);
$databasePdo = openBridgeTryDatabasePdo($config);
if (!empty($databaseSettings['enabled']) && $databasePdo === null) {
    openBridgeRespond(503, ['error' => 'OpenBridge SQLite storage could not start. Check PHP pdo_sqlite and /db write permissions.']);
}
$keyProvider = new OpenBridgeKeyProvider($config, __DIR__);
$openBridgeCrypto = new OpenBridgeCrypto($keyProvider);
if ($databasePdo !== null) {
    try {
        $openBridgeCrypto->assertStorageKey($databasePdo);
    } catch (Throwable) {
        openBridgeRespond(503, ['error' => 'OpenBridge cannot unlock its protected storage. Restore the matching installation key before continuing.']);
    }
}
$repository = $databasePdo === null ? null : new OpenBridgeRepository($databasePdo, $openBridgeCrypto);
$connectionRepository = $databasePdo === null ? null : new OpenBridgeConnectionRepository($databasePdo, $openBridgeCrypto);
$transactionRepository = $databasePdo === null ? null : new OpenBridgeTransactionRepository($databasePdo, $openBridgeCrypto);

$api = isset($config['api']) && is_array($config['api']) ? $config['api'] : [];
if (empty($api['enabled'])) {
    openBridgeRespond(503, ['error' => 'The OpenBridge health endpoint is disabled.']);
}

$configuredOrigins = $api['allowedOrigins'] ?? ($api['allowedOrigin'] ?? '');
$allowedOrigins = is_array($configuredOrigins) ? $configuredOrigins : [$configuredOrigins];
$allowedOrigins = array_values(array_filter(array_map(
    static fn ($origin): string => rtrim(trim((string) $origin), '/'),
    $allowedOrigins
)));
$requestOrigin = rtrim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''), '/');
$serverOrigin = '';
$serverHost = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
if ($serverHost !== '') {
    $serverSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($api['trustProxyHttps']) && (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $serverOrigin = ($serverSecure ? 'https://' : 'http://') . $serverHost;
}
if ($requestOrigin !== '') {
    $originAllowed = false;
    foreach ($allowedOrigins as $allowedOrigin) {
        if (hash_equals($allowedOrigin, $requestOrigin)) {
            $originAllowed = true;
            break;
        }
    }
    if (!$originAllowed && $serverOrigin !== '' && hash_equals($serverOrigin, $requestOrigin)) {
        $originAllowed = true;
    }
    if (!$originAllowed) {
        openBridgeRespond(403, ['error' => 'This browser origin is not allowed.']);
    }
    header('Access-Control-Allow-Origin: ' . $requestOrigin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-OpenBridge-Token, X-OpenBridge-CSRF');
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    openBridgeRespond(405, ['error' => 'Use POST for OpenBridge API requests.']);
}

$decoded = json_decode((string) file_get_contents('php://input'), true);
$action = is_array($decoded) ? (string) ($decoded['action'] ?? '') : '';
if (!in_array($action, ['setup_status', 'register', 'login', 'logout', 'session', 'configuration', 'health', 'playground', 'transport_test', 'schema_explore', 'go_status', 'go_request', 'storage_load', 'storage_save', 'vault_status', 'vault_save', 'vault_clear', 'connections_list', 'connection_delete', 'workspace_list', 'workspace_load', 'workspace_save', 'workspace_duplicate', 'workspace_archive', 'transactions_list', 'transaction_record', 'transaction_payload', 'transaction_delete', 'transactions_cleanup', 'transactions_secure_legacy', 'metadata_list', 'metadata_save', 'metrics_summary', 'metric_event', 'errors_list', 'database_diagnostics'], true)) {
    openBridgeRespond(400, ['error' => 'Unsupported OpenBridge action.']);
}

openBridgeStartAdminSession(
    $databasePdo,
    isset($api['adminSessionSeconds']) && is_numeric($api['adminSessionSeconds'])
        ? max(300, min(86400, (int) $api['adminSessionSeconds']))
        : 3600,
    $openBridgeCrypto,
    !empty($api['trustProxyHttps'])
);
$admin = isset($config['admin']) && is_array($config['admin']) ? $config['admin'] : [];

if ($action === 'setup_status') {
    if ($databasePdo === null) {
        openBridgeRespond(503, ['databaseReady' => false, 'error' => 'SQLite storage is not ready.']);
    }
    $hasAdministrator = openBridgeDatabaseHasConfiguredAdministrator($databasePdo);
    openBridgeRespond(200, [
        'databaseReady' => true,
        'requiresSetup' => !$hasAdministrator,
        'demoAvailable' => openBridgeDatabaseFindUser($databasePdo, 'demo') !== null,
        'passwordMinimumLength' => 12,
    ]);
}

if ($action === 'register') {
    if ($databasePdo === null) {
        openBridgeRespond(503, ['error' => 'SQLite storage is not ready.']);
    }
    if (openBridgeDatabaseHasConfiguredAdministrator($databasePdo)) {
        openBridgeRespond(403, ['error' => 'An OpenBridge administrator already exists. Sign in instead.']);
    }
    $username = trim((string) ($decoded['username'] ?? ''));
    $email = strtolower(trim((string) ($decoded['email'] ?? '')));
    $password = (string) ($decoded['password'] ?? '');
    $displayName = trim((string) ($decoded['displayName'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{2,63}$/', $username)) {
        openBridgeRespond(422, ['error' => 'Choose a username with 3–64 letters, numbers, dots, dashes, or underscores.']);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        openBridgeRespond(422, ['error' => 'Enter a valid email address.']);
    }
    if (strlen($password) < 12) {
        openBridgeRespond(422, ['error' => 'Choose a password with at least 12 characters.']);
    }
    if (strlen($password) > 72) {
        openBridgeRespond(422, ['error' => 'Choose a password with 72 bytes or fewer.']);
    }
    try {
        $databasePdo->exec('BEGIN IMMEDIATE');
        if (openBridgeDatabaseHasConfiguredAdministrator($databasePdo)) {
            $databasePdo->exec('ROLLBACK');
            openBridgeRespond(403, ['error' => 'An administrator already exists. Sign in instead.']);
        }
        $user = openBridgeDatabaseCreateUser($databasePdo, $username, $email, $password, $displayName);
        $databasePdo->exec('COMMIT');
    } catch (Throwable) {
        try { $databasePdo->exec('ROLLBACK'); } catch (Throwable) {}
        openBridgeRespond(409, ['error' => 'The administrator could not be created. Check account details and storage.']);
    }
    session_regenerate_id(true);
    $_SESSION = [
        'openbridge_authenticated' => true,
        'openbridge_authenticated_at' => time(),
        'openbridge_user_id' => (int) $user['id'],
        'openbridge_username' => (string) $user['username'],
    ];
    $csrfToken = OpenBridgeCsrf::token();
    session_write_close();
    openBridgeDatabaseAttachSession(
        $databasePdo,
        session_id(),
        (int) $user['id'],
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
    );
    openBridgeRespond(201, ['authenticated' => true, 'csrfToken' => $csrfToken, 'user' => ['username' => $user['username'], 'email' => $user['email']]]);
}

if ($action === 'login') {
    $now = time();
    $windowStarted = (int) ($_SESSION['openbridge_login_window'] ?? 0);
    if ($windowStarted === 0 || ($now - $windowStarted) > 300) {
        $_SESSION['openbridge_login_window'] = $now;
        $_SESSION['openbridge_login_attempts'] = 0;
    }
    $username = isset($decoded['username']) ? trim((string) $decoded['username']) : '';
    $password = isset($decoded['password']) ? (string) $decoded['password'] : '';
    if (strlen($password) > 72) {
        openBridgeRespond(422, ['error' => 'Administrator passwords must be 72 bytes or fewer.']);
    }
    $attempts = (int) ($_SESSION['openbridge_login_attempts'] ?? 0);
    if ($databasePdo !== null) {
        $identifier = strtolower($username) . '|' . (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if (!openBridgeDatabaseLoginAttempt($databasePdo, $identifier)) {
            openBridgeRespond(429, ['error' => 'Too many login attempts. Wait five minutes and try again.']);
        }
    }
    $user = $databasePdo !== null ? openBridgeDatabaseFindUser($databasePdo, $username) : null;
    $valid = $user !== null
        && !empty($user['is_active'])
        && $user['role'] === 'admin'
        && password_verify($password, (string) $user['password_hash']);
    if ($databasePdo === null) {
        $configuredUsername = trim((string) ($admin['username'] ?? OPENBRIDGE_DEMO_ADMIN_USERNAME));
        $passwordHash = trim((string) ($admin['passwordHash'] ?? OPENBRIDGE_DEMO_ADMIN_PASSWORD_HASH));
        $plainPassword = (string) ($admin['password'] ?? '');
        $valid = $configuredUsername !== ''
            && hash_equals($configuredUsername, $username)
            && (
                ($plainPassword !== '' && hash_equals($plainPassword, $password))
                || ($passwordHash !== '' && password_verify($password, $passwordHash))
            );
        $user = $valid ? ['id' => 0, 'username' => $configuredUsername] : null;
    }

    if (!$valid) {
        if ($attempts >= 5) {
            openBridgeRespond(429, ['error' => 'Too many login attempts. Wait five minutes and try again.']);
        }
        $_SESSION['openbridge_login_attempts'] = $attempts + 1;
        openBridgeRespond(401, ['error' => 'Invalid administrator credentials.']);
    }

    session_regenerate_id(true);
    $_SESSION = [
        'openbridge_authenticated' => true,
        'openbridge_authenticated_at' => $now,
        'openbridge_user_id' => (int) ($user['id'] ?? 0),
        'openbridge_username' => (string) ($user['username'] ?? ''),
    ];
    $csrfToken = OpenBridgeCsrf::token();
    if ($databasePdo !== null && !empty($user['id'])) {
        session_write_close();
        openBridgeDatabaseAttachSession(
            $databasePdo,
            session_id(),
            (int) $user['id'],
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
        );
        openBridgeDatabaseTouchLogin($databasePdo, (int) $user['id']);
    }
    openBridgeRespond(200, ['authenticated' => true, 'csrfToken' => $csrfToken]);
}

if ($action === 'session') {
    $authenticated = openBridgeAdminAuthenticated($api, $databasePdo);
    if (!$authenticated) {
        $_SESSION = [];
    }
    openBridgeRespond(200, [
        'authenticated' => $authenticated,
        'csrfToken' => $authenticated ? OpenBridgeCsrf::token() : null,
    ]);
}

if ($action === 'logout') {
    if (openBridgeAdminAuthenticated($api, $databasePdo) && !OpenBridgeCsrf::validate((string) ($_SERVER['HTTP_X_OPENBRIDGE_CSRF'] ?? ''))) {
        openBridgeRespond(403, ['error' => 'The OpenBridge security token is missing or expired. Refresh and try again.']);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Strict',
        ]);
    }
    session_destroy();
    openBridgeRespond(200, ['authenticated' => false, 'cleared' => true]);
}

$configuredApiToken = trim((string) ($api['apiToken'] ?? ''));
$providedApiToken = trim((string) ($_SERVER['HTTP_X_OPENBRIDGE_TOKEN'] ?? ''));
$tokenAuthorized = $configuredApiToken !== ''
    && $providedApiToken !== ''
    && hash_equals($configuredApiToken, $providedApiToken);
$sessionAuthorized = openBridgeAdminAuthenticated($api, $databasePdo);
if (!$sessionAuthorized && $tokenAuthorized && !openBridgePublicActionAllowedForToken($action)) {
    openBridgeRespond(403, ['error' => 'The bridge token is limited to FileMaker read-only API actions.']);
}
if (!$sessionAuthorized && !$tokenAuthorized) {
    openBridgeRespond(401, ['error' => 'A valid administrator session or X-OpenBridge-Token is required.']);
}

$csrfProtectedActions = [
    'storage_save', 'vault_save', 'vault_clear', 'workspace_save', 'workspace_duplicate', 'workspace_archive',
    'transaction_record', 'transactions_cleanup', 'transaction_delete', 'transactions_secure_legacy', 'connection_delete', 'metadata_save', 'metric_event', 'go_request', 'playground',
];
if ($sessionAuthorized && in_array($action, $csrfProtectedActions, true)
    && !OpenBridgeCsrf::validate((string) ($_SERVER['HTTP_X_OPENBRIDGE_CSRF'] ?? ''))) {
    openBridgeRespond(403, ['error' => 'The OpenBridge security token is missing or expired. Refresh and try again.']);
}

if ($action === 'vault_status') {
    $pdo = openBridgeDatabaseRequired($databasePdo);
    $userId = openBridgeRequireAdminUserId($api, $pdo);
    if ($userId === null || $repository === null) openBridgeRespond(401, ['error' => 'A browser administrator session is required.']);
    openBridgeRespond(200, [
        'configured' => $repository->hasSecret($userId, 'filemaker_connection'),
        'security' => $keyProvider->status(),
    ]);
}

if ($action === 'vault_save') {
    $pdo = openBridgeDatabaseRequired($databasePdo);
    $userId = openBridgeRequireAdminUserId($api, $pdo);
    if ($repository === null || $connectionRepository === null) openBridgeRespond(503, ['error' => 'Secure credential storage is unavailable.']);
    $profile = isset($decoded['profile']) && is_array($decoded['profile']) ? $decoded['profile'] : [];
    try { $existing = $repository->loadSecret($userId, 'filemaker_connection') ?? []; }
    catch (Throwable) { openBridgeRespond(503, ['error' => 'Stored credentials cannot be decrypted. Restore the matching installation key before saving.']); }
    $password = (string) ($profile['password'] ?? '');
    if ($password === '') $password = (string) ($existing['password'] ?? '');
    $clean = [
        'host' => openBridgeBoundText($profile['host'] ?? ($existing['host'] ?? ''), 2048),
        'database' => openBridgeExactText($profile['database'] ?? ($existing['database'] ?? ''), 255),
        'layout' => openBridgeExactText($profile['layout'] ?? ($existing['layout'] ?? ''), 255),
        'table' => openBridgeExactText($profile['table'] ?? ($existing['table'] ?? ''), 255),
        'username' => openBridgeExactText($profile['username'] ?? ($existing['username'] ?? ''), 255),
        'password' => $password,
        'transport' => ($profile['transport'] ?? ($existing['transport'] ?? 'data_api')) === 'odata' ? 'odata' : 'data_api',
    ];
    if ($clean['host'] === '' || $clean['database'] === '' || $clean['username'] === '' || $clean['password'] === '') {
        openBridgeRespond(422, ['error' => 'Secure FileMaker storage requires host, database, username, and password.']);
    }
    try {
        $saved = $connectionRepository->saveFileMaker($userId, $clean);
        openBridgeRespond(200, $saved + ['configured' => true, 'security' => $keyProvider->status()]);
    } catch (Throwable $error) {
        openBridgeDatabaseLogError($pdo, 'credential_vault', 'Credential encryption failed.', ['type' => get_class($error)], $userId);
        openBridgeRespond(503, ['error' => 'Secure credential storage is unavailable. Check PHP OpenSSL/sodium and private-folder permissions.']);
    }
}

if ($action === 'vault_clear') {
    $pdo = openBridgeDatabaseRequired($databasePdo);
    $userId = openBridgeRequireAdminUserId($api, $pdo);
    if ($userId === null || $repository === null) openBridgeRespond(401, ['error' => 'A browser administrator session is required.']);
    $repository->deleteSecret($userId, 'filemaker_connection');
    openBridgeRespond(200, ['cleared' => true, 'configured' => false]);
}

if ($action === 'connections_list') {
    $pdo = openBridgeDatabaseRequired($databasePdo);
    $userId = openBridgeRequireAdminUserId($api, $pdo);
    if ($connectionRepository === null) openBridgeRespond(503, ['error' => 'Connection storage is unavailable.']);
    try {
        openBridgeRespond(200, ['connections' => $connectionRepository->list($userId)]);
    } catch (Throwable) {
        openBridgeRespond(503, ['error' => 'OpenBridge could not load saved connections.']);
    }
}

if ($action === 'connection_delete') {
    $pdo = openBridgeDatabaseRequired($databasePdo);
    $userId = openBridgeRequireAdminUserId($api, $pdo);
    if ($connectionRepository === null) openBridgeRespond(503, ['error' => 'Connection storage is unavailable.']);
    $id = trim((string) ($decoded['id'] ?? ''));
    $revision = filter_var($decoded['revision'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($revision === false) openBridgeRespond(422, ['error' => 'A connection revision is required.']);
    try {
        $deleted = $connectionRepository->delete($userId, $id, (int) $revision);
        openBridgeRespond($deleted ? 200 : 404, $deleted ? ['deleted' => true] : ['error' => 'Connection not found.']);
    } catch (InvalidArgumentException) {
        openBridgeRespond(422, ['error' => 'Connection delete request is invalid.']);
    } catch (RuntimeException) {
        openBridgeRespond(409, ['error' => 'Connection changed. Reload before deleting.']);
    } catch (Throwable) {
        openBridgeRespond(503, ['error' => 'OpenBridge could not delete the connection.']);
    }
}

if ($action === 'storage_load') {
    $pdo = openBridgeDatabaseRequired($databasePdo);
    $userId = openBridgeRequireAdminUserId($api, $pdo);
    $statement = $pdo->prepare('SELECT setting_value FROM openbridge_settings WHERE user_id = :user_id AND setting_key = :key');
    $statement->execute(['user_id' => $userId, 'key' => 'admin_settings']);
    $value = $statement->fetchColumn();
    $settings = is_string($value) ? json_decode($value, true) : [];
    openBridgeRespond(200, ['settings' => is_array($settings) ? $settings : []]);
}

if ($action === 'database_diagnostics') {
    $pdo = openBridgeDatabaseRequired($databasePdo);
    $userId = openBridgeRequireAdminUserId($api, $pdo);
    try {
        openBridgeRespond(200, ['diagnostics' => openBridgeDatabaseDiagnostics($pdo, $config)]);
    } catch (Throwable $error) {
        openBridgeDatabaseLogError($pdo, 'database_diagnostics', 'SQLite diagnostics failed.', ['type' => get_class($error)], $userId);
        openBridgeRespond(500, ['error' => 'SQLite diagnostics failed. Review the protected server error log.']);
    }
}

if ($action === 'storage_save') {
    $pdo = openBridgeDatabaseRequired($databasePdo);
    $userId = openBridgeRequireAdminUserId($api, $pdo);
    $settings = isset($decoded['settings']) && is_array($decoded['settings']) ? $decoded['settings'] : null;
    if ($settings === null) {
        openBridgeRespond(422, ['error' => 'A settings object is required.']);
    }
    $settings = openBridgeNonSecretSettings($settings);
    $statement = $pdo->prepare(
        'INSERT INTO openbridge_settings (user_id, setting_key, setting_value, is_secret)
         VALUES (:user_id, :key, :value, 0)
         ON CONFLICT (user_id, setting_key) DO UPDATE
           SET setting_value = EXCLUDED.setting_value, updated_at = CURRENT_TIMESTAMP'
    );
    $statement->execute([
        'user_id' => $userId,
        'key' => 'admin_settings',
        'value' => openBridgeDatabaseJson($settings) ?? '{}',
    ]);
    openBridgeRespond(200, ['saved' => true]);
}

if ($action === 'workspace_list') {
    $pdo = openBridgeDatabaseRequired($databasePdo);
    $userId = openBridgeRequireAdminUserId($api, $pdo);
    $statement = $pdo->prepare('SELECT id, name, revision, updated_at FROM openbridge_workspaces WHERE user_id = :user_id AND archived_at IS NULL ORDER BY updated_at DESC LIMIT 200');
    $statement->execute(['user_id' => $userId]);
    openBridgeRespond(200, ['workspaces' => $statement->fetchAll()]);
}

if ($action === 'workspace_load') {
    $pdo = openBridgeDatabaseRequired($databasePdo);
    $userId = openBridgeRequireAdminUserId($api, $pdo);
    $id = trim((string) ($decoded['id'] ?? ''));
    if (!preg_match('/^[0-9a-f-]{36}$/i', $id)) openBridgeRespond(422, ['error' => 'A valid workspace ID is required.']);
    $statement = $pdo->prepare('SELECT id, name, language, transport, comment_mode, favorites, manual_draft, revision, updated_at FROM openbridge_workspaces WHERE id = :id AND user_id = :user_id AND archived_at IS NULL');
    $statement->execute(['id' => $id, 'user_id' => $userId]);
    $workspace = $statement->fetch();
    if (!is_array($workspace)) openBridgeRespond(404, ['error' => 'Workspace not found.']);
    $blocks = $pdo->prepare('SELECT block_id, module_id, module_version, inputs, collapsed FROM openbridge_workspace_blocks WHERE workspace_id = :id ORDER BY position');
    $blocks->execute(['id' => $id]);
    $workspace['commentMode'] = $workspace['comment_mode'];
    $workspace['manualDraft'] = $workspace['manual_draft'];
    $workspace['updatedAt'] = $workspace['updated_at'];
    $workspace['favorites'] = json_decode((string) $workspace['favorites'], true) ?: [];
    $workspace['revision'] = (int) $workspace['revision'];
    $workspace['blocks'] = array_map(static function (array $block): array {
        return ['blockId' => $block['block_id'], 'moduleId' => $block['module_id'], 'moduleVersion' => (int) $block['module_version'], 'inputs' => json_decode((string) $block['inputs'], true) ?: [], 'collapsed' => (bool) $block['collapsed']];
    }, $blocks->fetchAll());
    unset($workspace['comment_mode'], $workspace['manual_draft'], $workspace['updated_at']);
    openBridgeRespond(200, ['workspace' => $workspace]);
}

if ($action === 'workspace_save' || $action === 'workspace_duplicate') {
    $pdo = openBridgeDatabaseRequired($databasePdo);
    $userId = openBridgeRequireAdminUserId($api, $pdo);
    $workspace = isset($decoded['workspace']) && is_array($decoded['workspace']) ? $decoded['workspace'] : null;
    if ($workspace === null) openBridgeRespond(422, ['error' => 'A workspace is required.']);
    $name = trim((string) ($workspace['name'] ?? 'Untitled workspace'));
    $language = (string) ($workspace['language'] ?? 'php');
    $transport = (string) ($workspace['transport'] ?? 'data-api');
    $commentMode = (string) ($workspace['commentMode'] ?? 'inline');
    $blocks = isset($workspace['blocks']) && is_array($workspace['blocks']) ? $workspace['blocks'] : [];
    $manualDraft = isset($workspace['manualDraft']) ? (string) $workspace['manualDraft'] : null;
    if ($name === '' || strlen($name) > 120 || !in_array($language, ['php', 'javascript'], true) || !in_array($transport, ['data-api', 'odata'], true) || !in_array($commentMode, ['concise', 'inline', 'teaching', 'advanced'], true) || count($blocks) > 200 || ($manualDraft !== null && strlen($manualDraft) > 2000000)) openBridgeRespond(422, ['error' => 'Workspace fields exceed the allowed format or limits.']);
    $requestedId = (string) ($workspace['id'] ?? '');
    $isDuplicate = $action === 'workspace_duplicate';
    $id = !$isDuplicate && preg_match('/^[0-9a-f-]{36}$/i', $requestedId) ? $requestedId : openBridgeUuid();
    $revision = max(1, (int) ($workspace['revision'] ?? 1));
    $pdo->beginTransaction();
    try {
        $existing = $pdo->prepare('SELECT revision FROM openbridge_workspaces WHERE id = :id AND user_id = :user_id');
        $existing->execute(['id' => $id, 'user_id' => $userId]);
        $currentRevision = $existing->fetchColumn();
        if ($currentRevision !== false && (int) $currentRevision !== $revision) throw new RuntimeException('Workspace changed elsewhere. Reload it before saving.');
        $nextRevision = $currentRevision === false ? 1 : ((int) $currentRevision + 1);
        $save = $pdo->prepare('INSERT INTO openbridge_workspaces (id, user_id, name, language, transport, comment_mode, favorites, manual_draft, revision) VALUES (:id, :user_id, :name, :language, :transport, :comment_mode, :favorites, :manual_draft, :revision) ON CONFLICT (id) DO UPDATE SET name = excluded.name, language = excluded.language, transport = excluded.transport, comment_mode = excluded.comment_mode, favorites = excluded.favorites, manual_draft = excluded.manual_draft, revision = excluded.revision, updated_at = CURRENT_TIMESTAMP WHERE openbridge_workspaces.user_id = excluded.user_id');
        $save->execute(['id' => $id, 'user_id' => $userId, 'name' => $isDuplicate ? $name . ' copy' : $name, 'language' => $language, 'transport' => $transport, 'comment_mode' => $commentMode, 'favorites' => openBridgeDatabaseJson(array_slice(array_values((array) ($workspace['favorites'] ?? [])), 0, 200)) ?? '[]', 'manual_draft' => $manualDraft, 'revision' => $nextRevision]);
        $pdo->prepare('DELETE FROM openbridge_workspace_blocks WHERE workspace_id = :id')->execute(['id' => $id]);
        $insert = $pdo->prepare('INSERT INTO openbridge_workspace_blocks (workspace_id, block_id, position, module_id, module_version, inputs, collapsed) VALUES (:workspace_id, :block_id, :position, :module_id, :module_version, :inputs, :collapsed)');
        foreach ($blocks as $position => $block) {
            if (!is_array($block)) continue;
            $blockId = openBridgeBoundText($block['blockId'] ?? '', 120);
            $moduleId = openBridgeBoundText($block['moduleId'] ?? '', 120);
            if ($blockId === '' || $moduleId === '') throw new RuntimeException('Every workspace block needs a blockId and moduleId.');
            $insert->execute(['workspace_id' => $id, 'block_id' => $blockId, 'position' => $position, 'module_id' => $moduleId, 'module_version' => max(1, (int) ($block['moduleVersion'] ?? 1)), 'inputs' => openBridgeDatabaseJson(isset($block['inputs']) && is_array($block['inputs']) ? $block['inputs'] : []) ?? '{}', 'collapsed' => !empty($block['collapsed']) ? 1 : 0]);
        }
        $pdo->commit();
        openBridgeRespond($currentRevision === false ? 201 : 200, ['id' => $id, 'revision' => $nextRevision, 'saved' => true]);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        openBridgeRespond($error instanceof RuntimeException ? 409 : 500, ['error' => $error instanceof RuntimeException ? $error->getMessage() : 'OpenBridge could not save the workspace.']);
    }
}

if ($action === 'workspace_archive') {
    $pdo = openBridgeDatabaseRequired($databasePdo);
    $userId = openBridgeRequireAdminUserId($api, $pdo);
    $id = trim((string) ($decoded['id'] ?? ''));
    if (!preg_match('/^[0-9a-f-]{36}$/i', $id)) openBridgeRespond(422, ['error' => 'A valid workspace ID is required.']);
    $statement = $pdo->prepare('UPDATE openbridge_workspaces SET archived_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :user_id AND archived_at IS NULL');
    $statement->execute(['id' => $id, 'user_id' => $userId]);
    openBridgeRespond($statement->rowCount() ? 200 : 404, $statement->rowCount() ? ['archived' => true] : ['error' => 'Workspace not found.']);
}

if ($action === 'transactions_list') {
    $pdo = openBridgeDatabaseRequired($databasePdo);
    $userId = openBridgeRequireAdminUserId($api, $pdo);
    if ($transactionRepository === null) openBridgeRespond(503, ['error' => 'Transaction storage is unavailable.']);
    $limit = max(1, min(500, (int) ($decoded['limit'] ?? 100)));
    try {
        openBridgeRespond(200, ['transactions' => $transactionRepository->list($userId, $limit)]);
    } catch (Throwable) {
        openBridgeRespond(503, ['error' => 'OpenBridge could not load transaction history.']);
    }
}

if ($action === 'transaction_record') {
    $pdo = openBridgeDatabaseRequired($databasePdo);
    $userId = openBridgeRequireAdminUserId($api, $pdo);
    if ($transactionRepository === null) openBridgeRespond(503, ['error' => 'Transaction storage is unavailable.']);
    $input = is_array($decoded) ? $decoded : [];
    unset($input['id']);
    try {
        $result = $transactionRepository->record($userId, $input);
        $retentionDays = max(1, min(3650, (int) ($config['security']['transactionRetentionDays'] ?? 30)));
        if (random_int(1, 100) <= 5) $transactionRepository->cleanup($userId, $retentionDays);
        openBridgeRespond(201, $result + ['payloadEncryption' => !empty($result['payloadStored']) ? 'server-authenticated' : null]);
    } catch (LengthException) {
        openBridgeRespond(413, ['error' => 'A transaction field or retained body exceeds its size limit.']);
    } catch (InvalidArgumentException) {
        openBridgeRespond(422, ['error' => 'Transaction fields are invalid.']);
    } catch (Throwable $error) {
        openBridgeDatabaseLogError($pdo, 'transaction_record', 'Transaction encryption or storage failed.', ['type' => get_class($error)], $userId);
        openBridgeRespond(503, ['error' => 'OpenBridge could not securely retain this transaction. Only metadata should be retried.']);
    }
}

if ($action === 'transaction_payload') {
    $pdo = openBridgeDatabaseRequired($databasePdo);
    $userId = openBridgeCurrentUserId();
    $id = trim((string) ($decoded['id'] ?? ''));
    if ($userId === null || $repository === null || !preg_match('/^[0-9a-f-]{36}$/i', $id)) {
        openBridgeRespond(422, ['error' => 'A valid transaction ID and browser administrator session are required.']);
    }
    try {
        $payload = $repository->loadTransactionPayload($id, $userId);
        if ($payload === null) openBridgeRespond(404, ['error' => 'No encrypted payload is retained for this transaction.']);
        openBridgeRespond(200, ['id' => $id, 'payload' => $payload]);
    } catch (Throwable $error) {
        openBridgeDatabaseLogError($pdo, 'transaction_payload', 'Transaction payload decryption failed.', [], $userId, $id);
        openBridgeRespond(409, ['error' => 'This payload could not be authenticated or decrypted with the current installation key.']);
    }
}

if ($action === 'transactions_cleanup') {
    $pdo = openBridgeDatabaseRequired($databasePdo);
    $userId = openBridgeRequireAdminUserId($api, $pdo);
    if ($transactionRepository === null) openBridgeRespond(503, ['error' => 'Transaction storage is unavailable.']);
    $days = max(1, min(3650, (int) ($decoded['days'] ?? ($config['security']['transactionRetentionDays'] ?? 30))));
    try {
        openBridgeRespond(200, ['deleted' => $transactionRepository->cleanup($userId, $days), 'retentionDays' => $days]);
    } catch (Throwable) {
        openBridgeRespond(503, ['error' => 'OpenBridge could not clean up transaction history.']);
    }
}

if ($action === 'transaction_delete') {
    $pdo = openBridgeDatabaseRequired($databasePdo);
    $userId = openBridgeRequireAdminUserId($api, $pdo);
    if ($transactionRepository === null) openBridgeRespond(503, ['error' => 'Transaction storage is unavailable.']);
    $id = trim((string) ($decoded['id'] ?? ''));
    try {
        $deleted = $transactionRepository->delete($userId, $id);
        openBridgeRespond($deleted ? 200 : 404, $deleted ? ['deleted' => true] : ['error' => 'Transaction not found.']);
    } catch (InvalidArgumentException) {
        openBridgeRespond(422, ['error' => 'A valid transaction ID is required.']);
    } catch (Throwable) {
        openBridgeRespond(503, ['error' => 'OpenBridge could not delete the transaction.']);
    }
}

if ($action === 'transactions_secure_legacy') {
    $pdo = openBridgeDatabaseRequired($databasePdo);
    $userId = openBridgeRequireAdminUserId($api, $pdo);
    if ($transactionRepository === null) openBridgeRespond(503, ['error' => 'Transaction storage is unavailable.']);
    $limit = max(1, min(500, (int) ($decoded['limit'] ?? 100)));
    try {
        openBridgeRespond(200, ['secured' => $transactionRepository->migrateLegacy($userId, $limit)]);
    } catch (Throwable) {
        openBridgeRespond(503, ['error' => 'OpenBridge could not secure legacy transaction rows.']);
    }
}

if ($action === 'metadata_list') {
    $pdo = openBridgeDatabaseRequired($databasePdo);
    $userId = openBridgeCurrentUserId();
    $statement = $pdo->prepare('SELECT meta_key, meta_value, source, updated_at FROM openbridge_metadata WHERE (user_id = :user_id OR (user_id IS NULL AND :user_id IS NULL)) ORDER BY meta_key');
    $statement->execute(['user_id' => $userId]);
    openBridgeRespond(200, ['metadata' => $statement->fetchAll()]);
}

if ($action === 'metadata_save') {
    $pdo = openBridgeDatabaseRequired($databasePdo);
    $userId = openBridgeCurrentUserId();
    $key = openBridgeBoundText($decoded['key'] ?? '', 120);
    $value = $decoded['value'] ?? null;
    if ($key === '' || $value === null) {
        openBridgeRespond(422, ['error' => 'Metadata requires a key and JSON-compatible value.']);
    }
    $statement = $pdo->prepare(
        'INSERT INTO openbridge_metadata (user_id, meta_key, meta_value, source)
         VALUES (:user_id, :key, :value, :source)
         ON CONFLICT (user_id, meta_key) DO UPDATE
            SET meta_value = EXCLUDED.meta_value, source = EXCLUDED.source, updated_at = CURRENT_TIMESTAMP'
    );
    $statement->execute([
        'user_id' => $userId,
        'key' => $key,
        'value' => openBridgeDatabaseJson($value) ?? '{}',
        'source' => openBridgeBoundText($decoded['source'] ?? 'dashboard', 120),
    ]);
    openBridgeRespond(200, ['saved' => true]);
}

if ($action === 'metric_event') {
    if ($databasePdo === null) {
        openBridgeRespond(202, ['recorded' => false, 'skipped' => true, 'reason' => 'SQLite storage is not available.']);
    }
    $pdo = openBridgeDatabaseRequired($databasePdo);
    $eventName = openBridgeBoundText($decoded['event'] ?? '', 120);
    if ($eventName === '') {
        openBridgeRespond(422, ['error' => 'A metric event name is required.']);
    }
    $context = isset($decoded['context']) && is_array($decoded['context']) ? $decoded['context'] : [];
    unset($context['email'], $context['password'], $context['token'], $context['secret']);
    $statement = $pdo->prepare('INSERT INTO openbridge_metric_events (user_id, event_name, event_value, context) VALUES (:user_id, :event_name, :event_value, :context)');
    $statement->execute([
        'user_id' => openBridgeCurrentUserId(),
        'event_name' => $eventName,
        'event_value' => isset($decoded['value']) ? (int) $decoded['value'] : null,
        'context' => openBridgeDatabaseJson($context) ?? '{}',
    ]);
    openBridgeRespond(202, ['recorded' => true]);
}

if ($action === 'metrics_summary') {
    $pdo = openBridgeDatabaseRequired($databasePdo);
    $summary = $pdo->query(
        "SELECT
            (SELECT COUNT(*) FROM openbridge_users WHERE is_active = 1) AS active_users,
            (SELECT COUNT(*) FROM openbridge_transactions) AS transactions,
            (SELECT COALESCE(SUM(length(COALESCE(ciphertext, ''))), 0) FROM openbridge_transaction_payloads) AS payload_bytes,
            (SELECT COUNT(*) FROM openbridge_metric_events WHERE created_at >= datetime('now', '-30 days')) AS recent_events"
    )->fetch();
    openBridgeRespond(200, ['metrics' => is_array($summary) ? $summary : []]);
}

if ($action === 'errors_list') {
    $pdo = openBridgeDatabaseRequired($databasePdo);
    $statement = $pdo->query('SELECT severity, source, error_code, message, created_at FROM openbridge_error_logs ORDER BY created_at DESC LIMIT 200');
    openBridgeRespond(200, ['errors' => $statement->fetchAll()]);
}

if ($action === 'configuration') {
    $fileMakerConfig = isset($config['filemaker']) && is_array($config['filemaker']) ? $config['filemaker'] : [];
    $userId = openBridgeCurrentUserId();
    if ($userId !== null && $repository !== null) {
        try {
            $vaultConfig = $repository->loadSecret($userId, 'filemaker_connection');
            if (is_array($vaultConfig)) $fileMakerConfig = array_merge($fileMakerConfig, $vaultConfig);
        } catch (Throwable $error) {
            openBridgeDatabaseLogError(openBridgeDatabaseRequired($databasePdo), 'credential_vault', 'Credential decryption failed.', [], $userId);
            openBridgeRespond(503, ['error' => 'Stored FileMaker credentials cannot be decrypted. Restore the matching installation key.']);
        }
    }
    openBridgeRespond(200, [
        'serverUrl' => (string) ($fileMakerConfig['host'] ?? ''),
        'database' => (string) ($fileMakerConfig['database'] ?? ''),
        'layout' => (string) ($fileMakerConfig['layout'] ?? ''),
        'table' => (string) ($fileMakerConfig['table'] ?? ''),
        'transport' => (string) ($fileMakerConfig['transport'] ?? 'data_api'),
        'credentialsStored' => $userId !== null && $repository !== null && $repository->hasSecret($userId, 'filemaker_connection'),
        'configured' => !empty($fileMakerConfig['host'])
            && !empty($fileMakerConfig['database'])
            && (($fileMakerConfig['transport'] ?? 'data_api') === 'odata' ? !empty($fileMakerConfig['table']) : !empty($fileMakerConfig['layout'])),
    ]);
}

$go = isset($config['go']) && is_array($config['go']) ? $config['go'] : [];
if ($action === 'go_status') {
    $enabled = !empty($go['enabled']);
    $baseUrl = rtrim((string) ($go['baseUrl'] ?? ''), '/');
    if (!$enabled || $baseUrl === '') {
        openBridgeRespond(200, [
            'configured' => false,
            'reachable' => false,
            'authenticated' => false,
            'message' => 'Go backend is installed as a preview but is not enabled in PHP configuration.',
        ]);
    }
    $health = openBridgeGoHttp($baseUrl . '/health');
    $token = $health['ok'] ? openBridgeGoToken($go) : null;
    $authenticated = false;
    if ($token !== null) {
        $verification = openBridgeGoHttp($baseUrl . '/api/demo', 'GET', null, ['Authorization: Bearer ' . $token]);
        if (!$verification['ok'] && $verification['status'] === 401) {
            unset($_SESSION['openbridge_go_token']);
            $token = openBridgeGoToken($go);
            $verification = $token === null
                ? ['ok' => false]
                : openBridgeGoHttp($baseUrl . '/api/demo', 'GET', null, ['Authorization: Bearer ' . $token]);
        }
        $authenticated = !empty($verification['ok']);
    }
    openBridgeRespond(200, [
        'configured' => true,
        'reachable' => $health['ok'],
        'authenticated' => $authenticated,
        'service' => (string) (($health['body']['service'] ?? '') ?: 'goFM Server'),
        'message' => !$health['ok']
            ? 'Go backend could not be reached from PHP.'
            : (!$authenticated ? 'Go backend is online, but its service credentials were rejected or are missing.' : 'Go backend is online and authenticated through PHP.'),
    ]);
}

if ($action === 'go_request') {
    if (empty($go['enabled'])) {
        openBridgeRespond(503, ['error' => 'Go backend is not enabled in PHP configuration.']);
    }
    $routeName = trim((string) ($decoded['route'] ?? ''));
    $routes = isset($go['routes']) && is_array($go['routes']) ? $go['routes'] : [];
    $route = isset($routes[$routeName]) && is_array($routes[$routeName]) ? $routes[$routeName] : null;
    if ($route === null) {
        openBridgeRespond(400, ['error' => 'That Go route is not allowlisted in PHP configuration.']);
    }
    $method = strtoupper((string) ($route['method'] ?? 'POST'));
    $path = (string) ($route['path'] ?? '');
    if (!in_array($method, ['GET', 'POST'], true) || $path === '' || $path[0] !== '/') {
        openBridgeRespond(500, ['error' => 'The configured Go route is invalid.']);
    }
    $token = openBridgeGoToken($go);
    if ($token === null) {
        openBridgeRespond(502, ['error' => 'PHP could not authenticate to the Go backend.']);
    }
    $payload = isset($decoded['payload']) && is_array($decoded['payload']) ? $decoded['payload'] : [];
    $response = openBridgeGoHttp(
        rtrim((string) $go['baseUrl'], '/') . $path,
        $method,
        $method === 'GET' ? null : $payload,
        ['Authorization: Bearer ' . $token]
    );
    if (!$response['ok'] && $response['status'] === 401) {
        unset($_SESSION['openbridge_go_token']);
    }
    if (!$response['ok']) {
        openBridgeRespond(502, ['error' => 'The allowlisted Go route did not complete.', 'upstreamStatus' => $response['status']]);
    }
    openBridgeRespond(200, [
        'ok' => true,
        'route' => $routeName,
        'goResponse' => $response['body'],
    ]);
}

require_once __DIR__ . '/restCWP.php';

$fileMaker = isset($config['filemaker']) && is_array($config['filemaker']) ? $config['filemaker'] : [];
$currentUserId = openBridgeCurrentUserId();
if ($currentUserId !== null && $repository !== null) {
    try {
        $vaultFileMaker = $repository->loadSecret($currentUserId, 'filemaker_connection');
        if (is_array($vaultFileMaker)) $fileMaker = array_merge($fileMaker, $vaultFileMaker);
    } catch (Throwable $error) {
        openBridgeDatabaseLogError(openBridgeDatabaseRequired($databasePdo), 'credential_vault', 'Credential decryption failed before FileMaker request.', [], $currentUserId);
        openBridgeRespond(503, ['error' => 'Stored FileMaker credentials could not be decrypted. Check the installation key.']);
    }
}
$allowOverrides = !empty($api['allowTargetOverrides']);
$database = $allowOverrides && isset($decoded['database']) ? trim((string) $decoded['database']) : trim((string) ($fileMaker['database'] ?? ''));
$configuredLayout = trim((string) ($fileMaker['layout'] ?? ''));
$configuredTable = trim((string) ($fileMaker['table'] ?? ''));
$resource = $allowOverrides && isset($decoded['resource']) ? trim((string) $decoded['resource']) : $configuredLayout;
$transport = isset($decoded['transport']) ? (string) $decoded['transport'] : (string) ($fileMaker['transport'] ?? restCWP::TRANSPORT_DATA_API);
$options = isset($fileMaker['options']) && is_array($fileMaker['options']) ? $fileMaker['options'] : [];
$options['transport'] = $transport;

try {
    if (($decoded['action'] ?? '') === 'schema_explore') {
        $schemaTransport = $transport === restCWP::TRANSPORT_ODATA
            ? restCWP::TRANSPORT_ODATA
            : restCWP::TRANSPORT_DATA_API;
        $schemaConnection = new restCWP(
            (string) ($fileMaker['host'] ?? ''),
            $database,
            $configuredLayout,
            (string) ($fileMaker['username'] ?? ''),
            (string) ($fileMaker['password'] ?? ''),
            array_merge($options, ['transport' => $schemaTransport])
        );
        if ($schemaTransport === restCWP::TRANSPORT_ODATA) {
            $metadata = $schemaConnection->getODataMetadata(['database' => $database]);
            openBridgeRespond(200, [
                'source' => 'odata_metadata',
                'transport' => restCWP::TRANSPORT_ODATA,
                'success' => !empty($metadata['success']),
                'tables' => isset($metadata['discovered_tables']) && is_array($metadata['discovered_tables']) ? $metadata['discovered_tables'] : [],
                'metadata' => $metadata,
            ]);
        }
        openBridgeRespond(200, [
            'source' => 'data_api',
            'transport' => restCWP::TRANSPORT_DATA_API,
            'success' => true,
            'layouts' => $schemaConnection->getLayoutNames(),
            'scripts' => $schemaConnection->getScriptNames(),
            'layout_metadata' => $configuredLayout === '' ? null : $schemaConnection->getLayoutMetadata(),
        ]);
    }
    if (($decoded['action'] ?? '') === 'transport_test') {
        if (empty($api['enableTransportTest'])) {
            openBridgeRespond(403, ['error' => 'The temporary dual-transport test is disabled in PHP configuration.']);
        }

        $testStartedAt = microtime(true);
        $limit = isset($decoded['limit']) && is_numeric($decoded['limit'])
            ? max(1, min(10, (int) $decoded['limit']))
            : 3;

        $dataOptions = $options;
        $dataOptions['transport'] = restCWP::TRANSPORT_DATA_API;
        $dataConnection = new restCWP(
            (string) ($fileMaker['host'] ?? ''),
            $database,
            $configuredLayout,
            (string) ($fileMaker['username'] ?? ''),
            (string) ($fileMaker['password'] ?? ''),
            $dataOptions
        );
        $dataHealth = $dataConnection->healthCheck(restCWP::TRANSPORT_DATA_API, [
            'database' => $database,
            'layout' => $configuredLayout,
        ]);
        $dataSample = !empty($dataHealth['ok'])
            ? $dataConnection->playground('list', [
                'transport' => restCWP::TRANSPORT_DATA_API,
                'database' => $database,
                'layout' => $configuredLayout,
                'limit' => $limit,
            ])
            : ['success' => false, 'skipped' => true, 'error' => 'The Data API sample was skipped because its health check failed.'];

        $odataOptions = $options;
        $odataOptions['transport'] = restCWP::TRANSPORT_ODATA;
        $odataConnection = new restCWP(
            (string) ($fileMaker['host'] ?? ''),
            $database,
            '',
            (string) ($fileMaker['username'] ?? ''),
            (string) ($fileMaker['password'] ?? ''),
            $odataOptions
        );
        $odataMetadata = $odataConnection->getODataMetadata(['database' => $database]);
        $odataMetadataError = openBridgeODataError($odataMetadata);
        $availableTables = isset($odataMetadata['discovered_tables']) && is_array($odataMetadata['discovered_tables'])
            ? $odataMetadata['discovered_tables']
            : [];
        $requestedTable = $allowOverrides && isset($decoded['table'])
            ? trim((string) $decoded['table'])
            : $configuredTable;
        $selectedTable = $requestedTable;
        $selectionNote = '';

        if ($selectedTable === '' && !empty($availableTables)) {
            $selectedTable = (string) $availableTables[0];
            $selectionNote = 'No OData table was configured, so the first readable metadata entity set was selected for the read-only sample.';
        } elseif ($selectedTable !== '' && !empty($availableTables) && !in_array($selectedTable, $availableTables, true)) {
            $selectionNote = 'The configured OData table was not present in metadata. The health check will show the exact failure; choose one of available_tables and run again.';
        }

        $odataHealth = $odataConnection->healthCheck(restCWP::TRANSPORT_ODATA, [
            'database' => $database,
            'table' => $selectedTable,
        ]);
        $odataSample = !empty($odataMetadata['success']) && $selectedTable !== ''
            ? $odataConnection->playground('list', [
                'transport' => restCWP::TRANSPORT_ODATA,
                'database' => $database,
                'table' => $selectedTable,
                'queryOptions' => ['$top' => $limit, '$count' => 'true'],
            ])
            : ['success' => false, 'skipped' => true, 'error' => 'The OData sample was skipped because metadata or table discovery did not succeed.'];

        $dataOk = !empty($dataHealth['ok']) && !empty($dataSample['success']);
        $odataOk = !empty($odataMetadata['success']) && !empty($odataHealth['ok']) && !empty($odataSample['success']);
        openBridgeRespond(200, [
            'ok' => $dataOk && $odataOk,
            'temporary_feature' => true,
            'started_at' => gmdate('c', (int) $testStartedAt),
            'completed_at' => gmdate('c'),
            'duration_ms' => round((microtime(true) - $testStartedAt) * 1000, 1),
            'target' => [
                'host' => (string) ($fileMaker['host'] ?? ''),
                'database' => $database,
                'data_api_layout' => $configuredLayout,
                'odata_table' => $selectedTable,
            ],
            'tests' => [
                'data_api' => [
                    'ok' => $dataOk,
                    'health' => $dataHealth,
                    'sample_command' => ['operation' => 'list', 'layout' => $configuredLayout, 'limit' => $limit],
                    'sample_response' => $dataSample,
                ],
                'odata' => [
                    'ok' => $odataOk,
                    'metadata_error' => $odataMetadataError,
                    'available_tables' => $availableTables,
                    'table_selection_note' => $selectionNote,
                    'metadata' => $odataMetadata,
                    'health' => $odataHealth,
                    'sample_command' => ['operation' => 'list', 'table' => $selectedTable, '$top' => $limit, '$count' => true],
                    'sample_response' => $odataSample,
                ],
            ],
        ]);
    }

    $connection = new restCWP(
        (string) ($fileMaker['host'] ?? ''),
        $database,
        $resource,
        (string) ($fileMaker['username'] ?? ''),
        (string) ($fileMaker['password'] ?? ''),
        $options
    );
    if (($decoded['action'] ?? '') === 'health') {
        $healthOptions = $transport === restCWP::TRANSPORT_ODATA
            ? ['database' => $database, 'table' => $allowOverrides && isset($decoded['table']) ? trim((string) $decoded['table']) : $configuredTable]
            : ['database' => $database, 'layout' => $resource];
        openBridgeRespond(200, $connection->healthCheck($transport, $healthOptions));
    }

    $operation = strtolower(trim((string) ($decoded['operation'] ?? '')));
    if (!in_array($operation, ['list', 'find', 'get'], true)) {
        openBridgeRespond(400, ['error' => 'The Playground supports read-only list, find, and get operations.']);
    }
    if (isset($decoded['afterScript']) || isset($decoded['scriptName']) || isset($decoded['scriptParameter'])) {
        openBridgeRespond(400, ['error' => 'Scripts cannot run from the read-only Playground.']);
    }

    $playgroundOptions = [
        'transport' => $transport,
        'database' => $database,
        'layout' => $resource,
        'table' => $allowOverrides && isset($decoded['table']) ? trim((string) $decoded['table']) : $configuredTable,
    ];

    foreach (['recordId', 'scriptName', 'scriptParameter', 'filter', 'limit', 'offset', 'parameters', 'payload', 'queryOptions', 'afterScript'] as $key) {
        if (array_key_exists($key, $decoded)) {
            $playgroundOptions[$key] = $decoded[$key];
        }
    }

    // A top-level query array is a convenient alias for a Data API find body.
    if (!isset($playgroundOptions['payload']) && isset($decoded['query']) && is_array($decoded['query'])) {
        $playgroundOptions['payload'] = ['query' => $decoded['query']];
    }

    $result = $connection->playground($operation, $playgroundOptions);
    openBridgeRespond(200, $result);
} catch (Throwable $error) {
    openBridgeRespond(500, ['error' => 'The OpenBridge request could not be completed.', 'code' => $error->getCode()]);
}
