-- OpenBridge SQLite schema. The embedded file is created automatically.
CREATE TABLE IF NOT EXISTS openbridge_schema_migrations (version INTEGER PRIMARY KEY, applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
INSERT OR IGNORE INTO openbridge_schema_migrations (version) VALUES (1);
INSERT OR IGNORE INTO openbridge_schema_migrations (version) VALUES (2);

CREATE TABLE IF NOT EXISTS openbridge_installation (
    id INTEGER PRIMARY KEY CHECK (id = 1), installation_id TEXT NOT NULL UNIQUE,
    encryption_key_version INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS openbridge_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL UNIQUE COLLATE NOCASE,
    email TEXT UNIQUE COLLATE NOCASE, password_hash TEXT NOT NULL, display_name TEXT,
    role TEXT NOT NULL DEFAULT 'admin', is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0,1)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, last_login_at TEXT, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS openbridge_sessions (
    session_hash TEXT PRIMARY KEY CHECK (length(session_hash)=64), user_id INTEGER REFERENCES openbridge_users(id) ON DELETE CASCADE,
    session_data TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, expires_at INTEGER NOT NULL,
    ip_address TEXT, user_agent TEXT, data TEXT NOT NULL DEFAULT '{}', revoked_at TEXT
);
CREATE INDEX IF NOT EXISTS openbridge_sessions_active_idx ON openbridge_sessions (user_id, expires_at);

CREATE TABLE IF NOT EXISTS openbridge_metric_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT, installation_id TEXT, user_id INTEGER REFERENCES openbridge_users(id) ON DELETE SET NULL,
    event_name TEXT NOT NULL, event_value INTEGER, context TEXT NOT NULL DEFAULT '{}', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS openbridge_metric_events_recent_idx ON openbridge_metric_events (event_name, created_at DESC);

CREATE TABLE IF NOT EXISTS openbridge_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER REFERENCES openbridge_users(id) ON DELETE CASCADE,
    setting_key TEXT NOT NULL, setting_value TEXT NOT NULL DEFAULT '{}', is_secret INTEGER NOT NULL DEFAULT 0 CHECK (is_secret IN (0,1)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, setting_key)
);

-- Reserved encrypted vault. The application encryption service supplies ciphertext/nonce;
-- reusable credentials must never be stored in plaintext in this table.
CREATE TABLE IF NOT EXISTS openbridge_secrets (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER REFERENCES openbridge_users(id) ON DELETE CASCADE,
    secret_key TEXT NOT NULL, ciphertext TEXT NOT NULL, nonce TEXT NOT NULL, algorithm TEXT NOT NULL,
    key_version INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE (user_id, secret_key)
);

CREATE TABLE IF NOT EXISTS openbridge_metadata (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER REFERENCES openbridge_users(id) ON DELETE SET NULL,
    meta_key TEXT NOT NULL, meta_value TEXT NOT NULL DEFAULT '{}', source TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, meta_key)
);

CREATE TABLE IF NOT EXISTS openbridge_transactions (
    id TEXT PRIMARY KEY, user_id INTEGER REFERENCES openbridge_users(id) ON DELETE SET NULL,
    status TEXT NOT NULL CHECK (status IN ('success','failure','pending')),
    transport TEXT NOT NULL CHECK (transport IN ('data-api','odata','go','internal')),
    operation TEXT NOT NULL, resource TEXT, database_name TEXT, duration_ms INTEGER CHECK (duration_ms IS NULL OR duration_ms >= 0),
    http_status INTEGER, message TEXT, response_preview TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, completed_at TEXT
);
CREATE INDEX IF NOT EXISTS openbridge_transactions_recent_idx ON openbridge_transactions (created_at DESC);
CREATE INDEX IF NOT EXISTS openbridge_transactions_filter_idx ON openbridge_transactions (user_id, transport, status, operation, created_at DESC);

CREATE TABLE IF NOT EXISTS openbridge_transaction_payloads (
    transaction_id TEXT PRIMARY KEY REFERENCES openbridge_transactions(id) ON DELETE CASCADE,
    raw_request TEXT, raw_response TEXT, request_json TEXT, response_json TEXT,
    is_encrypted INTEGER NOT NULL DEFAULT 0 CHECK (is_encrypted IN (0,1)), encryption_version TEXT,
    ciphertext TEXT, nonce TEXT, algorithm TEXT, key_version INTEGER, aad TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS openbridge_error_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER REFERENCES openbridge_users(id) ON DELETE SET NULL,
    transaction_id TEXT REFERENCES openbridge_transactions(id) ON DELETE SET NULL,
    severity TEXT NOT NULL DEFAULT 'error' CHECK (severity IN ('debug','info','warning','error','critical')),
    source TEXT NOT NULL, error_code TEXT, message TEXT NOT NULL, context TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS openbridge_error_logs_recent_idx ON openbridge_error_logs (created_at DESC, severity);

CREATE TABLE IF NOT EXISTS openbridge_custom_modules (
    id TEXT PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES openbridge_users(id) ON DELETE CASCADE,
    module_key TEXT NOT NULL, name TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','active','archived')),
    current_version INTEGER NOT NULL DEFAULT 1 CHECK (current_version > 0), created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE (user_id, module_key)
);
CREATE TABLE IF NOT EXISTS openbridge_custom_module_versions (
    module_id TEXT NOT NULL REFERENCES openbridge_custom_modules(id) ON DELETE CASCADE,
    version INTEGER NOT NULL CHECK (version > 0), definition TEXT NOT NULL,
    created_by INTEGER REFERENCES openbridge_users(id) ON DELETE SET NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (module_id, version)
);

CREATE TABLE IF NOT EXISTS openbridge_workspaces (
    id TEXT PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES openbridge_users(id) ON DELETE CASCADE,
    name TEXT NOT NULL, language TEXT NOT NULL CHECK (language IN ('php','javascript')),
    transport TEXT NOT NULL CHECK (transport IN ('data-api','odata')),
    comment_mode TEXT NOT NULL CHECK (comment_mode IN ('concise','inline','teaching','advanced')),
    favorites TEXT NOT NULL DEFAULT '[]', manual_draft TEXT, revision INTEGER NOT NULL DEFAULT 1 CHECK (revision > 0),
    archived_at TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS openbridge_workspaces_owner_idx ON openbridge_workspaces (user_id, updated_at DESC);
CREATE TABLE IF NOT EXISTS openbridge_workspace_blocks (
    workspace_id TEXT NOT NULL REFERENCES openbridge_workspaces(id) ON DELETE CASCADE,
    block_id TEXT NOT NULL, position INTEGER NOT NULL CHECK (position >= 0), module_id TEXT NOT NULL,
    module_version INTEGER NOT NULL CHECK (module_version > 0), inputs TEXT NOT NULL DEFAULT '{}',
    collapsed INTEGER NOT NULL DEFAULT 0 CHECK (collapsed IN (0,1)),
    PRIMARY KEY (workspace_id, block_id), UNIQUE (workspace_id, position)
);
