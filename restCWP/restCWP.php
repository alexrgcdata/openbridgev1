<?php
/**
 * Author: RGC Data LLC
 */

namespace restCWP;

require_once __DIR__ . '/OpenBridgeResponseNormalizer.php';

use OpenBridge\Support\OpenBridgeResponseNormalizer;

/**
 * Class restCWP
 */
class restCWP
{
    /* --- Connection --- */
    private $host;
    private $db;
    private $layout;
    private $user;
    private $password;

    /* --- Define another attributes --- */
    private $token;
    private $rowNumber;
    private $cookieName = "fm_user_id";

    /* --- Default options --- */
    private $tokenExpireTime = 14;
    private $sessionName = "fm-api-token";
    private $logDir = "";
    private $logType = self::LOG_TYPE_NONE;
    private $allowInsecure = false;
    private $tokenStorage = self::TS_SESSION;
    private $autorelogin = true;
    private $tokenFilePath = "";
    private $timeout = 100;
    private $curlOptions = [];
    private $transport = self::TRANSPORT_DATA_API;
    private $responseFormat = self::RESPONSE_RAW;

    /* --- Define log const --- */
    const LOG_TYPE_DEBUG = "debug"; // All Messages
    const LOG_TYPE_ERRORS = "errors"; // Only Errors
    const LOG_TYPE_NONE = "none"; // none

    const LS_ERROR = "error";
    const LS_SUCCESS = "success";
    const LS_INFO = "info";
    const LS_WARNING = "warning";
    const LS_DEBUG = "debug"; // Newly added

    const TS_FILE = "file";
    const TS_SESSION = "session";

    /** Supported connection transports. OData is always FileMaker OData v4. */
    const TRANSPORT_DATA_API = "data_api";
    const TRANSPORT_ODATA = "odata";
    const RESPONSE_RAW = "raw";
    const RESPONSE_NORMALIZED = "normalized";



    const ERROR_RESPONSE_CODE = [400, 401, 403, 404, 405, 415, 500];
    const ERROR_AUTH_RESPONSE_CODE = 401;

    /**
     * restCWP constructor.
     * @param string $host
     * @param string $db
     * @param string $layout
     * @param string $user
     * @param string $password
     * @option array $options
     */


     public function __construct($host, $db, $layout, $user, $password, $options = null)
     {
         $this->host = $host;
         $this->db = $db;
         $this->layout = $layout;
         $this->user = $user;
         $this->password = $password;
         $this->logDir = $this->defaultStoragePath('logs') . DIRECTORY_SEPARATOR;
         $this->tokenFilePath = $this->defaultStoragePath('tokens');

         if ($options !== null) {
             $this->setOptions($options);
         }

         // Set default token file path if using file storage and no path is provided
         if ($this->tokenStorage === self::TS_FILE && empty($this->tokenFilePath)) {
             $this->tokenFilePath = $this->defaultStoragePath('tokens');
         }

         $this->setTimezone();
     }



    // *********************************************************************************************************************************

    private function setLogRowNumber()
    {
        $this->rowNumber = random_int(1000000, 9999999);
    }

     // *********************************************************************************************************************************

    /**
     * @param array $options
     */
    private function setOptions($options)
    {
        /* --- Log type --- */
        if (isset($options["logType"])) {
            $logType = $options["logType"];

            if (in_array($logType, [self::LOG_TYPE_DEBUG, self::LOG_TYPE_ERRORS, self::LOG_TYPE_NONE]) && !empty($logType)) {
                $this->logType = $logType;
            } else {
                $this->response(-101);
            }
        }

        /* --- Log dir --- */
        if (isset($options["logDir"])) {
            $logDir = $options["logDir"];

            if (is_string($logDir) && !empty($logDir)) {
                $this->logDir = $logDir;
            } else {
                $this->response(-102);
            }
        }

        /* --- Session name --- */
        if (isset($options["sessionName"])) {
            $sessionName = $options["sessionName"];

            if (is_string($sessionName) && !empty($sessionName)) {
                $this->sessionName = $sessionName;
            } else {
                $this->response(-103);
            }
        }

        /* --- Token Expire Time ( In minutes ) --- */
        if (isset($options["tokenExpireTime"])) {
            $tokenExpireTime = $options["tokenExpireTime"];

            if (is_numeric($tokenExpireTime)) {
                $this->tokenExpireTime = $tokenExpireTime;
            } else {
                $this->response(-104);
            }
        }

        /* --- Allow Insecure --- */
        if (isset($options["allowInsecure"])) {
            $allowInsecure = $options["allowInsecure"];

            if (is_bool($allowInsecure)) {
                $this->allowInsecure = $allowInsecure;
            } else {
                $this->response(-105);
            }
        }

        /* --- Request timeout in seconds --- */
        if (isset($options["timeout"])) {
            $timeout = $options["timeout"];

            if (is_numeric($timeout) && (int) $timeout >= 1 && (int) $timeout <= 600) {
                $this->timeout = (int) $timeout;
            } else {
                $this->response(-115);
            }
        }

        /* --- Relogin --- */
        if (isset($options["autorelogin"])) {
            $autorelogin = $options["autorelogin"];

            if (is_bool($autorelogin)) {
                $this->autorelogin = $autorelogin;
            } else {
                $this->response(-112);
            }
        }

        /* --- Extra cURL options --- */
        if (isset($options["curlOptions"])) {
            $curlOptions = $options["curlOptions"];

            if (is_array($curlOptions)) {
                $this->curlOptions = $curlOptions;
            } else {
                $this->response(-113);
            }
        }

        /* --- FileMaker transport --- */
        if (isset($options["transport"])) {
            $transport = $this->normalizeTransport($options["transport"]);

            if ($transport === null) {
                $this->response(-116);
            }

            $this->transport = $transport;
        }

        /* --- Public response format (raw legacy or normalized v1 envelope) --- */
        if (isset($options["responseFormat"])) {
            $responseFormat = strtolower(trim((string) $options["responseFormat"]));
            if (!in_array($responseFormat, [self::RESPONSE_RAW, self::RESPONSE_NORMALIZED], true)) {
                $this->response(-117);
            }
            $this->responseFormat = $responseFormat;
        }

        /* --- User identity cookie name for session token isolation --- */
        if (isset($options["cookieName"])) {
            $cookieName = $options["cookieName"];

            if (is_string($cookieName) && !empty($cookieName)) {
                $this->cookieName = $cookieName;
            } else {
                $this->response(-114);
            }
        }


        /* --- Save FileMaker Token to --- */
        if (isset($options["tokenStorage"])) {
            $tokenStorage = $options["tokenStorage"];

            if (in_array($tokenStorage, [self::TS_FILE, self::TS_SESSION])) {
                if ($tokenStorage === self::TS_FILE) {
                    if (isset($options["tokenFilePath"])) {
                        if (!empty($options["tokenFilePath"]) && is_string($options["tokenFilePath"])) {
                            $this->tokenFilePath = $options["tokenFilePath"];
                        } else {
                            $this->response(-111);
                        }
                    } elseif (empty($this->tokenFilePath)) {
                        $this->tokenFilePath = $this->defaultStoragePath('tokens');
                    }
                }
                $this->tokenStorage = $tokenStorage;
            } else {
                $this->response(-110);
            }
        }
    }

    /**
     * Select the transport used by diagnostics and future transport-aware calls.
     *
     * Existing Data API methods remain backward compatible. OData diagnostics
     * use FileMaker OData v4 and Basic Authentication; they do not reuse a
     * Data API session token.
     *
     * @param string $transport data_api, data-api, odata, or odata_v4
     * @return self
     * @throws \InvalidArgumentException when the transport is not supported
     */
    public function setTransport($transport)
    {
        $normalized = $this->normalizeTransport($transport);

        if ($normalized === null) {
            $this->response(-116);
        }

        $this->transport = $normalized;

        return $this;
    }

    /**
     * Return the currently selected transport identifier.
     *
     * @return string data_api or odata
     */
    public function getTransport()
    {
        return $this->transport;
    }

    /**
     * Run a safe, read-only connection diagnostic for FileMaker Data API or OData.
     *
     * The diagnostic checks configuration, network reachability, API availability,
     * authentication, database access, and the configured layout/table. It does
     * not create, edit, delete, upload, run scripts, or return credentials. For
     * OData, the database metadata document validates the server/database/auth
     * path and an optional table query validates table access.
     *
     * Use `table` in $options for OData. If it is omitted, the constructor's
     * layout value is used as the OData table for compatibility with existing
     * restCWP configuration. OData does not use FileMaker layouts.
     *
     * @param string|null $transport data_api, data-api, odata, or odata_v4
     * @param array $options Optional layout/table override and timeout override
     * @return array A stable diagnostic result with `checks`, `summary`,
     *               `latency_ms`, and transport `capabilities`
     */
    public function healthCheck($transport = null, $options = [])
    {
        $startedAt = microtime(true);
        $options = is_array($options) ? $options : [];
        $selectedTransport = $this->normalizeTransport($transport === null ? $this->transport : $transport);
        $checks = [];

        if ($selectedTransport === null) {
            $checks['configuration'] = $this->healthCheckItem(
                'configuration',
                'Configuration',
                'fail',
                'Unsupported transport. Use data_api or odata.'
            );

            return $this->finishHealthCheck(null, $checks, $startedAt);
        }

        $database = array_key_exists('database', $options) ? trim((string) $options['database']) : trim((string) $this->db);
        $layout = array_key_exists('layout', $options) ? trim((string) $options['layout']) : trim((string) $this->layout);
        $table = array_key_exists('table', $options) ? trim((string) $options['table']) : $layout;
        $missing = [];

        foreach ([
            'host' => $this->host,
            'database' => $database,
            'username' => $this->user,
            'password' => $this->password,
        ] as $name => $value) {
            if (trim((string) $value) === '') {
                $missing[] = $name;
            }
        }

        $configurationMessage = empty($missing)
            ? 'Required connection settings are present.'
            : 'Missing required settings: ' . implode(', ', $missing) . '.';
        $checks['configuration'] = $this->healthCheckItem(
            'configuration',
            'Configuration',
            empty($missing) ? 'pass' : 'fail',
            $configurationMessage
        );

        $timeout = isset($options['timeout']) && is_numeric($options['timeout'])
            ? max(1, min(600, (int) $options['timeout']))
            : $this->timeout;
        $requestOptions = [
            'timeout' => $timeout,
            'verify_ssl' => !$this->allowInsecure,
        ];
        $basicHeaders = [
            'Authorization' => 'Basic ' . base64_encode($this->user . ':' . $this->password),
            'Accept' => 'application/json',
        ];

        if (!empty($missing)) {
            $checks['server'] = $this->healthCheckItem('server', 'Server reachability', 'skipped', 'Skipped until configuration is complete.');
            $checks['api'] = $this->healthCheckItem('api', 'API availability', 'skipped', 'Skipped until configuration is complete.');
            $checks['authentication'] = $this->healthCheckItem('authentication', 'Authentication', 'skipped', 'Skipped until configuration is complete.');
            $checks['database'] = $this->healthCheckItem('database', 'Database access', 'skipped', 'Skipped until configuration is complete.');
            $checks['layout_or_table'] = $this->healthCheckItem('layout_or_table', $selectedTransport === self::TRANSPORT_ODATA ? 'Table access' : 'Layout access', 'skipped', 'Skipped until configuration is complete.');

            if ($selectedTransport === self::TRANSPORT_ODATA && $table === '') {
                $checks['layout_or_table'] = $this->healthCheckItem('layout_or_table', 'Table access', 'warning', 'No OData table was supplied. Set the table option or constructor layout.');
            }

            return $this->finishHealthCheck($selectedTransport, $checks, $startedAt);
        }

        if ($selectedTransport === self::TRANSPORT_ODATA) {
            $this->runODataHealthCheck($checks, $database, $table, $basicHeaders, $requestOptions);
        } else {
            $this->runDataApiHealthCheck($checks, $layout, $basicHeaders, $requestOptions);
        }

        return $this->finishHealthCheck($selectedTransport, $checks, $startedAt);
    }

    /**
     * Friendly alias for healthCheck(). Returns the same result without changing
     * the existing public Data API method names.
     *
     * @param string|null $transport
     * @param array $options
     * @return array
     */
    public function checkHealth($transport = null, $options = [])
    {
        return $this->healthCheck($transport, $options);
    }

