import type { AdminSettings } from "./settings";

export type CredentialVaultProfile = {
  host: string;
  database: string;
  layout: string;
  table: string;
  username: string;
  password: string;
  transport: AdminSettings["transport"];
};

export type PublicStoredSettings = Pick<
  AdminSettings,
  | "backendUrl"
  | "serverUrl"
  | "database"
  | "defaultLayout"
  | "username"
  | "notes"
  | "transport"
  | "logCapacity"
  | "storeResponses"
>;

export function buildCredentialVaultProfile(settings: AdminSettings, existingVaultConfigured = false): CredentialVaultProfile | null {
  if (!hasText(settings.backendUrl)) return null;
  if (!hasText(settings.serverUrl)) return null;
  if (!hasText(settings.database)) return null;
  if (!hasText(settings.defaultLayout)) return null;
  if (!hasText(settings.username)) return null;
  if (!settings.password && !existingVaultConfigured) return null;

  return {
    host: settings.serverUrl,
    database: settings.database,
    layout: settings.defaultLayout,
    table: settings.defaultLayout,
    username: settings.username,
    password: settings.password || "",
    transport: settings.transport,
  };
}

export function settingsAfterCredentialSave(settings: AdminSettings, credentialsStored: boolean): AdminSettings {
  return credentialsStored ? { ...settings, password: "" } : settings;
}

export function toPublicStoredSettings(settings: AdminSettings): PublicStoredSettings {
  return {
    backendUrl: settings.backendUrl,
    serverUrl: settings.serverUrl,
    database: settings.database,
    defaultLayout: settings.defaultLayout,
    username: settings.username,
    notes: settings.notes,
    transport: settings.transport,
    logCapacity: settings.logCapacity,
    storeResponses: settings.storeResponses,
  };
}

export function setupFileMakerCanAdvance(settings: AdminSettings, credentialsStored: boolean): boolean {
  return credentialsStored && fileMakerProfileFieldsReady(settings);
}

export function fileMakerProfileFieldsReady(settings: AdminSettings): boolean {
  return [
    settings.backendUrl,
    settings.serverUrl,
    settings.database,
    settings.defaultLayout,
    settings.username,
  ].every(hasText);
}

function hasText(value: string): boolean {
  return value.trim() !== "";
}
