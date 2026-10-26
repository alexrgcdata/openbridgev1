import { describe, expect, it } from "vitest";
import { connectorCatalog } from "./catalog";
import { connectorNormalizationProfiles, normalizeConnectorPayload } from "./normalization";

describe("connector response normalization", () => {
  it("defines a normalization profile for every catalog connector", () => {
    expect(Object.keys(connectorNormalizationProfiles).sort()).toEqual(connectorCatalog.map(({ id }) => id).sort());
  });

  it("normalizes Stripe data without changing vendor fields", () => {
    const result = normalizeConnectorPayload("stripe", { data: [{ id: "cus_1", email: "person@example.com", metadata: { fm: "42" } }], has_more: true }, { operation: "list customers", receivedAt: "2026-09-07T00:00:00.000Z" });
    expect(result.page).toEqual({ count: 1, hasMore: true, cursor: null });
    expect(result.records[0]).toMatchObject({ externalId: "cus_1", fields: { email: "person@example.com", metadata: { fm: "42" } } });
  });

  it("normalizes Microsoft and HubSpot collection shapes", () => {
    expect(normalizeConnectorPayload("microsoft-365", { value: [{ id: "ms-1", displayName: "Alex" }] }).records[0].externalId).toBe("ms-1");
    expect(normalizeConnectorPayload("hubspot", { results: [{ id: "hs-1", properties: { company: "Acme" } }] }).records[0].fields).toMatchObject({ properties: { company: "Acme" } });
  });

  it("finds nested QuickBooks entities and unwraps Shopify GraphQL edges", () => {
    expect(normalizeConnectorPayload("quickbooks", { QueryResponse: { Customer: [{ Id: "17", DisplayName: "Acme" }] } }).records[0].externalId).toBe("17");
    expect(normalizeConnectorPayload("shopify", { data: { customers: { edges: [{ node: { id: "gid://customer/1", email: "person@example.com" } }] } } }).records[0].fields).toMatchObject({ email: "person@example.com" });
  });

  it("rejects unknown connectors instead of silently guessing", () => {
    expect(() => normalizeConnectorPayload("missing", [])).toThrow("Unknown connector");
  });
});
