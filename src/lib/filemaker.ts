export type FileMakerMode = 'odata' | 'data-api'

export type HealthCheckName = 'configuration' | 'server' | 'credentials' | 'api' | 'database' | 'resource' | 'latency'
export type HealthCheckResult = {
  name: HealthCheckName
  ok: boolean
  label: string
  detail: string
  latencyMs?: number
}
export type ConnectionHealth = {
  ok: boolean
  transport: FileMakerMode
  checkedAt: string
  latencyMs: number | null
  checks: HealthCheckResult[]
}

export type SetupStatus = {
  databaseReady: boolean
  requiresSetup: boolean
  demoAvailable: boolean
  passwordMinimumLength: number
}

export type RegisteredAdmin = {
  username: string
  email: string
}

let adminCsrfToken = ''

export type StoredTransaction = {
  id: string
  status: 'success' | 'failure' | 'pending'
  operation: string
  transport: 'data-api' | 'odata' | 'go' | 'internal'
  resource: string
  durationMs: number
  createdAt: string
  message: string
  responsePreview?: string
  payloadStored: boolean
  legacyPlaintext: boolean
}

export type StoredTransactionRecordResult = {
  id: string
  recorded: boolean
  payloadStored: boolean
}

export type SavedConnectionProvider = 'filemaker' | string
export type SavedConnectionEnvironment = 'test' | 'production'
export type SavedConnectionAuthType = 'filemaker-credentials' | 'api-key' | 'oauth2' | 'basic' | 'none'

export type SavedConnection = {
  id: string
  providerId: SavedConnectionProvider
  displayName: string
  environment: SavedConnectionEnvironment
  authType: SavedConnectionAuthType
  revision: number
  createdAt: string
  updatedAt: string
}

export type DeleteSavedConnectionResult = {
  deleted: boolean
}

/** Authenticate the admin through PHP. The password is sent only in this
 * HTTPS request, is verified against a one-way server hash, and is not stored
 * by the browser application. */
export async function loginAdmin(endpoint: string, username: string, password: string): Promise<boolean> {
  const body = await adminSessionRequest(endpoint, { action: 'login', username, password })
  return body.authenticated === true
}

/** Ask PHP whether its HttpOnly admin session is still valid. */
export async function checkAdminSession(endpoint: string): Promise<boolean> {
  const body = await adminSessionRequest(endpoint, { action: 'session' })
  return body.authenticated === true
}

/** Destroy the server-side admin session and expire its HttpOnly cookie. */
export async function logoutAdmin(endpoint: string): Promise<void> {
  await adminSessionRequest(endpoint, { action: 'logout' })
}

/** Find out whether this installation needs its first administrator account. */
export async function getSetupStatus(endpoint: string): Promise<SetupStatus> {
  const body = await adminSessionRequest(endpoint, { action: 'setup_status' })
  return {
    databaseReady: body.databaseReady === true,
    requiresSetup: body.requiresSetup === true,
    demoAvailable: body.demoAvailable === true,
    passwordMinimumLength: typeof body.passwordMinimumLength === 'number' ? body.passwordMinimumLength : 12,
  }
}

/** Create the first local OpenBridge administrator. This is available only until an owner exists. */
export async function registerAdmin(endpoint: string, input: { username: string; email: string; password: string; displayName?: string }): Promise<RegisteredAdmin> {
  const body = await adminSessionRequest(endpoint, { action: 'register', ...input })
  const user = isRecord(body.user) ? body.user : {}
  return { username: readString(user.username), email: readString(user.email) }
}

/** Load non-secret personal dashboard preferences from packaged SQLite. */
export async function loadStoredSettings(endpoint: string): Promise<Record<string, unknown>> {
  const body = await adminSessionRequest(endpoint, { action: 'storage_load' })
  return isRecord(body.settings) ? body.settings : {}
}

/** Save non-secret personal dashboard preferences to packaged SQLite. */
export async function saveStoredSettings(endpoint: string, settings: Record<string, unknown>): Promise<void> {
  await adminSessionRequest(endpoint, { action: 'storage_save', settings })
}

