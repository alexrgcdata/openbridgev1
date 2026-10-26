import { cp, mkdir, readFile, rm, stat, writeFile } from 'node:fs/promises'
import { existsSync } from 'node:fs'
import { resolve } from 'node:path'
import { spawnSync } from 'node:child_process'

const root = resolve(import.meta.dirname, '..')
const target = resolve(root, 'release', 'openbridge')
await rm(target, { recursive: true, force: true })
await mkdir(resolve(target, 'api'), { recursive: true })
await mkdir(resolve(target, 'api', 'migrations'), { recursive: true })
await mkdir(resolve(target, 'docs'), { recursive: true })
await mkdir(resolve(target, 'db'), { recursive: true })
await mkdir(resolve(target, 'api', 'private'), { recursive: true })
await cp(resolve(root, 'dist'), target, { recursive: true })
await cp(resolve(root, 'dist-library', 'openbridge.js'), resolve(target, 'openbridge.js'))
await cp(resolve(root, 'openbridge', 'openbridge.php'), resolve(target, 'openbridge.php'))
await cp(resolve(root, 'openbridge', 'restCWP.php'), resolve(target, 'restCWP.php'))
await cp(resolve(root, 'openbridge', 'odataCWP.php'), resolve(target, 'odataCWP.php'))
await cp(resolve(root, 'openbridge', 'VERSION.txt'), resolve(target, 'VERSION.txt'))
for (const file of ['restCWP.php', 'odataCWP.php', 'OpenBridgeResponseNormalizer.php', 'openbridge-api.php', 'database.php', 'database_schema.sql', 'migrate.php', 'security.php', 'connections.php', 'transactions.php', 'openbridge.config.example.php', '.htaccess']) {
  await cp(resolve(root, 'restCWP', file), resolve(target, 'api', file))
}
await cp(resolve(root, 'restCWP', 'migrations'), resolve(target, 'api', 'migrations'), { recursive: true })
for (const file of ['.htaccess', 'web.config', 'README.txt']) {
  await cp(resolve(root, 'restCWP', 'private', file), resolve(target, 'api', 'private', file))
}
// Never place a developer's private configuration or credentials in a release.
// Enable the packaged API/SQLite boundary without demo accounts or vendor URLs.
const exampleConfigPath = resolve(root, 'restCWP', 'openbridge.config.example.php')
const exampleConfig = await readFile(exampleConfigPath, 'utf8')
const demoConfig = exampleConfig
  .replace(/('api'\s*=>\s*\[\s*(?:\/\/[^\r\n]*\r?\n\s*)*'enabled'\s*=>\s*)false/, '$1true')
  .replace(/('database'\s*=>\s*\[\s*(?:\/\/[^\r\n]*\r?\n\s*)*'enabled'\s*=>\s*)false/, '$1true')
if (demoConfig === exampleConfig) {
  throw new Error('Could not create the sanitized installation configuration.')
}
await writeFile(resolve(target, 'api', 'openbridge.config.php'), demoConfig, 'utf8')
for (const file of ['.htaccess', 'web.config', 'README.txt']) {
  await cp(resolve(root, 'db', file), resolve(target, 'db', file))
}
for (const file of ['HUMAN-GUIDE.md', 'installation.md', 'sqlite-storage.md', 'security.md', 'security-storage.md', 'architecture-storage.md', 'coding-playground-module-authoring.md', 'integrations.md']) {
  await cp(resolve(root, 'docs', file), resolve(target, 'docs', file))
}
await cp(resolve(root, 'OPENBRIDGE-AGENTIC.md'), resolve(target, 'OPENBRIDGE-AGENTIC.md'))
const phpCandidates = [process.env.PHP_BIN, 'C:\\xampp\\php\\php.exe', 'php'].filter(Boolean)
let migration
for (const executable of phpCandidates) {
  migration = spawnSync(executable, [resolve(target, 'api', 'migrate.php')], {
    cwd: target,
    encoding: 'utf8',
    env: { ...process.env, OPENBRIDGE_DB_PATH: resolve(target, 'db', 'openbridge.sqlite') },
  })
  if (!migration.error) break
}
if (!migration || migration.error || migration.status !== 0) {
  throw new Error(`Could not create packaged SQLite database. ${migration?.stderr || migration?.error?.message || 'Install PHP with pdo_sqlite or set PHP_BIN.'}`)
}
process.stdout.write(migration.stdout)
for (const required of ['api', 'api/restCWP.php', 'api/odataCWP.php', 'api/OpenBridgeResponseNormalizer.php', 'api/security.php', 'api/connections.php', 'api/transactions.php', 'api/migrations/003-connections.sql', 'api/private/.htaccess', 'assets', 'brand', 'db', 'docs', 'docs/integrations.md', 'index.html', 'openbridge.js', 'openbridge.php', 'restCWP.php', 'odataCWP.php']) {
  if (!existsSync(resolve(target, required))) throw new Error(`Packaged OpenBridge is missing root item: ${required}`)
}
const packagedDatabase = await stat(resolve(target, 'db', 'openbridge.sqlite'))
if (packagedDatabase.size < 4096) throw new Error('Packaged SQLite database was not initialized correctly.')
process.stdout.write(`OpenBridge FTP folder ready: ${target}\n`)
