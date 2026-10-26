import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
  deleteSavedConnection,
  listSavedConnections,
  type SavedConnection,
} from "../lib/filemaker";
import "./SavedConnections.css";

const authLabels: Record<SavedConnection["authType"], string> = {
  "filemaker-credentials": "FileMaker credentials",
  "api-key": "API key",
  oauth2: "OAuth 2",
  basic: "Basic auth",
  none: "No secret",
};

const providerLabels: Record<string, string> = {
  filemaker: "FileMaker",
};

export function SavedConnections({
  endpoint,
  onEditFileMaker,
  onRemovedFileMaker,
}: {
  endpoint: string;
  onEditFileMaker: () => void;
  onRemovedFileMaker?: () => void;
}) {
  const [connections, setConnections] = useState<SavedConnection[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [removingId, setRemovingId] = useState("");
  const loadGeneration = useRef(0);

  const fileMakerConnection = useMemo(
    () => connections.find((connection) => connection.providerId === "filemaker"),
    [connections],
  );

  const load = useCallback(async () => {
    const generation = loadGeneration.current + 1;
    loadGeneration.current = generation;
    setLoading(true);
    setError("");
    try {
      const nextConnections = await listSavedConnections(endpoint);
      if (loadGeneration.current === generation) {
        setConnections(nextConnections);
      }
    } catch (loadError) {
      if (loadGeneration.current === generation) {
        setError(loadError instanceof Error ? loadError.message : "OpenBridge could not load saved connections.");
      }
    } finally {
      if (loadGeneration.current === generation) {
        setLoading(false);
      }
    }
  }, [endpoint]);

  useEffect(() => {
    setConnections([]);
    void load();
    return () => {
      loadGeneration.current += 1;
    };
  }, [load]);

  const remove = async (connection: SavedConnection) => {
    if (loading || removingId) return;
    const confirmed = window.confirm(
      "Remove this saved local connection from OpenBridge? This deletes the local encrypted connection record only. It does not revoke access inside FileMaker or any outside vendor account.",
    );
    if (!confirmed) return;
    const generation = loadGeneration.current;
    setRemovingId(connection.id);
    setError("");
    try {
      const result = await deleteSavedConnection(endpoint, connection.id, connection.revision);
      if (loadGeneration.current !== generation) return;
      if (!result.deleted) {
        setError("Connection not found. Reload saved connections and try again.");
        return;
      }
      setConnections((current) => current.filter((item) => item.id !== connection.id));
      if (connection.providerId === "filemaker") onRemovedFileMaker?.();
    } catch (removeError) {
      const message = removeError instanceof Error ? removeError.message : "OpenBridge could not remove the saved connection.";
      if (loadGeneration.current !== generation) return;
      setError(message.toLowerCase().includes("changed")
        ? "Connection changed elsewhere. Reload saved connections before deleting."
        : message);
    } finally {
      if (loadGeneration.current === generation) {
        setRemovingId("");
      }
    }
  };

  return (
    <section className="panel saved-connections" aria-labelledby="saved-connections-title">
      <div className="panel-heading">
        <div>
          <p className="section-kicker">Saved locally in OpenBridge</p>
          <h3 id="saved-connections-title">Saved connections</h3>
          <p className="panel-description">
            Manage connection details saved securely for your account.
            Secrets and vendor tokens are never shown here.
          </p>
        </div>
        <button className="button secondary" onClick={() => void load()} disabled={loading || removingId !== ""}>
          {loading ? "Loading..." : error ? "Retry" : "Refresh"}
        </button>
      </div>

      {!loading && !error && (
        <div className="connector-next-step">
          <div>
            <strong>{fileMakerConnection ? "FileMaker is stored locally." : "FileMaker is not stored yet."}</strong>
            <span>
              Stored means OpenBridge has encrypted local connection details.
              Connected still depends on a successful read-only Test Connection.
            </span>
          </div>
          <button className="button primary" onClick={onEditFileMaker}>
            {fileMakerConnection ? "Edit FileMaker setup" : "Set up FileMaker"}
          </button>
        </div>
      )}

      {error && <p className="inline-warning" role="alert">{error}</p>}

      {!loading && connections.length === 0 && !error && (
        <div className="empty-state">
          <strong>No saved connections yet</strong>
          <p>Save FileMaker setup first. Planned providers stay unavailable until their adapters are implemented and tested.</p>
        </div>
      )}

      {connections.length > 0 && (
        <div className="connector-grid" aria-label="Saved connection metadata">
          {connections.map((connection) => (
            <article className="connector-card" key={connection.id}>
              <div className="connector-card-main">
                <span className="connector-logo goal-sync">{providerInitials(connection.providerId)}</span>
                <span className="connector-card-copy">
                  <strong>{connection.displayName}</strong>
                  <small>{providerLabel(connection.providerId)} · {authLabels[connection.authType]}</small>
                </span>
                <span className={`connector-status ${connection.providerId === "filemaker" ? "available" : "planned"}`}>
                  Stored
                </span>
              </div>
              <div className="connector-detail-group">
                <strong>{connection.environment === "production" ? "Production" : "Test"}</strong>
                <span>{connection.providerId === "filemaker" ? "Ready to test" : "Stored locally"}</span>
              </div>
              <div className="form-actions">
                {connection.providerId === "filemaker" && (
                  <button className="button secondary" onClick={onEditFileMaker} disabled={removingId !== ""}>
                    Edit
                  </button>
                )}
                <button
                  className="button danger"
                  disabled={loading || removingId !== ""}
                  onClick={() => void remove(connection)}
                >
                  {removingId === connection.id ? "Removing..." : "Remove local record"}
                </button>
              </div>
            </article>
          ))}
        </div>
      )}
    </section>
  );
}

function providerLabel(providerId: string): string {
  return providerLabels[providerId] ?? providerId.replaceAll("-", " ");
}

function providerInitials(providerId: string): string {
  if (providerId === "filemaker") return "FM";
  return providerId
    .split("-")
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? "")
    .join("") || "OB";
}
