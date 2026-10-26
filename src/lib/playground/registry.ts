import type {
  ModuleCategory,
  ModuleDifficulty,
  ModuleInput,
  ModuleRunMode,
  ModuleSafetyLevel,
  ModuleStatus,
  ModuleValidationError,
  ModuleVariant,
  ModuleVariantKey,
  PlaygroundLanguage,
  PlaygroundModule,
  PlaygroundTransport,
} from './types'

const languages: PlaygroundLanguage[] = ['php', 'javascript']
const transports: PlaygroundTransport[] = ['data-api', 'odata']
const variantKeys: ModuleVariantKey[] = ['php:data-api', 'php:odata', 'javascript:data-api', 'javascript:odata']
const statuses: ModuleStatus[] = ['active', 'draft', 'archived']
const categories: ModuleCategory[] = ['read', 'find', 'write', 'scripts', 'globals', 'setup', 'debugging']
const difficulties: ModuleDifficulty[] = ['beginner', 'intermediate', 'advanced']
const safetyLevels: ModuleSafetyLevel[] = ['read-only', 'side-effect', 'restricted']
const runModes: ModuleRunMode[] = ['read-only-live', 'code-only', 'disabled']
const placeholderPattern = /\{\{([a-zA-Z]+):([a-zA-Z][a-zA-Z0-9]*)\}\}/g
const placeholderKinds = ['php', 'phpDouble', 'odataPhpDouble', 'odataFieldPhpDouble', 'js', 'odataJs', 'odataFieldJs', 'integer', 'raw']

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function strings(value: unknown): value is string[] {
  return Array.isArray(value) && value.every((item) => typeof item === 'string')
}

function readInput(value: unknown, moduleId: string, index: number, errors: ModuleValidationError[]): ModuleInput | null {
  if (!isRecord(value)) {
    errors.push({ moduleId, path: `inputs.${index}`, message: 'Input metadata must be an object.' })
    return null
  }
  const id = typeof value.id === 'string' ? value.id : ''
  const type = value.type
  if (!id || typeof value.label !== 'string' || typeof value.help !== 'string') {
    errors.push({ moduleId, path: `inputs.${index}`, message: 'Every input needs an id, label, and help text.' })
    return null
  }
  if (!['text', 'integer', 'boolean', 'json'].includes(String(type))) {
    errors.push({ moduleId, path: `inputs.${index}.type`, message: `Unsupported input type for ${id}.` })
    return null
  }
  if (typeof value.required !== 'boolean' || !['string', 'number', 'boolean'].includes(typeof value.defaultValue) || !['string', 'number', 'boolean'].includes(typeof value.sample)) {
    errors.push({ moduleId, path: `inputs.${index}`, message: `Input ${id} needs required, defaultValue, and sample metadata.` })
    return null
  }
  const validation = isRecord(value.validation)
    ? {
        ...(typeof value.validation.min === 'number' ? { min: value.validation.min } : {}),
        ...(typeof value.validation.max === 'number' ? { max: value.validation.max } : {}),
        ...(typeof value.validation.pattern === 'string' ? { pattern: value.validation.pattern } : {}),
      }
    : undefined
  return {
    id,
    label: value.label,
    help: value.help,
    type: type as ModuleInput['type'],
    required: value.required,
    defaultValue: value.defaultValue as string | number | boolean,
    sample: value.sample as string | number | boolean,
    ...(validation ? { validation } : {}),
  }
}

function readVariant(value: unknown, moduleId: string, key: ModuleVariantKey, errors: ModuleValidationError[]): ModuleVariant | null {
  if (!isRecord(value) || (value.status !== 'supported' && value.status !== 'unsupported')) {
    errors.push({ moduleId, path: `variants.${key}`, message: 'Every language/transport pair needs an explicit supported or unsupported variant.' })
    return null
  }
  if (value.status === 'supported' && (typeof value.template !== 'string' || value.template.trim() === '')) {
    errors.push({ moduleId, path: `variants.${key}.template`, message: 'Supported variants need a non-empty template.' })
    return null
  }
  if (value.status === 'unsupported' && (typeof value.reason !== 'string' || value.reason.trim() === '')) {
    errors.push({ moduleId, path: `variants.${key}.reason`, message: 'Unsupported variants need an honest explanation.' })
    return null
  }
  return value.status === 'supported'
    ? { status: 'supported', template: value.template as string }
    : { status: 'unsupported', reason: value.reason as string, ...(typeof value.example === 'string' ? { example: value.example } : {}) }
}

