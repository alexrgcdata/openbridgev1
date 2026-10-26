import { mkdtemp, rm, mkdir, cp, writeFile, stat } from 'node:fs/promises'
import { existsSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join, resolve } from 'node:path'
import { spawn } from 'node:child_process'
import { createServer } from 'node:net'

const root = resolve(import.meta.dirname, '..')
const php = process.env.OPENBRIDGE_PHP_BIN?.trim()
  || (process.platform === 'win32' && existsSync('C:/xampp/php/php.exe') ? 'C:/xampp/php/php.exe' : 'php')
const origin = 'http://127.0.0.1:5173'
const badOrigin = 'https://attacker.example.test'

async function main() {
  const tempRoot = await mkdtemp(join(tmpdir(), 'openbridge-storage-api-'))
  let server
  const fixture = await createFixture(tempRoot)
  try {
    const port = await findPort()
    server = await startPhpServer(fixture.webRoot, port)
    const endpoint = `http://127.0.0.1:${port}/api/openbridge-api.php`
    const jar = new CookieJar()
    const api = new ApiClient(endpoint, jar)

    await api.expectStatus('foreign Origin is rejected before session work', { action: 'setup_status' }, 403, {
      origin: badOrigin,
    })

    const setup = await api.post({ action: 'setup_status' })
    assert(setup.status === 200, 'setup_status should be available')
    assert(setup.body.databaseReady === true, 'temporary SQLite database should initialize')
    assert(setup.body.requiresSetup === true, 'isolated fixture should require first admin')

    const registered = await api.post({
      action: 'register',
      username: 'owner',
      email: 'owner@example.test',
      password: 'correct horse battery staple',
      displayName: 'Owner',
    })
    assert(registered.status === 201, 'first administrator registration should succeed')
    assert(typeof registered.body.csrfToken === 'string' && registered.body.csrfToken.length >= 32, 'registration should return CSRF token')
    api.csrfToken = registered.body.csrfToken

    const session = await api.post({ action: 'session' })
    assert(session.status === 200 && session.body.authenticated === true, 'registered cookie should authenticate')
    assert(typeof session.body.csrfToken === 'string', 'session should refresh CSRF token')
    api.csrfToken = session.body.csrfToken

    await api.expectStatus('missing CSRF rejects vault_save', {
      action: 'vault_save',
      profile: fileMakerProfile('pw-alpha'),
    }, 403, { csrf: false })

    const savedVault = await api.post({
      action: 'vault_save',
      profile: fileMakerProfile('pw-alpha'),
    })
    assert(savedVault.status === 200 && savedVault.body.configured === true, 'vault_save should store encrypted FileMaker credentials')

    const editedVault = await api.post({
      action: 'vault_save',
      profile: { ...fileMakerProfile(''), table: 'Invoices', layout: 'Invoices' },
    })
    assert(editedVault.status === 200 && editedVault.body.configured === true, 'blank-password vault_save should reuse the existing saved password')

    const connections = await api.post({ action: 'connections_list' })
    assert(connections.status === 200, 'connections_list should return metadata after vault_save')
    const connectionRows = Array.isArray(connections.body.connections) ? connections.body.connections : []
    assert(connectionRows.length >= 1, 'connections_list should include a FileMaker connection')
    assertJsonDoesNotContain(connectionRows, ['pw-alpha', 'password', 'token', 'secret'], 'connections_list must not expose secret material')

    await api.expectStatus('shared bridge token cannot read private error logs', { action: 'errors_list' }, 403, {
      cookie: false,
      token: fixture.apiToken,
    })

    const recorded = await api.post({
      action: 'transaction_record',
      status: 'success',
      transport: 'odata',
      operation: 'Find records',
      resource: 'Customers',
      database: 'OpenBridgeFixture',
      durationMs: 17,
      message: 'synthetic metadata only',
      responsePreview: '2 records',
      rawRequest: JSON.stringify({ query: 'Status eq Active', token: 'request-token' }),
      rawResponse: JSON.stringify({ data: [{ id: 1, Password: 'hidden' }], authorization: 'response-token' }),
    })
    assert(recorded.status === 201, 'transaction_record should return Created')
    assert(recorded.body.recorded === true, 'transaction_record should report recorded=true')
    assert(recorded.body.payloadStored === true, 'transaction_record should retain payload when rawResponse is present')
    assertUuid(recorded.body.id, 'transaction_record should return a transaction id')
    const transactionId = recorded.body.id

    const listed = await api.post({ action: 'transactions_list', limit: 20 })
    assert(listed.status === 200, 'transactions_list should succeed for the owner session')
    const transactionRows = Array.isArray(listed.body.transactions) ? listed.body.transactions : []
    const listedTransaction = transactionRows.find((row) => row.id === transactionId)
    assert(listedTransaction, 'transactions_list should include the recorded transaction')
    assert(listedTransaction.payloadStored === true || listedTransaction.payload_stored === true || listedTransaction.payload_stored === 1, 'transactions_list should mark retained payloads')
    assert(listedTransaction.legacy === false || listedTransaction.legacyPlaintext === false || listedTransaction.legacy_plaintext === false, 'new encrypted transaction details should not be legacy')
    assertJsonDoesNotContain(listedTransaction, ['request-token', 'response-token', 'hidden'], 'transactions_list must not expose retained bodies')

    const payload = await api.post({ action: 'transaction_payload', id: transactionId })
    assert(payload.status === 200, 'transaction_payload should be readable by the owner')
    assert(payload.body.id === transactionId, 'transaction_payload should return the requested id')
    assertJsonDoesNotContain(payload.body.payload, ['request-token', 'response-token', 'hidden'], 'transaction_payload should redact credential-like synthetic values')

    const second = new ApiClient(endpoint, new CookieJar())
    const secondRegistered = await second.post({
      action: 'register',
      username: 'second',
      email: 'second@example.test',
      password: 'second horse battery staple',
      displayName: 'Second',
    })
    assert(secondRegistered.status === 403, 'second first-run registration should be blocked once an admin exists')
    await createSecondAdmin(fixture.apiDir, fixture.dbPath)
    const secondLogin = await second.post({
      action: 'login',
      username: 'second',
      password: 'second horse battery staple',
    })
    assert(secondLogin.status === 200, 'second fixture user should be able to log in after helper creation')
    second.csrfToken = secondLogin.body.csrfToken
    await second.expectStatus('second user cannot read owner transaction payload', { action: 'transaction_payload', id: transactionId }, 404)
    const secondList = await second.post({ action: 'transactions_list', limit: 20 })
    const secondRows = Array.isArray(secondList.body.transactions) ? secondList.body.transactions : []
    assert(!secondRows.some((row) => row.id === transactionId), 'second user transaction list should not include owner rows')

    for (const action of ['connection_delete', 'transaction_delete', 'transactions_secure_legacy']) {
      await api.expectStatus('mutation requires CSRF even with bridge token', { action, id: transactionId, revision: 1 }, 403, { csrf: false, token: fixture.apiToken })
    }
    const savedConnection = connectionRows[0]
    await second.expectStatus('another owner cannot remove saved connection', { action: 'connection_delete', id: savedConnection.id, revision: savedConnection.revision }, 404)
    await api.expectStatus('stale connection revision cannot remove credentials', { action: 'connection_delete', id: savedConnection.id, revision: savedConnection.revision - 1 }, 409)
    await api.expectStatus('owner can remove the current saved connection', { action: 'connection_delete', id: savedConnection.id, revision: savedConnection.revision }, 200)
    const afterRemoval = await api.post({ action: 'connections_list' })
    assert(afterRemoval.body.connections.length === 0, 'removed connection must disappear from owner list')
    const clearedVault = await api.post({ action: 'vault_status' })
    assert(clearedVault.body.configured === false, 'removal must also remove the private FileMaker profile')
    const revokedCookies = new Map(jar.values)
    const loggedOut = await api.post({ action: 'logout' })
    assert(loggedOut.status === 200 && loggedOut.body.authenticated === false, 'logout should clear the session')
    jar.values = revokedCookies
    await api.expectStatus('revoked cookie cannot read transaction payload after logout', { action: 'transaction_payload', id: transactionId }, 401)

    const dbStats = await stat(fixture.dbPath)
    assert(dbStats.size > 4096, 'temporary SQLite database should contain fixture data')
    console.log('OpenBridge storage API fixture passed')
    console.log(JSON.stringify({
      checks: [
        'foreign Origin rejected',
        'register/login cookie and CSRF established',
        'missing CSRF rejected',
        'vault_save stored credentials and reused blank password',
        'connections_list metadata checked for secret leakage',
        'bridge token denied private logs',
        'transaction_record/list/payload exercised',
        'second-user transaction isolation checked',
        'logout revoked cookie access',
      ],
      endpoint: 'loopback-only',
      database: 'temporary isolated SQLite',
    }, null, 2))
  } finally {
    if (server) await stopPhpServer(server)
    if (!resolve(tempRoot).startsWith(resolve(tmpdir()) + (process.platform === 'win32' ? '\\' : '/')) || !tempRoot.includes('openbridge-storage-api-')) throw new Error('Unexpected fixture cleanup path')
    await rm(tempRoot, { recursive: true, force: true })
  }
}