    /**
     * Describe transport operations and the authentication model they use.
     *
     * `supported` describes the FileMaker transport capability. `library` says
     * whether the current restCWP class already exposes a corresponding public
     * method. The beta OData adapter intentionally exposes read/find/script
     * operations only; it does not claim live CRUD or upload support here.
     *
     * @param string|null $transport data_api, data-api, odata, or odata_v4
     * @return array
     */
    public function getCapabilities($transport = null)
    {
        $selectedTransport = $this->normalizeTransport($transport === null ? $this->transport : $transport);

        if ($selectedTransport === self::TRANSPORT_ODATA) {
            return [
                'transport' => self::TRANSPORT_ODATA,
                'label' => 'FileMaker OData v4',
                'authentication' => 'HTTP Basic Authentication per request',
                'notes' => [
                    'OData addresses tables, not FileMaker layouts.',
                    'Responses are JSON by default and field names are preserved.',
                    'The health check uses $metadata and an optional $top=1 table query.',
                ],
                'operations' => [
                    'health_check' => ['supported' => true, 'library' => true, 'method' => 'GET', 'endpoint' => '$metadata'],
                    'schema_metadata' => ['supported' => true, 'library' => true, 'method' => 'GET', 'endpoint' => '$metadata'],
                    'list_records' => ['supported' => true, 'library' => true, 'method' => 'GET', 'endpoint' => 'Table?$top=...'],
                    'find_records' => ['supported' => true, 'library' => true, 'method' => 'GET', 'endpoint' => 'Table?$filter=...'],
                    'get_record' => ['supported' => true, 'library' => true, 'method' => 'GET', 'endpoint' => 'Table(primary-key)'],
                    'create_record' => ['supported' => true, 'library' => true, 'method' => 'POST', 'endpoint' => 'Table'],
                    'update_record' => ['supported' => true, 'library' => true, 'method' => 'PATCH', 'endpoint' => 'Table(key)'],
                    'delete_record' => ['supported' => true, 'library' => true, 'method' => 'DELETE', 'endpoint' => 'Table(key)'],
                    'run_script' => ['supported' => true, 'library' => true, 'method' => 'POST', 'endpoint' => 'Script.ScriptName'],
                    'container_upload' => ['supported' => true, 'library' => false, 'method' => 'PATCH', 'endpoint' => 'Table(key)/ContainerField'],
                    'layouts' => ['supported' => false, 'library' => false, 'method' => null, 'endpoint' => null, 'note' => 'Use tables and $metadata instead.'],
                    'transactions_batch' => ['supported' => true, 'library' => false, 'method' => 'POST', 'endpoint' => '$batch'],
                ],
            ];
        }

        return [
            'transport' => self::TRANSPORT_DATA_API,
            'label' => 'FileMaker Data API',
            'authentication' => 'Basic Authentication to obtain a short-lived session token, then Bearer token requests',
            'notes' => [
                'Data API addresses layouts and returns the standard FileMaker response envelope.',
                'The class preserves FileMaker field names and does not reshape record data.',
            ],
                'operations' => [
                    'health_check' => ['supported' => true, 'library' => true, 'method' => 'GET/POST', 'endpoint' => 'productInfo, sessions, layouts'],
                    'database_list' => ['supported' => true, 'library' => true, 'method' => 'GET', 'endpoint' => 'databases'],
                    'layout_metadata' => ['supported' => true, 'library' => true, 'method' => 'GET', 'endpoint' => 'layouts/{layout}'],
                    'list_records' => ['supported' => true, 'library' => true, 'method' => 'GET', 'endpoint' => 'layouts/{layout}/records'],
                    'find_records' => ['supported' => true, 'library' => true, 'method' => 'POST', 'endpoint' => 'layouts/{layout}/_find'],
                'get_records' => ['supported' => true, 'library' => true, 'method' => 'GET', 'endpoint' => 'layouts/{layout}/records'],
                'create_record' => ['supported' => true, 'library' => true, 'method' => 'POST', 'endpoint' => 'layouts/{layout}/records'],
                'update_record' => ['supported' => true, 'library' => true, 'method' => 'PATCH', 'endpoint' => 'layouts/{layout}/records/{id}'],
                'delete_record' => ['supported' => true, 'library' => true, 'method' => 'DELETE', 'endpoint' => 'layouts/{layout}/records/{id}'],
                'run_script' => ['supported' => true, 'library' => true, 'method' => 'GET', 'endpoint' => 'layouts/{layout}/script/{script}'],
                'container_upload' => ['supported' => true, 'library' => true, 'method' => 'POST', 'endpoint' => 'records/{id}/containers/{field}'],
                'schema_metadata' => ['supported' => true, 'library' => true, 'method' => 'GET', 'endpoint' => 'layouts/{layout}'],
                'transactions_batch' => ['supported' => false, 'library' => false, 'method' => null, 'endpoint' => null, 'note' => 'Not exposed by the current class.'],
            ],
        ];
    }

    /**
     * Execute one of the intentionally small, read-only beta Playground
     * operations. This is the public adapter used by openbridge-api.php.
     *
     * Supported operations are list, find, get, and script. The optional
     * afterScript is executed only after the primary request succeeds. No
     * credentials, bearer tokens, or Basic Authorization headers are returned
     * in the result. Record and field names are passed through unchanged.
     *
     * @param string $operation list, find, get, or script
     * @param array $options Transport, layout/table, request parameters, and
     *                       optional afterScript settings
     * @return array Stable result containing request metadata, raw response,
     *               decoded response, transport, and latency
     */
    public function playground($operation, $options = [])
    {
        $startedAt = microtime(true);
        $options = is_array($options) ? $options : [];
        $operation = strtolower(trim((string) $operation));

        if (!in_array($operation, ['list', 'find', 'get', 'script'], true)) {
            return $this->playgroundFailure('The Playground only supports list, find, get, and script.', $startedAt, null, $operation);
        }

        $transport = $this->normalizeTransport(isset($options['transport']) ? $options['transport'] : $this->transport);
        if ($transport === null) {
            return $this->playgroundFailure('Unsupported transport. Use data_api or odata.', $startedAt, null, $operation);
        }

        $options['transport'] = $transport;
        $primary = $transport === self::TRANSPORT_ODATA
            ? $this->playgroundODataRequest($operation, $options)
            : $this->playgroundDataApiRequest($operation, $options);

        $primary['operation'] = $operation;
        $primary['transport'] = $transport;
        $primarySuccess = !empty($primary['success']);
        $primary['primary_success'] = $primarySuccess;
        $primary['script_response'] = null;

        $afterScript = isset($options['afterScript']) && is_array($options['afterScript'])
            ? $options['afterScript']
            : null;

        if ($afterScript !== null && $afterScript !== []) {
            if (!$primarySuccess) {
                $primary['after_script_skipped'] = 'The primary request failed, so the afterScript was not run.';
            } else {
                $scriptName = isset($afterScript['name'])
                    ? $afterScript['name']
                    : (isset($afterScript['scriptName']) ? $afterScript['scriptName'] : '');
                $scriptParameter = array_key_exists('parameter', $afterScript)
                    ? $afterScript['parameter']
                    : (array_key_exists('scriptParameter', $afterScript) ? $afterScript['scriptParameter'] : null);

                $scriptResponse = $this->playgroundScriptRequest($transport, (string) $scriptName, $scriptParameter, $options);
                $primary['script_response'] = $scriptResponse;
                $primary['success'] = $primarySuccess && !empty($scriptResponse['success']);
                $primary['latency_ms'] = round((microtime(true) - $startedAt) * 1000, 2);
            }
        }

        return $primary;
    }

    /**
     * Read the FileMaker OData v4 metadata document and discover entity sets.
     *
     * This is a read-only diagnostic helper. It returns the complete metadata
     * response body plus a convenience `discovered_tables` array. FileMaker
     * credentials and the Basic Authorization header are never returned.
     *
     * @param array $options Optional `database` override
     * @return array Sanitized request details, status, headers, raw metadata,
     *               decoded response when JSON, discovered tables, and latency
     */
    public function getODataMetadata($options = [])
    {
        $options = is_array($options) ? $options : [];
        $startedAt = microtime(true);
        $database = trim((string) ($options['database'] ?? $this->db));

        if ($database === '' || trim((string) $this->host) === '') {
            return $this->playgroundFailure(
                'OData metadata requires a host and database.',
                $startedAt,
                null,
                'metadata'
            );
        }

        $path = '/fmi/odata/v4/' . rawurlencode($database) . '/$metadata';
        $response = $this->sendHttpRequest('GET', $this->buildHealthUrl($path), [
            'Authorization' => 'Basic ' . base64_encode($this->user . ':' . $this->password),
            'Accept' => 'application/xml, application/json',
        ], null, [
            'timeout' => $this->timeout,
            'verify_ssl' => !$this->allowInsecure,
        ]);

        $result = $this->playgroundResponse(
            self::TRANSPORT_ODATA,
            'metadata',
            ['method' => 'GET', 'path' => $path, 'query' => [], 'body' => null],
            $response,
            $startedAt
        );
        $result['discovered_tables'] = $this->discoverODataTables(
            isset($response['body']) ? (string) $response['body'] : ''
        );

        return $result;
    }

    /**
     * Read a collection from an OData table.
     *
     * Use queryOptions for OData options such as $select, $orderby, $top,
     * $skip, and $filter. The decoded response is an associative PHP array
     * with FileMaker's field names preserved.
     *
     * @param array $options Table and query options
     * @return array Request metadata and the OData response
     */
    public function listODataRecords($options = [])
    {
        $options = is_array($options) ? $options : [];
        $options['transport'] = self::TRANSPORT_ODATA;
        return $this->playground('list', $options);
    }

    /**
     * Find OData records using a FileMaker OData $filter expression.
     *
     * Example filter: `FirstName eq 'Alex' or startswith(LastName,'S')`.
     * OData filters are expressions, not Data API find-request arrays.
     *
     * @param string $filter OData $filter expression
     * @param array $options Table and additional OData query options
     * @return array Request metadata and the OData response
     */
    public function findODataRecords($filter, $options = [])
    {
        $options = is_array($options) ? $options : [];
        $options['transport'] = self::TRANSPORT_ODATA;
        $options['filter'] = (string) $filter;
        return $this->playground('find', $options);
    }

    /**
     * Read one OData record by its primary key or ROWID value.
     *
     * String keys are quoted and escaped for the OData URL. Numeric keys are
     * left numeric. Pass `keyType => string` when a text primary key happens
     * to contain only digits (for example, `00123`). OData uses tables and
     * primary keys, not FileMaker layouts.
     *
     * @param string|int $recordId Primary key or ROWID value
     * @param array $options Table and optional query options
     * @return array Request metadata and the OData response
     */
    public function getODataRecord($recordId, $options = [])
    {
        $options = is_array($options) ? $options : [];
        $options['transport'] = self::TRANSPORT_ODATA;
        $options['recordId'] = $recordId;
        return $this->playground('get', $options);
    }

    /**
     * Create one OData record. This direct library method intentionally is not
     * exposed through the browser Playground: callers must enforce their own
     * table and field policy before accepting untrusted input.
     *
     * @param array $fields OData entity fields and values
     * @param array $options Table/database overrides
     * @return array Sanitized request metadata and OData response
     */
    public function createODataRecord(array $fields, $options = [])
    {
        return $this->odataMutationRequest('create', 'POST', null, $fields, $options);
    }

    /** Update one OData record by its primary key or ROWID value. */
    public function updateODataRecord($recordId, array $fields, $options = [])
    {
        return $this->odataMutationRequest('update', 'PATCH', $recordId, $fields, $options);
    }

    /** Delete one OData record by its primary key or ROWID value. */
    public function deleteODataRecord($recordId, $options = [])
    {
        return $this->odataMutationRequest('delete', 'DELETE', $recordId, null, $options);
    }

    /**
     * Run an OData server-side script.
     *
     * FileMaker expects an empty POST body when there is no parameter, or a
     * single scriptParameterValue field when one is supplied. Only scripts
     * permitted by the FileMaker account should be callable.
     *
     * @param string $scriptName FileMaker script name
     * @param mixed $scriptParameter Optional string, number, or JSON object
     * @return array Request metadata and the OData script response
     */
    public function runODataScript($scriptName, $scriptParameter = null)
    {
        return $this->playground('script', [
            'transport' => self::TRANSPORT_ODATA,
            'scriptName' => $scriptName,
            'scriptParameter' => $scriptParameter,
        ]);
    }

