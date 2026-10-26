# SQLite security contract

SQLite is OpenBridge's embedded system database. Security comes from the PHP
access boundary, authenticated encryption, password hashing and restricted
files. The ordinary SQLite file is **not whole-file encrypted**.

## What is protected

| Data | Storage treatment |
|---|---|
| Login password | One-way PHP password hash; never a reusable encrypted password |
| Browser login token | Random opaque PHP session ID in an HttpOnly, SameSite=Strict cookie; Secure on HTTPS |
| Session identifier in SQLite | SHA-256 hash of the opaque identifier |
| Session contents used by the API | Authenticated encrypted envelope bound to the hashed session ID |
| Connection credentials/private profile | Authenticated encrypted envelope bound to owner and secret key |
| New Activity details | Operation, resource, database, message and preview encrypted together, bound to transaction ID and owner |
| Optional retained request/response bodies | Redacted where recognized, then authenticated encrypted; 20 MB per body limit |
| Account names, connection labels, provider IDs | Plain metadata; do not put passwords or tokens in these labels |
| Timestamps, status, transport, duration, HTTP status | Plain operational metadata for filtering and retention |
| Preferences, workspaces, module source and metadata | Private through API access, but not encrypted columns; do not put credentials here |

The encryption implementation chooses XChaCha20-Poly1305 when PHP sodium is
available, otherwise AES-256-GCM through OpenSSL. Each encryption uses a fresh
nonce. Authentication detects altered ciphertext and envelopes moved to a
different owner or record. Reusable secrets stay in PHP; the connection list
returns only safe metadata. FileMaker setup can return non-password connection
defaults to its authenticated owner for editing.

The 32-byte installation key is separate from SQLite, supplied by trusted server
configuration/environment or a protected PHP key file. Existing encrypted data
is checked before new encryption: a missing or incorrect installation key must
fail rather than silently create a replacement. Keep the correct key recoverable;
losing it means losing access to encrypted data. Only key version 1 is currently
supported; key rotation is not yet an implemented workflow.

## Access and lifetime

The dashboard uses an authenticated session and CSRF checks. A shared bridge
API token does not authorize access to an administrator's vault, preferences,
workspaces or Activity. Repository reads, deletes, cleanup and legacy conversion
are scoped to the signed-in owner. Advanced Integrations shows saved-connection
metadata without exposing credentials. Removal requires an explicit user action
and deletes the local encrypted profile; it does not revoke vendor-side access.
Connection edits and removal use a revision check to
reject stale updates. Disabled users and revoked/expired sessions cannot regain
access through a later session write. Login throttling is stored in SQLite,
independent of browser cookies.

Activity bodies are opt-in and loaded from PHP only when opened. Activity is no
longer saved to browser localStorage; the old Activity key is cleared. Decrypted
data necessarily exists in browser memory while its owner views it. Logout
clears Activity and the open payload view. Connection credentials must not be
stored in browser settings, source code, examples or logs.

## Existing development data

Schema v3 is additive. It adopts existing FileMaker vault entries and adds
encrypted transaction-detail storage without rewriting legacy log contents on
startup. Old rows are reported with a `legacy` flag. The owner-authenticated
`transactions_secure_legacy` action performs explicit, bounded conversion to
encrypted detail/payload records; owner-scoped deletion is also available.
Old browser-encrypted bodies are not magically decryptable by the installation
key. The schema upgrade is not a claim that existing plaintext has been erased.

Deleting or converting rows does not promise forensic erasure from old SQLite
pages, WAL files or backups. Old plaintext sessions are rejected by the API's
encrypted session handler and require signing in again. A direct PHP caller
constructing the low-level session handler without Crypto retains its legacy
behavior; the OpenBridge API always provides Crypto.

## Limits and evidence

Encrypted columns protect a database copy without its key. They cannot protect
against an attacker who controls PHP, reads both the database and its key, or
controls an authenticated browser. Runtime database/key files must remain
inaccessible over HTTP, and the real site must use HTTPS. Those live-server
properties still need verification when the application is deployed.

Temporary SQLite fixtures cover migration, owner isolation, encrypted storage,
ciphertext tampering, missing-key behavior, revision conflicts and session
revocation. Local HTTP fixtures exercise the browser/API security boundary.
These checks are development evidence, not a security certification or a live
FileMaker test. Future adapters still need provider-specific OAuth, permission,
refresh and revocation implementations before they are usable.
