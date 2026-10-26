export type TransactionStatus = 'success' | 'failure'
export type TransactionTransport = 'odata' | 'data-api'

/** JSON-compatible values used for encrypted response envelopes. */
export type JsonValue = string | number | boolean | null | JsonObject | JsonValue[]
export type JsonObject = { [key: string]: JsonValue }

export type Transaction = {
  id: string
  serverId?: string
  status: TransactionStatus
  operation: string
  transport: TransactionTransport
  resource: string
  durationMs: number
  createdAt: string
  message: string
  /**
   * An encrypted response envelope only. This module never accepts or writes
   * a plaintext request/response payload.
   */
  encryptedResponse?: JsonObject
  /** A short, redacted display note; never use this for the response body. */
  responsePreview?: string
  payloadStored?: boolean
  legacyPlaintext?: boolean
}

export type TransactionInput = Omit<Transaction, 'id' | 'createdAt'>

export type TransactionFilters = {
  /** Case-insensitive partial match across the supported searchable fields. */
  search?: string
  /** Inclusive ISO date/time lower bound. */
  from?: string
  /** Inclusive ISO date/time upper bound. */
  to?: string
  /** Optional exact calendar date, such as 2026-08-13. */
  date?: string
  status?: TransactionStatus | 'all'
  transport?: TransactionTransport | 'all'
  operation?: string
  resource?: string
  message?: string
}

export const DEFAULT_LOG_CAPACITY = 50
export const MIN_LOG_CAPACITY = 10
export const MAX_LOG_CAPACITY = 2000

const storageKey = 'openbridge-admin-transactions-v1'
const loggingPreferenceKey = 'openbridge-admin-logging-enabled-v1'
const maxResourceLength = 80
const maxMessageLength = 180
const maxPreviewLength = 240
const sensitiveValue = /\b(password|passphrase|token|authorization|cookie|salt|secret)\b\s*[:=]\s*[^\s,;]+/gi

/**
 * Clamp a configured log capacity to the supported 10-2000 range.
 * Invalid, missing, or non-integer values use the default of 50.
 */
export function normalizeLogCapacity(capacity: unknown = DEFAULT_LOG_CAPACITY): number {
  const value = typeof capacity === 'number' ? capacity : Number(capacity)
  if (!Number.isFinite(value)) return DEFAULT_LOG_CAPACITY
  return Math.min(MAX_LOG_CAPACITY, Math.max(MIN_LOG_CAPACITY, Math.floor(value)))
}

/**
 * Clear legacy browser-local activity and return no stored transactions.
 * Activity summaries now live in memory for the current UI session and in the
 * authenticated server store when PHP/SQLite are available.
 */
export function loadTransactions(_capacity: number = DEFAULT_LOG_CAPACITY): Transaction[] {
  try {
    if (typeof window !== 'undefined') window.localStorage.removeItem(storageKey)
  } catch {
    // Legacy browser activity cleanup is best effort.
  }
  return []
}

/**
 * Do not persist transaction activity to browser storage. The call remains as a
 * compatibility no-op for existing React effects while server persistence is
 * rolled out behind authenticated PHP.
 */
export function saveTransactions(_transactions: Transaction[], _capacity: number = DEFAULT_LOG_CAPACITY): void {
  try {
    if (typeof window !== 'undefined') window.localStorage.removeItem(storageKey)
  } catch {
    // Activity storage is server-owned; cleanup failures must not block requests.
  }
}

/**
 * Add one transaction at the front and overwrite the oldest entry when full.
 * The returned array is safe to use for both React state and persistence.
 */
export function appendTransaction(
  transactions: Transaction[],
  input: TransactionInput,
  capacity: number = DEFAULT_LOG_CAPACITY,
): Transaction[] {
  const next = [createTransaction(input), ...transactions]
  return orderNewestFirst(next).slice(0, normalizeLogCapacity(capacity)).map(sanitizeTransaction)
}

/**
 * Filter transactions by partial text, date/time, status, and transport.
 * Search is case-insensitive and uses only redacted metadata, never response bodies.
 */
