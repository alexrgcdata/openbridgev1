import { describe, expect, it } from 'vitest'
import { playgroundModuleIndex } from '../registry'
import { createWorkspaceBlock, duplicateWorkspaceBlock, moveWorkspaceBlock, removeWorkspaceBlock } from '../workspace'

describe('workspace operations', () => {
  const find = playgroundModuleIndex.get('find')!
  const get = playgroundModuleIndex.get('get')!
  const blocks = [createWorkspaceBlock(find, 'find-1'), createWorkspaceBlock(get, 'get-1')]

  it('creates defaults from module metadata', () => {
    expect(blocks[0].inputs.resource).toBe('Customers')
    expect(blocks[0].moduleVersion).toBe(find.version)
  })

  it('moves, removes, and duplicates by stable block id', () => {
    expect(moveWorkspaceBlock(blocks, 'get-1', -1).map((block) => block.blockId)).toEqual(['get-1', 'find-1'])
    expect(removeWorkspaceBlock(blocks, 'find-1').map((block) => block.blockId)).toEqual(['get-1'])
    const duplicated = duplicateWorkspaceBlock(blocks, 'find-1', 'find-2')
    expect(duplicated.map((block) => block.blockId)).toEqual(['find-1', 'find-2', 'get-1'])
    expect(duplicated[0].inputs).not.toBe(duplicated[1].inputs)
  })
})
