export type PlaygroundLanguage = 'php' | 'javascript'
export type PlaygroundTransport = 'data-api' | 'odata'
export type ModuleStatus = 'active' | 'draft' | 'archived'
export type ModuleCategory = 'read' | 'find' | 'write' | 'scripts' | 'globals' | 'setup' | 'debugging'
export type ModuleDifficulty = 'beginner' | 'intermediate' | 'advanced'
export type ModuleSafetyLevel = 'read-only' | 'side-effect' | 'restricted'
export type ModuleRunMode = 'read-only-live' | 'code-only' | 'disabled'
export type ModuleInputType = 'text' | 'integer' | 'boolean' | 'json'
export type ModuleVariantKey = `${PlaygroundLanguage}:${PlaygroundTransport}`
export type CommentMode = 'concise' | 'inline' | 'teaching' | 'advanced'

export type ModuleInputValidation = {
  min?: number
  max?: number
  pattern?: string
}

export type ModuleInput = {
  id: string
  label: string
  help: string
  type: ModuleInputType
  required: boolean
  defaultValue: string | number | boolean
  sample: string | number | boolean
  validation?: ModuleInputValidation
}

export type SupportedModuleVariant = {
  status: 'supported'
  template: string
}

export type UnsupportedModuleVariant = {
  status: 'unsupported'
  reason: string
  example?: string
}

export type ModuleVariant = SupportedModuleVariant | UnsupportedModuleVariant

export type PlaygroundModule = {
  id: string
  version: number
  status: ModuleStatus
  category: ModuleCategory
  order: number
  tags: string[]
  method: 'GET' | 'POST' | 'PATCH' | 'DELETE'
  beginnerName: string
  developerName: string
  summary: string
  purpose: string
  difficulty: ModuleDifficulty
  learningGoals: string[]
  explanation: string
  commonMistakes: string[]
  expectedResult: string
  safety: {
    level: ModuleSafetyLevel
    sideEffects: boolean
    runMode: ModuleRunMode
    confirmationText?: string
  }
  support: {
    languages: PlaygroundLanguage[]
    transports: PlaygroundTransport[]
    prerequisites: string[]
  }
  inputs: ModuleInput[]
  variants: Record<ModuleVariantKey, ModuleVariant>
  comments: {
    beginner: string[]
    advanced: string[]
  }
  tests: {
    fixtureId: string
    expectedPlaceholders: string[]
    safeOperation?: 'list' | 'find' | 'get'
  }
  links: string[]
  compatibilityAliases?: string[]
}

export type WorkspaceBlock = {
  blockId: string
  moduleId: string
  moduleVersion: number
  inputs: Record<string, string | number | boolean>
  collapsed?: boolean
}

export type PlaygroundWorkspace = {
  schemaVersion: 1
  id: string
  name: string
  language: PlaygroundLanguage
  transport: PlaygroundTransport
  commentMode: CommentMode
  blocks: WorkspaceBlock[]
  manualDraft?: string
  createdAt: string
  updatedAt: string
}

export type ModuleValidationError = {
  moduleId: string
  path: string
  message: string
}

export type GenerationIssue = {
  blockId: string
  moduleId: string
  inputId?: string
  message: string
}

export type GenerationResult = {
  code: string
  issues: GenerationIssue[]
  executable: boolean
}