async function createFixture(base) {
  const webRoot = join(base, 'openbridge')
  const apiDir = join(webRoot, 'api')
  const dbDir = join(webRoot, 'db')
  await mkdir(apiDir, { recursive: true })
  await mkdir(dbDir, { recursive: true })
  await mkdir(join(apiDir, 'migrations'), { recursive: true })
  await mkdir(join(apiDir, 'private'), { recursive: true })

  for (const file of [
    'openbridge-api.php',
    'database.php',
    'database_schema.sql',
    'security.php',
    'transactions.php',
    'connections.php',
    'restCWP.php',
    'odataCWP.php',
    'OpenBridgeResponseNormalizer.php',
  ]) {
    await cp(resolve(root, 'restCWP', file), join(apiDir, file))
  }
  await cp(resolve(root, 'restCWP', 'migrations', '003-connections.sql'), join(apiDir, 'migrations', '003-connections.sql'))

  const dbPath = join(dbDir, 'openbridge.sqlite')
  const apiToken = `fixture-token-${randomString()}`
  const masterKey = Buffer.from(crypto.getRandomValues(new Uint8Array(32))).toString('base64')
  await writeFile(join(apiDir, 'openbridge.config.php'), `<?php
return [
  'api' => [
    'enabled' => true,
    'allowedOrigins' => ['${origin}'],
    'apiToken' => '${apiToken}',
    'adminSessionSeconds' => 3600,
    'enableTransportTest' => false,
    'allowTargetOverrides' => false,
  ],
  'admin' => [
    'username' => '',
    'password' => '',
    'passwordHash' => '',
  ],
  'database' => [
    'enabled' => true,
    'path' => ${phpString(dbPath)},
    'autoInitialize' => true,
    'busyTimeoutMs' => 5000,
    'seedDemoAdmin' => false,
  ],
  'security' => [
    'masterKey' => '${masterKey}',
    'masterKeyFile' => 'private/openbridge.key.php',
    'keyVersion' => 1,
    'transactionRetentionDays' => 30,
  ],
  'go' => [
    'enabled' => false,
    'baseUrl' => '',
    'username' => '',
    'password' => '',
    'routes' => [],
  ],
  'filemaker' => [
    'host' => '',
    'database' => '',
    'layout' => '',
    'table' => '',
    'username' => '',
    'password' => '',
    'transport' => 'odata',
    'options' => [
      'allowInsecure' => false,
      'tokenStorage' => 'session',
      'timeout' => 1,
    ],
  ],
];
`, 'utf8')
  return { webRoot, apiDir, dbPath, apiToken }
}

