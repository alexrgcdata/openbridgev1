<?php

/**
 * Copy this file to openbridge.config.php on the PHP server and replace the
 * development values before deployment. Never expose this file to the browser.
 */
return [
    'api' => [
        // Explicit opt-in: the health route is disabled until this is true.
        'enabled' => false,
        // Exact browser origins allowed to call this endpoint. Add the deployed
        // admin URL in production; these loopback entries are for local Vite use.
        'allowedOrigins' => [
            'http://localhost:5173',
            'http://127.0.0.1:5173',
        ],
        // Optional external-server token. Normal dashboard calls use a PHP session.
        // Never place this token in browser source code.
        'apiToken' => '',
        // Absolute administrator session lifetime: 5 minutes to 24 hours.
        'adminSessionSeconds' => 3600,
        // Temporary admin-only route that tests Data API and OData in sequence.
        // Keep false except during a controlled connection test.
        'enableTransportTest' => false,
        // Keep false to prevent browser input from selecting other databases.
        'allowTargetOverrides' => false,
    ],
    'admin' => [
        // Create the installation owner through first-run setup instead.
        'username' => '',
        'password' => '',
        // Generate with: php -r "echo password_hash('YOUR_PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"
        // Store only the one-way hash for non-demo installs.
        'passwordHash' => '',
    ],
    'database' => [
        // Packaged SQLite system database. Relative paths resolve from /api.
        'enabled' => false,
        'path' => '../db/openbridge.sqlite',
        // Creates/updates the database automatically when OpenBridge starts.
        'autoInitialize' => true,
        'busyTimeoutMs' => 5000,
        // Explicit demo opt-in only. Keep false on real installations.
        'seedDemoAdmin' => false,
    ],
    'security' => [
        // Preferred: set OPENBRIDGE_MASTER_KEY to 32 random bytes encoded as Base64.
        // Shared-hosting fallback: OpenBridge creates this PHP-only protected file.
        'masterKey' => '',
        'masterKeyFile' => 'private/openbridge.key.php',
        'keyVersion' => 1,
        'transactionRetentionDays' => 30,
    ],
    'go' => [
        // Optional standalone Go authentication/router. Keep disabled until the
        // deployed URL and a dedicated service account are configured.
        'enabled' => false,
        'baseUrl' => '',
        'username' => '',
        'password' => '',
        // React sends only these route names. PHP owns the real paths/methods.
        'routes' => [
            'demo_event' => ['method' => 'POST', 'path' => '/api/demo'],
        ],
    ],
    'filemaker' => [
        'host' => '',
        'database' => '',
        'layout' => '',
        // OData uses a table name; it may differ from the Data API layout.
        'table' => '',
        'username' => '',
        'password' => '',
        'transport' => 'data_api',
        'options' => [
            'allowInsecure' => false,
            'tokenStorage' => 'session',
            'timeout' => 15,
        ],
    ],
];
