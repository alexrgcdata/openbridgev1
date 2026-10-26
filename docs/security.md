# Application security

PHP holds FileMaker credentials. Browser clients call your authenticated app
endpoint; they must not contain passwords, bridge tokens or installation keys.
The dashboard's administrator session does not authenticate your application's users.

Allowlist tables, fields and operations server-side. Escape OData literal values
and separately validate field names; never accept arbitrary upstream URLs or
connection override objects from a browser. Protect application writes against
CSRF and enforce account permissions before calling the PHP client.

Use HTTPS. Verify config/key/database files are blocked on the deployed web
server. Keep reusable credentials encrypted and out of logs. The installation
key is separate from SQLite and must be backed up with the matching database.

See [the storage security contract](security-storage.md), [SECURITY.md](../SECURITY.md)
and [installation](installation.md) for current implementation boundaries.
