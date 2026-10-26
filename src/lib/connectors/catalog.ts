import type { ConnectorDefinition, ConnectorGoal } from "./types";

export const connectorGoals: Array<{ id: ConnectorGoal; label: string }> = [
  { id: "all", label: "All connections" },
  { id: "filemaker", label: "FileMaker" },
  { id: "accounting", label: "Accounting" },
  { id: "payments", label: "Payments" },
  { id: "commerce", label: "Online stores" },
  { id: "crm", label: "CRM" },
  { id: "productivity", label: "Work tools" },
  { id: "files", label: "Files & data" },
  { id: "developer", label: "Custom APIs" },
];

export const connectorCatalog: ConnectorDefinition[] = [
  {
    id: "filemaker",
    name: "Claris FileMaker",
    shortName: "FileMaker",
    initials: "FM",
    goal: "filemaker",
    status: "available",
    summary: "Connect through FileMaker OData, discover tables, test safely, and build requests.",
    auth: { type: "filemaker-credentials", label: "FileMaker account", requirements: ["FileMaker Server address", "Database name", "Account name and password"] },
    capabilities: ["OData metadata discovery", "OData requests", "Read-only connection testing", "PHP and JavaScript code builder"],
  },
  {
    id: "quickbooks", name: "QuickBooks Online", shortName: "QuickBooks", initials: "QB", goal: "accounting", status: "planned",
    summary: "Move customer, invoice, payment, and account data between FileMaker and QuickBooks.",
    auth: { type: "oauth2", label: "Sign in with Intuit", requirements: ["Intuit developer app", "Company authorization", "OAuth callback URL"] },
    capabilities: ["Customers and vendors", "Invoices and payments", "Accounts and reports"],
  },
  {
    id: "xero", name: "Xero", shortName: "Xero", initials: "XE", goal: "accounting", status: "planned",
    summary: "Connect FileMaker records to Xero contacts, invoices, and accounting data.",
    auth: { type: "oauth2", label: "Sign in with Xero", requirements: ["Xero developer app", "Organization authorization", "OAuth callback URL"] },
    capabilities: ["Contacts", "Invoices", "Payments and accounts"],
  },
  {
    id: "stripe", name: "Stripe", shortName: "Stripe", initials: "ST", goal: "payments", status: "planned",
    summary: "Create payment workflows and bring safe payment status data back into FileMaker.",
    auth: { type: "api-key", label: "Restricted API key", requirements: ["Restricted Stripe key", "Webhook signing secret for events"] },
    capabilities: ["Customers", "Payments and refunds", "Invoices", "Verified webhooks"],
  },
  {
    id: "authorize-net", name: "Authorize.Net", shortName: "Authorize.Net", initials: "AN", goal: "payments", status: "planned",
    summary: "Use familiar Authorize.Net transaction and customer workflows from FileMaker.",
    auth: { type: "api-key", label: "API credentials", requirements: ["API Login ID", "Transaction key", "Signature key for webhooks"] },
    capabilities: ["Transactions", "Customer profiles", "Refund status", "Webhooks"],
  },
  {
    id: "square", name: "Square", shortName: "Square", initials: "SQ", goal: "payments", status: "planned",
    summary: "Connect customers, payments, orders, and catalog information to FileMaker.",
    auth: { type: "oauth2", label: "Connect a Square account", requirements: ["Square developer app", "Merchant authorization", "OAuth callback URL"] },
    capabilities: ["Customers", "Payments", "Orders", "Catalog"],
  },
  {
    id: "shopify", name: "Shopify", shortName: "Shopify", initials: "SH", goal: "commerce", status: "planned",
    summary: "Bring customers, products, and orders into FileMaker and build controlled store workflows.",
    auth: { type: "oauth2", label: "Connect a Shopify store", requirements: ["Shopify app", "Store authorization", "Approved access scopes"] },
    capabilities: ["Products", "Customers", "Orders", "Fulfillment status"],
  },
  {
    id: "woocommerce", name: "WooCommerce", shortName: "WooCommerce", initials: "WC", goal: "commerce", status: "planned",
    summary: "Connect a WordPress store to FileMaker through the WooCommerce REST API.",
    auth: { type: "api-key", label: "WooCommerce API keys", requirements: ["Store URL", "Consumer key", "Consumer secret"] },
    capabilities: ["Products", "Customers", "Orders", "Coupons"],
  },
  {
    id: "hubspot", name: "HubSpot", shortName: "HubSpot", initials: "HS", goal: "crm", status: "planned",
    summary: "Connect FileMaker contacts and companies with HubSpot CRM activity.",
    auth: { type: "oauth2", label: "Connect HubSpot", requirements: ["HubSpot developer app", "Portal authorization", "Approved access scopes"] },
    capabilities: ["Contacts", "Companies", "Deals", "Activities"],
  },
  {
    id: "salesforce", name: "Salesforce", shortName: "Salesforce", initials: "SF", goal: "crm", status: "research",
    summary: "A future enterprise adapter for selected Salesforce objects and FileMaker workflows.",
    auth: { type: "oauth2", label: "Salesforce connected app", requirements: ["Connected app", "Organization authorization", "Object and field permissions"] },
    capabilities: ["Selected objects", "Queries", "Controlled record updates"],
  },
  {
    id: "google-workspace", name: "Google Workspace", shortName: "Google", initials: "GW", goal: "productivity", status: "planned",
    summary: "Connect selected Google Sheets, Drive, Calendar, and Gmail workflows to FileMaker.",
    auth: { type: "oauth2", label: "Sign in with Google", requirements: ["Google Cloud project", "OAuth consent", "Narrow service scopes"] },
    capabilities: ["Sheets", "Drive files", "Calendar", "Email workflows"],
  },
  {
    id: "microsoft-365", name: "Microsoft 365", shortName: "Microsoft 365", initials: "MS", goal: "productivity", status: "research",
    summary: "A future Microsoft Graph adapter for carefully selected work and document flows.",
    auth: { type: "oauth2", label: "Sign in with Microsoft", requirements: ["Microsoft Entra app", "Tenant authorization", "Narrow Graph scopes"] },
    capabilities: ["Excel and OneDrive", "Outlook", "Calendar", "Teams workflows"],
  },
  {
    id: "airtable", name: "Airtable", shortName: "Airtable", initials: "AT", goal: "files", status: "planned",
    summary: "Map Airtable bases, tables, and records to FileMaker-friendly data.",
    auth: { type: "api-key", label: "Personal access token", requirements: ["Scoped Airtable token", "Base permission", "Table selection"] },
    capabilities: ["Bases and tables", "Records", "Field mapping"],
  },
  {
    id: "file-import", name: "CSV, Excel & JSON", shortName: "File import", initials: "FI", goal: "files", status: "planned",
    summary: "Preview, map, validate, and transfer common files without writing integration code.",
    auth: { type: "file-import", label: "No service account", requirements: ["A local CSV, Excel, or JSON file", "A destination field map"] },
    capabilities: ["Preview", "Field mapping", "Validation", "Reusable import recipes"],
  },
  {
    id: "sftp", name: "SFTP", shortName: "SFTP", initials: "SF", goal: "files", status: "planned",
    summary: "Move scheduled files through a server-controlled SFTP connection.",
    auth: { type: "connection-details", label: "SFTP credentials", requirements: ["Host and port", "Account name", "Password or private key"] },
    capabilities: ["Upload and download", "Folder watch", "Transfer history"],
  },
  {
    id: "rest-api", name: "Generic REST API", shortName: "Custom API", initials: "API", goal: "developer", status: "planned",
    summary: "Describe a REST endpoint once, then reuse safe requests and field mappings.",
    auth: { type: "connection-details", label: "API authentication", requirements: ["Base URL", "Authentication method", "Allowed endpoints"] },
    capabilities: ["GET requests", "Headers and parameters", "Response mapping", "Allowlisted write requests"],
  },
];

export function filterConnectors(query: string, goal: ConnectorGoal): ConnectorDefinition[] {
  const normalized = query.trim().toLowerCase();
  return connectorCatalog.filter((connector) => {
    if (goal !== "all" && connector.goal !== goal) return false;
    if (!normalized) return true;
    return [connector.name, connector.shortName, connector.summary, connector.goal, ...connector.capabilities]
      .some((value) => value.toLowerCase().includes(normalized));
  });
}
