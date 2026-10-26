import { describe, expect, it } from 'vitest'
import { playgroundModuleIndex } from '../registry'
import { createWorkspaceBlock } from '../workspace'
import { friendlySafeTestError, preflightSafeTest } from '../safety'

describe('safe test policy', () => {
  it('allows a configured read and rejects writes regardless of module metadata supplied by UI', () => {
    const find = playgroundModuleIndex.get('find')!
    expect(preflightSafeTest(find, createWorkspaceBlock(find, 'find-1'), { endpoint: '/api.php', transport: 'data-api', authenticated: true }).ok).toBe(true)
    const create = playgroundModuleIndex.get('create')!
    expect(preflightSafeTest(create, createWorkspaceBlock(create, 'create-1'), { endpoint: '/api.php', transport: 'data-api', authenticated: true }).ok).toBe(false)
  })

  it('turns common failures into beginner advice', () => {
    expect(friendlySafeTestError('Failed to fetch')).toContain('PHP endpoint')
    expect(friendlySafeTestError('401 session')).toContain('Sign in')
  })
})
