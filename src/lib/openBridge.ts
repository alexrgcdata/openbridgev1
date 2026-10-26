/**
 * Single public React/TypeScript entry point for OpenBridge browser helpers.
 * Consumers can import normalization, encryption, settings, and diagnostic
 * storage from this one module while the PHP transport remains server-side.
 */
export * from './crypto'
export * from './normalize'
export { checkConnectionHealth, runPlaygroundRequest, runTransportTest } from './filemaker'
export type { ConnectionHealth, FileMakerMode, HealthCheckName, HealthCheckResult, PlaygroundOperation, PlaygroundRequest, TransportTestResult } from './filemaker'
export {
  DEFAULT_ADMIN_SETTINGS,
  loadSettings,
  saveSettings,
  normalizeLogCapacity as normalizeSettingsLogCapacity,
} from './settings'
export type { AdminSettings, FileMakerTransport } from './settings'
export {
  appendTransaction,
  createTransaction,
  filterTransactions,
  loadTransactions,
  saveTransactions,
  normalizeLogCapacity as normalizeTransactionLogCapacity,
} from './transactions'
export type { JsonObject, JsonValue, Transaction, TransactionFilters, TransactionInput, TransactionStatus, TransactionTransport } from './transactions'
export { generateModuleCode, generateWorkspaceCode, playgroundModules, resolvePlaygroundModule, validatePlaygroundModule } from './playground'
export type { CommentMode, ModuleInput, ModuleRunMode, ModuleSafetyLevel, ModuleValidationError, ModuleVariant, PlaygroundLanguage, PlaygroundModule, PlaygroundTransport, PlaygroundWorkspace, WorkspaceBlock } from './playground'
export { connectorCatalog, connectorGoals, filterConnectors } from './connectors/catalog'
export { connectorNormalizationProfiles, normalizeConnectorPayload } from './connectors/normalization'
export type { NormalizedConnectorEnvelope, NormalizedConnectorRecord } from './connectors/normalization'
export type { ConnectorAuthType, ConnectorDefinition, ConnectorGoal, ConnectorStatus } from './connectors/types'
