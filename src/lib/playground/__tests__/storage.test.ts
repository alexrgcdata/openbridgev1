import { describe, expect, it } from 'vitest'
import { sanitizeWorkspace } from '../storage'

describe('workspace storage boundary', () => {
  it('bounds persisted workspace data and excludes arbitrary properties', () => {
    const workspace = sanitizeWorkspace({
      id: 'workspace-1', name: 'A'.repeat(200), language: 'php', transport: 'data-api', commentMode: 'inline',
      favorites: ['find', 'bad key'], blocks: [], manualDraft: 'example', revision: 1, synced: false,
    })
    expect(workspace.name).toHaveLength(120)
    expect(workspace.favorites).toEqual(['find'])
    expect(workspace).not.toHaveProperty('password')
  })
})
