import type { CommentMode, PlaygroundLanguage, PlaygroundTransport, WorkspaceBlock } from './types'

export type SavedWorkspace = {
  id: string
  name: string
  language: PlaygroundLanguage
  transport: PlaygroundTransport
  commentMode: CommentMode
  favorites: string[]
  blocks: WorkspaceBlock[]
  manualDraft?: string
  revision: number
  updatedAt?: string
  synced: boolean
}

const fallbackKey = 'openbridge-playground-workspace-v1'

export function sanitizeWorkspace(value: SavedWorkspace): SavedWorkspace {
  return {
    ...value,
    name: value.name.slice(0, 120),
    favorites: value.favorites.filter((item) => /^[a-z][a-z0-9-]*$/.test(item)).slice(0, 200),
    blocks: value.blocks.slice(0, 200).map((block) => ({ ...block, inputs: { ...block.inputs } })),
    ...(value.manualDraft ? { manualDraft: value.manualDraft.slice(0, 2_000_000) } : {}),
  }
}

export function saveWorkspaceFallback(value: SavedWorkspace): void {
  if (typeof localStorage === 'undefined') return
  localStorage.setItem(fallbackKey, JSON.stringify({ ...sanitizeWorkspace(value), synced: false }))
}

export function loadWorkspaceFallback(): SavedWorkspace | null {
  if (typeof localStorage === 'undefined') return null
  try {
    const value: unknown = JSON.parse(localStorage.getItem(fallbackKey) || 'null')
    return isSavedWorkspace(value) ? sanitizeWorkspace({ ...value, synced: false }) : null
  } catch { return null }
}

export async function saveWorkspace(endpoint: string, value: SavedWorkspace): Promise<SavedWorkspace> {
  const workspace = sanitizeWorkspace(value)
  const response = await request(endpoint, { action: 'workspace_save', workspace })
  return { ...workspace, id: String(response.id || workspace.id), revision: Number(response.revision || workspace.revision), synced: true }
}

export async function loadWorkspace(endpoint: string, id: string): Promise<SavedWorkspace> {
  const response = await request(endpoint, { action: 'workspace_load', id })
  if (!isSavedWorkspace(response.workspace)) throw new Error('The saved workspace response is invalid.')
  return sanitizeWorkspace({ ...response.workspace, synced: true })
}

export async function listWorkspaces(endpoint: string): Promise<Array<Pick<SavedWorkspace, 'id' | 'name' | 'revision' | 'updatedAt'>>> {
  const response = await request(endpoint, { action: 'workspace_list' })
  return Array.isArray(response.workspaces) ? response.workspaces.filter(isWorkspaceSummary).map((item) => ({ id: item.id, name: item.name, revision: Number(item.revision), updatedAt: String((item as Record<string, unknown>).updated_at || item.updatedAt || '') })) : []
}

function isSavedWorkspace(value: unknown): value is SavedWorkspace {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return false
  const item = value as Record<string, unknown>
  return typeof item.id === 'string' && typeof item.name === 'string' && (item.language === 'php' || item.language === 'javascript') && (item.transport === 'data-api' || item.transport === 'odata') && ['concise', 'inline', 'teaching', 'advanced'].includes(String(item.commentMode)) && Array.isArray(item.favorites) && Array.isArray(item.blocks) && typeof item.revision === 'number'
}

function isWorkspaceSummary(value: unknown): value is Pick<SavedWorkspace, 'id' | 'name' | 'revision' | 'updatedAt'> {
  return !!value && typeof value === 'object' && typeof (value as Record<string, unknown>).id === 'string' && typeof (value as Record<string, unknown>).name === 'string' && typeof (value as Record<string, unknown>).revision === 'number'
}

async function request(endpoint: string, payload: Record<string, unknown>): Promise<Record<string, unknown>> {
  const response = await fetch(endpoint, { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(payload) })
  const body = await response.json() as Record<string, unknown>
  if (!response.ok) throw new Error(typeof body.error === 'string' ? body.error : `Workspace request returned HTTP ${response.status}.`)
  return body
}
