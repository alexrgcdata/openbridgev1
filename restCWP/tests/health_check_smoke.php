<?php
/**
 * Offline smoke test for the transport-aware restCWP diagnostics.
 *
 * Run from the repository root with:
 *   php restCWP/tests/health_check_smoke.php
 *
 * The test intentionally uses incomplete settings, so it never makes a
 * network request or requires a live FileMaker Server.
 */

require_once dirname(__DIR__) . '/restCWP.php';

$connection = new \restCWP\restCWP('', '', '', '', '', [
    'transport' => \restCWP\restCWP::TRANSPORT_ODATA,
]);

$health = $connection->healthCheck();

if ($health['transport'] !== \restCWP\restCWP::TRANSPORT_ODATA) {
    throw new \RuntimeException('Expected the OData transport to be selected.');
}

if ($health['ok'] !== false || $health['summary']['failed'] < 1) {
    throw new \RuntimeException('Incomplete configuration should fail health checks.');
}

$capabilities = $connection->getCapabilities();

if (empty($capabilities['operations']['schema_metadata']['supported'])) {
    throw new \RuntimeException('OData schema metadata should be reported as supported.');
}

if (empty($capabilities['operations']['schema_metadata']['library'])) {
    throw new \RuntimeException('The diagnostic adapter should report schema metadata as library-supported.');
}

echo "restCWP health smoke test passed\n";