export function validatePlaygroundModule(value: unknown): { module: PlaygroundModule | null; errors: ModuleValidationError[] } {
  const errors: ModuleValidationError[] = []
  if (!isRecord(value)) return { module: null, errors: [{ moduleId: 'unknown', path: '', message: 'Module must be an object.' }] }
  const moduleId = typeof value.id === 'string' ? value.id : 'unknown'
  if (!/^[a-z][a-z0-9-]*$/.test(moduleId)) errors.push({ moduleId, path: 'id', message: 'Module id must be stable lowercase kebab-case.' })
  if (!Number.isInteger(value.version) || Number(value.version) < 1) errors.push({ moduleId, path: 'version', message: 'Version must be a positive integer.' })
  if (!statuses.includes(value.status as ModuleStatus)) errors.push({ moduleId, path: 'status', message: 'Module status is invalid.' })
  if (!categories.includes(value.category as ModuleCategory)) errors.push({ moduleId, path: 'category', message: 'Module category is invalid.' })
  if (!Number.isInteger(value.order) || Number(value.order) < 0) errors.push({ moduleId, path: 'order', message: 'Order must be a non-negative integer.' })
  if (!strings(value.tags)) errors.push({ moduleId, path: 'tags', message: 'Tags must be strings.' })
  if (!['GET', 'POST', 'PATCH', 'DELETE'].includes(String(value.method))) errors.push({ moduleId, path: 'method', message: 'HTTP method is invalid.' })
  for (const key of ['beginnerName', 'developerName', 'summary', 'purpose', 'explanation', 'expectedResult'] as const) {
    if (typeof value[key] !== 'string' || value[key].trim() === '') errors.push({ moduleId, path: key, message: `${key} is required.` })
  }
  if (!difficulties.includes(value.difficulty as ModuleDifficulty)) errors.push({ moduleId, path: 'difficulty', message: 'Difficulty is invalid.' })
  if (!strings(value.learningGoals) || !strings(value.commonMistakes)) errors.push({ moduleId, path: 'teaching', message: 'Learning goals and common mistakes must be string lists.' })

  const safety = isRecord(value.safety) ? value.safety : {}
  if (!safetyLevels.includes(safety.level as ModuleSafetyLevel) || typeof safety.sideEffects !== 'boolean' || !runModes.includes(safety.runMode as ModuleRunMode)) {
    errors.push({ moduleId, path: 'safety', message: 'Safety requires a level, sideEffects flag, and runMode.' })
  }
  if (safety.sideEffects === true && safety.runMode === 'read-only-live') errors.push({ moduleId, path: 'safety.runMode', message: 'A side-effecting module cannot be marked read-only-live.' })

  const support = isRecord(value.support) ? value.support : {}
  if (!Array.isArray(support.languages) || !support.languages.every((item) => languages.includes(item as PlaygroundLanguage))) errors.push({ moduleId, path: 'support.languages', message: 'Supported languages are invalid.' })
  if (!Array.isArray(support.transports) || !support.transports.every((item) => transports.includes(item as PlaygroundTransport))) errors.push({ moduleId, path: 'support.transports', message: 'Supported transports are invalid.' })
  if (!strings(support.prerequisites)) errors.push({ moduleId, path: 'support.prerequisites', message: 'Prerequisites must be strings.' })

  const inputs: ModuleInput[] = []
  if (!Array.isArray(value.inputs)) errors.push({ moduleId, path: 'inputs', message: 'Inputs must be an array.' })
  else value.inputs.forEach((input, index) => { const parsed = readInput(input, moduleId, index, errors); if (parsed) inputs.push(parsed) })
  const duplicateInput = inputs.find((input, index) => inputs.findIndex((candidate) => candidate.id === input.id) !== index)
  if (duplicateInput) errors.push({ moduleId, path: 'inputs', message: `Duplicate input id: ${duplicateInput.id}.` })

  const variants = {} as Record<ModuleVariantKey, ModuleVariant>
  const rawVariants = isRecord(value.variants) ? value.variants : {}
  variantKeys.forEach((key) => { const parsed = readVariant(rawVariants[key], moduleId, key, errors); if (parsed) variants[key] = parsed })

  const comments = isRecord(value.comments) ? value.comments : {}
  if (!strings(comments.beginner) || !strings(comments.advanced)) errors.push({ moduleId, path: 'comments', message: 'Beginner and advanced comments must be string lists.' })
  const tests = isRecord(value.tests) ? value.tests : {}
  if (typeof tests.fixtureId !== 'string' || !strings(tests.expectedPlaceholders)) errors.push({ moduleId, path: 'tests', message: 'Tests need a fixture id and expected placeholder list.' })
  if (!strings(value.links)) errors.push({ moduleId, path: 'links', message: 'Links must be string references.' })
  if (value.compatibilityAliases !== undefined && !strings(value.compatibilityAliases)) errors.push({ moduleId, path: 'compatibilityAliases', message: 'Compatibility aliases must be strings.' })
  if (strings(value.compatibilityAliases)) {
    for (const alias of value.compatibilityAliases) {
      if (!/^[a-z][a-z0-9-]*$/.test(alias)) errors.push({ moduleId, path: 'compatibilityAliases', message: `Alias must be lowercase kebab-case: ${alias}.` })
      if (alias === moduleId) errors.push({ moduleId, path: 'compatibilityAliases', message: 'An alias cannot repeat the module id.' })
    }
  }

  const inputIds = new Set(inputs.map((input) => input.id))
  const discoveredPlaceholders = new Set<string>()
  for (const key of variantKeys) {
    const variant = variants[key]
    if (!variant || variant.status !== 'supported') continue
    for (const match of variant.template.matchAll(placeholderPattern)) {
      const kind = match[1]
      const inputId = match[2]
      if (!placeholderKinds.includes(kind)) errors.push({ moduleId, path: `variants.${key}`, message: `Unknown placeholder kind: ${kind}.` })
      if (!inputIds.has(inputId)) errors.push({ moduleId, path: `variants.${key}`, message: `Template references unknown input: ${inputId}.` })
      discoveredPlaceholders.add(match[0])
    }
    const unmatched = variant.template.replace(placeholderPattern, '').match(/\{\{[^}]+\}\}/)
    if (unmatched) errors.push({ moduleId, path: `variants.${key}`, message: `Invalid placeholder syntax: ${unmatched[0]}.` })
  }
  if (strings(tests.expectedPlaceholders)) {
    const expected = [...new Set(tests.expectedPlaceholders)].sort()
    const discovered = [...discoveredPlaceholders].sort()
    if (expected.join('|') !== discovered.join('|')) errors.push({ moduleId, path: 'tests.expectedPlaceholders', message: `Expected placeholders (${expected.join(', ')}) do not match templates (${discovered.join(', ')}).` })
  }
  if (tests.safeOperation !== undefined && !['list', 'find', 'get'].includes(String(tests.safeOperation))) errors.push({ moduleId, path: 'tests.safeOperation', message: 'Safe operation must be list, find, or get.' })
  if (tests.safeOperation !== undefined && safety.runMode !== 'read-only-live') errors.push({ moduleId, path: 'tests.safeOperation', message: 'Only read-only-live modules may define a safe operation.' })

  const supportedPairs = variantKeys.filter((key) => variants[key]?.status === 'supported')
  const declaredLanguages = Array.isArray(support.languages) ? support.languages as PlaygroundLanguage[] : []
  const declaredTransports = Array.isArray(support.transports) ? support.transports as PlaygroundTransport[] : []
  for (const key of supportedPairs) {
    const [language, transport] = key.split(':') as [PlaygroundLanguage, PlaygroundTransport]
    if (!declaredLanguages.includes(language)) errors.push({ moduleId, path: 'support.languages', message: `${language} has a supported variant but is not declared.` })
    if (!declaredTransports.includes(transport)) errors.push({ moduleId, path: 'support.transports', message: `${transport} has a supported variant but is not declared.` })
  }
  for (const language of declaredLanguages) {
    if (!supportedPairs.some((key) => key.startsWith(`${language}:`))) errors.push({ moduleId, path: 'support.languages', message: `${language} is declared but has no supported variant.` })
  }
  for (const transport of declaredTransports) {
    if (!supportedPairs.some((key) => key.endsWith(`:${transport}`))) errors.push({ moduleId, path: 'support.transports', message: `${transport} is declared but has no supported variant.` })
  }

  if (errors.length) return { module: null, errors }
  return { module: value as unknown as PlaygroundModule, errors: [] }
}

const rawModules = import.meta.glob('../../data/playground-modules/*.json', { eager: true, import: 'default' })

export function buildModuleRegistry(values: unknown[]): PlaygroundModule[] {
  const modules = values.map((value) => {
    const result = validatePlaygroundModule(value)
    if (!result.module) throw new Error(result.errors.map((error) => `${error.moduleId}:${error.path} ${error.message}`).join('\n'))
    return result.module
  })
  const ids = new Set<string>()
  for (const module of modules) {
    for (const name of [module.id, ...(module.compatibilityAliases ?? [])]) {
      if (ids.has(name)) throw new Error(`Duplicate module id or alias: ${name}.`)
      ids.add(name)
    }
  }
  return modules.sort((left, right) => left.order - right.order || left.id.localeCompare(right.id))
}

export const playgroundModules = buildModuleRegistry(Object.values(rawModules))
export const playgroundModuleIndex = new Map<string, PlaygroundModule>()
for (const module of playgroundModules) {
  playgroundModuleIndex.set(module.id, module)
  for (const alias of module.compatibilityAliases ?? []) playgroundModuleIndex.set(alias, module)
}

export function resolvePlaygroundModule(id: string): PlaygroundModule | undefined {
  return playgroundModuleIndex.get(id)
}
