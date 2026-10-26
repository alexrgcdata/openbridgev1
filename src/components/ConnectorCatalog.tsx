import { useMemo, useState } from "react";
import { connectorCatalog, connectorGoals, filterConnectors } from "../lib/connectors/catalog";
import type { ConnectorGoal, ConnectorStatus } from "../lib/connectors/types";

const statusLabels: Record<ConnectorStatus, string> = {
  available: "Available now",
  planned: "Planned",
  research: "Under review",
};

export function ConnectorCatalog({
  goal,
  setGoal,
  primaryConnectorId,
  choosePrimaryConnector,
  configureFileMaker,
  continueToTest,
}: {
  goal: ConnectorGoal;
  setGoal: (goal: ConnectorGoal) => void;
  primaryConnectorId: string;
  choosePrimaryConnector: (id: string) => void;
  configureFileMaker: () => void;
  continueToTest: () => void;
}) {
  const [query, setQuery] = useState("");
  const [selectedId, setSelectedId] = useState("filemaker");
  const filtered = useMemo(() => filterConnectors(query, goal), [query, goal]);
  const selected = connectorCatalog.find(({ id }) => id === selectedId) ?? connectorCatalog[0];
  const primaryConnector = connectorCatalog.find(({ id }) => id === primaryConnectorId);
  const setupSteps = buildSetupSteps(selected);

  return (
    <section className="connector-workspace">
      <header className="panel connector-hero">
        <div>
          <p className="section-kicker">OpenBridge connections</p>
          <h2>What should FileMaker connect to?</h2>
          <p>First connect FileMaker through OData. Then choose one primary outside service. OpenBridge explains its requirements and will route data through one consistent, protected middleware layer.</p>
        </div>
        <div className="connector-security-note"><span aria-hidden="true">◇</span><div><strong>Credentials stay server-side</strong><small>Future adapters will send secrets directly to the encrypted PHP vault—not browser storage.</small></div></div>
      </header>

      <div className="integration-route" aria-label="Current integration route">
        <span><b>1</b> Set up FileMaker</span><i aria-hidden="true">→</i><span><b>2</b> {primaryConnector?.name ?? "Optional integration"}</span><i aria-hidden="true">→</i><span className="waiting"><b>3</b> Test FileMaker</span>
      </div>

      <div className="connector-next-step">
        <div><strong>Step 2 is optional.</strong><span>Choose one future integration, or skip it and verify FileMaker now.</span></div>
        <button className="button secondary" onClick={continueToTest}>Skip integration and test FileMaker →</button>
      </div>

      <nav className="connector-goals" aria-label="Choose a connection goal">
        {connectorGoals.map((item) => (
          <button key={item.id} className={goal === item.id ? "active" : ""} onClick={() => setGoal(item.id)} aria-pressed={goal === item.id}>
            {item.label}
          </button>
        ))}
      </nav>

      <div className="connector-browser">
        <section className="panel connector-list-panel">
          <div className="connector-list-heading">
            <div><p className="section-kicker">Connector catalog</p><h3>{filtered.length} connection{filtered.length === 1 ? "" : "s"}</h3></div>
            <label className="connector-search"><span className="sr-only">Search connectors</span><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search services or tasks…" /></label>
          </div>
          <div className="connector-grid">
            {filtered.map((connector) => {
              const selectedCard = selected.id === connector.id;
              const primary = primaryConnectorId === connector.id;
              return (
                <article key={connector.id} className={`connector-card ${selectedCard ? "selected" : ""} ${primary ? "primary-integration" : ""}`}>
                  <button className="connector-card-main" onClick={() => setSelectedId(connector.id)} aria-pressed={selectedCard}>
                    <span className={`connector-logo goal-${connector.goal}`}>{connector.initials}</span>
                    <span className="connector-card-copy"><strong>{connector.name}</strong><small>{connector.summary}</small></span>
                    <span className={`connector-status ${connector.status}`}>{statusLabels[connector.status]}</span>
                  </button>
                  {primary && <span className="primary-integration-badge">Primary</span>}
                </article>
              );
            })}
            {filtered.length === 0 && <p className="connector-empty">No connectors match that search yet.</p>}
          </div>
        </section>

        <aside className="panel connector-detail" aria-live="polite">
          <div className="connector-detail-title"><span className={`connector-logo goal-${selected.goal}`}>{selected.initials}</span><div><p className="section-kicker">Selected connector</p><h3>{selected.name}</h3></div></div>
          <span className={`connector-status ${selected.status}`}>{statusLabels[selected.status]}</span>
          <p>{selected.summary}</p>
          <div className="connector-detail-group"><strong>How you will connect</strong><span>{selected.auth.label}</span><ul>{selected.auth.requirements.map((item) => <li key={item}>{item}</li>)}</ul></div>
          <div className="connector-detail-group"><strong>{selected.status === "available" ? "Available tools" : "Planned tools"}</strong><ul>{selected.capabilities.map((item) => <li key={item}>{item}</li>)}</ul></div>
          <div className="connector-setup-flow">
            <strong>How setup will work</strong>
            <ol>{setupSteps.map((step, index) => <li key={step}><b>{index + 1}</b><span>{step}</span></li>)}</ol>
          </div>
          {selected.status === "available" ? (
            <button className="button primary connector-setup-button" onClick={configureFileMaker}>Connect FileMaker with OData →</button>
          ) : (
            <>
              <button className="button primary connector-setup-button" onClick={() => {
                choosePrimaryConnector(selected.id);
                continueToTest();
              }}>
                {primaryConnectorId === selected.id ? "Continue to FileMaker test →" : `Choose ${selected.shortName} and continue →`}
              </button>
              <div className="connector-placeholder"><strong>{primaryConnectorId === selected.id ? "Saved as your primary integration plan." : "Adapter not active yet."}</strong><span>You can choose it now and review setup. Credential entry and API actions remain disabled until this adapter is implemented and tested.</span></div>
            </>
          )}
        </aside>
      </div>
    </section>
  );
}

