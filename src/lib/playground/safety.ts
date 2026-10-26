import type { PlaygroundModule, PlaygroundTransport, WorkspaceBlock } from './types'

export type SafeTestPreflight = { ok: boolean; errors: Array<{ inputId?: string; message: string }> }

export function preflightSafeTest(module: PlaygroundModule | undefined, block: WorkspaceBlock | undefined, options: { endpoint: string; transport: PlaygroundTransport; authenticated: boolean }): SafeTestPreflight {
  const errors: SafeTestPreflight['errors'] = []
  if (!options.endpoint.trim()) errors.push({ message: 'Add the OpenBridge PHP endpoint in Setup.' })
  if (!options.authenticated) errors.push({ message: 'Sign in to the OpenBridge admin panel before testing.' })
  if (!module || !block) errors.push({ message: 'Choose one building block to test.' })
  if (module && (module.safety.level !== 'read-only' || module.safety.runMode !== 'read-only-live' || !module.tests.safeOperation)) errors.push({ message: 'This block is code-only because it can change data or run server logic.' })
  if (module && !module.support.transports.includes(options.transport)) errors.push({ message: `This block does not support ${options.transport}.` })
  if (module && block) for (const input of module.inputs) {
    const value = block.inputs[input.id]
    if (input.required && String(value ?? '').trim() === '') errors.push({ inputId: input.id, message: `${input.label} is required before testing.` })
  }
  return { ok: errors.length === 0, errors }
}

export function friendlySafeTestError(message: string): string {
  const text = message.toLowerCase()
  if (text.includes('401') || text.includes('session')) return 'Your admin session expired. Sign in again, then retry.'
  if (text.includes('layout') || text.includes('table') || text.includes('resource')) return 'Check the default layout/table in Setup and the selected block.'
  if (text.includes('credential') || text.includes('authentication')) return 'FileMaker rejected the configured account. Review the private server credentials and privilege set.'
  if (text.includes('fetch') || text.includes('network')) return 'OpenBridge could not reach the PHP endpoint. Check its relative URL, HTTPS, and server routing.'
  return 'The safe test did not complete. Review the highlighted block inputs and Connection Health details.'
}
