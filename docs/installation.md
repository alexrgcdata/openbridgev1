# Installation

## Source checkout

Install the versions listed in the README. Copy
`restCWP/openbridge.config.example.php` to `restCWP/openbridge.config.php` and set
`api.enabled` and `database.enabled` to true in the private copy. Demo seeding
and Go integration should remain disabled. Run `pnpm dev`; PHP starts on port
8090 and the dashboard opens at the Vite URL printed in the terminal.

The default allowed browser origins are localhost/127.0.0.1 on port 5173. If
Vite uses another origin, add that exact value to the private configuration.
`OPENBRIDGE_PHP_BIN` selects a PHP executable when PHP is not on PATH.

## Prepare the hosted folder

Run `pnpm package:openbridge` from the source root. The generated
`release/openbridge/` folder contains:

```text
openbridge/
  index.html
  openbridge.php
  openbridge.js
  restCWP.php
  odataCWP.php
  VERSION.txt
  OPENBRIDGE-AGENTIC.md
  api/          # PHP engine, controllers and private configuration
  assets/       # Compiled React assets
  brand/        # UI branding
  demo-data/    # Synthetic sample files
  db/           # Fresh protected application database and deny rules
  docs/         # Public guides
```

Upload the complete generated folder to the chosen location on a PHP host.
Do not upload the source checkout's private config, database or encryption key.
The package generator uses the example configuration and a fresh database.
It replaces its generated output directory on each run and does not deploy.

## Host requirements

- PHP 8.2+ with cURL, PDO SQLite, and sodium or OpenSSL.
- HTTPS for production.
- Write permission for the protected `db/` and `api/private/` directories.
- Apache/IIS denial rules or equivalent nginx configuration that blocks direct
  downloads of databases, private keys and configuration. Disable directory listing.

Open the uploaded dashboard, create an owner account, and configure FileMaker.
The packaged API is at `api/openbridge-api.php` relative to the installed folder.
Check the Setup endpoint when changing folder names or nesting the installation.
Add the exact deployed browser origin to the private API configuration if needed.

FileMaker must host the selected database and allow the integration account to use
OData (`fmodata`) or Data API (`fmrest`). OData addresses entity sets/tables; Data
API addresses layouts. Saving dashboard settings does not enable those features.

## Before using real data

Verify file protection and connection health on the actual host. Keep the
installation key with the matching SQLite backup. Guided restore/key rotation
and administrator recovery are not finished workflows. GitHub Pages cannot run
the PHP/SQLite backend; this repository does not deploy to it automatically.
