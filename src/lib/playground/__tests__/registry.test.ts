import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { buildModuleRegistry, playgroundModules, resolvePlaygroundModule, validatePlaygroundModule } from '../registry'

describe('playground module registry', () => {
  it('loads the eight legacy modules in stable order', () => {
    expect(playgroundModules.map((module) => module.id)).toEqual([
      'list', 'get', 'find', 'create', 'edit', 'delete', 'script', 'global',
    ])
  })

  it('accepts every installed module through the public validator', () => {
    for (const module of playgroundModules) {
      expect(validatePlaygroundModule(module)).toEqual({ module, errors: [] })
    }
  })

  it('returns understandable validation errors', () => {
    const invalid = {
      id: 'Bad Id',
      version: 0,
      safety: { level: 'side-effect', sideEffects: true, runMode: 'read-only-live' },
    }
    const result = validatePlaygroundModule(invalid)
    expect(result.module).toBeNull()
    expect(result.errors.map((error) => error.message)).toContain('Module id must be stable lowercase kebab-case.')
    expect(result.errors.map((error) => error.message)).toContain('A side-effecting module cannot be marked read-only-live.')
  })

  it('rejects duplicate module ids', () => {
    expect(() => buildModuleRegistry([playgroundModules[0], playgroundModules[0]])).toThrow('Duplicate module id or alias: list.')
  })

  it('resolves compatibility aliases to the canonical module', () => {
    expect(resolvePlaygroundModule('show-records')?.id).toBe('list')
    expect(resolvePlaygroundModule('search-records')?.id).toBe('find')
    expect(resolvePlaygroundModule('missing-module')).toBeUndefined()
  })

  it('rejects aliases that collide with another module id or alias', () => {
    const list = structuredClone(playgroundModules[0])
    const get = structuredClone(playgroundModules[1])
    get.compatibilityAliases = [...(get.compatibilityAliases ?? []), 'list']
    expect(() => buildModuleRegistry([list, get])).toThrow('Duplicate module id or alias: list.')
  })

  it('keeps the copyable starter module valid', () => {
    const starter = JSON.parse(readFileSync(new URL('../../../../docs/samples/playground-module-template.json', import.meta.url), 'utf8')) as unknown
    expect(validatePlaygroundModule(starter).errors).toEqual([])
  })

  it('ships a parseable JSON schema requiring all four variants', () => {
    const schema = JSON.parse(readFileSync(new URL('../../../data/playground-module.schema.json', import.meta.url), 'utf8')) as { properties?: { variants?: { required?: string[] } } }
    expect(schema.properties?.variants?.required).toEqual([
      'php:data-api', 'php:odata', 'javascript:data-api', 'javascript:odata',
    ])
  })

  it('rejects unknown or malformed typed placeholders', () => {
    const invalid = structuredClone(playgroundModules[0])
    invalid.variants['php:data-api'] = { status: 'supported', template: '// {{mystery:resource}} {{bad-token}}' }
    const messages = validatePlaygroundModule(invalid).errors.map((error) => error.message)
    expect(messages).toContain('Unknown placeholder kind: mystery.')
    expect(messages).toContain('Invalid placeholder syntax: {{bad-token}}.')
  })
})
