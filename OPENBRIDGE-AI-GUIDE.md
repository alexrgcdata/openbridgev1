# OpenBridge integration guide for coding agents

Begin with OPENBRIDGE-AGENTIC.md, the README and the current PHP factories. Use
the public methods implemented in this checkout.

Browser JavaScript/TypeScript calls an authenticated application PHP endpoint.
PHP uses OpenBridge::odata() or OpenBridge::dataApi() and returns only fields the
application user may access. Never turn the administrator API into an anonymous
general-purpose proxy or forward arbitrary browser input as connection overrides.

Keep reusable credentials server-side. Use synthetic examples and mock FileMaker
tests. Preserve field names and distinguish OData table/filter behavior from Data
API layout/find-array behavior. SQLite is OpenBridge's internal system database.

Do not send private notes, keys, passwords, database files or live customer data
to tools or public repositories. Do not run live writes, scripts or deployment
without explicit user authorization. Run the relevant local checks and report
which claims still require live verification.
