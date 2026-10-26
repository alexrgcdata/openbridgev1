import { describe, expect, it } from 'vitest'
import starter from '../../../data/playground-modules/find.json'
import { exportModule, validateModuleDraft } from '../moduleStudio'

describe('module studio boundary', () => {
  it('validates and exports a schema-safe module without persistence', () => {
    const result = validateModuleDraft(JSON.stringify(starter))
    expect(result.errors).toEqual([])
    expect(result.module?.id).toBe('find')
    expect(exportModule(result.module!)).toContain('"id": "find"')
  })

  it('rejects malformed JSON and invalid module data', () => {
    expect(validateModuleDraft('{').errors[0]).toMatch(/^JSON:/)
    expect(validateModuleDraft(JSON.stringify({ id: 'unsafe' })).errors.length).toBeGreaterThan(0)
  })
})