    /**
     * Execute a protected Data API Playground request without exposing the
     * internal session token. This intentionally bypasses create/update/delete
     * and upload methods because the beta endpoint is read-oriented.
     *
     * @param string $operation
     * @param array $options
     * @return array
     */
    private function playgroundDataApiRequest($operation, array $options)
    {
        $startedAt = microtime(true);
        $database = trim((string) ($options['database'] ?? $this->db));
        $layout = trim((string) ($options['layout'] ?? $this->layout));

        if ($database === '' || $layout === '' || trim((string) $this->host) === '') {
            return $this->playgroundFailure('The Data API requires a host, database, and layout.', $startedAt, null, $operation);
        }

        $loginPath = '/fmi/data/vLatest/databases/' . rawurlencode($database) . '/sessions';
        if (!$this->isLogged() && $this->login() !== true) {
            return $this->playgroundFailure('FileMaker Data API authentication failed.', $startedAt, [
                'method' => 'POST',
                'path' => $loginPath,
                'body' => null,
            ], $operation, ['http_code' => 401]);
        }

        $path = '/fmi/data/vLatest/databases/' . rawurlencode($database) . '/layouts/' . rawurlencode($layout);
        $method = 'GET';
        $body = null;
        $query = [];

        if ($operation === 'list') {
            $path .= '/records';
            $query = $this->dataApiQueryOptions($options);
        } elseif ($operation === 'find') {
            $path .= '/_find';
            $method = 'POST';
            $body = $this->dataApiFindPayload($options);
            if ($body === null) {
                return $this->playgroundFailure('A Data API find requires a JSON payload containing query criteria.', $startedAt, [
                    'method' => 'POST',
                    'path' => $path,
                    'body' => null,
                ], $operation);
            }
        } elseif ($operation === 'get') {
            if (!array_key_exists('recordId', $options) || (string) $options['recordId'] === '') {
                return $this->playgroundFailure('A Data API get requires recordId.', $startedAt, null, $operation);
            }
            $path .= '/records/' . rawurlencode((string) $options['recordId']);
            $query = $this->dataApiQueryOptions($options);
        } else {
            $scriptName = trim((string) ($options['scriptName'] ?? ''));
            if ($scriptName === '') {
                return $this->playgroundFailure('A Data API script request requires scriptName.', $startedAt, null, $operation);
            }
            $path .= '/script/' . rawurlencode($scriptName);
            $query = $this->dataApiScriptQuery($options);
        }

        $requestPath = $path . $this->queryString($query);
        $response = $this->sendHttpRequest('GET' === $method ? 'GET' : $method, $this->buildHealthUrl($requestPath), [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ], $body === null ? null : self::jsonEncode($body), [
            'timeout' => $this->timeout,
            'verify_ssl' => !$this->allowInsecure,
        ]);

        $result = $this->playgroundResponse(
            self::TRANSPORT_DATA_API,
            $operation,
            [
                'method' => $method,
                'path' => $path,
                'query' => $query,
                'body' => $body,
            ],
            $response,
            $startedAt
        );

        if (!empty($result['success'])) {
            $this->extendTokenExpiration();
        }

        return $result;
    }

    /**
     * Execute a FileMaker OData v4 list, find, get, or script request with
     * Basic Authentication on the server. OData responses stay JSON arrays/
     * objects after decoding; no field names or record values are changed.
     *
     * @param string $operation
     * @param array $options
     * @return array
     */
    private function playgroundODataRequest($operation, array $options)
    {
        $startedAt = microtime(true);
        $database = trim((string) ($options['database'] ?? $this->db));
        $table = trim((string) ($options['table'] ?? ($options['layout'] ?? $this->layout)));

        if ($database === '' || trim((string) $this->host) === '') {
            return $this->playgroundFailure('OData requires a host and database.', $startedAt, null, $operation);
        }

        $path = '/fmi/odata/v4/' . rawurlencode($database);
        $method = 'GET';
        $body = null;
        $query = [];

        if ($operation === 'script') {
            $scriptName = trim((string) ($options['scriptName'] ?? ''));
            if ($scriptName === '') {
                return $this->playgroundFailure('An OData script request requires scriptName.', $startedAt, null, $operation);
            }
            $path .= '/Script.' . rawurlencode($scriptName);
            $method = 'POST';
            if (array_key_exists('scriptParameter', $options) && $options['scriptParameter'] !== null) {
                $body = ['scriptParameterValue' => $options['scriptParameter']];
            }
        } else {
            if ($table === '') {
                return $this->playgroundFailure('OData requires a table. OData addresses tables, not layouts.', $startedAt, null, $operation);
            }
            $path .= '/' . rawurlencode($table);

            if ($operation === 'find') {
                $filter = $this->odataFilter($options);
                if ($filter === '') {
                    return $this->playgroundFailure('An OData find requires a $filter expression.', $startedAt, [
                        'method' => 'GET',
                        'path' => $path,
                        'query' => [],
                        'body' => null,
                    ], $operation);
                }
                $query['$filter'] = $filter;
            }

            $query = array_merge($this->odataQueryOptions($options), $query);

            if ($operation === 'get') {
                if (!array_key_exists('recordId', $options) || (string) $options['recordId'] === '') {
                    return $this->playgroundFailure('An OData get requires recordId, using the table primary key or ROWID.', $startedAt, null, $operation);
                }
                $path .= '(' . $this->odataKey(
                    $options['recordId'],
                    isset($options['keyType']) ? $options['keyType'] : null
                ) . ')';
            }
        }

        $requestPath = $path . $this->queryString($query);
        $response = $this->sendHttpRequest($method, $this->buildHealthUrl($requestPath), [
            'Authorization' => 'Basic ' . base64_encode($this->user . ':' . $this->password),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ], $body === null ? null : self::jsonEncode($body), [
            'timeout' => $this->timeout,
            'verify_ssl' => !$this->allowInsecure,
        ]);

        return $this->playgroundResponse(
            self::TRANSPORT_ODATA,
            $operation,
            [
                'method' => $method,
                'path' => $path,
                'query' => $query,
                'body' => $body,
            ],
            $response,
            $startedAt
        );
    }

    /**
     * Send one explicit OData record mutation without routing it through the
     * intentionally read-only Playground adapter.
     */
    private function odataMutationRequest($operation, $method, $recordId, $fields, $options)
    {
        $options = is_array($options) ? $options : [];
        $startedAt = microtime(true);
        $database = trim((string) ($options['database'] ?? $this->db));
        $table = trim((string) ($options['table'] ?? ($options['layout'] ?? $this->layout)));
        if ($database === '' || $table === '' || trim((string) $this->host) === '') {
            return $this->playgroundFailure('OData mutations require a host, database, and table.', $startedAt, null, $operation);
        }
        if (($operation === 'create' || $operation === 'update') && (!is_array($fields) || $fields === [])) {
            return $this->playgroundFailure('OData create and update requests require a non-empty field object.', $startedAt, null, $operation);
        }
        if ($operation !== 'create' && ($recordId === null || (string) $recordId === '')) {
            return $this->playgroundFailure('OData update and delete requests require a record key.', $startedAt, null, $operation);
        }

        $path = '/fmi/odata/v4/' . rawurlencode($database) . '/' . rawurlencode($table);
        if ($operation !== 'create') {
            $path .= '(' . $this->odataKey($recordId, $options['keyType'] ?? null) . ')';
        }
        $body = is_array($fields) ? $fields : null;
        $response = $this->sendHttpRequest($method, $this->buildHealthUrl($path), [
            'Authorization' => 'Basic ' . base64_encode($this->user . ':' . $this->password),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'OData-Version' => '4.0',
        ], $body === null ? null : self::jsonEncode($body), [
            'timeout' => $this->timeout,
            'verify_ssl' => !$this->allowInsecure,
        ]);
        return $this->playgroundResponse(self::TRANSPORT_ODATA, $operation, [
            'method' => $method,
            'path' => $path,
            'query' => [],
            'body' => $body,
        ], $response, $startedAt);
    }

    /**
     * Run an afterScript using the same transport as the primary request.
     *
     * @param string $transport
     * @param string $scriptName
     * @param mixed $scriptParameter
     * @param array $options
     * @return array
     */
    private function playgroundScriptRequest($transport, $scriptName, $scriptParameter, array $options)
    {
        $scriptOptions = $options;
        $scriptOptions['scriptName'] = $scriptName;
        $scriptOptions['scriptParameter'] = $scriptParameter;
        return $transport === self::TRANSPORT_ODATA
            ? $this->playgroundODataRequest('script', $scriptOptions)
            : $this->playgroundDataApiRequest('script', $scriptOptions);
    }

    /**
     * Convert the shared cURL response into the public Playground response.
     * Only request metadata is returned; authentication headers and internal
     * FileMaker session tokens are deliberately omitted.
     *
     * @param string $transport
     * @param string $operation
     * @param array $request
     * @param array $httpResponse
     * @param float $startedAt
     * @return array
     */
    private function playgroundResponse($transport, $operation, array $request, array $httpResponse, $startedAt)
    {
        $raw = isset($httpResponse['body']) ? (string) $httpResponse['body'] : '';
        $decoded = null;
        $isJson = $raw !== '';

        if ($isJson) {
            $decoded = json_decode($raw, true);
            $isJson = json_last_error() === JSON_ERROR_NONE;
        }

        $httpCode = isset($httpResponse['status']['http_code']) ? (int) $httpResponse['status']['http_code'] : 0;
        $success = $httpCode >= 200 && $httpCode < 300 && empty($httpResponse['error']);

        if ($success && is_array($decoded) && isset($decoded['messages'][0]['code']) && (string) $decoded['messages'][0]['code'] !== '0') {
            $success = false;
        }

        return [
            'success' => $success,
            'transport' => $transport,
            'operation' => $operation,
            'http_code' => $httpCode,
            'latency_ms' => isset($httpResponse['latency_ms'])
                ? (float) $httpResponse['latency_ms']
                : round((microtime(true) - $startedAt) * 1000, 2),
            'request' => $this->sanitizePlaygroundValue($request),
            'response_headers' => $this->safeResponseHeaders(
                isset($httpResponse['headers']) && is_array($httpResponse['headers'])
                    ? $httpResponse['headers']
                    : []
            ),
            'raw_response' => $raw,
            'response' => $isJson ? $decoded : ($raw !== '' ? $raw : null),
            'error' => !empty($httpResponse['error']) ? (string) $httpResponse['error'] : null,
        ];
    }

    /**
     * Return a safe local error result without making a network call.
     *
     * @param string $message
     * @param float $startedAt
     * @param array|null $request
     * @param string|null $operation
     * @param array $status
     * @return array
     */
    private function playgroundFailure($message, $startedAt, $request = null, $operation = null, $status = [])
    {
        $httpCode = isset($status['http_code']) ? (int) $status['http_code'] : 400;
        $response = ['messages' => [['code' => (string) $httpCode, 'message' => $message]]];

        return [
            'success' => false,
            'transport' => null,
            'operation' => $operation,
            'http_code' => $httpCode,
            'latency_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            'request' => $request === null ? null : $this->sanitizePlaygroundValue($request),
            'raw_response' => self::jsonEncode($response),
            'response' => $response,
            'error' => $message,
        ];
    }

    /**
     * Limit Data API collection query parameters to harmless read/script
     * options. Fixed endpoints prevent the Playground from becoming a raw URL
     * proxy.
     *
     * @param array $options
     * @return array
     */
    private function dataApiQueryOptions(array $options)
    {
        $source = isset($options['parameters']) && is_array($options['parameters']) ? $options['parameters'] : [];
        $allowed = ['_limit', '_offset', 'limit', 'offset', 'sort', 'portal', 'script', 'script.param', 'script.prerequest.param', 'script.presort.param', 'script.predelete.param', 'script.preexit.param'];
        $query = [];

        foreach ($allowed as $key) {
            if (array_key_exists($key, $source) && is_scalar($source[$key])) {
                $query[$key] = $source[$key];
            }
        }

        if (array_key_exists('limit', $options) && is_numeric($options['limit'])) {
            $query['_limit'] = max(1, min(1000, (int) $options['limit']));
        }
        if (array_key_exists('offset', $options) && is_numeric($options['offset'])) {
            $query['_offset'] = max(1, (int) $options['offset']);
        }

        return $query;
    }

    /**
     * Build a Data API find payload from the explicit Playground payload.
     *
     * @param array $options
     * @return array|null
     */
    private function dataApiFindPayload(array $options)
    {
        if (isset($options['payload']) && is_array($options['payload'])) {
            return $options['payload'];
        }
        if (isset($options['parameters']) && is_array($options['parameters'])) {
            return $options['parameters'];
        }
        if (isset($options['query']) && is_array($options['query'])) {
            $payload = ['query' => $options['query']];
            foreach (['limit', 'offset', 'sort', 'portal', 'script'] as $key) {
                if (array_key_exists($key, $options) && is_scalar($options[$key])) {
                    $payload[$key] = $options[$key];
                }
            }
            return $payload;
        }
        return null;
    }

    /**
     * Build the Data API script parameter query using FileMaker's
     * `script.param` name.
     *
     * @param array $options
     * @return array
     */
    private function dataApiScriptQuery(array $options)
    {
        if (!array_key_exists('scriptParameter', $options) || $options['scriptParameter'] === null) {
            return [];
        }

        $value = $options['scriptParameter'];
        return ['script.param' => is_array($value) ? self::jsonEncode($value) : $value];
    }

