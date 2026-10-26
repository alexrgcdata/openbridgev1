-- Additive system-storage migration. No network requests or credential decryption.
CREATE TABLE IF NOT EXISTS openbridge_connections (
    id TEXT PRIMARY KEY CHECK(length(id) = 36),
    user_id INTEGER NOT NULL REFERENCES openbridge_users(id) ON DELETE CASCADE,
    provider_id TEXT NOT NULL,
    display_name TEXT NOT NULL,
    environment TEXT NOT NULL DEFAULT 'production' CHECK(environment IN ('test','production')),
    auth_type TEXT NOT NULL CHECK(auth_type IN ('filemaker-credentials','api-key','oauth2','basic','none')),
    secret_key TEXT NOT NULL,
    revision INTEGER NOT NULL DEFAULT 1 CHECK(revision > 0),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id, secret_key),
    FOREIGN KEY(user_id, secret_key) REFERENCES openbridge_secrets(user_id, secret_key) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS openbridge_connections_owner_idx ON openbridge_connections(user_id, provider_id);

-- Adopt existing FileMaker vault entries without decrypting/re-encrypting them.
INSERT OR IGNORE INTO openbridge_connections (id, user_id, provider_id, display_name, auth_type, secret_key)
SELECT lower(hex(randomblob(4))) || '-' || lower(hex(randomblob(2))) || '-4' || substr(lower(hex(randomblob(2))),2) || '-8' || substr(lower(hex(randomblob(2))),2) || '-' || lower(hex(randomblob(6))),
       user_id, 'filemaker', 'FileMaker', 'filemaker-credentials', secret_key
FROM openbridge_secrets WHERE user_id IS NOT NULL AND secret_key = 'filemaker_connection';

CREATE UNIQUE INDEX IF NOT EXISTS openbridge_transactions_owner_identity_idx ON openbridge_transactions(id, user_id);
CREATE TABLE IF NOT EXISTS openbridge_transaction_details (
    transaction_id TEXT PRIMARY KEY REFERENCES openbridge_transactions(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES openbridge_users(id) ON DELETE CASCADE,
    ciphertext TEXT NOT NULL,
    nonce TEXT NOT NULL,
    algorithm TEXT NOT NULL,
    key_version INTEGER NOT NULL,
    FOREIGN KEY(transaction_id, user_id) REFERENCES openbridge_transactions(id, user_id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS openbridge_transaction_details_owner_idx ON openbridge_transaction_details(user_id);

-- Hashed rate-limit identifiers; independent of attacker-controlled cookies.
CREATE TABLE IF NOT EXISTS openbridge_auth_attempts (
    identifier_hash TEXT PRIMARY KEY CHECK(length(identifier_hash) = 64),
    attempts INTEGER NOT NULL CHECK(attempts >= 0),
    window_ends_at INTEGER NOT NULL
);
INSERT OR IGNORE INTO openbridge_schema_migrations(version) VALUES (3);
