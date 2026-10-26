import type {
  CommentMode,
  GenerationIssue,
  GenerationResult,
  ModuleInput,
  PlaygroundLanguage,
  PlaygroundModule,
  PlaygroundTransport,
  WorkspaceBlock,
} from './types'

type GenerateOptions = {
  language: PlaygroundLanguage
  transport: PlaygroundTransport
  commentMode?: CommentMode
  includeBootstrap?: boolean
}

const tokenPattern = /\{\{([a-zA-Z]+):([a-zA-Z][a-zA-Z0-9]*)\}\}/g

function phpSingle(value: string): string {
  return value.replaceAll('\\', '\\\\').replaceAll("'", "\\'")
}

function phpDouble(value: string): string {
  return value.replaceAll('\\', '\\\\').replaceAll('$', '\\$').replaceAll('"', '\\"')
}

function odata(value: string): string {
  return value.replaceAll("'", "''").replace(/[\r\n]/g, ' ')
}

function odataField(value: string): string | null {
  return /['"\r\n(){};,]/.test(value) || value.trim() === '' ? null : value
}

function javascriptString(value: string): string {
  return JSON.stringify(value)
}

function integer(value: unknown, input: ModuleInput): string | null {
  const parsed = typeof value === 'number' ? value : Number(String(value))
  if (!Number.isInteger(parsed)) return null
  if (input.validation?.min !== undefined && parsed < input.validation.min) return null
  if (input.validation?.max !== undefined && parsed > input.validation.max) return null
  return String(parsed)
}

function renderValue(kind: string, value: unknown, input: ModuleInput): string | null {
  if (kind === 'integer') return integer(value, input)
  const text = String(value)
  if (input.required && text.trim() === '') return null
  if (input.validation?.pattern && !new RegExp(input.validation.pattern).test(text)) return null
  if (kind === 'php') return phpSingle(text)
  if (kind === 'phpDouble') return phpDouble(text)
  if (kind === 'odataPhpDouble') return phpDouble(odata(text))
  if (kind === 'odataFieldPhpDouble') {
    const field = odataField(text)
    return field === null ? null : phpDouble(field)
  }
  if (kind === 'js') return javascriptString(text)
  if (kind === 'odataJs') return javascriptString(odata(text))
  if (kind === 'odataFieldJs') {
    const field = odataField(text)
    return field === null ? null : javascriptString(field)
  }
  if (kind === 'raw') return text
  return null
}

function commentPrefix(module: PlaygroundModule, language: PlaygroundLanguage, mode: CommentMode): string {
  if (mode === 'concise' || mode === 'inline') return ''
  const comments = mode === 'teaching' ? module.comments.beginner : module.comments.advanced
  const marker = language === 'php' || language === 'javascript' ? '// ' : '# '
  return comments.map((comment) => `${marker}${comment}`).join('\n') + (comments.length ? '\n' : '')
}

function stripInlineComments(code: string): string {
  return code.split('\n').filter((line) => !line.trimStart().startsWith('//')).join('\n').replace(/^\s+|\s+$/g, '')
}

function bootstrap(language: PlaygroundLanguage): string {
  return language === 'php'
    ? "<?php\nrequire_once __DIR__ . '/restCWP/restCWP.php';\n\n"
    : "// OpenBridge browser requests use the protected same-origin PHP endpoint.\n\n"
}

export function generateModuleCode(module: PlaygroundModule, block: WorkspaceBlock, options: GenerateOptions): GenerationResult {
  const key = `${options.language}:${options.transport}` as const
  const variant = module.variants[key]
  if (variant.status === 'unsupported') {
    return {
      code: variant.example || `// ${module.beginnerName} is unavailable for ${options.language} + ${options.transport}: ${variant.reason}`,
      issues: [{ blockId: block.blockId, moduleId: module.id, message: variant.reason }],
      executable: false,
    }
  }
  const issues: GenerationIssue[] = []
  const byId = new Map(module.inputs.map((input) => [input.id, input]))
  const code = variant.template.replace(tokenPattern, (_token, kind: string, inputId: string) => {
    const input = byId.get(inputId)
    if (!input) {
      issues.push({ blockId: block.blockId, moduleId: module.id, inputId, message: `Template references unknown input ${inputId}.` })
      return `{{${kind}:${inputId}}}`
    }
    const value = block.inputs[inputId] ?? input.defaultValue
    const rendered = renderValue(kind, value, input)
    if (rendered === null) {
      issues.push({ blockId: block.blockId, moduleId: module.id, inputId, message: `${input.label} is missing or invalid.` })
      return `{{${kind}:${inputId}}}`
    }
    return rendered
  })
  const commentMode = options.commentMode ?? 'inline'
  const renderedCode = commentMode === 'concise' ? stripInlineComments(code) : `${commentPrefix(module, options.language, commentMode)}${code}`
  return { code: renderedCode, issues, executable: issues.length === 0 && module.safety.runMode === 'read-only-live' }
}

export function generateWorkspaceCode(modules: Map<string, PlaygroundModule>, blocks: WorkspaceBlock[], options: GenerateOptions): GenerationResult {
  const results = blocks.map((block): GenerationResult => {
    const module = modules.get(block.moduleId)
    if (!module) return { code: '', issues: [{ blockId: block.blockId, moduleId: block.moduleId, message: 'Module is missing from the registry.' }], executable: false }
    if (module.version !== block.moduleVersion) return { code: '', issues: [{ blockId: block.blockId, moduleId: block.moduleId, message: `Workspace expects version ${block.moduleVersion}; registry has version ${module.version}.` }], executable: false }
    return generateModuleCode(module, block, options)
  })
  const body = results.map((result) => result.code).filter(Boolean).map((code) => (
    options.language === 'javascript' ? `{\n${code.split('\n').map((line) => `  ${line}`).join('\n')}\n}` : code
  )).join('\n\n')
  return {
    code: `${options.includeBootstrap ? bootstrap(options.language) : ''}${body}`,
    issues: results.flatMap((result) => result.issues),
    executable: results.every((result) => result.executable),
  }
}
