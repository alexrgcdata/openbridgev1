<?php

declare(strict_types=1);

/**
 * Stable customer include for source checkouts and packaged FTP deployments.
 *
 *   require_once __DIR__ . '/openbridge/openbridge.php';
 *   $fm = OpenBridge::fromDefaults(['layout' => 'Invoices']);
 */
$bridgeClass = is_file(__DIR__ . '/api/restCWP.php')
    ? __DIR__ . '/api/restCWP.php'
    : dirname(__DIR__) . '/restCWP/restCWP.php';
require_once $bridgeClass;
$odataClass = is_file(__DIR__ . '/api/odataCWP.php')
    ? __DIR__ . '/api/odataCWP.php'
    : dirname(__DIR__) . '/restCWP/odataCWP.php';
require_once $odataClass;

use odataCWP\odataCWP;
use restCWP\restCWP;

final class OpenBridge
{
    /** Create the v1 Data API client with normalized responses enabled. */
    public static function dataApi(array $overrides = []): restCWP
    {
        [$fileMaker, $value] = self::configuration($overrides);
        return new restCWP(
            (string) $value('host'),
            (string) $value('database'),
            (string) $value('layout'),
            (string) $value('username'),
            (string) $value('password'),
            array_merge((array) ($fileMaker['options'] ?? []), (array) ($overrides['options'] ?? []), [
                'transport' => restCWP::TRANSPORT_DATA_API,
                'responseFormat' => restCWP::RESPONSE_NORMALIZED,
            ])
        );
    }

    /** Create the v1 OData client. OData uses tables, not FileMaker layouts. */
    public static function odata(array $overrides = []): odataCWP
    {
        [$fileMaker, $value] = self::configuration($overrides);
        return new odataCWP(
            (string) $value('host'),
            (string) $value('database'),
            (string) $value('table'),
            (string) $value('username'),
            (string) $value('password'),
            array_merge((array) ($fileMaker['options'] ?? []), (array) ($overrides['options'] ?? []))
        );
    }

    /**
     * Backward-compatible factory. Existing integrations keep raw restCWP
     * responses; new integrations should prefer dataApi() or odata().
     */
    public static function fromDefaults(array $overrides = []): restCWP
    {
        [$fileMaker, $value] = self::configuration($overrides);
        return new restCWP(
            (string) $value('host'),
            (string) $value('database'),
            (string) $value('layout'),
            (string) $value('username'),
            (string) $value('password'),
            array_merge((array) ($fileMaker['options'] ?? []), (array) ($overrides['options'] ?? []), [
                'transport' => (string) $value('transport', restCWP::TRANSPORT_DATA_API),
                'table' => (string) $value('table'),
            ])
        );
    }

    /** Load private defaults once and apply explicit per-call overrides. */
    private static function configuration(array $overrides): array
    {
        $configPath = self::configPath();
        $config = is_file($configPath) ? require $configPath : [];
        $fileMaker = is_array($config) && isset($config['filemaker']) && is_array($config['filemaker'])
            ? $config['filemaker']
            : [];
        $vaultDefaults = is_array($config) ? self::trustedVaultFileMaker($config, dirname($configPath)) : null;
        if ($vaultDefaults !== null) $fileMaker = array_merge($fileMaker, $vaultDefaults);
        $value = static fn (string $key, mixed $fallback = ''): mixed => $overrides[$key] ?? $fileMaker[$key] ?? $fallback;
        return [$fileMaker, $value];
    }

    private static function configPath(): string
    {
        return is_file(__DIR__ . '/api/openbridge.config.php')
            ? __DIR__ . '/api/openbridge.config.php'
            : dirname(__DIR__) . '/restCWP/openbridge.config.php';
    }

    /**
     * Load the trusted default FileMaker connection saved by the admin GUI.
     *
     * This intentionally does not read browser session state. A packaged PHP
     * include may use a server-configured vault user, an installation metadata
     * pointer, or one unambiguous active admin secret.
     */
    private static function trustedVaultFileMaker(array $config, string $configDirectory): ?array
    {
        $fileMaker = is_array($config['filemaker'] ?? null) ? $config['filemaker'] : [];
        // Opting out must not even open/create SQLite or load its key.
        if (($fileMaker['useVaultDefaults'] ?? true) === false || ($config['database']['enabled'] ?? true) === false) return null;

        $databasePath = $configDirectory . DIRECTORY_SEPARATOR . 'database.php';
        $securityPath = $configDirectory . DIRECTORY_SEPARATOR . 'security.php';
        if (!is_file($databasePath) || !is_file($securityPath)) return null;
        require_once $databasePath;
        require_once $securityPath;

        // Standalone use does not initialize the dashboard's database.
        $settings = openBridgeDatabaseConfig($config);
        if (!is_file($settings['path'])) {
            if (array_key_exists('vaultUserId', $fileMaker)) throw new RuntimeException('The selected FileMaker vault database does not exist.');
            return null;
        }
        $pdo = openBridgeDatabasePdo($config);
        $repository = new OpenBridgeRepository(
            $pdo,
            new OpenBridgeCrypto(new OpenBridgeKeyProvider($config, $configDirectory))
        );
        return $repository->loadDefaultFileMaker($fileMaker);
    }
}