/** Encrypt and save reusable FileMaker credentials in the server-side vault. */
export async function saveCredentialVault(endpoint: string, profile: {
  host: string
  database: string
  layout: string
  table: string
  username: string
  password: string
  transport: FileMakerMode
}): Promise<void> {
  await adminSessionRequest(endpoint, { action: 'vault_save', profile })
}

/** Return only whether credentials exist; secret values are never returned. */
export async function getCredentialVaultStatus(endpoint: string): Promise<{ configured: boolean; security: Record<string, unknown> }> {
  const body = await adminSessionRequest(endpoint, { action: 'vault_status' })
  return { configured: body.configured === true, security: isRecord(body.security) ? body.security : {} }
}

/** Load safe connection metadata for the signed-in administrator. Secrets are never returned. */
export async function listSavedConnections(endpoint: string): Promise<SavedConnection[]> {
  const body = await adminSessionRequest(endpoint, { action: 'connections_list' })
  if (!Array.isArray(body.connections)) throw new Error('Saved connections returned an invalid response.')
  const rows = body.connections
  return rows.map(toSavedConnection).filter((connection): connection is SavedConnection => connection !== null)
}

/** Remove one saved local connection and its encrypted local secret after a revision check. */
export async function deleteSavedConnection(endpoint: string, id: string, revision: number): Promise<DeleteSavedConnectionResult> {
  const body = await adminSessionRequest(endpoint, { action: 'connection_delete', id, revision })
  return { deleted: body.deleted === true }
}

/** Load one retained payload after server-side authentication and decryption. */
export async function loadStoredTransactionPayload(endpoint: string, id: string): Promise<Record<string, unknown>> {
  const body = await adminSessionRequest(endpoint, { action: 'transaction_payload', id })
  return isRecord(body.payload) ? body.payload : {}
}

/** Load retained transaction summaries from packaged SQLite. */
export async function loadStoredTransactions(endpoint: string, limit = 200): Promise<StoredTransaction[]> {
  const body = await adminSessionRequest(endpoint, { action: 'transactions_list', limit })
  const rows = Array.isArray(body.transactions) ? body.transactions : []
  return rows.filter(isRecord).map((row): StoredTransaction => {
    const status: StoredTransaction['status'] = row.status === 'success' || row.status === 'pending' ? row.status : 'failure'
    const transport: StoredTransaction['transport'] = row.transport === 'odata' || row.transport === 'go' || row.transport === 'internal' ? row.transport : 'data-api'
    return {
      id: readString(row.id),
      status,
      operation: readString(row.operation) || 'Unknown operation',
      transport,
      resource: readString(row.resource),
      durationMs: readNumber(row.duration_ms ?? row.durationMs) ?? 0,
      createdAt: readString(row.created_at ?? row.createdAt) || new Date().toISOString(),
      message: readString(row.message),
      ...(readString(row.response_preview ?? row.responsePreview) ? { responsePreview: readString(row.response_preview ?? row.responsePreview) } : {}),
      payloadStored: readBoolean(row.payload_stored ?? row.payloadStored),
      legacyPlaintext: readBoolean(row.legacy_plaintext ?? row.legacyPlaintext ?? row.legacy),
    }
  }).filter((transaction) => transaction.id !== '')
}

/** Persist a transaction summary and optional retained raw payload in packaged SQLite. */
export async function recordStoredTransaction(endpoint: string, transaction: Omit<StoredTransaction, 'id' | 'createdAt' | 'payloadStored' | 'legacyPlaintext'> & { database?: string; rawRequest?: string; rawResponse?: string; isEncrypted?: boolean; encryptionVersion?: string }): Promise<StoredTransactionRecordResult> {
  const body = await adminSessionRequest(endpoint, { action: 'transaction_record', ...transaction })
  return {
    id: readString(body.id),
    recorded: body.recorded === true,
    payloadStored: readBoolean(body.payloadStored ?? body.payload_stored),
  }
}

/** Record privacy-minimal local product telemetry for a later opt-in relay. */
export async function recordMetricEvent(endpoint: string, event: string, context: Record<string, unknown> = {}): Promise<void> {
  await adminSessionRequest(endpoint, { action: 'metric_event', event, context })
}

