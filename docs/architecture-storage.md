# OpenBridge storage architecture

Decision: **SQLite is OpenBridge's system database.** Accepted September 7, 2026.
This is the development architecture, not a release-readiness claim.

## Why this is settled

OpenBridge is one PHP application installed within a customer's project. Its
system data belongs on that server's local disk and should not require another
database service. SQLite provides embedded storage, transactions, constraints,
indexes and versioned schema changes. PHP requires PDO and pdo_sqlite; the user
does not install a database daemon, configure database users or open a port.

PostgreSQL is a good choice for a centrally hosted, multi-server application
with many simultaneous writers. That is not the current OpenBridge architecture.
MySQL/MariaDB would also add a separate service. JSON files would require us to
reimplement coordinated updates, relationships and querying. We will not build
multiple database adapters for speculative future use.

SQLite permits concurrent readers but one writer at a time. Keep transactions
short, use the configured busy timeout and keep network calls outside database
transactions. WAL storage must be on local server disk, not a shared network
drive. Revisit the decision only if the product deliberately becomes a
multi-server service or measured write contention requires that change.

Reference: [SQLite's appropriate uses](https://www.sqlite.org/whentouse.html).

## Three independent responsibilities

1. **FileMaker engine:** restCWP and odataCWP perform FileMaker operations. Direct
   class use requires no OpenBridge SQLite database or dashboard login.
2. **System storage:** PHP opens the private SQLite file for OpenBridge accounts,
   sessions, preferences, secrets and saved work. It does not host or mirror the
   customer's FileMaker business database.
3. **Convenience entry point:** OpenBridge factories can reuse saved server-side
   defaults. Their SQL belongs in the repository; explicit per-call overrides
   remain supported. Browser JavaScript calls PHP and never opens SQLite.

`require_once` loads classes; it does not initialize the dashboard database.
Factory vault lookup skips nonexistent storage. Setting trusted PHP configuration
`filemaker.useVaultDefaults` to `false` skips SQLite entirely. A saved but broken
vault fails visibly; it must not silently fall back to different credentials.
With multiple saved connections, configure `filemaker.vaultUserId` server-side.
This is a trusted PHP choice, never a browser-supplied account selector.

## Source structure

| Source | Responsibility |
|---|---|
| restCWP/database.php | PDO creation, local path, pragmas, schema versions, migrations, sessions and database helpers |
| restCWP/database_schema.sql + restCWP/migrations/ | Base schema and ordered migrations, used together for fresh and existing databases |
| restCWP/connections.php | Owner-scoped connection metadata, encrypted private profiles and revision checks |
| restCWP/transactions.php | Encrypted Activity summaries, payload retention, owner access and explicit legacy conversion |
| restCWP/security.php | Key provider, encryption and repository for vault/payload/default-selection queries |
| restCWP/openbridge-api.php | Authenticated dashboard actions; existing non-vault controller SQL remains to be moved into repositories as those features are worked on |
| openbridge/openbridge.php | Public factory/include, delegates saved-default lookup to the repository |
| db/openbridge.sqlite | Private runtime data, ignored by Git |
| restCWP/private/openbridge.key.php | Protected development key fallback, separate from SQLite |

The eventual installed equivalent is `api/` for PHP sources and `db/` for
runtime storage, under the same application folder. Relative paths resolve
against the PHP storage layer, not the shell working directory. WAL may create
temporary `-wal` and `-shm` companion files alongside the database.

## Existing schema areas

| Area | Tables |
|---|---|
| Installation/versioning | openbridge_installation, openbridge_schema_migrations |
| Identity and sessions | openbridge_users, openbridge_sessions, openbridge_auth_attempts |
| Preferences and encrypted secrets | openbridge_settings, openbridge_secrets, openbridge_connections |
| Activity and diagnostics | openbridge_transactions, openbridge_transaction_details, openbridge_transaction_payloads, openbridge_metadata, openbridge_error_logs, openbridge_metric_events |
| Saved development work | openbridge_custom_modules, openbridge_custom_module_versions, openbridge_workspaces, openbridge_workspace_blocks |

Password hashes are one-way. Reusable credentials and retained encrypted
payloads use the server key outside SQLite. JSON/text payloads use TEXT; normal
relationships use foreign keys. Preserve FileMaker names and values exactly.
Schema v3 adds a shared connection registry: owner, provider, human label,
authentication type, environment, revision and a reference to an encrypted
private profile. Multiple provider instances can be stored without inventing
vendor-specific columns. FileMaker's existing vault entry is adopted without
re-encryption, preserving the independent library's saved-default contract.
Only reviewed PHP adapters may save/load private profiles. A registry row does
not implement OAuth, token refresh, vendor permissions or an API integration.

## Initialization and change rules

- Dashboard startup opens/initializes storage; direct engine imports do not.
- New database defaults do not seed demo users. Existing development config may
  opt in to demonstration accounts; that is separate from the storage default.
- Current schema is version 3. Migrations run under an atomic write transaction;
  current-schema checks avoid a write transaction on ordinary non-demo opens.
- Unknown newer schemas fail visibly. Do not silently reinterpret another
  configured driver as SQLite or downgrade an existing database.
- Existing data survives repeat initialization. Test changes using temporary
  databases before touching an installed database.
- Backups must be consistent SQLite snapshots, not an arbitrary copy of an
  active WAL file. Key recovery, hosted denial rules and release setup remain
  separate future work; this decision does not certify them.

## Evidence

Offline coverage lives in database_config_smoke.php, security_storage_smoke.php,
storage_boundary_smoke.php and engine_http_smoke.php under restCWP/tests.
It covers actual temporary SQLite databases and synthetic FileMaker HTTP
responses. Live FileMaker verification waits for a user-provided test account.

## Security and next development steps

See [security-storage.md](security-storage.md) for the exact encryption boundary,
legacy-data handling and verification limits. SQLite itself is not encrypted.

1. Storage foundation implemented and fixture-tested: shared connection
   repositories, encrypted Activity, owner isolation and session revocation.
   Advanced Integrations now includes saved-connection management above the
   planned-provider catalog: list, refresh, edit FileMaker setup and explicitly
   remove a saved connection. A stored record is not a successful connection test.
2. Continue FileMaker Connect → Test → Build against the independent libraries;
   perform live checks when a test account is available. Keep the rest of the
   dashboard stable.
3. Build connection management on the shared registry. Choose the first outside
   service deliberately, then verify its official authentication, permission,
   expiry/revocation and API contracts before implementing its adapter. No
   generic credential form should claim a service works merely because a secret
   can be saved.

This work creates source and verifies temporary databases. It does not create
archives, install a release, migrate an existing owner's private log contents,
or make live FileMaker requests.
