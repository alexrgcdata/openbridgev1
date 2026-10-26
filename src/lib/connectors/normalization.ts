import { connectorCatalog } from "./catalog";

export type NormalizedConnectorRecord = {
  connectorId: string;
  externalId: string | null;
  kind: string;
  fields: Record<string, unknown>;
};

export type NormalizedConnectorEnvelope = {
  ok: true;
  connectorId: string;
  operation: string;
  receivedAt: string;
  records: NormalizedConnectorRecord[];
  page: {
    count: number;
    hasMore: boolean;
    cursor: string | null;
  };
  warnings: string[];
};

type NormalizationProfile = {
  collectionKeys: string[];
  idKeys: string[];
  cursorKeys: string[];
  hasMoreKeys: string[];
};

const defaults: NormalizationProfile = {
  collectionKeys: ["data", "items", "results", "records", "value"],
  idKeys: ["id", "Id", "ID", "recordId", "record_id"],
  cursorKeys: ["next_cursor", "nextCursor", "cursor", "after"],
  hasMoreKeys: ["has_more", "hasMore"],
};

export const connectorNormalizationProfiles: Record<string, NormalizationProfile> = {
  filemaker: { ...defaults, collectionKeys: ["value", "records", "data"] },
  quickbooks: { ...defaults, collectionKeys: ["QueryResponse", "Customer", "Vendor", "Invoice", "Payment", "Account"] },
  xero: { ...defaults, collectionKeys: ["Contacts", "Invoices", "Payments", "Accounts"] },
  stripe: { ...defaults, collectionKeys: ["data"] },
  "authorize-net": { ...defaults, collectionKeys: ["transactions", "profiles", "data"] },
  square: { ...defaults, collectionKeys: ["customers", "payments", "orders", "objects", "catalog_objects"] },
  shopify: { ...defaults, collectionKeys: ["edges", "nodes", "data"] },
  woocommerce: { ...defaults, collectionKeys: ["data"] },
  hubspot: { ...defaults, collectionKeys: ["results"] },
  salesforce: { ...defaults, collectionKeys: ["records"] },
  "google-workspace": { ...defaults, collectionKeys: ["files", "values", "items"] },
  "microsoft-365": { ...defaults, collectionKeys: ["value"] },
  airtable: { ...defaults, collectionKeys: ["records"] },
  "file-import": { ...defaults, collectionKeys: ["rows", "records", "data"] },
  sftp: { ...defaults, collectionKeys: ["files", "entries", "items"] },
  "rest-api": defaults,
};

export function normalizeConnectorPayload(
  connectorId: string,
  payload: unknown,
  options: { operation?: string; kind?: string; receivedAt?: string } = {},
): NormalizedConnectorEnvelope {
  if (!connectorCatalog.some((connector) => connector.id === connectorId)) {
    throw new Error(`Unknown connector: ${connectorId}`);
  }
  const profile = connectorNormalizationProfiles[connectorId];
  const collection = findCollection(payload, profile.collectionKeys);
  const warnings: string[] = [];
  if (!collection) warnings.push("No record collection was found; the response was normalized as one record.");
  const values = collection ?? [payload];
  const records = values.map((value) => {
    const unwrapped = unwrapGraphEdge(value);
    const fields = isRecord(unwrapped) ? { ...unwrapped } : { value: unwrapped };
    return {
      connectorId,
      externalId: findScalar(fields, profile.idKeys) ?? null,
      kind: options.kind ?? connectorId,
      fields,
    };
  });
  const root = isRecord(payload) ? payload : {};
  return {
    ok: true,
    connectorId,
    operation: options.operation ?? "read",
    receivedAt: options.receivedAt ?? new Date().toISOString(),
    records,
    page: {
      count: records.length,
      hasMore: findBoolean(root, profile.hasMoreKeys) ?? false,
      cursor: findScalar(root, profile.cursorKeys) ?? null,
    },
    warnings,
  };
}

function findCollection(value: unknown, preferredKeys: string[], depth = 0): unknown[] | null {
  if (Array.isArray(value)) return value;
  if (!isRecord(value) || depth > 4) return null;
  for (const key of preferredKeys) {
    if (!(key in value)) continue;
    const candidate = value[key];
    if (Array.isArray(candidate)) return candidate;
    const nested = findCollection(candidate, preferredKeys, depth + 1);
    if (nested) return nested;
  }
  for (const candidate of Object.values(value)) {
    const nested = findCollection(candidate, preferredKeys, depth + 1);
    if (nested) return nested;
  }
  return null;
}

function unwrapGraphEdge(value: unknown): unknown {
  return isRecord(value) && "node" in value ? value.node : value;
}

function findScalar(value: Record<string, unknown>, keys: string[]): string | null {
  for (const key of keys) {
    const candidate = value[key];
    if (typeof candidate === "string" || typeof candidate === "number") return String(candidate);
  }
  return null;
}

function findBoolean(value: Record<string, unknown>, keys: string[]): boolean | null {
  for (const key of keys) if (typeof value[key] === "boolean") return value[key];
  return null;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}