async function createSecondAdmin(apiDir, dbPath) {
  const helper = join(apiDir, 'create-second-admin.php')
  await writeFile(helper, `<?php
require_once __DIR__ . '/database.php';
$config = require __DIR__ . '/openbridge.config.php';
$pdo = openBridgeDatabasePdo($config);
if (openBridgeDatabaseFindUser($pdo, 'second') === null) {
  openBridgeDatabaseCreateUser($pdo, 'second', 'second@example.test', 'second horse battery staple', 'Second');
}
`, 'utf8')
  await runProcess(php, [helper], {
    env: { ...process.env, OPENBRIDGE_DB_PATH: dbPath },
  })
}

function fileMakerProfile(password) {
  return {
    host: 'https://filemaker.example.test',
    database: 'OpenBridgeFixture',
    layout: 'Customers',
    table: 'Customers',
    username: 'fm-fixture-user',
    password,
    transport: 'odata',
  }
}

class ApiClient {
  csrfToken = ''

  constructor(endpoint, jar) {
    this.endpoint = endpoint
    this.jar = jar
  }

  async post(payload, options = {}) {
    const headers = {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      Origin: options.origin ?? origin,
    }
    if (options.csrf !== false && this.csrfToken) headers['X-OpenBridge-CSRF'] = this.csrfToken
    if (options.token) headers['X-OpenBridge-Token'] = options.token
    if (options.cookie !== false) {
      const cookie = this.jar.header()
      if (cookie) headers.Cookie = cookie
    }
    const response = await fetch(this.endpoint, {
      method: 'POST',
      headers,
      body: JSON.stringify(payload),
      redirect: 'manual',
    })
    if (options.cookie !== false) this.jar.store(response.headers.getSetCookie?.() ?? response.headers.get('set-cookie'))
    const text = await response.text()
    let body = {}
    try {
      body = text ? JSON.parse(text) : {}
    } catch {
      body = { parseError: true }
    }
    return { status: response.status, body }
  }

  async expectStatus(label, payload, status, options = {}) {
    const response = await this.post(payload, options)
    assert(response.status === status, `${label}: expected HTTP ${status}, received HTTP ${response.status}; ${response.body.error ?? 'no JSON error'}`)
    return response
  }
}

class CookieJar {
  values = new Map()

