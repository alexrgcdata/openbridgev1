import { afterEach, describe, expect, it, vi } from "vitest";
import {
  deleteSavedConnection,
  listSavedConnections,
} from "./filemaker";

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

function jsonResponse(body: Record<string, unknown>, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" },
  });
}

describe("saved connection metadata client", () => {
  it("maps safe connection metadata and drops malformed or unsafe rows", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => jsonResponse({
      connections: [
        {
          id: "11111111-1111-4111-8111-111111111111",
          provider_id: "filemaker",
          display_name: "FileMaker production",
          environment: "production",
          auth_type: "filemaker-credentials",
          revision: 3,
          created_at: "2026-09-07T10:00:00.000Z",
          updated_at: "2026-09-07T11:00:00.000Z",
          encrypted_blob: "must-not-map",
          password: "must-not-map",
        },
        {
          id: "22222222-2222-4222-8222-222222222222",
          providerId: "future-provider",
          displayName: "Future provider metadata",
          environment: "test",
          authType: "oauth2",
          revision: 1,
          createdAt: "2026-09-07T12:00:00.000Z",
          updatedAt: "2026-09-07T13:00:00.000Z",
        },
        {
          id: "not-a-safe-id",
          provider_id: "filemaker",
          display_name: "Invalid id",
          environment: "production",
          auth_type: "filemaker-credentials",
          revision: 1,
        },
        {
          id: "33333333-3333-4333-8333-333333333333",
          provider_id: "../bad",
          display_name: "Unsafe provider",
          environment: "production",
          auth_type: "api-key",
          revision: 1,
        },
        {
          id: "44444444-4444-4444-8444-444444444444",
          provider_id: "filemaker",
          display_name: "Floating revision",
          environment: "production",
          auth_type: "filemaker-credentials",
          revision: 1.5,
        },
        {
          id: "55555555-5555-4555-8555-555555555555",
          provider_id: "filemaker",
          display_name: "x".repeat(256),
          environment: "production",
          auth_type: "filemaker-credentials",
          revision: 1,
        },
      ],
    })));

    const connections = await listSavedConnections("/openbridge/api/openbridge-api.php");

    expect(connections).toEqual([
      {
        id: "11111111-1111-4111-8111-111111111111",
        providerId: "filemaker",
        displayName: "FileMaker production",
        environment: "production",
        authType: "filemaker-credentials",
        revision: 3,
        createdAt: "2026-09-07T10:00:00.000Z",
        updatedAt: "2026-09-07T11:00:00.000Z",
      },
      {
        id: "22222222-2222-4222-8222-222222222222",
        providerId: "future-provider",
        displayName: "Future provider metadata",
        environment: "test",
        authType: "oauth2",
        revision: 1,
        createdAt: "2026-09-07T12:00:00.000Z",
        updatedAt: "2026-09-07T13:00:00.000Z",
      },
    ]);
    expect(connections[0]).not.toHaveProperty("encrypted_blob");
    expect(connections[0]).not.toHaveProperty("password");
  });

  it("sends a revision-checked local delete request", async () => {
    const fetchMock = vi.fn(async () => jsonResponse({ deleted: true }));
    vi.stubGlobal("fetch", fetchMock);

    const result = await deleteSavedConnection(
      "/openbridge/api/openbridge-api.php",
      "11111111-1111-4111-8111-111111111111",
      4,
    );

    expect(result).toEqual({ deleted: true });
    const [url, request] = fetchMock.mock.calls[0] ?? [];
    expect(url).toBe("/openbridge/api/openbridge-api.php");
    expect(JSON.parse((request as RequestInit).body as string)).toMatchObject({
      action: "connection_delete",
      id: "11111111-1111-4111-8111-111111111111",
      revision: 4,
    });
  });

  it("surfaces revision conflicts from the server", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => jsonResponse({
      error: "Connection changed. Reload before deleting.",
    }, 409)));

    await expect(deleteSavedConnection(
      "/openbridge/api/openbridge-api.php",
      "11111111-1111-4111-8111-111111111111",
      1,
    )).rejects.toThrow("Connection changed. Reload before deleting.");
  });

  it("surfaces list failures without returning unsafe fallback data", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => jsonResponse({
      error: "Saved connections are unavailable.",
    }, 503)));

    await expect(listSavedConnections("/openbridge/api/openbridge-api.php"))
      .rejects.toThrow("Saved connections are unavailable.");
  });

  it("rejects an invalid list response instead of treating it as empty", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => jsonResponse({
      connections: null,
    })));

    await expect(listSavedConnections("/openbridge/api/openbridge-api.php"))
      .rejects.toThrow("Saved connections returned an invalid response.");
  });
});