export type DatabaseDiagnostics = {
  ready: boolean
  integrity: string
  foreignKeyIssues: number
  journalMode: string
  tableCount: number
  schemaVersion: number
  writeRollback: boolean
  directoryWritable: boolean
  databaseBytes: number
  counts: Record<string, number>
}

/** Verify SQLite integrity, schema, write access, and key table counts through PHP. */
export async function runDatabaseDiagnostics(endpoint: string): Promise<DatabaseDiagnostics> {
  const body = await adminSessionRequest(endpoint, { action: 'database_diagnostics' })
  const diagnostics = isRecord(body.diagnostics) ? body.diagnostics : {}
  const rawCounts = isRecord(diagnostics.counts) ? diagnostics.counts : {}
  return {
    ready: diagnostics.ready === true,
    integrity: readString(diagnostics.integrity),
    foreignKeyIssues: readNumber(diagnostics.foreignKeyIssues) ?? 0,
    journalMode: readString(diagnostics.journalMode),
    tableCount: readNumber(diagnostics.tableCount) ?? 0,
    schemaVersion: readNumber(diagnostics.schemaVersion) ?? 0,
    writeRollback: diagnostics.writeRollback === true,
    directoryWritable: diagnostics.directoryWritable === true,
    databaseBytes: readNumber(diagnostics.databaseBytes) ?? 0,
    counts: Object.fromEntries(Object.entries(rawCounts).map(([key, value]) => [key, readNumber(value) ?? 0])),
  }
}

/** Read live FileMaker metadata through the authenticated, read-only PHP route. */
export async function loadSchemaSnapshot(endpoint: string, transport: FileMakerMode): Promise<Record<string, unknown>> {
  return adminSessionRequest(endpoint, { action: 'schema_explore', transport })
}

export type ServerDemoConfiguration = {
  serverUrl: string
  database: string
  layout: string
  table: string
  transport: FileMakerMode
  configured: boolean
}

/** Load non-secret FileMaker defaults after secure admin login. */
export async function loadServerDemoConfiguration(endpoint: string): Promise<ServerDemoConfiguration> {
  const body = await adminSessionRequest(endpoint, { action: 'configuration' })
  return {
    serverUrl: readString(body.serverUrl),
    database: readString(body.database),
    layout: readString(body.layout),
    table: readString(body.table),
    transport: body.transport === 'odata' ? 'odata' : 'data-api',
    configured: body.configured === true,
  }
}

async function adminSessionRequest(endpoint: string, payload: Record<string, unknown>): Promise<Record<string, unknown>> {
  const response = await fetch(endpoint, {
    method: 'POST',
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      ...(adminCsrfToken ? { 'X-OpenBridge-CSRF': adminCsrfToken } : {}),
    },
    body: JSON.stringify(payload),
  })
  const body = await response.json() as Record<string, unknown>
  if (!response.ok) throw new Error(typeof body.error === 'string' ? body.error : `Admin endpoint returned HTTP ${response.status}.`)
  if (typeof body.csrfToken === 'string') adminCsrfToken = body.csrfToken
  if (payload.action === 'logout') adminCsrfToken = ''
  return body
}

/**
 * Ask a server-side OpenBridge endpoint to run the real FileMaker checks.
 * Credentials must remain in PHP/server configuration; this request sends only
 * non-secret target overrides. The endpoint may ignore those overrides.
 */
export async function checkConnectionHealth(options: {
  endpoint: string
  bridgeToken: string
  mode: FileMakerMode
  serverUrl: string
  database: string
  resource: string
  timeoutMs?: number
}): Promise<ConnectionHealth> {
  const controller = new AbortController()
  const timeout = window.setTimeout(() => controller.abort(), options.timeoutMs ?? 15_000)
  const started = performance.now()
  try {
    const response = await fetch(options.endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: bridgeHeaders(options.bridgeToken),
      body: JSON.stringify({
        action: 'health',
        transport: options.mode,
        serverUrl: options.serverUrl,
        database: options.database,
        resource: options.resource,
      }),
      signal: controller.signal,
    })
    const body = await response.json() as Record<string, unknown>
    if (!response.ok) throw new Error(typeof body.error === 'string' ? body.error : `Health endpoint returned HTTP ${response.status}.`)
    const checks = normalizeHealthChecks(body.checks)
    if (!checks.length) throw new Error('Health endpoint returned an invalid response.')
    return {
      ok: Boolean(body.ok),
      transport: body.transport === 'odata' ? 'odata' : 'data-api',
      checkedAt: readString(body.checkedAt ?? body.checked_at) || new Date().toISOString(),
      latencyMs: readNumber(body.latencyMs ?? body.latency_ms) ?? Math.round(performance.now() - started),
      checks,
    }
  } finally {
    window.clearTimeout(timeout)
  }
}