    /**
     * Accept only documented OData query options and cap pagination for a
     * safe beta Playground request.
     *
     * @param array $options
     * @return array
     */
    private function odataQueryOptions(array $options)
    {
        $source = [];
        if (isset($options['queryOptions']) && is_array($options['queryOptions'])) {
            $source = $options['queryOptions'];
        } elseif (isset($options['parameters']) && is_array($options['parameters'])) {
            $source = $options['parameters'];
        }

        $aliases = [
            '$select' => '$select', 'select' => '$select',
            '$orderby' => '$orderby', 'orderby' => '$orderby', 'orderBy' => '$orderby',
            '$top' => '$top', 'top' => '$top',
            '$skip' => '$skip', 'skip' => '$skip',
            '$expand' => '$expand', 'expand' => '$expand',
            '$count' => '$count', 'count' => '$count',
            '$filter' => '$filter', 'filter' => '$filter',
        ];
        $query = [];

        foreach ($aliases as $input => $output) {
            if (!array_key_exists($input, $source) || !is_scalar($source[$input])) {
                continue;
            }
            $value = $source[$input];
            if ($output === '$top') {
                $value = max(1, min(1000, (int) $value));
            } elseif ($output === '$skip') {
                $value = max(0, (int) $value);
            } elseif ($output === '$count') {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($value === null) {
                    continue;
                }
                $value = $value ? 'true' : 'false';
            } elseif ($output === '$filter') {
                $value = trim((string) $value);
                if ($value === '') {
                    continue;
                }
            }
            $query[$output] = $value;
        }

        return $query;
    }

    /**
     * Read an OData filter from the direct option or query options.
     *
     * @param array $options
     * @return string
     */
    private function odataFilter(array $options)
    {
        if (isset($options['filter']) && is_string($options['filter'])) {
            return trim($options['filter']);
        }

        foreach (['queryOptions', 'parameters'] as $key) {
            if (isset($options[$key]) && is_array($options[$key])) {
                foreach (['$filter', 'filter'] as $filterKey) {
                    if (isset($options[$key][$filterKey]) && is_scalar($options[$key][$filterKey])) {
                        return trim((string) $options[$key][$filterKey]);
                    }
                }
            }
        }

        return '';
    }

    /**
     * Format an OData primary key. OData string keys use doubled apostrophes;
     * numeric keys remain numeric so ROWID requests work as documented.
     *
     * @param mixed $recordId
     * @param string|null $keyType Optional `string` or `number` override
     * @return string
     */
    private function odataKey($recordId, $keyType = null)
    {
        $value = (string) $recordId;
        $normalizedType = is_string($keyType) ? strtolower(trim($keyType)) : null;
        $isNumericLiteral = preg_match('/^-?\d+(?:\.\d+)?$/', $value) === 1;
        if ($isNumericLiteral && $normalizedType !== 'string') {
            return $value;
        }

        $encoded = rawurlencode(str_replace("'", "''", $value));
        $encoded = str_replace('%27', "'", $encoded);

        return "'" . $encoded . "'";
    }

    /**
     * Build a RFC3986 query string without allowing a caller to inject a path.
     *
     * @param array $query
     * @return string
     */
    private function queryString(array $query)
    {
        if (empty($query)) {
            return '';
        }

        $pairs = [];
        foreach ($query as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $encodedKey = rawurlencode((string) $key);
            if (str_starts_with((string) $key, '$')) {
                $encodedKey = '$' . rawurlencode(substr((string) $key, 1));
            }
            $pairs[] = $encodedKey . '=' . rawurlencode((string) $value);
        }

        return empty($pairs) ? '' : '?' . implode('&', $pairs);
    }

    /**
     * Redact values that look like credentials in returned request metadata.
     * Field names and ordinary FileMaker data are otherwise untouched.
     *
     * @param mixed $value
     * @param string|null $key
     * @return mixed
     */
    private function sanitizePlaygroundValue($value, $key = null)
    {
        if ($key !== null && preg_match('/pass(word)?|token|secret|authorization|api[-_]?key|script[._-]?param/i', (string) $key)) {
            return '[redacted]';
        }
        if (!is_array($value)) {
            return $value;
        }

        $sanitized = [];
        foreach ($value as $childKey => $childValue) {
            $sanitized[$childKey] = $this->sanitizePlaygroundValue($childValue, (string) $childKey);
        }
        return $sanitized;
    }

    /**
     * Remove response headers that may contain reusable authentication state.
     *
     * @param array $headers
     * @return array
     */
    private function safeResponseHeaders(array $headers)
    {
        foreach (['set-cookie', 'authorization', 'proxy-authorization'] as $blocked) {
            unset($headers[$blocked]);
        }
        return $headers;
    }

    /**
     * Extract OData entity-set names from an XML metadata response.
     *
     * @param string $metadata
     * @return array
     */
    private function discoverODataTables($metadata)
    {
        if ($metadata === '') {
            return [];
        }

        preg_match_all('/<EntitySet\b[^>]*\bName=["\']([^"\']+)["\']/i', $metadata, $matches);
        $tables = isset($matches[1]) ? array_values(array_unique($matches[1])) : [];
        sort($tables, SORT_NATURAL | SORT_FLAG_CASE);
        return $tables;
    }

    /**
     * Normalize user-facing transport names while keeping one internal value.
     *
     * @param mixed $transport
     * @return string|null
     */
    private function normalizeTransport($transport)
    {
        if (!is_string($transport)) {
            return null;
        }

        $value = strtolower(trim($transport));

        if (in_array($value, ['data_api', 'data-api', 'dataapi', 'data api', 'data'], true)) {
            return self::TRANSPORT_DATA_API;
        }

        if (in_array($value, ['odata', 'odata_v4', 'odata-v4', 'odata v4'], true)) {
            return self::TRANSPORT_ODATA;
        }

        return null;
    }

    /**
     * Build an HTTPS/HTTP URL without changing a configured host value.
     *
     * @param string $path
     * @return string
     */
    private function buildHealthUrl($path)
    {
        $host = trim((string) $this->host);

        if (!preg_match('/^https?:\\/\\//i', $host)) {
            $host = 'https://' . $host;
        }

        return rtrim($host, '/') . '/' . ltrim($path, '/');
    }

    /**
     * Create one consistent check object for the public health result.
     *
     * @param string $key
     * @param string $label
     * @param string $status pass, fail, warning, or skipped
     * @param string $message
     * @param array $extra
     * @return array
     */
    private function healthCheckItem($key, $label, $status, $message, $extra = [])
    {
        $item = [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'ok' => $status === 'pass',
            'message' => $message,
        ];

        return array_merge($item, is_array($extra) ? $extra : []);
    }

    /**
     * Complete the health result and calculate a summary without exposing
     * credentials or raw FileMaker payloads.
     *
     * @param string|null $transport
     * @param array $checks
     * @param float $startedAt
     * @return array
     */
    private function finishHealthCheck($transport, array $checks, $startedAt)
    {
        $summary = [
            'passed' => 0,
            'failed' => 0,
            'warnings' => 0,
            'skipped' => 0,
        ];

        foreach ($checks as $check) {
            $status = isset($check['status']) ? $check['status'] : 'skipped';

            if ($status === 'pass') {
                $summary['passed']++;
            } elseif ($status === 'fail') {
                $summary['failed']++;
            } elseif ($status === 'warning') {
                $summary['warnings']++;
            } else {
                $summary['skipped']++;
            }
        }

        $ok = $summary['failed'] === 0;

        return [
            'ok' => $ok,
            'success' => $ok,
            'transport' => $transport,
            'checked_at' => gmdate('c'),
            'latency_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            'checks' => $checks,
            'summary' => $summary,
            'capabilities' => $transport === null ? [] : $this->getCapabilities($transport),
        ];
    }

    /**
     * Execute the read-only Data API health sequence.
     *
     * @param array $checks
     * @param string $layout
     * @param array $headers
     * @param array $requestOptions
     * @return void
     */
    private function runDataApiHealthCheck(array &$checks, $layout, array $headers, array $requestOptions)
    {
        $probe = $this->sendHttpRequest(
            'GET',
            $this->buildHealthUrl('/fmi/data/vLatest/productInfo'),
            $headers,
            null,
            $requestOptions
        );
        $probeCode = $this->healthHttpCode($probe);
        $probeLatency = $this->healthLatency($probe);

        $checks['server'] = $this->healthCheckItem(
            'server',
            'Server reachability',
            $probeCode > 0 ? 'pass' : 'fail',
            $probeCode > 0 ? 'The FileMaker host responded.' : $this->healthError($probe, 'The FileMaker host could not be reached.'),
            ['http_code' => $probeCode, 'latency_ms' => $probeLatency]
        );
        $apiAvailable = $probeCode > 0 && !in_array($probeCode, [404, 405], true);
        $checks['api'] = $this->healthCheckItem(
            'api',
            'Data API availability',
            $apiAvailable ? 'pass' : 'fail',
            $apiAvailable ? 'The FileMaker Data API endpoint responded.' : 'The FileMaker Data API endpoint was not available at the configured host.',
            ['http_code' => $probeCode, 'latency_ms' => $probeLatency]
        );

        $login = null;
        if ($probeCode > 0) {
            $loginStartedAt = microtime(true);
            $login = $this->login();
            $loginLatency = round((microtime(true) - $loginStartedAt) * 1000, 2);
            $authenticated = $login === true;
            $loginCode = $authenticated ? 200 : $this->healthHttpCode($login);

            $checks['authentication'] = $this->healthCheckItem(
                'authentication',
                'Data API authentication',
                $authenticated ? 'pass' : 'fail',
                $authenticated ? 'Credentials were accepted and a Data API session was established.' : $this->healthError($login, 'The Data API credentials were rejected.'),
                ['http_code' => $loginCode, 'latency_ms' => $loginLatency]
            );
        } else {
            $checks['authentication'] = $this->healthCheckItem('authentication', 'Data API authentication', 'skipped', 'Skipped because the FileMaker host did not respond.');
        }

        $authenticated = $login === true;
        if (!$authenticated) {
            $checks['database'] = $this->healthCheckItem('database', 'Database access', 'skipped', 'Skipped until Data API authentication succeeds.');
            $checks['layout_or_table'] = empty($layout)
                ? $this->healthCheckItem('layout_or_table', 'Layout access', 'warning', 'No FileMaker layout was supplied.')
                : $this->healthCheckItem('layout_or_table', 'Layout access', 'skipped', 'Skipped until Data API authentication succeeds.');
            return;
        }

        $databaseStartedAt = microtime(true);
        $databaseResult = $this->getLayoutNames();
        $databaseLatency = round((microtime(true) - $databaseStartedAt) * 1000, 2);
        $databaseOk = !$this->isError($databaseResult);
        $checks['database'] = $this->healthCheckItem(
            'database',
            'Database access',
            $databaseOk ? 'pass' : 'fail',
            $databaseOk ? 'The configured database accepted an authenticated layout metadata request.' : $this->healthError($databaseResult, 'The configured database could not be accessed.'),
            ['http_code' => $this->healthHttpCode($databaseResult), 'latency_ms' => $databaseLatency]
        );

        if (empty($layout)) {
            $checks['layout_or_table'] = $this->healthCheckItem('layout_or_table', 'Layout access', 'warning', 'Database access succeeded, but no FileMaker layout was supplied.');
            return;
        }

        $originalLayout = $this->layout;
        $this->layout = $layout;
        $layoutStartedAt = microtime(true);
        $layoutResult = $this->getLayoutMetadata();
        $layoutLatency = round((microtime(true) - $layoutStartedAt) * 1000, 2);
        $this->layout = $originalLayout;
        $layoutOk = !$this->isError($layoutResult);

        $checks['layout_or_table'] = $this->healthCheckItem(
            'layout_or_table',
            'Layout access',
            $layoutOk ? 'pass' : 'fail',
            $layoutOk ? 'The configured FileMaker layout is accessible.' : $this->healthError($layoutResult, 'The configured FileMaker layout could not be accessed.'),
            ['http_code' => $this->healthHttpCode($layoutResult), 'latency_ms' => $layoutLatency, 'name' => $layout]
        );
    }

