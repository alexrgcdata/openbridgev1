import type { PlaygroundModule, WorkspaceBlock } from './types'

export function createWorkspaceBlock(module: PlaygroundModule, blockId: string, overrides: Record<string, string | number | boolean> = {}): WorkspaceBlock {
  return {
    blockId,
    moduleId: module.id,
    moduleVersion: module.version,
    inputs: Object.fromEntries(module.inputs.map((input) => [input.id, overrides[input.id] ?? input.defaultValue])),
  }
}

export function insertWorkspaceBlock(blocks: WorkspaceBlock[], block: WorkspaceBlock, index = blocks.length): WorkspaceBlock[] {
  const next = [...blocks]
  next.splice(Math.max(0, Math.min(index, next.length)), 0, block)
  return next
}

export function removeWorkspaceBlock(blocks: WorkspaceBlock[], blockId: string): WorkspaceBlock[] {
  return blocks.filter((block) => block.blockId !== blockId)
}

export function moveWorkspaceBlock(blocks: WorkspaceBlock[], blockId: string, direction: -1 | 1): WorkspaceBlock[] {
  const index = blocks.findIndex((block) => block.blockId === blockId)
  const nextIndex = index + direction
  if (index < 0 || nextIndex < 0 || nextIndex >= blocks.length) return blocks
  const next = [...blocks]
  const [block] = next.splice(index, 1)
  next.splice(nextIndex, 0, block)
  return next
}

export function duplicateWorkspaceBlock(blocks: WorkspaceBlock[], blockId: string, newBlockId: string): WorkspaceBlock[] {
  const index = blocks.findIndex((block) => block.blockId === blockId)
  if (index < 0) return blocks
  const copy = { ...blocks[index], blockId: newBlockId, inputs: { ...blocks[index].inputs } }
  return insertWorkspaceBlock(blocks, copy, index + 1)
}
