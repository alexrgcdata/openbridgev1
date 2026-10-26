# OpenBridge contributor instructions

Read README.md, OPENBRIDGE-AGENTIC.md and docs/architecture-storage.md before
changing application or storage boundaries.

- Keep FileMaker credentials, keys, runtime databases and private configuration
  out of Git, browser bundles, examples, test output and logs.
- Preserve exact FileMaker field names and values. OData uses tables and filters;
  Data API uses layouts and find arrays. Do not imply full transport parity.
- PHP owns authentication, authorization, reusable secrets and FileMaker calls.
  SQLite is internal application storage, not the customer's business database.
- Keep the shipped application usable on PHP hosting without Node.js at runtime.
- Preserve unrelated work and use the existing package manager and visual system.
- Safe browser tests are read-only. Do not execute live writes, scripts, pushes
  or deployments unless the user explicitly authorizes them.
- Validate relevant changes with pnpm validate:phase2 and focused browser checks.
  Report fixture evidence separately from live FileMaker or hosting evidence.
