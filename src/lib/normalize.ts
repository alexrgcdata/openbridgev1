export type Transport = 'odata' | 'data-api'

export type NormalizedMessage = { code: string; message: string }

/** Stable outer contract shared by both transports. Source records are never flattened or renamed. */
export type NormalizedResponse = {
  ok: boolean
  transport: Transport
  operation: string
  database: string
  resource: string
  records: Record<string, unknown>[]
  returnedCount: number
  totalCount: number | null
  nextLink: string | null
  messages: NormalizedMessage[]
  meta: Record<string, unknown>
  raw: unknown
}

export function normalizeOData(raw: Record<string, unknown>, context: { operation: string; database: string; resource: string }): NormalizedResponse {
  const value = Array.isArray(raw.value) ? raw.value.filter(isRecord) : []
  const error = isRecord(raw.error) ? raw.error : null
  const records = value.length ? value : isEntity(raw) ? [raw] : []
  return {
    ok: !error,
    transport: 'odata', operation: context.operation, database: context.database, resource: context.resource,
    records, returnedCount: records.length,
    totalCount: typeof raw['@odata.count'] === 'number' ? raw['@odata.count'] : null,
    nextLink: typeof raw['@odata.nextLink'] === 'string' ? raw['@odata.nextLink'] : null,
    messages: error ? [{ code: String(error.code ?? 'odata_error'), message: readMessage(error.message) }] : [],
    meta: Object.fromEntries(Object.entries(raw).filter(([key]) => key.startsWith('@odata.'))), raw,
  }
}

export function normalizeDataApi(raw: Record<string, unknown>, context: { operation: string; database: string; resource: string }): NormalizedResponse {
  const response = isRecord(raw.response) ? raw.response : {}
  const dataInfo = isRecord(response.dataInfo) ? response.dataInfo : {}
  const records = Array.isArray(response.data) ? response.data.filter(isRecord) : []
  const messages = Array.isArray(raw.messages) ? raw.messages.filter(isRecord).map((item) => ({ code: String(item.code ?? ''), message: String(item.message ?? '') })) : []
  return {
    ok: !messages.some((item) => item.code !== '0'),
    transport: 'data-api', operation: context.operation, database: context.database, resource: context.resource,
    records, returnedCount: records.length,
    totalCount: typeof dataInfo.foundCount === 'number' ? dataInfo.foundCount : null,
    nextLink: null, messages, meta: { dataInfo }, raw,
  }
}

function isRecord(value: unknown): value is Record<string, unknown> { return Boolean(value) && typeof value === 'object' && !Array.isArray(value) }
function isEntity(raw: Record<string, unknown>) { return Object.keys(raw).some((key) => !key.startsWith('@odata.')) && !('error' in raw) }
function readMessage(value: unknown) { return typeof value === 'string' ? value : isRecord(value) && typeof value.value === 'string' ? value.value : 'OData request failed.' }

export const sampleODataRaw = { '@odata.context': '$metadata#Customers', '@odata.count': 2, value: [{ CustomerID: '1001', Name: 'Avery Labs', Status: 'Active' }, { CustomerID: '1002', Name: 'Northstar', Status: 'Active' }] }
export const sampleDataApiRaw = { response: { dataInfo: { database: 'OpenBridge Demo', layout: 'Customers', foundCount: 2, returnedCount: 2 }, data: [{ fieldData: { CustomerID: '1001', Name: 'Avery Labs', Status: 'Active' }, portalData: {}, recordId: '1', modId: '3' }, { fieldData: { CustomerID: '1002', Name: 'Northstar', Status: 'Active' }, portalData: {}, recordId: '2', modId: '1' }] }, messages: [{ code: '0', message: 'OK' }] }
