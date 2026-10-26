import { execFileSync } from 'node:child_process'
import { existsSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import { generateModuleCode, generateWorkspaceCode } from '../generator'
import { playgroundModuleIndex, playgroundModules } from '../registry'
import type { PlaygroundModule, PlaygroundTransport, WorkspaceBlock } from '../types'

const defaults = {
  resource: 'Customers',
  recordId: '1',
  limit: '10',
  field: 'Status',
  value: 'Active',
  scriptName: 'OpenBridge Demo',
}

const legacyPhp: Record<string, Record<PlaygroundTransport, string>> = {
  list: {
    'data-api': "// Data API: list 10 records from the Customers layout\n$result = $bridge->getRecords([\n    '_limit' => 10,\n    '_offset' => 1,\n]);",
    odata: "// OData: list 10 records from the Customers table\n$result = $bridge->listODataRecords([\n    'table' => 'Customers',\n    'top' => 10,\n    'count' => true,\n]);",
  },
  get: {
    'data-api': "// Data API: get one FileMaker record\n$result = $bridge->getRecord('1');",
    odata: "// OData: get one entity by key\n$result = $bridge->getODataRecord('1', [\n    'table' => 'Customers',\n]);",
  },
  find: {
    'data-api': "// Data API: find records where Status matches Active\n$result = $bridge->findRecords([\n    'query' => [[\n        'Status' => 'Active',\n    ]],\n    'limit' => 10,\n]);",
    odata: "// OData: filter Customers by Status\n$result = $bridge->findODataRecords(\n    \"Status eq 'Active'\",\n    ['table' => 'Customers', 'top' => 10]\n);",
  },
  create: {
    'data-api': "// Data API: create a record\n$result = $bridge->createRecord([\n    'Status' => 'Active',\n]);",
    odata: "// OData: create an entity in Customers\n// Send this payload through your protected OData route.\n$payload = [\n    'Status' => 'Active',\n];",
  },
  edit: {
    'data-api': "// Data API: update record 1\n$result = $bridge->editRecord('1', [\n    'Status' => 'Active',\n]);",
    odata: "// OData: update entity 1 in Customers\n// Send this payload with PATCH through your protected OData route.\n$payload = [\n    'Status' => 'Active',\n];",
  },
  delete: {
    'data-api': "// Data API: permanently delete record 1\n// Confirm the record ID before running this operation.\n$result = $bridge->deleteRecord('1');",
    odata: "// OData: permanently delete entity 1 from Customers\n// Send DELETE through your protected OData route after confirmation.",
  },
  script: {
    'data-api': "// Data API: run a FileMaker script\n$result = $bridge->runScript(\n    'OpenBridge Demo',\n    ['source' => 'OpenBridge', 'value' => 'Active']\n);",
    odata: "// FileMaker scripts use the Data API route.\n// Switch to Data API to generate an executable script example.",
  },
  global: {
    'data-api': "// Data API: set a FileMaker global field\n$result = $bridge->setGlobalField([\n    'Status' => 'Active',\n]);",
    odata: "// Global fields are a Data API operation.\n// Switch to Data API to generate this helper.",
  },
}

function block(module: PlaygroundModule, values = defaults): WorkspaceBlock {
  return { blockId: `${module.id}-block`, moduleId: module.id, moduleVersion: module.version, inputs: values }
}

describe('deterministic playground generator', () => {
  it('protects every existing PHP snippet for both transports', () => {
    for (const module of playgroundModules) {
      for (const transport of ['data-api', 'odata'] as const) {
        expect(generateModuleCode(module, block(module), { language: 'php', transport }).code).toBe(legacyPhp[module.id][transport])
      }
    }
  })

  it('generates stable ordered workspaces', () => {
    const find = playgroundModuleIndex.get('find')!
    const list = playgroundModuleIndex.get('list')!
    const blocks = [block(find), block(list)]
    const first = generateWorkspaceCode(playgroundModuleIndex, blocks, { language: 'php', transport: 'data-api' })
    const second = generateWorkspaceCode(playgroundModuleIndex, blocks, { language: 'php', transport: 'data-api' })
    expect(first).toEqual(second)
    expect(first.code.indexOf('findRecords')).toBeLessThan(first.code.indexOf('getRecords'))
  })

  it('generates parseable multi-block JavaScript without variable collisions', () => {
    const find = playgroundModuleIndex.get('find')!
    const list = playgroundModuleIndex.get('list')!
    const result = generateWorkspaceCode(playgroundModuleIndex, [block(find), block(list)], { language: 'javascript', transport: 'data-api' })
    expect(result.executable).toBe(true)
    expect(() => new Function(`return async function () {\n${result.code}\n}`)).not.toThrow()
  })

  it('uses PHP and OData-aware escaping without changing field names', () => {
    const find = playgroundModuleIndex.get('find')!
    const values = { ...defaults, field: 'Last Name', value: "O'Brien $5" }
    const dataApi = generateModuleCode(find, block(find, values), { language: 'php', transport: 'data-api' })
    const odata = generateModuleCode(find, block(find, values), { language: 'php', transport: 'odata' })
    expect(dataApi.code).toContain("'Last Name' => 'O\\'Brien $5'")
    expect(odata.code).toContain("Last Name eq 'O''Brien \\$5'")
  })

  it('rejects an OData field expression that contains executable filter syntax', () => {
    const find = playgroundModuleIndex.get('find')!
    const values = { ...defaults, field: "Status) or (Name" }
    const result = generateModuleCode(find, block(find, values), { language: 'php', transport: 'odata' })
    expect(result.executable).toBe(false)
    expect(result.issues[0]?.inputId).toBe('field')
  })

  it('reports missing required values', () => {
    const find = playgroundModuleIndex.get('find')!
    const result = generateModuleCode(find, block(find, { ...defaults, field: '' }), { language: 'php', transport: 'data-api' })
    expect(result.executable).toBe(false)
    expect(result.issues[0]?.inputId).toBe('field')
  })

  it('produces parseable JavaScript for safe starter modules', () => {
    for (const id of ['list', 'get', 'find']) {
      const module = playgroundModuleIndex.get(id)!
      for (const transport of ['data-api', 'odata'] as const) {
        const result = generateModuleCode(module, block(module), { language: 'javascript', transport })
        expect(result.executable).toBe(true)
        expect(() => new Function(`return async function () {\n${result.code}\n}`)).not.toThrow()
      }
    }
  })

  it('labels unsupported JavaScript combinations instead of producing fake code', () => {
    const create = playgroundModuleIndex.get('create')!
    const result = generateModuleCode(create, block(create), { language: 'javascript', transport: 'data-api' })
    expect(result.executable).toBe(false)
    expect(result.code).toContain('unavailable')
  })

  it('preserves legacy guidance while marking unsupported PHP transport variants non-executable', () => {
    for (const id of ['script', 'global']) {
      const module = playgroundModuleIndex.get(id)!
      const result = generateModuleCode(module, block(module), { language: 'php', transport: 'odata' })
      expect(result.code).toBe(legacyPhp[id].odata)
      expect(result.executable).toBe(false)
      expect(result.issues.length).toBe(1)
    }
  })

  it('supports concise, teaching, advanced, and bootstrap output modes', () => {
    const list = playgroundModuleIndex.get('list')!
    const concise = generateModuleCode(list, block(list), { language: 'php', transport: 'data-api', commentMode: 'concise' })
    const teaching = generateModuleCode(list, block(list), { language: 'php', transport: 'data-api', commentMode: 'teaching' })
    const advanced = generateModuleCode(list, block(list), { language: 'php', transport: 'data-api', commentMode: 'advanced' })
    const workspace = generateWorkspaceCode(playgroundModuleIndex, [block(list)], { language: 'php', transport: 'data-api', includeBootstrap: true })
    expect(concise.code).not.toContain('//')
    expect(teaching.code.split('\n').length).toBeGreaterThan(legacyPhp.list['data-api'].split('\n').length)
    expect(advanced.code).not.toBe(teaching.code)
    expect(workspace.code.startsWith('<?php')).toBe(true)
  })
})

const phpBinary = process.env.OPENBRIDGE_PHP_BIN
  || (existsSync('C:\\xampp\\php\\php.exe') ? 'C:\\xampp\\php\\php.exe' : undefined)

describe.skipIf(!phpBinary)('generated PHP syntax', () => {
  it('passes PHP lint for every supported PHP variant', () => {
    const directory = mkdtempSync(join(tmpdir(), 'openbridge-phase2-'))
    try {
      for (const module of playgroundModules) {
        for (const transport of ['data-api', 'odata'] as const) {
          const result = generateModuleCode(module, block(module), { language: 'php', transport })
          const file = join(directory, `${module.id}-${transport}.php`)
          writeFileSync(file, `<?php\n${result.code}\n`)
          execFileSync(phpBinary!, ['-l', file], { stdio: 'pipe' })
        }
      }
    } finally {
      rmSync(directory, { recursive: true, force: true })
    }
  })
})