function buildSetupSteps(connector: (typeof connectorCatalog)[number]): string[] {
  if (connector.id === "filemaker") return [
    "Enable OData for the hosted FileMaker file and grant the account the fmodata extended privilege.",
    "Enter the HTTPS FileMaker Server address, database name, OData table, and a dedicated account in Setup FileMaker.",
    "OpenBridge encrypts reusable credentials in its server-side vault; they never enter generated browser code.",
    "Run the read-only connection test, inspect discovered tables, and choose the default table.",
    "Build an OData request in the Coding Playground or route it to your chosen integration.",
  ];
  if (connector.auth.type === "oauth2") return [
    `Create a ${connector.shortName} developer application and register OpenBridge's exact HTTPS callback URL.`,
    `Choose only the permissions needed for: ${connector.capabilities.join(", ")}.`,
    `Click Connect ${connector.shortName}, sign in on ${connector.shortName}, and approve the requested access.`,
    "OpenBridge exchanges the returned authorization code server-side and encrypts access and refresh tokens in SQLite.",
    "Run a read-only identity/list test, map outside fields to FileMaker fields, then explicitly enable any write workflow.",
  ];
  if (connector.auth.type === "api-key") return [
    `Create a restricted ${connector.shortName} credential with the smallest required permissions.`,
    `Enter ${connector.auth.requirements.join(", ")} in the protected OpenBridge setup form.`,
    "OpenBridge sends the secret directly to PHP and stores only encrypted credential material in SQLite.",
    "Run a read-only account or list test and confirm the correct environment before mapping data.",
    "Choose which FileMaker fields correspond to the service, then explicitly enable individual workflows.",
  ];
  if (connector.auth.type === "file-import") return [
    "Choose a CSV, Excel, or JSON file and preview its headings and sample values.",
    "Match each incoming column to a FileMaker OData field.",
    "Validate types, required fields, duplicate rules, and a small read-only preview.",
    "Save the field map as a reusable recipe without retaining the uploaded source file by default.",
    "Run an explicitly approved import and record its result in Activity.",
  ];
  return [
    `Enter ${connector.auth.requirements.join(", ")} in a protected server-side setup form.`,
    "Restrict the target host and allowed operations so OpenBridge cannot become an unrestricted proxy.",
    "Encrypt reusable secrets in SQLite and keep all outbound requests inside the PHP router.",
    "Run a safe connection/list test and inspect the normalized response.",
    "Create a field map and explicitly enable each read or write workflow.",
  ];
}