export type PlaygroundOperation = 'list' | 'find' | 'get' | 'script'
export type PlaygroundRequest = {
  endpoint: string
  bridgeToken: string
  transport: FileMakerMode
  operation: PlaygroundOperation
  resource: string
  parameters: Record<string, unknown>
  afterScript?: { name: string; parameter?: unknown }
}

export type TransportTestResult = {
  ok: boolean
  temporary_feature: boolean
  target?: Record<string, unknown>
  tests?: {
    data_api?: Record<string, unknown>
    odata?: Record<string, unknown>
  }
  [key: string]: unknown
}

export type GoBackendStatus = {
  configured: boolean
  reachable: boolean
  authenticated: boolean
  message: string
  service?: string
}

/** Check the optional Go service through PHP so Go credentials and tokens never enter React. */
export async function checkGoBackend(endpoint: string, bridgeToken: string): Promise<GoBackendStatus> {
  const response = await fetch(endpoint, {
    method: 'POST',
    credentials: 'include',
    headers: bridgeHeaders(bridgeToken),
    body: JSON.stringify({ action: 'go_status' }),
  })
  const body = await response.json() as Record<string, unknown>
  if (!response.ok) throw new Error(readString(body.error) || `Go status returned HTTP ${response.status}.`)
  return {
    configured: body.configured === true,
    reachable: body.reachable === true,
    authenticated: body.authenticated === true,
    message: readString(body.message) || 'Go backend status returned.',
    ...(readString(body.service) ? { service: readString(body.service) } : {}),
  }
}

/** Send one allowlisted demo payload through PHP to the authenticated Go service. */
export async function runGoDemoRoute(
  endpoint: string,
  bridgeToken: string,
  payload: Record<string, unknown>,
): Promise<Record<string, unknown>> {
  const response = await fetch(endpoint, {
    method: 'POST',
    credentials: 'include',
    headers: bridgeHeaders(bridgeToken),
    body: JSON.stringify({ action: 'go_request', route: 'demo_event', payload }),
  })
  const body = await response.json() as Record<string, unknown>
  if (!response.ok) throw new Error(readString(body.error) || `Go demo route returned HTTP ${response.status}.`)
  return body
}

/** Run one allowlisted request through the protected PHP bridge. */
export async function runPlaygroundRequest(request: PlaygroundRequest): Promise<Record<string, unknown>> {
  const recordId = request.parameters.recordId ?? request.parameters.key
  const scriptName = request.parameters.scriptName
  const scriptParameter = request.parameters.scriptParameter
  const response = await fetch(request.endpoint, {
    method: 'POST',
    credentials: 'include',
    headers: bridgeHeaders(request.bridgeToken),
    body: JSON.stringify({
      action: 'playground',
      transport: request.transport,
      operation: request.operation,
      resource: request.resource,
      ...(request.transport === 'odata' ? { table: request.resource } : {}),
      parameters: request.parameters,
      ...(request.operation === 'find' && request.transport === 'data-api' && Array.isArray(request.parameters.query)
        ? { query: request.parameters.query }
        : {}),
      ...(request.operation === 'find' && request.transport === 'odata' && typeof request.parameters.filter === 'string'
        ? { filter: request.parameters.filter }
        : {}),
      ...(recordId !== undefined ? { recordId } : {}),
      ...(typeof scriptName === 'string' ? { scriptName } : {}),
      ...(scriptParameter !== undefined ? { scriptParameter } : {}),
      ...(request.afterScript?.name ? { afterScript: request.afterScript } : {}),
    }),
  })
  const body = await response.json() as Record<string, unknown>
  if (!response.ok) throw new Error(typeof body.error === 'string' ? body.error : `Playground endpoint returned HTTP ${response.status}.`)
  return body
}

