/** The two FileMaker transports supported by OpenBridge. */
export type FileMakerTransport = 'odata' | 'data-api'

/**
 * Settings used by the OpenBridge administrative interface.
 *
 * `password` is memory-only and is handed to the server vault on save.
 * `encryptionSecret` is a legacy browser-only diagnostic value. Neither is
 * written to localStorage by this module.
 */
export type AdminSettings = {
  backendUrl: string
  bridgeToken: string
  serverUrl: string
  database: string
  defaultLayout: string
  username: string
  password: string
  notes: string
  transport: FileMakerTransport
  logCapacity: number
  storeResponses: boolean
  encryptionSecret: string
}

/** Safe defaults for a new browser session. */
export const DEFAULT_ADMIN_SETTINGS: AdminSettings = {
  backendUrl: '/openbridge-api.php',
  bridgeToken: '',
  serverUrl: '',
  database: '',
  defaultLayout: '',
  username: '',
  password: '',
  notes: '',
  transport: 'odata',
  logCapacity: 50,
  storeResponses: false,
  encryptionSecret: '',
}

/** Return the bridge URL for the current deployment shape. */
function getDefaultBackendUrl(): string {
  if (typeof window !== 'undefined' && import.meta.env.PROD) {
    return new URL('api/openbridge-api.php', window.location.href).pathname
  }
  return DEFAULT_ADMIN_SETTINGS.backendUrl
}

function isHostedOpenBridge(): boolean {
  return typeof window !== 'undefined' && import.meta.env.PROD
}

export const MIN_LOG_CAPACITY = 10
export const MAX_LOG_CAPACITY = 2000

const settingsStorageKey = 'openbridge-admin-settings-v1'
const encryptionSecretStorageKey = 'openbridge-admin-encryption-secret-v1'
const bridgeTokenStorageKey = 'openbridge-api-token-v1'
const maxFieldLengths = {
  backendUrl: 2048,
  bridgeToken: 4096,
  serverUrl: 2048,
  database: 255,
  defaultLayout: 255,
  username: 255,
  password: 4096,
  notes: 10000,
  encryptionSecret: 4096,
} as const

type UnknownRecord = Record<string, unknown>

/**
 * Load settings using localStorage for non-secret values and sessionStorage
 * only for legacy diagnostic secrets. FileMaker passwords are never loaded
 * from browser storage.
 *
 * Malformed, missing, or legacy values are replaced with safe defaults. Any
 * legacy secret fields found in localStorage are discarded and are never
 * loaded back into the returned settings object.
 */
export function loadSettings(): AdminSettings {
  writeSessionSecret('openbridge-admin-password-v1', '')
  const saved = readJson(getStorage('localStorage'), settingsStorageKey)
  const publicSettings = isRecord(saved) ? saved : {}
  const normalized = normalizeSettings({
    ...publicSettings,
    password: '',
    encryptionSecret: readString(getStorage('sessionStorage'), encryptionSecretStorageKey),
    bridgeToken: readString(getStorage('sessionStorage'), bridgeTokenStorageKey),
  })

  if ('password' in publicSettings || 'encryptionSecret' in publicSettings || 'bridgeToken' in publicSettings) {
    writePublicSettings(normalized)
  }

  return normalized
}

/**
 * Persist settings safely and return the validated value that was stored.
 * Public settings go to localStorage. The FileMaker password is never written
 * to browser storage; the server vault owns reusable credentials.
 */
export function saveSettings(settings: AdminSettings): AdminSettings {
  const normalized = normalizeSettings(settings as unknown as UnknownRecord)
  writePublicSettings(normalized)
  writeSessionSecret(encryptionSecretStorageKey, normalized.encryptionSecret)
  writeSessionSecret(bridgeTokenStorageKey, normalized.bridgeToken)
  return normalized
}

/**
 * Remove every browser value owned by OpenBridge without touching unrelated
 * application data on the same origin. This includes settings, notes,
 * diagnostic transactions, encrypted response envelopes, tour state,
 * FileMaker password, bridge token, and response-encryption key. The PHP
 * logout route separately expires the HttpOnly administrator cookie.
 */
export function clearOpenBridgeBrowserData(): void {
  for (const kind of ['localStorage', 'sessionStorage'] as const) {
    const storage = getStorage(kind)
    if (!storage) continue
    try {
      const keys: string[] = []
      for (let index = 0; index < storage.length; index += 1) {
        const key = storage.key(index)
        if (key?.startsWith('openbridge-')) keys.push(key)
      }
      keys.forEach((key) => storage.removeItem(key))
    } catch {
      // Logout remains best effort when a browser blocks storage access.
    }
  }
}

