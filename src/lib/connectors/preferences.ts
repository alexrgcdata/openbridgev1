import { connectorCatalog } from "./catalog";

const STORAGE_KEY = "openbridge-primary-integration-v1";

export function normalizePrimaryConnectorId(value: unknown): string {
  if (typeof value !== "string" || value === "filemaker") return "";
  return connectorCatalog.some((connector) => connector.id === value) ? value : "";
}

export function loadPrimaryConnectorId(): string {
  return normalizePrimaryConnectorId(localStorage.getItem(STORAGE_KEY));
}

export function savePrimaryConnectorId(id: string): void {
  const normalized = normalizePrimaryConnectorId(id);
  if (normalized) localStorage.setItem(STORAGE_KEY, normalized);
  else localStorage.removeItem(STORAGE_KEY);
}