/**
 * Run the temporary server-side test that checks Data API and OData in order.
 * The bridge token is sent only as a header and is never included in the
 * returned payload or the sanitized browser console diagnostics.
 */
export async function runTransportTest(options: {
  endpoint: string
  bridgeToken: string
  limit?: number
  timeoutMs?: number
}): Promise<TransportTestResult> {
  const controller = new AbortController()
  const timeout = window.setTimeout(() => controller.abort(), options.timeoutMs ?? 90_000)
  try {
    const response = await fetch(options.endpoint, {
      method: 'POST',
      credentials: 'include',
      headers: bridgeHeaders(options.bridgeToken),
      body: JSON.stringify({
        action: 'transport_test',
        limit: Math.max(1, Math.min(10, options.limit ?? 3)),
      }),
      signal: controller.signal,
    })
    const body = await response.json() as TransportTestResult & { error?: unknown }
    if (!response.ok) {
      throw new Error(typeof body.error === 'string' ? body.error : `Transport test returned HTTP ${response.status}.`)
    }
    return body
  } finally {
    window.clearTimeout(timeout)
  }
}

function normalizeHealthChecks(value: unknown): HealthCheckResult[] {
  const items = Array.isArray(value)
    ? value
    : isRecord(value)
      ? Object.values(value)
      : []
  return items.filter(isRecord).map((item) => {
    const sourceName = readString(item.name ?? item.key)
    const name = mapHealthName(sourceName)
    const latencyMs = readNumber(item.latencyMs ?? item.latency_ms)
    return {
      name,
      ok: item.ok === true || item.status === 'pass',
      label: readString(item.label) || name,
      detail: readString(item.detail ?? item.message) || 'No diagnostic detail was returned.',
      ...(latencyMs === null ? {} : { latencyMs }),
    }
  })
}

function mapHealthName(value: string): HealthCheckName {
  if (value === 'authentication') return 'credentials'
  if (value === 'layout_or_table') return 'resource'
  return ['configuration', 'server', 'credentials', 'api', 'database', 'resource', 'latency'].includes(value)
    ? value as HealthCheckName
    : 'api'
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function readString(value: unknown): string { return typeof value === 'string' ? value : '' }
function readNumber(value: unknown): number | null { return typeof value === 'number' && Number.isFinite(value) ? value : null }
function readBoolean(value: unknown): boolean {
  return value === true || value === 1 || value === '1' || value === 'true'
}

function toSavedConnection(value: unknown): SavedConnection | null {
  if (!isRecord(value)) return null
  const id = readString(value.id)
  const providerId = readString(value.provider_id ?? value.providerId)
  const displayName = readString(value.display_name ?? value.displayName)
  const environment = readString(value.environment)
  const authType = readString(value.auth_type ?? value.authType)
  const revision = readNumber(value.revision)
  const trimmedDisplayName = displayName.trim()
  if (!isUuidLike(id) || !isSafeProviderId(providerId) || trimmedDisplayName === '' || displayName.length > 255 || revision === null || !Number.isInteger(revision) || revision < 1) return null
  if (environment !== 'test' && environment !== 'production') return null
  if (!['filemaker-credentials', 'api-key', 'oauth2', 'basic', 'none'].includes(authType)) return null
  return {
    id,
    providerId,
    displayName,
    environment,
    authType: authType as SavedConnectionAuthType,
    revision: Math.floor(revision),
    createdAt: readString(value.created_at ?? value.createdAt),
    updatedAt: readString(value.updated_at ?? value.updatedAt),
  }
}

function isUuidLike(value: string): boolean {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value)
}

function isSafeProviderId(value: string): boolean {
  return /^[a-z][a-z0-9-]{0,63}$/.test(value)
}

function bridgeHeaders(token: string): Record<string, string> {
  return {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    ...(adminCsrfToken ? { 'X-OpenBridge-CSRF': adminCsrfToken } : {}),
    ...(token ? { 'X-OpenBridge-Token': token } : {}),
  }
}
