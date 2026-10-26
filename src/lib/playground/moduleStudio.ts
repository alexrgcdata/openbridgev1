import { validatePlaygroundModule } from './registry'
import type { PlaygroundModule } from './types'

export type ModuleStudioResult = {
  module: PlaygroundModule | null
  errors: string[]
  json: string
}

/** Local authoring boundary. Durable persistence is supplied by the SQLite-backed API. */
export function validateModuleDraft(source: string): ModuleStudioResult {
  try {
    const parsed: unknown = JSON.parse(source)
    const result = validatePlaygroundModule(parsed)
    return { module: result.module, errors: result.errors.map((error) => `${error.path}: ${error.message}`), json: result.module ? JSON.stringify(result.module, null, 2) : source }
  } catch (error) {
    return { module: null, errors: [error instanceof Error ? `JSON: ${error.message}` : 'JSON is invalid.'], json: source }
  }
}

export function exportModule(module: PlaygroundModule): string {
  return `${JSON.stringify(module, null, 2)}\n`
}