/** Clamp a requested log capacity to the supported integer range. */
export function normalizeLogCapacity(value: unknown): number {
  if (typeof value !== 'number' && typeof value !== 'string') return DEFAULT_ADMIN_SETTINGS.logCapacity
  const numeric = typeof value === 'number' ? value : Number(value)
  if (!Number.isFinite(numeric)) return DEFAULT_ADMIN_SETTINGS.logCapacity
  return Math.min(MAX_LOG_CAPACITY, Math.max(MIN_LOG_CAPACITY, Math.round(numeric)))
}

function normalizeSettings(value: UnknownRecord): AdminSettings {
  const requestedBackendUrl = normalizeText(value.backendUrl, maxFieldLengths.backendUrl)
  const defaultBackendUrl = getDefaultBackendUrl()
  const shouldRepairHostedEndpoint = isHostedOpenBridge() && (
    !requestedBackendUrl
    || requestedBackendUrl === DEFAULT_ADMIN_SETTINGS.backendUrl
    || requestedBackendUrl === '/openbridge/openbridge-api.php'
    || requestedBackendUrl.endsWith('/openbridge/openbridge-api.php')
  )
  const backendUrl = shouldRepairHostedEndpoint
    ? defaultBackendUrl
    : requestedBackendUrl || defaultBackendUrl

  return {
    backendUrl,
    bridgeToken: normalizeSecret(value.bridgeToken, maxFieldLengths.bridgeToken),
    serverUrl: normalizeText(value.serverUrl, maxFieldLengths.serverUrl),
    database: normalizeText(value.database, maxFieldLengths.database),
    defaultLayout: normalizeText(value.defaultLayout ?? value.layout, maxFieldLengths.defaultLayout),
    username: normalizeText(value.username, maxFieldLengths.username),
    password: normalizeSecret(value.password, maxFieldLengths.password),
    notes: normalizeText(value.notes, maxFieldLengths.notes),
    // Data API remains supported by the legacy libraries, but the current
    // dashboard intentionally presents one simpler OData-first route.
    transport: 'odata',
    logCapacity: normalizeLogCapacity(value.logCapacity),
    storeResponses: typeof value.storeResponses === 'boolean'
      ? value.storeResponses
      : value.encryptionEnabled === true,
    encryptionSecret: normalizeSecret(value.encryptionSecret, maxFieldLengths.encryptionSecret),
  }
}

function normalizeText(value: unknown, maxLength: number): string {
  if (typeof value !== 'string') return ''
  return removeControlCharacters(value).trim().slice(0, maxLength)
}

function normalizeSecret(value: unknown, maxLength: number): string {
  if (typeof value !== 'string') return ''
  return removeControlCharacters(value).slice(0, maxLength)
}

function removeControlCharacters(value: string): string {
  return [...value].filter((character) => {
    const code = character.codePointAt(0) ?? 0
    return !(code <= 0x1f && code !== 0x09 && code !== 0x0a && code !== 0x0d) && code !== 0x7f
  }).join('')
}

function writePublicSettings(settings: AdminSettings): void {
  const storage = getStorage('localStorage')
  if (!storage) return
  const publicSettings = {
    backendUrl: settings.backendUrl,
    serverUrl: settings.serverUrl,
    database: settings.database,
    defaultLayout: settings.defaultLayout,
    username: settings.username,
    notes: settings.notes,
    transport: settings.transport,
    logCapacity: settings.logCapacity,
    storeResponses: settings.storeResponses,
  }
  try {
    storage.setItem(settingsStorageKey, JSON.stringify(publicSettings))
  } catch {
    // Settings persistence is best effort and must not break the dashboard.
  }
}

function writeSessionSecret(key: string, value: string): void {
  const storage = getStorage('sessionStorage')
  if (!storage) return
  try {
    if (value) storage.setItem(key, value)
    else storage.removeItem(key)
  } catch {
    // Private browsing or restrictive browser policies can disable storage.
  }
}

function readString(storage: Storage | null, key: string): string {
  if (!storage) return ''
  try {
    return storage.getItem(key) ?? ''
  } catch {
    return ''
  }
}

function readJson(storage: Storage | null, key: string): unknown {
  const saved = readString(storage, key)
  if (!saved) return null
  try {
    return JSON.parse(saved) as unknown
  } catch {
    return null
  }
}

function getStorage(kind: 'localStorage' | 'sessionStorage'): Storage | null {
  try {
    return typeof window === 'undefined' ? null : window[kind]
  } catch {
    return null
  }
}

function isRecord(value: unknown): value is UnknownRecord {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}