    /**
     * Execute the read-only OData v4 health sequence.
     *
     * OData uses `$metadata` for service/database validation and a `$top=1`
     * query for the configured table. The response body is intentionally not
     * included in health results because it can contain schema details.
     *
     * @param array $checks
     * @param string $database
     * @param string $table
     * @param array $headers
     * @param array $requestOptions
     * @return void
     */
    private function runODataHealthCheck(array &$checks, $database, $table, array $headers, array $requestOptions)
    {
        $basePath = '/fmi/odata/v4/' . rawurlencode($database);
        $metadataHeaders = $headers;
        $metadataHeaders['Accept'] = 'application/xml, application/json';
        $metadata = $this->sendHttpRequest(
            'GET',
            $this->buildHealthUrl($basePath . '/$metadata'),
            $metadataHeaders,
            null,
            $requestOptions
        );
        $metadataCode = $this->healthHttpCode($metadata);
        $metadataLatency = $this->healthLatency($metadata);
        $metadataOk = $metadataCode >= 200 && $metadataCode < 300;

        $checks['server'] = $this->healthCheckItem(
            'server',
            'Server reachability',
            $metadataCode > 0 ? 'pass' : 'fail',
            $metadataCode > 0 ? 'The FileMaker host responded to the OData request.' : $this->healthError($metadata, 'The FileMaker host could not be reached.'),
            ['http_code' => $metadataCode, 'latency_ms' => $metadataLatency]
        );
        $apiAvailable = $metadataCode > 0 && !in_array($metadataCode, [404, 405], true);
        $checks['api'] = $this->healthCheckItem(
            'api',
            'OData v4 availability',
            $apiAvailable ? 'pass' : 'fail',
            $apiAvailable ? 'The FileMaker OData v4 endpoint responded.' : 'The FileMaker OData v4 endpoint was not available at the configured host.',
            ['http_code' => $metadataCode, 'latency_ms' => $metadataLatency]
        );
        $checks['authentication'] = $this->healthCheckItem(
            'authentication',
            'OData authentication',
            $metadataOk ? 'pass' : 'fail',
            $metadataOk ? 'Basic Authentication was accepted by OData.' : $this->healthError($metadata, 'OData authentication failed or the database is not enabled for OData.'),
            ['http_code' => $metadataCode, 'latency_ms' => $metadataLatency]
        );
        $checks['database'] = $this->healthCheckItem(
            'database',
            'Database access',
            $metadataOk ? 'pass' : 'fail',
            $metadataOk ? 'The OData metadata document for the configured database was returned.' : 'The OData database metadata document could not be read.',
            ['http_code' => $metadataCode, 'latency_ms' => $metadataLatency, 'name' => $database]
        );

        if (!$metadataOk) {
            $checks['layout_or_table'] = empty($table)
                ? $this->healthCheckItem('layout_or_table', 'Table access', 'warning', 'No OData table was supplied.')
                : $this->healthCheckItem('layout_or_table', 'Table access', 'skipped', 'Skipped until OData metadata/authentication succeeds.');
            return;
        }

        if (empty($table)) {
            $checks['layout_or_table'] = $this->healthCheckItem('layout_or_table', 'Table access', 'warning', 'OData database access succeeded, but no table was supplied. OData uses tables instead of layouts.');
            return;
        }

        $tableResult = $this->sendHttpRequest(
            'GET',
            $this->buildHealthUrl($basePath . '/' . rawurlencode($table) . '?$top=1'),
            $headers,
            null,
            $requestOptions
        );
        $tableCode = $this->healthHttpCode($tableResult);
        $tableOk = $tableCode >= 200 && $tableCode < 300;
        $checks['layout_or_table'] = $this->healthCheckItem(
            'layout_or_table',
            'Table access',
            $tableOk ? 'pass' : 'fail',
            $tableOk ? 'The configured OData table accepted a read-only query.' : $this->healthError($tableResult, 'The configured OData table could not be accessed.'),
            ['http_code' => $tableCode, 'latency_ms' => $this->healthLatency($tableResult), 'name' => $table]
        );
    }

    /**
     * Read an HTTP code from either a raw cURL response or a class response.
     *
     * @param mixed $response
     * @return int
     */
    private function healthHttpCode($response)
    {
        if (!is_array($response)) {
            return 0;
        }

        if (isset($response['status']['http_code'])) {
            return (int) $response['status']['http_code'];
        }

        if (isset($response['result']['status']['http_code'])) {
            return (int) $response['result']['status']['http_code'];
        }

        return 0;
    }

    /**
     * Read request latency when supplied by sendHttpRequest().
     *
     * @param mixed $response
     * @return float|null
     */
    private function healthLatency($response)
    {
        if (!is_array($response)) {
            return null;
        }

        return isset($response['latency_ms']) ? (float) $response['latency_ms'] : null;
    }

    /**
     * Produce a safe diagnostic message from a FileMaker response.
     *
     * @param mixed $response
     * @param string $fallback
     * @return string
     */
    private function healthError($response, $fallback)
    {
        if (is_array($response) && !empty($response['error']) && is_string($response['error'])) {
            return $response['error'];
        }

        $messages = null;
        if (is_array($response) && isset($response['messages']) && is_array($response['messages'])) {
            $messages = $response['messages'];
        } elseif (is_array($response) && isset($response['result']['messages']) && is_array($response['result']['messages'])) {
            $messages = $response['result']['messages'];
        }

        if (!empty($messages[0]['message'])) {
            return (string) $messages[0]['message'];
        }

        $body = is_array($response) && isset($response['body']) && is_string($response['body'])
            ? $response['body']
            : '';
        if ($body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded) && isset($decoded['error'])) {
                $error = is_array($decoded['error']) ? $decoded['error'] : [];
                $message = $error['message'] ?? null;
                if (is_array($message) && isset($message['value'])) {
                    $message = $message['value'];
                }
                if (is_string($message) && trim($message) !== '') {
                    return trim($message);
                }
            }

