<?php

declare(strict_types=1);

/** Create or update the packaged OpenBridge SQLite database. */
require_once __DIR__ . '/database.php';
$configPath = __DIR__ . '/openbridge.config.php';
$config = is_file($configPath) ? require $configPath : [];
if (!is_array($config)) throw new RuntimeException('OpenBridge configuration must return an array.');
$pdo = openBridgeDatabasePdo($config);
$settings = openBridgeDatabaseConfig($config);
openBridgeDatabaseInitialize($pdo, $settings['seedDemoAdmin']);
fwrite(STDOUT, "OpenBridge SQLite database is ready at {$settings['path']}.\n");
fwrite(STDOUT, $settings['seedDemoAdmin'] ? "Demo administrators are alex/pass, demo/pass, and the private Design Lab admin/admin is 1234.\n" : "No demonstration administrator was added. Use the setup wizard to create one.\n");
