import { afterEach, describe, expect, it, vi } from "vitest";
import {
  appendTransaction,
  loadTransactions,
  saveTransactions,
} from "./transactions";
import {
  loadStoredTransactions,
  recordStoredTransaction,
} from "./filemaker";

function installMockStorage(initial: Record<string, string> = {}) {
  const values = new Map(Object.entries(initial));
  const localStorage = {
    get length() {
      return values.size;
    },
    key(index: number) {
      return Array.from(values.keys())[index] ?? null;
    },
    getItem(key: string) {
      return values.get(key) ?? null;
    },
    setItem(key: string, value: string) {
      values.set(key, value);
    },
    removeItem(key: string) {
      values.delete(key);
    },
    clear() {
      values.clear();
    },
  };

  vi.stubGlobal("window", {
    localStorage,
  });

  return { values };
}

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe("browser transaction persistence", () => {
  it("clears legacy localStorage activity instead of loading browser-owned payloads", () => {
    const { values } = installMockStorage({
      "openbridge-admin-transactions-v1": JSON.stringify([
        {
          id: "legacy",
          status: "success",
          operation: "Find records",
          transport: "odata",
          resource: "Customers",
          durationMs: 10,
          createdAt: "2026-09-07T12:00:00.000Z",
          message: "password=secret token=abc",
          encryptedResponse: { ciphertext: "browser-body" },
        },
      ]),
    });

    expect(loadTransactions()).toEqual([]);
    expect(values.has("openbridge-admin-transactions-v1")).toBe(false);
  });

  it("keeps saveTransactions as a cleanup no-op for browser activity", () => {
    const { values } = installMockStorage({
      "openbridge-admin-transactions-v1": "old-value",
    });

    saveTransactions([
      {
        id: "local",
        status: "success",
        operation: "Find records",
        transport: "odata",
        resource: "Customers",
        durationMs: 10,
        createdAt: "2026-09-07T12:00:00.000Z",
        message: "metadata only",
        responsePreview: "preview",
        payloadStored: true,
      },
    ]);

    expect(values.has("openbridge-admin-transactions-v1")).toBe(false);
  });

  it("redacts credential-like metadata while retaining server payload flags in memory", () => {
    const [transaction] = appendTransaction([], {
      status: "success",
      operation: "Find records",
      transport: "odata",
      resource: "Customers",
      durationMs: 12.8,
      message: "authorization=Bearer-token password=secret finished",
      responsePreview: "token=secret preview",
      payloadStored: true,
      legacyPlaintext: true,
    });

    expect(transaction.message).toBe("authorization=[redacted] password=[redacted] finished");
    expect(transaction.responsePreview).toBe("token=[redacted] preview");
    expect(transaction.payloadStored).toBe(true);
    expect(transaction.legacyPlaintext).toBe(true);
  });
});

describe("server transaction client mapping", () => {
  it("maps server-owned transaction summaries with snake-case payload flags", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => new Response(JSON.stringify({
      transactions: [
        {
          id: "server-1",
          status: "success",
          operation: "Find records",
          transport: "odata",
          resource: "Customers",
          duration_ms: 24,
          created_at: "2026-09-07T12:00:00.000Z",
          message: "Stored by PHP",
          response_preview: "2 records",
          payload_stored: 1,
          legacy: true,
        },
      ],
    }), { status: 200, headers: { "Content-Type": "application/json" } })));

    const [transaction] = await loadStoredTransactions("/openbridge/api/openbridge-api.php");

    expect(transaction).toMatchObject({
      id: "server-1",
      payloadStored: true,
      legacyPlaintext: true,
      responsePreview: "2 records",
      durationMs: 24,
    });
  });

  it("returns the server record result when storing a transaction", async () => {
    const fetchMock = vi.fn(async () => new Response(JSON.stringify({
      id: "server-2",
      recorded: true,
      payloadStored: true,
    }), { status: 200, headers: { "Content-Type": "application/json" } }));
    vi.stubGlobal("fetch", fetchMock);

    const result = await recordStoredTransaction("/openbridge/api/openbridge-api.php", {
      status: "success",
      operation: "Find records",
      transport: "odata",
      resource: "Customers",
      durationMs: 31,
      message: "Retain body",
      rawResponse: JSON.stringify({ data: [] }),
    });

    expect(result).toEqual({ id: "server-2", recorded: true, payloadStored: true });
    const [, request] = fetchMock.mock.calls[0] ?? [];
    expect(JSON.parse((request as RequestInit).body as string)).toMatchObject({
      action: "transaction_record",
      rawResponse: "{\"data\":[]}",
    });
  });
});