            if (preg_match('/<(?:[A-Za-z0-9_-]+:)?message[^>]*>([^<]+)<\/(?:[A-Za-z0-9_-]+:)?message>/i', $body, $match)) {
                $message = trim(html_entity_decode($match[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                if ($message !== '') {
                    return $message;
                }
            }
        }

        return $fallback;
    }


    // *********************************************************************************************************************************

    /**
     * Check if is set default timezone in PHP.ini
     */
    private function setTimezone()
    {
        // Intentionally left blank: do not change PHP's default timezone here.
    }

    // *********************************************************************************************************************************

    /**
     * @return mixed
     */
    public function logout()
    {
        $this->setLogRowNumber();

        $this->log(array(
            "line" => __LINE__,
            "method" => __METHOD__,
            "type" => self::LS_INFO,
            "message" => "Attempt to logout from database"
        ));

        if ($this->isLogged() === false) {
            $this->log(array(
                "line" => __LINE__,
                "method" => __METHOD__,
                "type" => self::LS_SUCCESS,
                "message" => "User is logged out - invalid token"
            ));

            $this->destroySessionToken();
        }

        $request = array(
            "url" => "/fmi/data/v1/databases/" . rawurlencode($this->db) . "/sessions/" . rawurlencode($this->token),
            "method" => "DELETE",
        );

        $result = $this->callURL($request);

        try {
            $this->isError($result, true);

            $this->log(array(
                "line" => __LINE__,
                "method" => __METHOD__,
                "type" => self::LS_SUCCESS,
                "message" => "Logout was successfull",
                "data" => $result
            ));

            $this->destroySessionToken();
        } catch (\Exception $e) {
            $this->log(array(
                "line" => __LINE__,
                "method" => __METHOD__,
                "type" => self::LS_ERROR,
                "message" => "Logout was not successfull",
                "data" => $result
            ));

            $this->destroySessionToken();
        }

        return $result;

    }

    // *********************************************************************************************************************************

    /**
     * @param string $scriptName
     * @param array $scriptPrameters
     * @return bool|mixed
     */
    public function runScript($scriptName, $scriptParameters = null)
    {
        if (empty($this->layout)) {
            return $this->formatDataApiResponse([
                'messages' => [
                    [
                        'code' => '-2',
                        'message' => 'FileMaker layout is not set. Please specify a layout.'
                    ]
                ],
                'response' => new \stdClass()
            ], 'script');
        }
        $this->setLogRowNumber();

        // Log the attempt to run a script
        $this->log([
            'line' => __LINE__,
            'method' => __METHOD__,
            'type' => self::LS_INFO,
            'message' => 'Attempt to run a script',
            'data' => $scriptParameters
        ]);

        // Build the request URL
        $url = $this->buildHealthUrl("/fmi/data/v1/databases/" . rawurlencode($this->db) . "/layouts/" . rawurlencode($this->layout) . "/script/" . rawurlencode($scriptName));

        // Append script parameters if provided
        if ($scriptParameters !== null) {
            $url .= '?' . http_build_query($scriptParameters);
        }

        // Call the centralized API handler
        return $this->formatDataApiResponse($this->callAPI('GET', $url), 'script');
    }

    // *********************************************************************************************************************************

    /**
     * @return bool|mixed
     */
    public function getDatabaseNames($relogin = false)
    {
        if (empty($this->host) || empty($this->user) || empty($this->password)) {
            return [
                'messages' => [
                    [
                        'code' => '-1',
                        'message' => 'The FileMaker connection settings are incomplete. Please check host, username, and password.'
                    ]
                ],
                'response' => new \stdClass()
            ];
        }
        $this->setLogRowNumber();

        // Log the attempt to get database names
        $this->log([
            'line' => __LINE__,
            'method' => __METHOD__,
            'type' => self::LS_INFO,
            'message' => 'Attempt to get metadata - database names'
        ]);

        // Build the request URL
        $url = "/fmi/data/vLatest/databases";

        // Use Basic Authentication for this endpoint
        $headers = [
            'Authorization' => 'Basic ' . base64_encode($this->user . ':' . $this->password),
            'Content-Type' => 'application/json'
        ];

        // Prepare the request
        $request = [
            'url' => $url,
            'method' => 'GET',
            'headers' => $headers
        ];

        // Make the request
        $response = $this->callURL($request);

        // Log the raw response
        $this->log([
            'line' => __LINE__,
            'method' => __METHOD__,
            'type' => self::LS_DEBUG,
            'message' => 'Raw response from getDatabaseNames',
            'data' => $response
        ]);

        try {
            // Check for errors
            $this->isError($response, true);

            $this->log([
                'line' => __LINE__,
                'method' => __METHOD__,
                'type' => self::LS_SUCCESS,
                'message' => 'Successfully retrieved database names',
                'data' => $response
            ]);

            return $response;
        } catch (\Exception $e) {
            $this->log([
                'line' => __LINE__,
                'method' => __METHOD__,
                'type' => self::LS_ERROR,
                'message' => 'Failed to retrieve database names',
                'data' => $response
            ]);

            // Retry with relogin if enabled
            if ($this->autorelogin && !$relogin) {
                return $this->getDatabaseNames(true);
            }
        }

        return $response;
    }



    // *********************************************************************************************************************************

    /**
     * @return bool|mixed
     */
    public function getProductInformation()
    {
        $this->setLogRowNumber();

        // Log the attempt to get product information
        $this->log([
            'line' => __LINE__,
            'method' => __METHOD__,
            'type' => self::LS_INFO,
            'message' => 'Attempt to get metadata - product information'
        ]);

        // Build the request URL
        $url = $this->buildHealthUrl('/fmi/data/vLatest/productInfo');

        // Call the centralized API handler
        return $this->callAPI('GET', $url);
    }

    // *********************************************************************************************************************************

    /**
     * @return bool|mixed
     */
    public function getScriptNames()
    {
        $this->setLogRowNumber();

        // Log the attempt to get script names
        $this->log([
            'line' => __LINE__,
            'method' => __METHOD__,
            'type' => self::LS_INFO,
            'message' => 'Attempt to get script names'
        ]);

        // Build the request URL
        $url = $this->buildHealthUrl("/fmi/data/v1/databases/" . rawurlencode($this->db) . "/scripts");

        // Call the centralized API handler
        return $this->callAPI('GET', $url);
    }

    // *********************************************************************************************************************************

    /**
     * @return bool|mixed
     */
    public function getLayoutNames()
    {
        $this->setLogRowNumber();

        // Log the attempt to get layout names
        $this->log([
            'line' => __LINE__,
            'method' => __METHOD__,
            'type' => self::LS_INFO,
            'message' => 'Attempt to get layout names'
        ]);

        // Build the request URL
        $url = $this->buildHealthUrl("/fmi/data/v1/databases/" . rawurlencode($this->db) . "/layouts");

        // Call the centralized API handler
        return $this->callAPI('GET', $url);
    }

    // *********************************************************************************************************************************

    /**
     * @return bool|mixed
     */
    public function getLayoutMetadata()
    {
        if (empty($this->layout)) {
            return [
                'messages' => [
                    [
                        'code' => '-2',
                        'message' => 'FileMaker layout is not set. Please specify a layout.'
                    ]
                ],
                'response' => new \stdClass()
            ];
        }
        $this->setLogRowNumber();

        // Log the attempt to get layout metadata
        $this->log([
            'line' => __LINE__,
            'method' => __METHOD__,
            'type' => self::LS_INFO,
            'message' => 'Attempt to get layout metadata'
        ]);

        // Build the request URL
        $url = $this->buildHealthUrl("/fmi/data/v1/databases/" . rawurlencode($this->db) . "/layouts/" . rawurlencode($this->layout));

        // Call the centralized API handler
        return $this->callAPI('GET', $url);
    }

    // *********************************************************************************************************************************

    /**
     * @param array $parameters
     * @return bool|mixed
     */
    public function createRecord($parameters)
    {
        if (empty($this->layout)) {
            return $this->formatDataApiResponse([
                'messages' => [
                    [
                        'code' => '-2',
                        'message' => 'FileMaker layout is not set. Please specify a layout.'
                    ]
                ],
                'response' => new \stdClass()
            ], 'create');
        }
        $this->setLogRowNumber();

        // Log the attempt to create a record
        $this->log([
            'line' => __LINE__,
            'method' => __METHOD__,
            'type' => self::LS_INFO,
            'message' => 'Attempt to create a record',
            'data' => $parameters
        ]);

        // Build the request URL
        $url = $this->buildHealthUrl("/fmi/data/v1/databases/" . rawurlencode($this->db) . "/layouts/" . rawurlencode($this->layout) . "/records");

        // Call the centralized API handler, passing the parameters as the body
        return $this->formatDataApiResponse($this->callAPI('POST', $url, $parameters), 'create');
    }

    // *********************************************************************************************************************************

    /**
     * @param integer $id
     * @param array $parameters
     * @return bool|mixed
     */
    public function deleteRecord($id, $parameters = null)
    {
        if (empty($this->layout)) {
            return $this->formatDataApiResponse([
                'messages' => [
                    [
                        'code' => '-2',
                        'message' => 'FileMaker layout is not set. Please specify a layout.'
                    ]
                ],
                'response' => new \stdClass()
            ], 'delete');
        }
        $this->setLogRowNumber();

        // Log the attempt to delete a record
        $this->log([
            'line' => __LINE__,
            'method' => __METHOD__,
            'type' => self::LS_INFO,
            'message' => 'Attempt to delete a record',
            'data' => $parameters
        ]);

        // Build the request URL
        $url = $this->buildHealthUrl("/fmi/data/v1/databases/" . rawurlencode($this->db) . "/layouts/" . rawurlencode($this->layout) . "/records/" . rawurlencode((string) $id));

        // Append parameters to the URL if provided
        if ($parameters !== null) {
            $url .= '?' . http_build_query($parameters);
        }

        // Call the centralized API handler
        return $this->formatDataApiResponse($this->callAPI('DELETE', $url), 'delete');
    }

    // *********************************************************************************************************************************

    /**
     * @param integer $id
     * @param array $parameters
     * @return bool|mixed
     */
    public function duplicateRecord($id, $parameters = null)
    {
        if (empty($this->layout)) {
            return [
                'messages' => [
                    [
                        'code' => '-2',
                        'message' => 'FileMaker layout is not set. Please specify a layout.'
                    ]
                ],
                'response' => new \stdClass()
            ];
        }
        $this->setLogRowNumber();

        // Log the attempt to duplicate a record
        $this->log([
            'line' => __LINE__,
            'method' => __METHOD__,
            'type' => self::LS_INFO,
            'message' => 'Attempt to duplicate a record',
            'data' => $parameters
        ]);

        // Build the request URL
        $url = $this->buildHealthUrl("/fmi/data/v1/databases/" . rawurlencode($this->db) . "/layouts/" . rawurlencode($this->layout) . "/records/" . rawurlencode((string) $id) . "/duplicate");

        // Call the centralized API handler with POST method
        return $this->callAPI('POST', $url, $parameters);
    }

    // *********************************************************************************************************************************

    /**
     * @param integer $id
     * @param array $parameters
     * @return bool|mixed
     */
    public function editRecord($id, $parameters)
    {
        $this->setLogRowNumber();

        // Log the attempt to edit a record
        $this->log([
            'line' => __LINE__,
            'method' => __METHOD__,
            'type' => self::LS_INFO,
            'message' => 'Attempt to edit a record',
            'data' => $parameters
        ]);

        // Build the request URL
        $url = $this->buildHealthUrl("/fmi/data/v1/databases/" . rawurlencode($this->db) . "/layouts/" . rawurlencode($this->layout) . "/records/" . rawurlencode((string) $id));

        // Call the centralized API handler with PATCH method
        return $this->formatDataApiResponse($this->callAPI('PATCH', $url, $parameters), 'update');
    }

    // *********************************************************************************************************************************

    /**
     * @param integer $id
     * @option array $parameters
     * @return bool|mixed
     */
    public function getRecord($id, $parameters = null)
    {
        if (empty($this->layout)) {
            return $this->formatDataApiResponse([
                'messages' => [
                    [
                        'code' => '-2',
                        'message' => 'FileMaker layout is not set. Please specify a layout.'
                    ]
                ],
                'response' => new \stdClass()
            ], 'get');
        }
        $this->setLogRowNumber();

        // Log the attempt to get a record
        $this->log([
            'line' => __LINE__,
            'method' => __METHOD__,
            'type' => self::LS_INFO,
            'message' => 'Attempt to get a record from the database',
            'data' => $parameters
        ]);

        // Build the request URL
        $url = $this->buildHealthUrl("/fmi/data/v1/databases/" . rawurlencode($this->db) . "/layouts/" . rawurlencode($this->layout) . "/records/" . rawurlencode((string) $id));

        // Append query parameters if provided
        if ($parameters !== null) {
            $url .= "?" . http_build_query($parameters);
        }

        // Call the centralized API handler
        return $this->formatDataApiResponse($this->callAPI('GET', $url), 'get');
    }

    // *********************************************************************************************************************************

    /**
     * @option array $parameters
     * @return bool|mixed
     */
    public function getRecords($parameters = null)
    {
        if (empty($this->layout)) {
            return $this->formatDataApiResponse([
                'messages' => [
                    [
                        'code' => '-2',
                        'message' => 'FileMaker layout is not set. Please specify a layout.'
                    ]
                ],
                'response' => new \stdClass()
            ], 'list');
        }
        $this->setLogRowNumber();

        // Log the attempt to get records
        $this->log([
            'line' => __LINE__,
            'method' => __METHOD__,
            'type' => self::LS_INFO,
            'message' => 'Attempt to get records',
            'data' => $parameters
        ]);

        if (isset($parameters['limit'])) {
            $parameters['_limit'] = $parameters['limit'];
            unset($parameters['limit']);
        }
        if (isset($parameters['offset'])) {
            $parameters['_offset'] = $parameters['offset'];
            unset($parameters['offset']);
        }

        // Build the request URL
        $url = $this->buildHealthUrl("/fmi/data/v1/databases/" . rawurlencode($this->db) . "/layouts/" . rawurlencode($this->layout) . "/records/");

        // Append query parameters if provided
        if ($parameters !== null) {
            $url .= '?' . http_build_query($parameters);
        }

        // Call the centralized API handler with GET method
        return $this->formatDataApiResponse($this->callAPI('GET', $url), 'list');
    }

    // *********************************************************************************************************************************

    /**
     * @param array $parameters
     * @return bool|mixed
     */
    public function findRecords($parameters)
    {
        if (empty($this->layout)) {
            return $this->formatDataApiResponse([
                'messages' => [
                    [
                        'code' => '-2',
                        'message' => 'FileMaker layout is not set. Please specify a layout.'
                    ]
                ],
                'response' => new \stdClass()
            ], 'find');
        }
        $this->setLogRowNumber();

        // Log the attempt to find records
        $this->log(array(
            "line" => __LINE__,
            "method" => __METHOD__,
            "type" => self::LS_INFO,
            "message" => "Attempt to find records",
            "data" => $parameters
        ));

        // Build the request URL
        $url = $this->buildHealthUrl("/fmi/data/v1/databases/" . rawurlencode($this->db) . "/layouts/" . rawurlencode($this->layout) . "/_find");

        // Call the centralized API handler
        return $this->formatDataApiResponse($this->callAPI('POST', $url, $parameters), 'find');
    }

    // *********************************************************************************************************************************

    /**
     * @param array $parameters
     * @return bool|mixed
     */
    public function setGlobalField($parameters)
    {
        if (empty($this->layout)) {
            return [
                'messages' => [
                    [
                        'code' => '-2',
                        'message' => 'FileMaker layout is not set. Please specify a layout.'
                    ]
                ],
                'response' => new \stdClass()
            ];
        }
        $this->setLogRowNumber();

        // Log the attempt to set global fields
        $this->log(array(
            "line" => __LINE__,
            "method" => __METHOD__,
            "type" => self::LS_INFO,
            "message" => "Attempt to set global fields",
            "data" => $parameters
        ));

        // Build the request URL
        $url = $this->buildHealthUrl("/fmi/data/v1/databases/" . rawurlencode($this->db) . "/globals/");

        // Call the centralized API handler
        return $this->callAPI('PATCH', $url, $parameters);
    }

    // *********************************************************************************************************************************

    /**
     * @param integer $id
     * @param string $containerFieldName
     * @param string containerFieldRepetition
     * @param array $file
     * @return bool|mixed
     */
    public function uploadFileToContainer($id, $containerFieldName, $containerFieldRepetition, $path)
    {
        if (empty($this->layout)) {
            return [
                'messages' => [
                    [
                        'code' => '-2',
                        'message' => 'FileMaker layout is not set. Please specify a layout.'
                    ]
                ],
                'response' => new \stdClass()
            ];
        }
        $this->setLogRowNumber();

        // Ensure that the file exists
        if (!file_exists($path)) {
            return [
                'status' => ['http_code' => 0],
                'result' => 'File not found at the provided path.'
            ];
        }

        // Log the file upload attempt
        $this->log(array(
            "line" => __LINE__,
            "method" => __METHOD__,
            "type" => self::LS_INFO,
            "message" => "Attempt to upload a file to a container",
            "data" => $path
        ));

        // Build the request URL
        $url = "/fmi/data/v1/databases/" . rawurlencode($this->db) . "/layouts/" . rawurlencode($this->layout) . "/records/" . $id . "/containers/" . $containerFieldName . "/" . $containerFieldRepetition;

        // Prepare file data for multipart/form-data upload
        $fileData = [
            'upload' => new \CURLFile($path, mime_content_type($path), basename($path))
        ];

        // Call the new file upload API handler
        return $this->callFileUploadAPI('POST', $url, $fileData);
    }



    // *********************************************************************************************************************************
    /**
     * @param integer $id
     * @param string $containerFieldName
     * @param string containerFieldRepetition
     * @param array $file
     * @return bool|mixed
     */
    public function uploadFormDataToContainer($id, $containerFieldName, $containerFieldRepetition, $file)
    {
        if (empty($this->layout)) {
            return [
                'messages' => [
                    [
                        'code' => '-2',
                        'message' => 'FileMaker layout is not set. Please specify a layout.'
                    ]
                ],
                'response' => new \stdClass()
            ];
        }
        $this->setLogRowNumber();

        $this->log(array(
            "line" => __LINE__,
            "method" => __METHOD__,
            "type" => self::LS_INFO,
            "message" => "Attempt to upload a form data file to a container",
            "data" => $file
        ));

        // Ensure the file exists
        if (!isset($file['tmp_name']) || !file_exists($file['tmp_name'])) {
            return [
                'status' => ['http_code' => 0],
                'result' => 'File not found at the provided path.'
            ];
        }

        // Build the request URL
        $url = "/fmi/data/v1/databases/" . rawurlencode($this->db) . "/layouts/" . rawurlencode($this->layout) . "/records/" . $id . "/containers/" . $containerFieldName . "/" . $containerFieldRepetition;

        // Prepare file data for multipart/form-data upload
        $fileData = [
            'upload' => new \CURLFile($file['tmp_name'], mime_content_type($file['tmp_name']), $file['name'])
        ];

        // Call the new file upload API handler
        return $this->callFileUploadAPI('POST', $url, $fileData);
    }


    // *********************************************************************************************************************************

    /**
     * @param string $layout
     * @return bool
     */
    public function setFilemakerLayout($layout)
    {
        if (is_string($layout)) {
            $this->layout = $layout;
            return true;
        } else {
            return false;
        }
    }

    // *********************************************************************************************************************************

    /**
     * @param array $parameters
     * @return null|string
     */
    private function convertParametersToJson($parameters)
    {
        if (is_array($parameters)) {
            if (!empty($parameters)) {
                return self::jsonEncode($parameters);
            } else {
                return null;
            }
        }
        return null;
    }

    // *********************************************************************************************************************************

    /**
     * @param array $parameters
     * @return string
     */
    private function convertParametersToString($parameters)
    {
        if (is_array($parameters)) {
            if (!empty($parameters)) {
                return http_build_query($parameters);
            } else {
                return "";
            }
        }
        return "";
    }

    // *********************************************************************************************************************************

    /**
     * @param array $requestSettings
     * @option array $data
     * @return mixed
     */
    private function callURL($requestSettings, $data = null)
    {
        $this->log(array(
            "line" => __LINE__,
            "method" => __METHOD__,
            "type" => self::LS_INFO,
            "message" => "Sending request using standalone HTTP client"
        ));

        $headers = (isset($requestSettings["headers"]) ? $requestSettings["headers"] : []);
        $method = $requestSettings["method"];
        $baseUrl = trim((string) $this->host);
        if (!preg_match('/^https?:\/\//i', $baseUrl)) {
            $baseUrl = 'https://' . $baseUrl;
        }
        $url = rtrim($baseUrl, '/') . '/' . ltrim((string) $requestSettings["url"], '/');

        // If data is provided and the method is not GET, ensure it's passed in the body as JSON
        $body = null;
        if ($data !== null && $method !== 'GET') {
            $body = self::jsonEncode($data);
            $headers['Content-Type'] = 'application/json';  // Ensure the Content-Type is JSON
        }

        $response = $this->sendHttpRequest($method, $url, $headers, $body, [
            'timeout' => $this->timeout,
            'verify_ssl' => !$this->allowInsecure,
        ]);

        if (!empty($response['error'])) {
            return [
                'status' => ['http_code' => 0], // No valid HTTP status
                'result' => $response['error']
            ];
        }

        // Decode the JSON response
        $responseBody = $response['body'];
        $decodedBody = json_decode($responseBody, true);

        // Log the successful request
        $this->log(array(
            "line" => __LINE__,
            "method" => __METHOD__,
            "type" => self::LS_INFO,
            "message" => "Request sent successfully"
        ));

        return [
            'status' => ['http_code' => $response['status']['http_code']],
            'result' => ($decodedBody !== null ? $decodedBody : $responseBody)
        ];
    }

    // *********************************************************************************************************************************

    private function getUserIdentifier() {
        if (isset($_COOKIE[$this->cookieName]) && is_string($_COOKIE[$this->cookieName]) && $_COOKIE[$this->cookieName] !== '') {
            return $this->cleanIdentifier($_COOKIE[$this->cookieName]);
        }

        if (session_status() === PHP_SESSION_ACTIVE && session_id() !== '') {
            return session_id();
        }

        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            $this->startSessionIfNeeded();
            $_SESSION[$this->cookieName] = $_SESSION[$this->cookieName] ?? $this->uuid4();
            $this->setIdentifierCookie((string) $_SESSION[$this->cookieName]);

            return (string) $_SESSION[$this->cookieName];
        }

        $parts = [
            $_SERVER['REMOTE_ADDR'] ?? 'unknown_ip',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown_user_agent',
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'unknown_language',
        ];

        return hash('sha256', implode('|', $parts));
    }

