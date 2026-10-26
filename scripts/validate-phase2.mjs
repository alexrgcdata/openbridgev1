import { existsSync } from 'node:fs'
import { spawnSync } from 'node:child_process'

const projectChecks = [
  ['pnpm', ['--ignore-workspace', 'run', 'lint']],
  ['pnpm', ['--ignore-workspace', 'run', 'typecheck:tests']],
  ['pnpm', ['--ignore-workspace', 'run', 'test']],
  ['pnpm', ['--ignore-workspace', 'run', 'build']],
  ['pnpm', ['--ignore-workspace', 'run', 'build:library']],
]

function run(command, args) {
  const pnpmCli = command === 'pnpm' ? process.env.npm_execpath : undefined
  const executable = pnpmCli ? process.execPath : command
  const commandArgs = pnpmCli ? [pnpmCli, ...args] : args
  const result = spawnSync(executable, commandArgs, { stdio: 'inherit', shell: false })
  if (result.error) throw result.error
  if (result.status !== 0) process.exit(result.status || 1)
}

for (const [command, args] of projectChecks) run(command, args)

const configuredPhp = process.env.OPENBRIDGE_PHP_BIN?.trim()
const commonWindowsPhp = 'C:\\xampp\\php\\php.exe'
const php = configuredPhp || (process.platform === 'win32' && existsSync(commonWindowsPhp) ? commonWindowsPhp : 'php')
const phpProbe = spawnSync(php, ['-v'], { stdio: 'ignore', shell: false })
if (phpProbe.error || phpProbe.status !== 0) {
  console.error('Phase 2 validation requires PHP. Install PHP or set OPENBRIDGE_PHP_BIN.')
  process.exit(1)
}

for (const file of ['restCWP/restCWP.php', 'restCWP/odataCWP.php', 'restCWP/OpenBridgeResponseNormalizer.php', 'restCWP/openbridge-api.php', 'restCWP/database.php', 'restCWP/security.php', 'restCWP/connections.php', 'restCWP/transactions.php', 'restCWP/migrate.php', 'openbridge/openbridge.php', 'openbridge/restCWP.php', 'openbridge/odataCWP.php']) {
  run(php, ['-l', file])
}
for (const file of ['restCWP/tests/playground_smoke.php', 'restCWP/tests/database_config_smoke.php', 'restCWP/tests/transport_contract_smoke.php', 'restCWP/tests/security_storage_smoke.php', 'restCWP/tests/storage_boundary_smoke.php', 'restCWP/tests/engine_http_smoke.php', 'restCWP/tests/connections_smoke.php', 'restCWP/tests/session_security_smoke.php', 'restCWP/tests/transaction_repository_smoke.php']) {
  run(php, [file])
}

run(process.execPath, ['scripts/test-storage-api.mjs'])
run('git', ['diff', '--check'])
console.log('OpenBridge Phase 2 validation passed.')
