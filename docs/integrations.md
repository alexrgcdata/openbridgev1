# OpenBridge integrations

OpenBridge is an OData-first FileMaker integration router. FileMaker is the
foundation; a user may choose one outside service as the installation's primary
integration. This keeps setup understandable while the connector catalog grows.

## The simple route

```text
FileMaker OData -> OpenBridge PHP router -> selected outside service
                         |
                         +-> encrypted SQLite settings and safe activity history
```

The browser dashboard selects and explains a workflow. PHP performs the actual
work: it owns credentials, validates allowed actions, calls FileMaker and the
outside service, normalizes results, and records bounded activity. OpenBridge
must never become an unrestricted URL proxy.

## How a user sets up a connection

1. Open **Setup FileMaker** and configure FileMaker Server, database, OData
   table, and a dedicated account with the `fmodata` extended privilege.
2. Open **Optional Integration**, filter by the desired business goal, and select a
   service.
3. Review that service's required account, credentials, permissions, and
   planned operations.
4. Choose it as the installation's one primary integration. OpenBridge advances
   directly to the connection test. Users who do not need another service can
   skip this optional step.
5. Run **Test Connection** and prove a read-only FileMaker OData request works.
6. When the selected outside adapter becomes available, complete its server-side authorization,
   run a read-only test, map fields, and explicitly enable individual writes.

Choosing a planned integration does not activate its API or collect secrets.
It records product intent and keeps the expected setup visible.

## Modular authorization patterns

- **OAuth services** such as QuickBooks Online, Xero, Square, Shopify, HubSpot,
  Google Workspace, Microsoft 365, and Salesforce need a provider application,
  an exact HTTPS callback URL, minimal scopes, a server-side authorization-code
  exchange, and encrypted token storage and refresh.
- **Scoped-key services** such as Stripe, Authorize.Net, WooCommerce, and
  Airtable need restricted keys or tokens sent directly to PHP and encrypted
  before storage. Airtable uses personal access tokens or OAuth, not legacy API
  keys.
- **SFTP and generic APIs** need an allowlisted host, authentication details,
  explicit allowed operations, and strict protection against arbitrary URLs.
- **CSV, Excel, and JSON imports** do not need a service account. They need a
  preview, field mapping, validation, duplicate rules, and explicit approval
  before writing to FileMaker.

## Why this helps FileMaker designers

Designers get a guided path instead of implementing OAuth, pagination, token
refresh, field mapping, retries, logging, and error interpretation themselves.
They choose a business goal, see exactly what each service requires, connect a
dedicated FileMaker account, and test the route without writing middleware.

## Why this helps developers

Developers get one connector contract and one middleware boundary. Each adapter
can implement authorization, capability declarations, request validation,
normalization, and tests without changing the dashboard navigation. Generated
PHP or JavaScript talks to OpenBridge; provider secrets remain server-side.

Every catalog entry has a frontend normalization profile. Incoming provider
payloads are converted to one stable envelope containing the connector ID,
operation, timestamp, records, pagination hints, and warnings. Original vendor
field names and values remain intact inside each record's `fields` object. This
contract is exported by the OpenBridge TypeScript library as
`normalizeConnectorPayload`. It prepares fixtures and future adapters; it does
not make a Planned connector live or authorize outbound API calls.

## Current availability

FileMaker OData is the only active connector. Other catalog entries are marked
**Planned** or **Under review** until their server adapter, credential vault,
read-only test, error handling, and documentation are complete. The original
Data API classes remain packaged as legacy developer support but are hidden
from the current OData-focused dashboard.
