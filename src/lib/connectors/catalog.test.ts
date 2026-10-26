import { describe, expect, it } from "vitest";
import { connectorCatalog, filterConnectors } from "./catalog";
import { normalizePrimaryConnectorId } from "./preferences";

describe("connector catalog", () => {
  it("uses unique IDs and only advertises FileMaker as available", () => {
    expect(new Set(connectorCatalog.map(({ id }) => id)).size).toBe(connectorCatalog.length);
    expect(connectorCatalog.filter(({ status }) => status === "available").map(({ id }) => id)).toEqual(["filemaker"]);
  });

  it("filters by a plain-English goal and search term", () => {
    expect(filterConnectors("invoice", "accounting").map(({ id }) => id)).toEqual(["quickbooks", "xero"]);
    expect(filterConnectors("orders", "commerce").map(({ id }) => id)).toEqual(["shopify", "woocommerce"]);
  });

  it("accepts one known secondary integration and rejects FileMaker or unknown IDs", () => {
    expect(normalizePrimaryConnectorId("stripe")).toBe("stripe");
    expect(normalizePrimaryConnectorId("filemaker")).toBe("");
    expect(normalizePrimaryConnectorId("missing")).toBe("");
    expect(normalizePrimaryConnectorId(["stripe"])).toBe("");
  });
});
