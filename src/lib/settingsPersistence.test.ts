import { describe, expect, it } from "vitest";
import {
  buildCredentialVaultProfile,
  fileMakerProfileFieldsReady,
  settingsAfterCredentialSave,
  setupFileMakerCanAdvance,
  toPublicStoredSettings,
} from "./settingsPersistence";
import type { AdminSettings } from "./settings";

const baseSettings: AdminSettings = {
  backendUrl: "/openbridge/api/openbridge-api.php",
  bridgeToken: "bridge-token",
  serverUrl: "https://filemaker.example.com",
  database: "OpenBridge",
  defaultLayout: "Customers",
  username: "api-user",
  password: "secret",
  notes: "Call before changing schema.",
  transport: "odata",
  logCapacity: 50,
  storeResponses: true,
  encryptionSecret: "diagnostic-secret",
};

describe("settingsPersistence", () => {
  it("builds a vault profile when full new FileMaker credentials are ready", () => {
    expect(buildCredentialVaultProfile(baseSettings)).toEqual({
      host: "https://filemaker.example.com",
      database: "OpenBridge",
      layout: "Customers",
      table: "Customers",
      username: "api-user",
      password: "secret",
      transport: "odata",
    });

    expect(buildCredentialVaultProfile({ ...baseSettings, defaultLayout: " " })).toBeNull();
    expect(buildCredentialVaultProfile({ ...baseSettings, password: "" })).toBeNull();
  });

  it("allows edited connection fields to reuse an existing vault password", () => {
    expect(buildCredentialVaultProfile({
      ...baseSettings,
      serverUrl: "https://new-filemaker.example.com",
      defaultLayout: "Invoices",
      password: "",
    }, true)).toEqual({
      host: "https://new-filemaker.example.com",
      database: "OpenBridge",
      layout: "Invoices",
      table: "Invoices",
      username: "api-user",
      password: "",
      transport: "odata",
    });
  });

  it("rejects a blank password when no vault has been configured yet", () => {
    expect(buildCredentialVaultProfile({ ...baseSettings, password: "" }, false)).toBeNull();
  });

  it("clears the password only after credentials are stored", () => {
    expect(settingsAfterCredentialSave(baseSettings, true).password).toBe("");
    expect(settingsAfterCredentialSave(baseSettings, false).password).toBe("secret");
  });

  it("keeps secrets out of packaged SQLite settings sync", () => {
    const stored = toPublicStoredSettings(baseSettings) as Record<string, unknown>;

    expect(stored.password).toBeUndefined();
    expect(stored.bridgeToken).toBeUndefined();
    expect(stored.encryptionSecret).toBeUndefined();
    expect(stored.serverUrl).toBe(baseSettings.serverUrl);
  });

  it("advances setup only after required fields and vault storage succeed", () => {
    expect(setupFileMakerCanAdvance(baseSettings, true)).toBe(true);
    expect(setupFileMakerCanAdvance(baseSettings, false)).toBe(false);
    expect(setupFileMakerCanAdvance({ ...baseSettings, database: "" }, true)).toBe(false);
  });

  it("recognizes when fields are complete enough to check for saved vault credentials", () => {
    expect(fileMakerProfileFieldsReady({ ...baseSettings, password: "" })).toBe(true);
    expect(fileMakerProfileFieldsReady({ ...baseSettings, username: "" })).toBe(false);
  });
});
