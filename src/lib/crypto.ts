/** JSON-serializable AES-GCM response envelope. The raw key is never included. */
export type EncryptedEnvelope = {
  version: 2
  algorithm: 'AES-GCM-256'
  keyEncoding: 'base64'
  iv: string
  ciphertext: string
}

const keyLength = 32
const ivLength = 12

/** Generate a cryptographically random 256-bit Base64 key for encryption and decryption. */
export function generateEncryptionKey(): string {
  return toBase64(getWebCrypto().getRandomValues(new Uint8Array(keyLength)))
}

/** Return true when a string decodes to exactly one 256-bit key. */
export function isValidEncryptionKey(value: string): boolean {
  try { return fromBase64(value).length === keyLength } catch { return false }
}

/** Encrypt JSON with the exact supplied 256-bit key. No hashing or password derivation is used. */
export async function encryptJson<T>(value: T, base64Key: string): Promise<EncryptedEnvelope> {
  const webCrypto = getWebCrypto()
  const key = await importKey(base64Key, ['encrypt'])
  const iv = webCrypto.getRandomValues(new Uint8Array(ivLength))
  const plaintext = new TextEncoder().encode(JSON.stringify(value))
  const encrypted = await webCrypto.subtle.encrypt({ name: 'AES-GCM', iv: copyBuffer(iv) }, key, copyBuffer(plaintext))
  return { version: 2, algorithm: 'AES-GCM-256', keyEncoding: 'base64', iv: toBase64(iv), ciphertext: toBase64(new Uint8Array(encrypted)) }
}

/** Decrypt and JSON-parse an OpenBridge response with the same exact 256-bit key. */
export async function decryptJson<T>(envelope: unknown, base64Key: string): Promise<T> {
  const checked = validateEnvelope(envelope)
  const key = await importKey(base64Key, ['decrypt'])
  const plaintext = await getWebCrypto().subtle.decrypt({ name: 'AES-GCM', iv: copyBuffer(fromBase64(checked.iv)) }, key, copyBuffer(fromBase64(checked.ciphertext)))
  return JSON.parse(new TextDecoder().decode(plaintext)) as T
}

export function isEncryptedEnvelope(value: unknown): value is EncryptedEnvelope {
  try { validateEnvelope(value); return true } catch { return false }
}

async function importKey(value: string, usages: KeyUsage[]): Promise<CryptoKey> {
  const bytes = fromBase64(value)
  if (bytes.length !== keyLength) throw new Error('Encryption key must be a 256-bit Base64 key.')
  return getWebCrypto().subtle.importKey('raw', copyBuffer(bytes), { name: 'AES-GCM' }, false, usages)
}

function validateEnvelope(value: unknown): EncryptedEnvelope {
  if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error('Invalid encrypted envelope.')
  const record = value as Record<string, unknown>
  if (record.version !== 2 || record.algorithm !== 'AES-GCM-256' || record.keyEncoding !== 'base64') throw new Error('Unsupported encrypted envelope.')
  if (typeof record.iv !== 'string' || fromBase64(record.iv).length !== ivLength || typeof record.ciphertext !== 'string' || !record.ciphertext) throw new Error('Invalid encrypted envelope.')
  return record as unknown as EncryptedEnvelope
}

function getWebCrypto(): Crypto {
  if (!globalThis.crypto?.subtle || !globalThis.crypto.getRandomValues) throw new Error('Web Crypto API is unavailable in this browser.')
  return globalThis.crypto
}

function copyBuffer(bytes: Uint8Array): ArrayBuffer { const copy = new Uint8Array(bytes.byteLength); copy.set(bytes); return copy.buffer }
function toBase64(bytes: Uint8Array): string { let binary = ''; for (let index = 0; index < bytes.length; index += 0x8000) binary += String.fromCharCode(...bytes.subarray(index, index + 0x8000)); return btoa(binary) }
function fromBase64(value: string): Uint8Array { if (!/^[A-Za-z0-9+/]*={0,2}$/.test(value) || value.length % 4 !== 0) throw new Error('Invalid Base64 key or encrypted data.'); return Uint8Array.from(atob(value), (character) => character.charCodeAt(0)) }
