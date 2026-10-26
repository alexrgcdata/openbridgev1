import { spawn } from 'node:child_process'
import { existsSync } from 'node:fs'
import { resolve } from 'node:path'
import { defineConfig, type Plugin } from 'vite'
import react from '@vitejs/plugin-react'

const bridgeHost = '127.0.0.1'
const bridgePort = 8090
const bridgeTarget = `http://${bridgeHost}:${bridgePort}`

function bundledPhpBridge(): Plugin {
  let phpProcess: ReturnType<typeof spawn> | undefined

  return {
    name: 'openbridge-php-dev-server',
    apply: 'serve',
    configureServer(server) {
      const configuredBinary = process.env.OPENBRIDGE_PHP_BIN?.trim()
      const commonWindowsBinary = process.platform === 'win32'
        ? ['C:\\xampp\\php\\php.exe', 'C:\\php\\php.exe'].find(existsSync)
        : undefined
      const phpBinary = configuredBinary || commonWindowsBinary || 'php'
      const documentRoot = resolve(process.cwd(), 'restCWP')

      phpProcess = spawn(
        phpBinary,
        ['-S', `${bridgeHost}:${bridgePort}`, '-t', documentRoot],
        { windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'] },
      )
      phpProcess.once('error', () => {
        server.config.logger.warn(
          '[OpenBridge] PHP was not found. Install PHP or set OPENBRIDGE_PHP_BIN, then restart pnpm dev.',
        )
      })
      phpProcess.stderr?.on('data', (chunk) => {
        const message = String(chunk)
        if (message.includes('Development Server') && message.includes('started')) {
          server.config.logger.info(`[OpenBridge] PHP bridge ready at ${bridgeTarget}/openbridge-api.php`)
        } else if (message.includes('Failed to listen')) {
          server.config.logger.warn(
            `[OpenBridge] Port ${bridgePort} is already in use; the Vite proxy will use the existing service.`,
          )
        }
      })

      server.httpServer?.once('close', () => {
        if (phpProcess && !phpProcess.killed) phpProcess.kill()
      })
    },
  }
}

// https://vite.dev/config/
export default defineConfig({
  // Use /openbridge/ for the FTP deployment and / for local development.
  // OPENBRIDGE_BASE lets the build target any subdirectory without changing
  // application code or manually editing the generated index.html.
  base: process.env.OPENBRIDGE_BASE || (process.env.GITHUB_ACTIONS ? '/openbridge/' : '/'),
  plugins: [react(), bundledPhpBridge()],
  server: {
    proxy: {
      '/openbridge-api.php': {
        target: bridgeTarget,
        changeOrigin: false,
      },
    },
  },
})