  store(setCookie) {
    const headers = Array.isArray(setCookie) ? setCookie : setCookie ? [setCookie] : []
    for (const header of headers) {
      for (const part of splitSetCookie(header)) {
        const first = part.split(';')[0]
        const index = first.indexOf('=')
        if (index <= 0) continue
        const name = first.slice(0, index).trim()
        const value = first.slice(index + 1).trim()
        if (!value) this.values.delete(name)
        else this.values.set(name, value)
      }
    }
  }

  header() {
    return Array.from(this.values.entries()).map(([name, value]) => `${name}=${value}`).join('; ')
  }
}

function splitSetCookie(value) {
  if (!value) return []
  return value.split(/,(?=\s*[^;,]+=)/g).map((part) => part.trim()).filter(Boolean)
}

async function findPort() {
  const server = createServer()
  await new Promise((resolveOpen, rejectOpen) => {
    server.once('error', rejectOpen)
    server.listen(0, '127.0.0.1', resolveOpen)
  })
  const address = server.address()
  const port = typeof address === 'object' && address ? address.port : 0
  await new Promise((resolveClose) => server.close(resolveClose))
  if (!port) throw new Error('Could not allocate a loopback port.')
  return port
}

async function startPhpServer(webRoot, port) {
  const child = spawn(php, ['-S', `127.0.0.1:${port}`, '-t', webRoot], {
    cwd: webRoot,
    stdio: ['ignore', 'pipe', 'pipe'],
    windowsHide: true,
  })
  let output = ''
  child.stdout.on('data', (chunk) => {
    output += chunk.toString()
  })
  child.stderr.on('data', (chunk) => {
    output += chunk.toString()
  })
  child.once('exit', (code) => {
    if (code !== null && code !== 0) output += `\nPHP server exited with code ${code}`
  })
  const deadline = Date.now() + 10000
  while (Date.now() < deadline) {
    if (child.exitCode !== null) throw new Error(`PHP server exited before startup. ${redact(output)}`)
    try {
      const response = await fetch(`http://127.0.0.1:${port}/api/openbridge-api.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Origin: origin },
        body: JSON.stringify({ action: 'setup_status' }),
      })
      await response.arrayBuffer()
      return child
    } catch {
      await delay(100)
    }
  }
  throw new Error(`PHP server did not start. ${redact(output)}`)
}

async function stopPhpServer(child) {
  if (child.exitCode !== null) return
  child.kill()
  await Promise.race([
    new Promise((resolveExit) => child.once('exit', resolveExit)),
    delay(2000).then(() => {
      if (child.exitCode === null) child.kill('SIGKILL')
    }),
  ])
}

async function runProcess(command, args, options = {}) {
  const child = spawn(command, args, {
    stdio: ['ignore', 'pipe', 'pipe'],
    windowsHide: true,
    ...options,
  })
  let stdout = ''
  let stderr = ''
  child.stdout.on('data', (chunk) => {
    stdout += chunk.toString()
  })
  child.stderr.on('data', (chunk) => {
    stderr += chunk.toString()
  })
  const code = await new Promise((resolveExit) => child.once('exit', resolveExit))
  if (code !== 0) throw new Error(`Process failed: ${command} ${args.join(' ')}\n${redact(stdout)}\n${redact(stderr)}`)
}

function assert(condition, message) {
  if (!condition) throw new Error(message)
}

function assertUuid(value, message) {
  assert(typeof value === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value), message)
}

function assertJsonDoesNotContain(value, forbidden, message) {
  const text = JSON.stringify(value)
  for (const term of forbidden) {
    assert(!text.toLocaleLowerCase().includes(term.toLocaleLowerCase()), `${message}: found ${term}`)
  }
}

function phpString(value) {
  return `'${String(value).replaceAll('\\', '\\\\').replaceAll("'", "\\'")}'`
}

function randomString() {
  return Buffer.from(crypto.getRandomValues(new Uint8Array(12))).toString('hex')
}

function delay(ms) {
  return new Promise((resolveDelay) => setTimeout(resolveDelay, ms))
}

function redact(value) {
  return String(value)
    .replaceAll(/openbridge_admin=[^;\s]+/g, 'openbridge_admin=[redacted]')
    .replaceAll(/fixture-token-[a-f0-9]+/g, 'fixture-token-[redacted]')
    .replaceAll(/correct horse battery staple/g, '[redacted]')
    .replaceAll(/second horse battery staple/g, '[redacted]')
    .replaceAll(/pw-alpha/g, '[redacted]')
    .replaceAll(/request-token/g, '[redacted]')
    .replaceAll(/response-token/g, '[redacted]')
}


await main().catch((error) => {
  console.error(redact(error.message))
  process.exitCode = 1
})
