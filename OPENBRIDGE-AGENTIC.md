# OpenBridge agentic reference

Purpose: free self-hosted FileMaker middleware + admin UI. Deployment contract: upload the generated `openbridge/` folder intact; admin entry is `index.html`; authenticated API is `api/openbridge-api.php`; customer PHP include is `openbridge.php`; browser ESM entry is `openbridge.js`. Never put passwords, reusable tokens, DB credentials, real customer data, or unrestricted URLs in React, module JSON, tests, logs, prompts, or examples.

## Public PHP

`OpenBridge::dataApi(array $overrides=[]): restCWP` and `OpenBridge::odata(array $overrides=[]): odataCWP` load private `api/openbridge.config.php` (source checkout fallback: `restCWP/openbridge.config.php`). `fromDefaults()` remains the legacy raw-response Data API factory. New factories return the shared normalized envelope. Allowed application-level overrides are connection options such as `database`, `layout`, `table`, and `options`; do not forward untrusted request objects as overrides.

Both public classes expose familiar data calls: `healthCheck()`, `getRecords()`, `findRecords()`, `getRecord()`, `createRecord()`, `editRecord()`, `deleteRecord()`, `runScript()`, and `getResponse()`. Data API `restCWP` uses layouts and FileMaker find arrays. OData `odataCWP` uses tables/entity sets and `$filter` strings. The OData class is a narrow composition façade over the reviewed OData engine in `restCWP`; it does not expose layout-only methods. Preserve exact FileMaker field names and values.

Normalized PHP result keys are `ok`, `transport`, `operation`, `database`, `resource`, `records`, `returnedCount`, `totalCount`, `nextLink`, `messages`, `meta`, and `raw`. Data API records retain `fieldData`, `portalData`, `recordId`, and `modId`; OData entity properties remain unchanged. Legacy direct `new restCWP(...)` callers retain raw output unless they pass `responseFormat => restCWP::RESPONSE_NORMALIZED`.

## Public JavaScript/TypeScript

Entry `src/openbridge.ts` / packaged `openbridge.js` exports normalization, crypto envelopes, bounded browser diagnostics, settings helpers, server health/testing clients, module registry/generator, workspace types, safety preflight, and storage clients. Browser code never receives FileMaker credentials or direct SQLite access. Server calls use same-origin authenticated PHP with `credentials:'include'`; external non-browser clients may use an explicitly configured `X-OpenBridge-Token`. Public normalized response contract: `transport`, `operation`, `database`, `resource`, `records`, `returnedCount`, `totalCount`, `nextLink`, `messages`, `raw`.

## Modules/playground

Registry source: `src/data/playground-modules/*.json`; schema: `src/data/playground-module.schema.json`; types: `src/lib/playground/types.ts`; validation/index: `registry.ts`; deterministic code: `generator.ts`; ordered block operations: `workspace.ts`; read-only policy: `safety.ts`; SQLite/fallback client: `storage.ts`; custom draft validation/export: `moduleStudio.ts`. Module identity is stable kebab-case `id` + positive `version`. Required dimensions are `language` (`php|javascript`) and transport (`data-api|odata`); every pair is explicitly supported or unsupported. Safety authority is server policy, never custom metadata. Only list/find/get can be `read-only-live`; writes/scripts/globals remain code-only unless a future explicit server allowlist and disposable environment prove otherwise.

## Server/API/database

`restCWP/openbridge-api.php` is a POST-only allowlisted JSON controller. Admin auth: one-way password hashes + hardened PHP session/HttpOnly cookie + per-session CSRF; sessions persist in packaged SQLite. `restCWP/database.php` is the sole PDO creation/session utility boundary. `restCWP/security.php` provides `OpenBridgeKeyProvider`, authenticated `OpenBridgeCrypto`, and `OpenBridgeRepository` for vault/payload persistence. `restCWP/OpenBridgeResponseNormalizer.php` owns the shared PHP response envelope; `restCWP/odataCWP.php` is the separate public OData library. Schema tables: installation/migrations, users, sessions, settings, encrypted-secret envelopes, metadata, transactions + payloads, errors, metrics, custom modules + versions, workspaces + ordered blocks. Workspace API: `workspace_list|workspace_load|workspace_save|workspace_duplicate|workspace_archive`; ownership is `user_id`, payload/block counts are bounded, saves use revision checks, credentials are not workspace fields. SQLite is OpenBridge-internal, not a public customer application database.

SQLite diagnostics: authenticated action `database_diagnostics`; implementation `openBridgeDatabaseDiagnostics()` in `restCWP/database.php`. It returns bounded integrity/schema/journal/writeability/count evidence, never the database path or row contents. Its write test runs inside a transaction and always rolls back. Optional Go seam: `go_status|go_request`; PHP owns service credentials, token caching, and route allowlisting. No Go process is bundled or required by the FTP package.

## Configuration/security invariants

Private config: `api/openbridge.config.php`, copied from example and ignored. SQLite path override: `OPENBRIDGE_DB_PATH`; default packaged path is `db/openbridge.sqlite`. Installation-key override: `OPENBRIDGE_MASTER_KEY` (Base64 for exactly 32 random bytes); protected shared-host fallback: `api/private/openbridge.key.php`. PHP prefers XChaCha20-Poly1305/Sodium and falls back to AES-256-GCM/OpenSSL. Never put the key in SQLite or browser storage. Back it up with the database. Production still requires HTTPS, exact CORS origins, restrictive permissions, key rotation/recovery, and setup verification. Go remains optional and is not part of the v1 FTP runtime.

## Validation/release

`pnpm validate:phase2` runs frontend lint/typechecks/tests/build, PHP syntax/smokes, and diff check. `pnpm package:openbridge` builds admin + ESM library and creates ignored `release/openbridge/`, including protected `/db`. Offline fixtures are not live FileMaker evidence. Never push/publish or run live writes without explicit authorization.