    // *********************************************************************************************************************************

    /**
     * @return bool|mixed
     */
    public function login()
    {
        if (empty($this->host) || empty($this->db) || empty($this->user) || empty($this->password)) {
            return [
                'messages' => [
                    [
                        'code' => '-1',
                        'message' => 'The FileMaker connection settings are incomplete. Please check host, database, username, and password.'
                    ]
                ],
                'response' => new \stdClass()
            ];
        }
        // Cleanup old token files only if file storage is used
        if ($this->tokenStorage === self::TS_FILE) {
            $this->cleanupOldTokenFiles();
        }

        // Check for existing token for the current host
        $tokenProps = $this->getCurrentFileMakerTokenProps();
        if (!empty($tokenProps)) {
            // If the token exists, check if it's valid
            $currentHost = $this->host;
            if ($tokenProps['host'] !== $currentHost) {
                $this->destroySessionToken(); // Clear the token for the previous host
            }
        }

        // Prepare the request to obtain the token
        $request = [
            "url" => "/fmi/data/v1/databases/" . rawurlencode($this->db) . "/sessions",
            "method" => "POST",
            "headers" => [
                "Authorization" => "Basic " . base64_encode($this->user . ":" . $this->password),
                "Content-Type"  => "application/json"
            ]
        ];

        // Perform the request
        $response = $this->callURL($request);

        // Check if the response contains an error
        if ($this->isError($response)) {
            return $response;
        }

        // Check if the response contains the token
        if (isset($response["result"]["response"]["token"])) {
            $this->token = $response["result"]["response"]["token"]; // Set the token

            // Calculate token expiration time
            $currentTime = new \DateTime();
            $expireTime = $currentTime->modify("+" . $this->tokenExpireTime . " minutes");

            // Store the token and expiration time to the file
            $this->setFileMakerTokenProps([
                "token" => $this->token,
                "expire" => $expireTime->format("Y-m-d H:i:s"),
                "host" => $this->host // Save the host for future reference
            ]);

            return true; // Return success
        } else {
            // Log failure to acquire the token
            $this->log([
                "line" => __LINE__,
                "method" => __METHOD__,
                "type" => self::LS_ERROR,
                "message" => "Token not found in response",
                "data" => $response
            ]);

            return $response;
        }
    }



    // *********************************************************************************************************************************

    /**
     * @return bool
     */
    private function isLogged()
    {
        $this->log([
            'line' => __LINE__,
            'method' => __METHOD__,
            'type' => self::LS_INFO,
            'message' => 'Checking if user is logged into the database'
        ]);

        $tokenProps = $this->getCurrentFileMakerTokenProps();

        if (!empty($tokenProps)) {
            if ($tokenProps['host'] !== $this->host || $tokenProps['db'] !== $this->db) {
                $this->log([
                    'line' => __LINE__,
                    'method' => __METHOD__,
                    'type' => self::LS_WARNING,
                    'message' => 'Token belongs to a different host or database. Clearing it.'
                ]);
                $this->destroySessionToken(); // Clear mismatched token
                return false;
            }

            $currentTime = new \DateTime();
            $tokenExpire = \DateTime::createFromFormat('Y-m-d H:i:s', $tokenProps['expire']);

            if ($tokenExpire === false || $currentTime >= $tokenExpire) {
                $this->log([
                    'line' => __LINE__,
                    'method' => __METHOD__,
                    'type' => self::LS_WARNING,
                    'message' => 'Token is expired or invalid'
                ]);
                return false;
            }

            $this->setToken($tokenProps['token']);
            return true;
        }

        return false;
    }




    // *********************************************************************************************************************************

    private function extendTokenExpiration()
    {
        $this->log(array(
            "line" => __LINE__,
            "method" => __METHOD__,
            "type" => self::LS_INFO,
            "message" => "Token expiration time extending"
        ));

        $currentTime = new \DateTime();
        $tokenExpire = $currentTime->modify("+" . $this->tokenExpireTime . "minutes");
        $this->setFileMakerTokenProps(["expire" => $tokenExpire->format("Y-m-d H:i:s")]);
    }

    // *********************************************************************************************************************************

    private function getCurrentFileMakerTokenProps()
    {
        return $this->getStoredToken($this->getTokenStoreKey());
    }



    // *********************************************************************************************************************************

    private function setFileMakerTokenProps($data)
    {
        $currentData = $this->getCurrentFileMakerTokenProps();

        if (empty($currentData)) {
            $currentData = [
                "token" => "",
                "expire" => "",
                "host" => "",
                "db" => "", // Include database in token data
                "timestamp" => ""
            ];
        }

        // Update data with provided values
        if (isset($data["token"])) {
            $currentData["token"] = $data["token"];
            $this->setToken($data["token"]);
        }
        if (isset($data["expire"])) {
            $currentData["expire"] = $data["expire"];
        }
        if (isset($data["host"])) {
            $currentData["host"] = $data["host"];
        } else {
            $currentData["host"] = $this->host;
        }
        if (isset($data["db"])) {
            $currentData["db"] = $data["db"];
        } else {
            $currentData["db"] = $this->db; // Ensure database is included
        }
        $currentData["timestamp"] = gmdate("Y-m-d H:i:s");

        $this->setStoredToken($this->getTokenStoreKey(), $currentData, $this->tokenExpireTime * 60);
    }




    // *********************************************************************************************************************************

    private function setToken($token)
    {
        $this->token = $token;
    }

    private function getTokenStoreKey()
    {
        return $this->makeTokenKey($this->host, $this->db, $this->getUserIdentifier());
    }

    private function destroySessionToken()
    {
        $this->deleteStoredToken($this->getTokenStoreKey());
    }


    // *********************************************************************************************************************************

    /**
     * @param array $log
     */
    private function log($log)
    {
        $type = $log["type"] ?? '';
        $message = $log["message"] ?? '';
        $section = $log["method"] ?? '';
        $data = $log["data"] ?? '';

        if ($this->logType !== self::LOG_TYPE_NONE) {
            if ($this->logType == self::LOG_TYPE_ERRORS && $type === self::LS_ERROR || $this->logType == self::LOG_TYPE_DEBUG) {

                /* --- Define basic variable needed for log function --- */
                $log_message = "";
                $split_string = "\t";

                /* --- Row number --- */
                $log_message .= $this->rowNumber . $split_string;

                /* --- Date & Time --- */
                $log_message .= gmdate("Y-m-d H:i:s") . $split_string;

                /* --- Section name --- */
                $log_message .= !empty($section) ? $section . $split_string : "" . $split_string;

                /* --- Type --- */
                $log_message .= strtoupper($type) . $split_string;

                /* --- Data --- */
                $log_message .= !empty($data) ? self::jsonEncode($data) . $split_string : "";

                /* --- Message --- */
                $log_message .= !empty($message) ? $message : "";

                $log_message .= "\n";

                /* --- Save log to file --- */
                $pathDir = $this->logDir;
                $file = "fm-api-log_" . gmdate("d.m.Y") . ".txt";

                if (!is_dir($pathDir)) {
                    mkdir($pathDir, 0775, true);
                }

                if (is_dir($pathDir) && is_writable($pathDir)) {
                    file_put_contents($pathDir . $file, $log_message, FILE_APPEND | LOCK_EX);
                }
            }
        }
    }

    // *********************************************************************************************************************************

    private function defaultStoragePath($type)
    {
        if (defined('REST_CWP_STORAGE_PATH')) {
            return rtrim(REST_CWP_STORAGE_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $type;
        }

        return __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . $type;
    }

    private function ensureStorageDirectories()
    {
        foreach ([$this->logDir, $this->tokenFilePath] as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }

            if (!is_dir($path)) {
                mkdir($path, 0775, true);
            }
        }
    }