export function filterTransactions(transactions: Transaction[], filters: TransactionFilters = {}): Transaction[] {
  const search = normalizeSearch(filters.search)
  const from = parseFilterTime(filters.from)
  const to = parseFilterTime(filters.to)
  const exactDate = filters.date?.trim()

  return orderNewestFirst(transactions).filter((transaction) => {
    const createdAt = Date.parse(transaction.createdAt)
    if (from !== undefined && (!Number.isFinite(createdAt) || createdAt < from)) return false
    if (to !== undefined && (!Number.isFinite(createdAt) || createdAt > to)) return false
    if (exactDate && !transaction.createdAt.startsWith(exactDate)) return false
    if (filters.status && filters.status !== 'all' && transaction.status !== filters.status) return false
    if (filters.transport && filters.transport !== 'all' && transaction.transport !== filters.transport) return false
    if (filters.operation && !matches(transaction.operation, filters.operation)) return false
    if (filters.resource && !matches(transaction.resource, filters.resource)) return false
    if (filters.message && !matches(transaction.message, filters.message)) return false
    if (search && !searchableText(transaction).includes(search)) return false
    return true
  })
}

/** Load the persisted logging preference; enabled is the safe default. */
export function loadLoggingPreference(): boolean {
  try {
    return typeof window === 'undefined' || window.localStorage.getItem(loggingPreferenceKey) !== 'false'
  } catch {
    return true
  }
}

/** Persist whether diagnostic transaction logging is enabled. */
export function saveLoggingPreference(enabled: boolean): void {
  try {
    if (typeof window !== 'undefined') window.localStorage.setItem(loggingPreferenceKey, String(enabled))
  } catch {
    // Optional preference; an unavailable browser store is harmless.
  }
}

/** Create a redacted transaction with a generated identifier and timestamp. */
export function createTransaction(input: TransactionInput): Transaction {
  return sanitizeTransaction({ ...input, id: createId(), createdAt: new Date().toISOString() })
}

function createId(): string {
  return globalThis.crypto?.randomUUID?.() ?? `tx-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
}

function orderNewestFirst(transactions: Transaction[]): Transaction[] {
  return [...transactions].sort(newestFirst)
}

function newestFirst(a: Transaction, b: Transaction): number {
  return Date.parse(b.createdAt) - Date.parse(a.createdAt)
}

function sanitizeTransaction(transaction: Transaction): Transaction {
  const sanitized: Transaction = {
    ...transaction,
    id: cleanText(transaction.id, 100),
    ...(transaction.serverId ? { serverId: cleanText(transaction.serverId, 100) } : {}),
    operation: cleanText(transaction.operation, 60),
    resource: cleanText(transaction.resource, maxResourceLength),
    message: cleanText(transaction.message, maxMessageLength),
    durationMs: Math.max(0, Math.round(Number.isFinite(transaction.durationMs) ? transaction.durationMs : 0)),
    payloadStored: transaction.payloadStored === true,
    legacyPlaintext: transaction.legacyPlaintext === true,
  }

  if (transaction.responsePreview) sanitized.responsePreview = cleanText(transaction.responsePreview, maxPreviewLength)
  if (isJsonObject(transaction.encryptedResponse)) sanitized.encryptedResponse = cloneJsonObject(transaction.encryptedResponse)
  else delete sanitized.encryptedResponse

  return sanitized
}

function cloneJsonObject(value: JsonObject): JsonObject {
  return JSON.parse(JSON.stringify(value)) as JsonObject
}

function isJsonValue(value: unknown): value is JsonValue {
  if (value === null || typeof value === 'string' || typeof value === 'boolean') return true
  if (typeof value === 'number') return Number.isFinite(value)
  if (Array.isArray(value)) return value.every(isJsonValue)
  return isJsonObject(value)
}

function isJsonObject(value: unknown): value is JsonObject {
  return isRecord(value) && Object.values(value).every(isJsonValue)
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return Boolean(value) && typeof value === 'object' && !Array.isArray(value)
}

function cleanText(value: string, maxLength: number): string {
  return value.replace(sensitiveValue, '$1=[redacted]').replace(/\s+/g, ' ').trim().slice(0, maxLength) || 'Not available'
}

function normalizeSearch(value: string | undefined): string {
  return value?.trim().toLocaleLowerCase() ?? ''
}

function matches(value: string, query: string): boolean {
  return value.toLocaleLowerCase().includes(query.trim().toLocaleLowerCase())
}

function searchableText(transaction: Transaction): string {
  return [
    transaction.createdAt,
    new Date(transaction.createdAt).toLocaleString(),
    transaction.status,
    transaction.operation,
    transaction.transport,
    transaction.resource,
    transaction.message,
  ].join(' ').toLocaleLowerCase()
}

function parseFilterTime(value: string | undefined): number | undefined {
  if (!value?.trim()) return undefined
  const parsed = Date.parse(value)
  return Number.isFinite(parsed) ? parsed : undefined
}
