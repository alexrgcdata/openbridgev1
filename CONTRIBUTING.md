# Contributing

Start with the README setup instructions and the in-app Docs & Help. Keep changes
focused, describe the intended behavior and validation in your pull request, and
preserve the existing GPLv3 license.

Do not commit credentials, private configuration, real customer records, keys,
runtime SQLite databases, logs, dependencies or generated distributions. Fixtures
must use synthetic data and local mock servers.

## Project layout

- `src/`: React interface, browser helpers, module definitions and unit tests.
- `restCWP/`: PHP API, FileMaker transports, SQLite storage and test fixtures.
- `openbridge/`: stable public PHP include and factory wrappers.
- `scripts/`: validation and distribution assembly.
- `docs/`: public usage, security and architecture guides.

Use `pnpm validate:phase2` for the complete local suite. PHP needs cURL, PDO
SQLite, and sodium or OpenSSL. If nested beneath an unrelated pnpm workspace,
use `pnpm --ignore-workspace install --frozen-lockfile` and
`pnpm --ignore-workspace run validate:phase2`.

Never use production credentials in tests. A passing fixture test is not proof
that an operation works on a specific FileMaker Server. Label that boundary.
Check keyboard navigation and narrow layouts for interface changes. Reuse the
tab, modal and document patterns already in the app.