    private static function jsonEncode($data)
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new \RuntimeException('JSON encode failed: ' . json_last_error_msg());
        }

        return $json;
    }

    private static function jsonDecode($json, $assoc = true)
    {
        $data = json_decode($json, $assoc);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('JSON decode failed: ' . json_last_error_msg());
        }

        return $data;
    }

    private function sendHttpRequest($method, $url, $headers = [], $body = null, $options = [])
    {
        if (!function_exists('curl_init')) {
            return $this->transportError('The PHP cURL extension is not available.');
        }

        $method = strtoupper($method);
        $timeout = isset($options['timeout']) ? (int) $options['timeout'] : $this->timeout;
        $verifySsl = array_key_exists('verify_ssl', $options) ? (bool) $options['verify_ssl'] : !$this->allowInsecure;
        $responseHeaders = [];
        $startedAt = microtime(true);
        $curl = curl_init($url);

        if ($curl === false) {
            return $this->transportError('Failed to initialize cURL.');
        }

        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, min(10, $timeout));
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, $verifySsl);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, $verifySsl ? 2 : 0);
        curl_setopt($curl, CURLOPT_HEADERFUNCTION, static function ($curl, $headerLine) use (&$responseHeaders) {
            $length = strlen($headerLine);
            $headerLine = trim($headerLine);

            if ($headerLine === '' || strpos($headerLine, ':') === false) {
                return $length;
            }

            list($name, $value) = explode(':', $headerLine, 2);
            $responseHeaders[strtolower(trim($name))] = trim($value);

            return $length;
        });

        if ($body === null && !in_array($method, ['GET', 'HEAD'], true)) {
            $body = '';
        }

        if ($body !== null) {
            if (is_array($body)) {
                $body = self::jsonEncode($body);
            }

            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        if (!empty($headers)) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, $this->formatHeaders($headers));
        }

        foreach ($this->curlOptions as $keyOption => $optionValue) {
            curl_setopt($curl, $keyOption, $optionValue);
        }

        $responseBody = curl_exec($curl);
        $error = curl_error($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        curl_close($curl);

        if ($responseBody === false) {
            return [
                'status' => ['http_code' => 0],
                'body' => '',
                'headers' => $responseHeaders,
                'error' => $error !== '' ? $error : 'Unknown cURL transport error.',
                'latency_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            ];
        }

        return [
            'status' => ['http_code' => $httpCode],
            'body' => (string) $responseBody,
            'headers' => $responseHeaders,
            'error' => null,
            'latency_ms' => round((microtime(true) - $startedAt) * 1000, 2),
        ];
    }

    private function transportError($message)
    {
        return [
            'status' => ['http_code' => 0],
            'body' => '',
            'headers' => [],
            'error' => $message,
        ];
    }

    private function formatHeaders($headers)
    {
        $formatted = [];

        foreach ($headers as $name => $value) {
            $formatted[] = $name . ': ' . $value;
        }

        return $formatted;
    }

    private function buildMultipartFormData($fields)
    {
        $boundary = 'restCWPBoundary' . bin2hex(random_bytes(12));
        $parts = [];

        foreach ($fields as $name => $content) {
            if ($content instanceof \CURLFile) {
                $filePath = $content->getFilename();

                if (!is_file($filePath)) {
                    throw new \InvalidArgumentException('Multipart file does not exist: ' . $filePath);
                }

                $contents = file_get_contents($filePath);

                if ($contents === false) {
                    throw new \RuntimeException('Unable to read multipart file: ' . $filePath);
                }

                $filename = $content->getPostFilename() ?: basename($filePath);
                $mimeType = $content->getMimeType() ?: 'application/octet-stream';
                $parts[] = sprintf(
                    "--%s\r\nContent-Disposition: form-data; name=\"%s\"; filename=\"%s\"\r\nContent-Type: %s\r\n\r\n%s\r\n",
                    $boundary,
                    addcslashes((string) $name, "\"\\"),
                    addcslashes((string) $filename, "\"\\"),
                    $mimeType,
                    $contents
                );
            } else {
                $parts[] = sprintf(
                    "--%s\r\nContent-Disposition: form-data; name=\"%s\"\r\n\r\n%s\r\n",
                    $boundary,
                    addcslashes((string) $name, "\"\\"),
                    (string) $content
                );
            }
        }

        return [
            'contentType' => 'multipart/form-data; boundary=' . $boundary,
            'body' => implode('', $parts) . "--{$boundary}--\r\n",
        ];
    }

    private function startSessionIfNeeded()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (headers_sent()) {
            throw new \RuntimeException('Cannot start a PHP session after headers have been sent.');
        }

        session_start();
    }

    private function setIdentifierCookie($value)
    {
        if (headers_sent()) {
            return;
        }

        setcookie($this->cookieName, $value, [
            'expires' => time() + 315360000,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $_COOKIE[$this->cookieName] = $value;
    }

    private function cleanIdentifier($value)
    {
        $clean = preg_replace('/[^A-Za-z0-9_.:-]+/', '', $value);

        return $clean !== '' ? $clean : hash('sha256', $value);
    }

    private function uuid4()
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function makeTokenKey($host, $database, $userIdentifier)
    {
        return implode('_', [
            'fm_api_token',
            rawurlencode($host),
            rawurlencode($database),
            rawurlencode($userIdentifier),
        ]);
    }

    private function getStoredToken($key)
    {
        if ($this->tokenStorage === self::TS_FILE) {
            return $this->getFileToken($key);
        }

        try {
            $sessionToken = $this->getSessionToken($key);
            if ($sessionToken !== null) {
                return $sessionToken;
            }
        } catch (\RuntimeException $e) {
            // Fall back to files when sessions cannot be started.
        }

        return $this->getFileToken($key);
    }

    private function setStoredToken($key, $data, $ttlSeconds)
    {
        if ($this->tokenStorage === self::TS_FILE) {
            $this->setFileToken($key, $data, $ttlSeconds);
            return;
        }

        try {
            $this->setSessionToken($key, $data, $ttlSeconds);
            return;
        } catch (\RuntimeException $e) {
            $this->setFileToken($key, $data, $ttlSeconds);
        }
    }

    private function deleteStoredToken($key)
    {
        if ($this->tokenStorage !== self::TS_FILE) {
            try {
                $this->deleteSessionToken($key);
            } catch (\RuntimeException $e) {
                // Continue deleting fallback file storage.
            }
        }

        $this->deleteFileToken($key);
    }

    private function getSessionToken($key)
    {
        $this->startSessionIfNeeded();
        $stored = $_SESSION[$this->sessionName][$key] ?? null;

        if (!is_array($stored)) {
            return null;
        }

        $expiresAt = isset($stored['_expires_at']) ? (int) $stored['_expires_at'] : 0;

        if ($expiresAt > 0 && $expiresAt < time()) {
            $this->deleteSessionToken($key);
            return null;
        }

        unset($stored['_expires_at']);

        return $stored;
    }

    private function setSessionToken($key, $data, $ttlSeconds)
    {
        $this->startSessionIfNeeded();

        if (!isset($_SESSION[$this->sessionName]) || !is_array($_SESSION[$this->sessionName])) {
            $_SESSION[$this->sessionName] = [];
        }

        $data['_expires_at'] = time() + max(1, (int) $ttlSeconds);
        $_SESSION[$this->sessionName][$key] = $data;
    }

    private function deleteSessionToken($key)
    {
        $this->startSessionIfNeeded();
        unset($_SESSION[$this->sessionName][$key]);
    }

    private function getFileToken($key)
    {
        $path = $this->tokenPathForKey($key);

        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false || $contents === '') {
            return null;
        }

        try {
            $stored = self::jsonDecode($contents);
        } catch (\RuntimeException $e) {
            $this->deleteFileToken($key);
            return null;
        }

        if (!is_array($stored)) {
            return null;
        }

        $expiresAt = isset($stored['_expires_at']) ? (int) $stored['_expires_at'] : 0;

        if ($expiresAt > 0 && $expiresAt < time()) {
            $this->deleteFileToken($key);
            return null;
        }

        unset($stored['_expires_at']);

        return $stored;
    }

    private function setFileToken($key, $data, $ttlSeconds)
    {
        if (!is_dir($this->tokenFilePath)) {
            mkdir($this->tokenFilePath, 0775, true);
        }

        $data['_expires_at'] = time() + max(1, (int) $ttlSeconds);
        file_put_contents($this->tokenPathForKey($key), self::jsonEncode($data), LOCK_EX);
    }

    private function deleteFileToken($key)
    {
        $path = $this->tokenPathForKey($key);

        if (is_file($path)) {
            unlink($path);
        }
    }

    private function cleanupExpiredTokenFiles()
    {
        if (!is_dir($this->tokenFilePath)) {
            return 0;
        }

        $deleted = 0;
        $files = glob($this->tokenFilePath . DIRECTORY_SEPARATOR . '*.json');

        if ($files === false) {
            return 0;
        }

        foreach ($files as $file) {
            $contents = file_get_contents($file);

            try {
                $stored = is_string($contents) && $contents !== '' ? self::jsonDecode($contents) : null;
            } catch (\RuntimeException $e) {
                $stored = null;
            }

            $expiresAt = is_array($stored) && isset($stored['_expires_at']) ? (int) $stored['_expires_at'] : 0;

            if ($expiresAt > 0 && $expiresAt < time() && is_file($file)) {
                unlink($file);
                $deleted++;
            }
        }

        return $deleted;
    }

    private function tokenPathForKey($key)
    {
        return rtrim($this->tokenFilePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $this->safeFilename($key) . '.json';
    }

    private function safeFilename($value)
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $value);
        $safe = trim((string) $safe, '._-');

        return $safe !== '' ? $safe : hash('sha256', $value);
    }

    // *********************************************************************************************************************************

    private function initFilesystem() {
        // Kept for backward compatibility with the original class internals.
        // Standalone restCWP uses native PHP filesystem functions directly.
    }

    // *********************************************************************************************************************************

    /**
     * @param integer $code
     */
    private function response($code)
    {
        throw new \InvalidArgumentException((string) $code, (int) $code);
    }

    // *********************************************************************************************************************************

    public function isError($result, $throwException = false)
    {
        // Also check for http_code === 0, which indicates an internal or connection error.
        if (isset($result['status']['http_code']) && (in_array($result['status']['http_code'], self::ERROR_RESPONSE_CODE) || $result['status']['http_code'] === 0)) {
            if ($throwException) {
                throw new \Exception(self::jsonEncode($result['result']), intval($result['status']['http_code']));
            }
            return true;
        }

        $messages = null;

        if (isset($result['messages']) && is_array($result['messages'])) {
            $messages = $result['messages'];
        } elseif (isset($result['result']['messages']) && is_array($result['result']['messages'])) {
            $messages = $result['result']['messages'];
        }

        // Check for FileMaker-specific errors
        if (isset($messages[0]['code'])) {
            $errorCode = intval($messages[0]['code']);
            if ($errorCode !== 0) {
                if ($throwException) {
                    throw new \Exception((string) $messages[0]['message'], intval($errorCode));
                }
                return true;
            }
        }

        return false;
    }


    // *********************************************************************************************************************************

    public function isRecordExist($result){
        $recordMissingErrorCode = 401;

        if(isset($result["result"]["messages"][0]["code"]) && intval($result["result"]["messages"][0]["code"]) === $recordMissingErrorCode){
            return false;
        }
        return true;
    }

    // *********************************************************************************************************************************

    public function getResponse($result)
    {
        if (is_array($result) && isset($result['transport'], $result['records']) && array_key_exists('ok', $result)) {
            return $result;
        }
        if (isset($result["result"])) {
            return $result["result"];
        }

        // If "result" key is not found, return the entire $result array or a default message
        return $result;
    }

    /**
     * Normalize a captured Data API result into the stable OpenBridge v1
     * envelope without changing FileMaker field names or values.
     */
    public function normalizeResponse($result, $operation = 'request', $resource = null)
    {
        return OpenBridgeResponseNormalizer::dataApi($result, [
            'operation' => (string) $operation,
            'database' => (string) $this->db,
            'resource' => $resource === null ? (string) $this->layout : (string) $resource,
        ]);
    }

    /** Apply normalized output only when explicitly requested by the caller. */
    private function formatDataApiResponse($result, $operation)
    {
        return $this->responseFormat === self::RESPONSE_NORMALIZED
            ? $this->normalizeResponse($result, $operation)
            : $result;
    }


    // *********************************************************************************************************************************

    private function callAPI($method, $url, $body = null, $retry = false)
    {
        // Ensure the token is valid
        if ($this->isLogged() === false) {
            $login = $this->login();
            if ($login !== true) {
                return $login; // Return login error
            }
        }

        // Set headers including the Bearer token
        $headers = [
            'Authorization' => 'Bearer ' . $this->token,
            'Content-Type'  => 'application/json'
        ];

        $response = $this->sendHttpRequest($method, $url, $headers, $body, [
            'timeout' => $this->timeout,
            'verify_ssl' => !$this->allowInsecure,
        ]);

        if (!empty($response['error'])) {
            return [
                'status' => ['http_code' => 0],
                'result' => $response['error']
            ];
        }

        // Decode the response body
        $result = json_decode($response['body'], true);
        $httpCode = $response['status']['http_code'];

        // Handle token invalidation (e.g., error 952 or HTTP 401)
        if ($httpCode === 401 || (isset($result['messages'][0]['code']) && intval($result['messages'][0]['code']) === 952)) {
            if ($retry) {
                // If already retried, fail the call
                return [
                    'status' => ['http_code' => $httpCode],
                    'result' => 'Token is invalid even after re-login.'
                ];
            }

            // Log the invalid token error
            $this->log([
                'line' => __LINE__,
                'method' => __METHOD__,
                'type' => self::LS_WARNING,
                'message' => 'Invalid token detected. Attempting to fetch a new token.'
            ]);

            // Destroy the invalid token
            $this->destroySessionToken();

            // Attempt to fetch a new token and retry the request
            $this->login();
            return $this->callAPI($method, $url, $body, true);
        }

        // Extend token expiration on success
        if ($httpCode === 200) {
            $this->extendTokenExpiration();
        }

        return [
            'status' => ['http_code' => $httpCode],
            'result' => $result
        ];
    }



    // *********************************************************************************************************************************

    private function callFileUploadAPI($method, $url, $fileData, $retry = false)
    {
        // Check if the token is valid or needs to be refreshed
        if ($this->isLogged() === false) {
            $login = $this->login();
            if ($login !== true) {
                return $login; // Return login error
            }
        }

        // Set authorization header with Bearer token
        $headers = [
            'Authorization' => 'Bearer ' . $this->token
        ];

        $multipart = $this->buildMultipartFormData($fileData);

        // Set headers for multipart request
        $headers['Content-Type'] = $multipart['contentType'];

        $response = $this->sendHttpRequest($method, $this->buildHealthUrl($url), $headers, $multipart['body'], [
            'timeout' => $this->timeout,
            'verify_ssl' => !$this->allowInsecure,
        ]);

        if (!empty($response['error'])) {
            return [
                'status' => ['http_code' => 0],
                'result' => $response['error']
            ];
        }

        // Decode the response body
        $result = json_decode($response['body'], true);
        $httpCode = $response['status']['http_code'];

        // Handle token invalidation (error 952)
        if ($httpCode === 401 || (isset($result['messages'][0]['code']) && intval($result['messages'][0]['code']) === 952)) {
            if ($retry) {
                return [
                    'status' => ['http_code' => $httpCode],
                    'result' => 'Token is invalid even after re-login.'
                ];
            }

            $this->destroySessionToken(); // Clear the invalid token
            $this->log([
                'line' => __LINE__,
                'method' => __METHOD__,
                'type' => self::LS_WARNING,
                'message' => 'Invalid token detected. Destroying token and attempting re-login.'
            ]);

            // Re-login and retry the request
            return $this->callFileUploadAPI($method, $url, $fileData, true);
        }

        // If the request is successful, extend the token expiration
        $this->extendTokenExpiration(); // Extend the token expiration on successful interaction

        return [
            'status' => ['http_code' => $httpCode],
            'result' => $result
        ];
    }



// *********************************************************************************************************************************

    private function cleanupOldTokenFiles()
    {
        $this->cleanupExpiredTokenFiles();
    }




}

if (!\class_exists('restCWP', false)) {
    \class_alias(__NAMESPACE__ . '\\restCWP', 'restCWP');
}

if (!\class_exists(__NAMESPACE__ . '\\fmCWP', false)) {
    \class_alias(__NAMESPACE__ . '\\restCWP', __NAMESPACE__ . '\\fmCWP');
}

if (!\class_exists('fmCWP', false)) {
    \class_alias(__NAMESPACE__ . '\\restCWP', 'fmCWP');
}
