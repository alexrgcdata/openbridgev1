<?php

declare(strict_types=1);

namespace odataCWP;

require_once __DIR__ . '/restCWP.php';
require_once __DIR__ . '/OpenBridgeResponseNormalizer.php';

use OpenBridge\Support\OpenBridgeResponseNormalizer;
use restCWP\restCWP;

/**
 * FileMaker OData v4 client with restCWP-style method names.
 *
 * OData uses database tables/entity sets and OData filter expressions rather
 * than Data API layouts and find request arrays. All data operations return
 * the same normalized OpenBridge outer envelope used by normalized restCWP.
 */
final class odataCWP
{
    private restCWP $engine;
    private string $database;
    private string $table;

    public function __construct(
        string $host,
        string $database,
        string $table,
        string $username,
        string $password,
        ?array $options = null
    ) {
        $this->database = $database;
        $this->table = $table;
        $engineOptions = is_array($options) ? $options : [];
        $engineOptions['transport'] = restCWP::TRANSPORT_ODATA;
        $engineOptions['table'] = $table;
        $this->engine = new restCWP(
            $host,
            $database,
            '',
            $username,
            $password,
            $engineOptions
        );
    }

    /** Validate OData authentication, metadata, database, and table access. */
    public function healthCheck(array $options = []): array
    {
        return $this->engine->healthCheck(restCWP::TRANSPORT_ODATA, [
            'database' => (string) ($options['database'] ?? $this->database),
            'table' => (string) ($options['table'] ?? $this->table),
        ]);
    }

    /** Return FileMaker's OData metadata diagnostic and discovered tables. */
    public function getMetadata(array $options = []): array
    {
        return $this->engine->getODataMetadata([
            'database' => (string) ($options['database'] ?? $this->database),
        ]);
    }

    /** Read a page of records from the configured OData table. */
    public function getRecords(?array $options = null): array
    {
        $options = $this->options($options);
        return $this->normalize($this->engine->listODataRecords($options), 'list', $options);
    }

    /** Alias for users who prefer explicit collection terminology. */
    public function listRecords(?array $options = null): array
    {
        return $this->getRecords($options);
    }

    /** Find records using an OData `$filter` expression. */
    public function findRecords(string $filter, ?array $options = null): array
    {
        $options = $this->options($options);
        return $this->normalize($this->engine->findODataRecords($filter, $options), 'find', $options);
    }

    /** Read one record using its primary key or FileMaker ROWID. */
    public function getRecord(string|int $recordId, ?array $options = null): array
    {
        $options = $this->options($options);
        return $this->normalize($this->engine->getODataRecord($recordId, $options), 'get', $options);
    }

    /** Create one OData entity. Pass the fields directly, without `fieldData`. */
    public function createRecord(array $fields, ?array $options = null): array
    {
        $options = $this->options($options);
        return $this->normalize($this->engine->createODataRecord($fields, $options), 'create', $options);
    }

    /** Update one OData entity using its primary key or FileMaker ROWID. */
    public function editRecord(string|int $recordId, array $fields, ?array $options = null): array
    {
        $options = $this->options($options);
        return $this->normalize($this->engine->updateODataRecord($recordId, $fields, $options), 'update', $options);
    }

    /** Delete one OData entity using its primary key or FileMaker ROWID. */
    public function deleteRecord(string|int $recordId, ?array $options = null): array
    {
        $options = $this->options($options);
        return $this->normalize($this->engine->deleteODataRecord($recordId, $options), 'delete', $options);
    }

    /** Run one FileMaker script through the OData script action. */
    public function runScript(string $scriptName, mixed $scriptParameter = null): array
    {
        return $this->normalize(
            $this->engine->runODataScript($scriptName, $scriptParameter),
            'script',
            ['table' => $this->table]
        );
    }

    /** Return the FileMaker OData capabilities exposed by the underlying engine. */
    public function getCapabilities(): array
    {
        return $this->engine->getCapabilities(restCWP::TRANSPORT_ODATA);
    }

    /** Set the default OData table/entity set for following requests. */
    public function setTable(string $table): self
    {
        $this->table = trim($table);
        return $this;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    /** Normalized results are already the final response. */
    public function getResponse(array $result): array
    {
        return $result;
    }

    /** Normalize a captured OData response for fixtures, imports, and testing. */
    public function normalizeResponse(mixed $raw, string $operation = 'request', ?string $resource = null): array
    {
        return OpenBridgeResponseNormalizer::oData($raw, [
            'operation' => $operation,
            'database' => $this->database,
            'resource' => $resource ?? $this->table,
        ]);
    }

    private function options(?array $options): array
    {
        $options = is_array($options) ? $options : [];
        $options['database'] = (string) ($options['database'] ?? $this->database);
        $options['table'] = (string) ($options['table'] ?? $this->table);
        return $options;
    }

    private function normalize(mixed $raw, string $operation, array $options): array
    {
        return OpenBridgeResponseNormalizer::oData($raw, [
            'operation' => $operation,
            'database' => (string) ($options['database'] ?? $this->database),
            'resource' => (string) ($options['table'] ?? $this->table),
        ]);
    }
}

if (!\class_exists('odataCWP', false)) {
    \class_alias(__NAMESPACE__ . '\\odataCWP', 'odataCWP');
}
