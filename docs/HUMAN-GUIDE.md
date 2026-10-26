# OpenBridge user guide

Use [Getting started](quick-start.md) for the first connection and
[Installation](installation.md) for local development or PHP hosting.

## Dashboard areas

- **Setup FileMaker:** Connection, Preferences and Security tabs. Enter your
  FileMaker account; PHP handles reusable credential encryption. Tokens are
  optional for external server clients, not a replacement for FileMaker login.
- **Test Connection:** inspect connection and resource-access diagnostics.
- **Coding Playground:** compose PHP/JS examples using OData building blocks.
  Write/script examples are code-only. Save/load and module editing UI remain incomplete.
- **JSON Tools:** local formatting and inspection. Response-inspect actions open
  in dismissible modals without replacing the current page.
- **Metrics & Activity:** server-scoped summaries and optional retained payloads.
- **Docs & Help:** tabbed installation, PHP, JS/TS, reference and security guides.
- **Advanced Integrations:** FileMaker connections and a catalog of future
  adapters. A planned connector is not a working external integration.

## Build your own application

Follow [PHP and browser integration](using-openbridge-libraries.md). PHP calls
FileMaker; JavaScript/TypeScript calls your own authenticated PHP route. Your app's
users do not inherit the OpenBridge administrator session.

See [the method reference](api-reference.md) and [security](security-storage.md).
OpenBridge SQLite stores application/system data, not your FileMaker records.

## Current limits

Backup/restore UI, key rotation, account recovery, complete Playground persistence,
and outside-service adapters are unfinished. Live FileMaker and host permissions
need verification per installation. The repository includes no production secrets.
