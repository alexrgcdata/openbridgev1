# Packaged SQLite storage

SQLite is the settled system database. See [the storage architecture](architecture-storage.md)
for the source layout, table responsibilities and independent FileMaker library use.
The distribution description below is the eventual installation model; packaging
and release verification are deferred while the core is developed.

OpenBridge uses one embedded database file:

```text
/openbridge/db/openbridge.sqlite
```

The file is created automatically the first time the PHP API starts. Customers
do not install SQLite, PostgreSQL, or another database server. PHP only needs
the commonly available `PDO` and `pdo_sqlite` extensions and write permission
for `/openbridge/db`.

## What it stores

- OpenBridge users and password hashes
- server-side sessions
- non-secret settings
- transaction summaries and optional long request/response payloads
- metadata, errors, and privacy-minimal metrics
- custom code modules and saved Coding Playground workspaces
- authenticated-encrypted FileMaker credential envelopes

SQLite `TEXT` can hold large transaction content. OpenBridge applies a 20 MB
limit to each retained request/response to protect shared-hosting resources.

## Protection

The package includes `/db/.htaccess` for Apache and `/db/web.config` for IIS;
both deny browser access to the directory. Never remove those files. PHP should
own/write the directory while public web users cannot download it.

Reusable credentials are encrypted before SQLite storage. The 32-byte
installation key stays outside the database: preferably in the
`OPENBRIDGE_MASTER_KEY` hosting environment variable, or in the protected
`/api/private/openbridge.key.php` shared-hosting fallback. Back up the key and
database together; neither is useful for recovery without the other.

## Backup

Back up the SQLite database through a controlled backup/export flow while writes
are paused or through SQLite's backup mechanism. Do not expose the live database
at a public URL. A future setup screen will provide verified backup and restore.

## Built-in diagnostics

The admin-only Design Lab includes quick and full SQLite checks. The full test
verifies database integrity, foreign keys, WAL mode, schema version, required
tables, directory write access, database size, and safe table counts. It also
performs one real metadata write inside a transaction and rolls it back, proving
write access without retaining a test row. The separate metric button
intentionally records one privacy-minimal test event.

## Troubleshooting

- `SQLite storage could not start`: enable PHP `pdo_sqlite` and make `/db` writable.
- Database downloads in a browser: stop deployment and repair the server deny rule.
- `database is locked`: confirm the host permits SQLite WAL files in `/db` and
  that the directory is writable; OpenBridge already applies a five-second busy timeout.
