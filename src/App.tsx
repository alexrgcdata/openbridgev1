import { lazy, Suspense, useEffect, useMemo, useRef, useState } from "react";
import type { FormEvent, RefObject } from "react";
import "./App.css";
import "./styles/button-variants.css";
import "./styles/setup.css";
import { ConnectorCatalog } from "./components/ConnectorCatalog";
import { SavedConnections } from "./components/SavedConnections";
import { Modal } from "./components/Modal";
import { DocsPage } from "./components/DocsPage";
import {
  decryptJson,
  generateEncryptionKey,
  isEncryptedEnvelope,
  isValidEncryptionKey,
} from "./lib/crypto";
import {
  checkAdminSession,
  checkConnectionHealth,
  getCredentialVaultStatus,
  getSetupStatus,
  loginAdmin,
  loadSchemaSnapshot,
  loadServerDemoConfiguration,
  loadStoredSettings,
  loadStoredTransactionPayload,
  loadStoredTransactions,
  logoutAdmin,
  recordMetricEvent,
  recordStoredTransaction,
  registerAdmin,
  runDatabaseDiagnostics,
  runPlaygroundRequest,
  saveCredentialVault,
  saveStoredSettings,
  runTransportTest,
  type ConnectionHealth,
  type PlaygroundOperation,
  type StoredTransaction,
  type TransportTestResult,
} from "./lib/filemaker";
import {
  normalizeDataApi,
  normalizeOData,
  sampleDataApiRaw,
  sampleODataRaw,
  type NormalizedResponse,
  type Transport,
} from "./lib/normalize";
import {
  clearOpenBridgeBrowserData,
  DEFAULT_ADMIN_SETTINGS,
  loadSettings,
  normalizeLogCapacity,
  saveSettings,
  type AdminSettings,
} from "./lib/settings";
import {
  buildCredentialVaultProfile,
  fileMakerProfileFieldsReady,
  settingsAfterCredentialSave,
  setupFileMakerCanAdvance,
  toPublicStoredSettings,
} from "./lib/settingsPersistence";
import {
  createTransaction,
  filterTransactions,
  loadTransactions,
  saveTransactions,
  type Transaction,
  type TransactionFilters,
} from "./lib/transactions";
import { connectorCatalog } from "./lib/connectors/catalog";
import {
  loadPrimaryConnectorId,
  savePrimaryConnectorId,
} from "./lib/connectors/preferences";
import type { ConnectorGoal } from "./lib/connectors/types";

const CodingPlayground = lazy(() => import("./components/CodingPlayground").then((module) => ({ default: module.CodingPlayground })));

const LOCAL_OFFLINE_SESSION_KEY = "openbridge-local-offline-admin";
const localOfflineAccessEnabled = import.meta.env.DEV && ["localhost", "127.0.0.1", "::1"].includes(window.location.hostname);
type ServerConfigurationWithUsername = Awaited<ReturnType<typeof loadServerDemoConfiguration>> & { username?: string };

type View =
  | "overview"
  | "connections"
  | "demo"
  | "health"
  | "test"
  | "playground"
  | "coding"
  | "schema"
  | "capabilities"
  | "docs"
  | "ai"
  | "progress"
  | "studio"
  | "activity"
  | "prettifier"
  | "metrics"
  | "security"
  | "design"
  | "admin"
  | "about";
type ResponseModal = {
  transaction: Transaction;
  response?: Record<string, unknown>;
  error?: string;
};
type TourStep = {
  eyebrow: string;
  title: string;
  body: string;
  view: View;
  tip: string;
};
const brandLogoUrl = `${import.meta.env.BASE_URL}brand/openbridge-rgc.svg`;
const tourSteps: TourStep[] = [
  {
    eyebrow: "01 · Welcome",
    title: "Meet your OpenBridge control plane",
    body: "The dashboard keeps connection status, activity totals, and transport usage visible without exposing FileMaker credentials in the browser.",
    view: "overview",
    tip: "Start here whenever you need a quick operational snapshot.",
  },
  {
    eyebrow: "02 · Connect",
    title: "Configure the PHP bridge and FileMaker target",
    body: "Setup keeps connection details, transport preferences, logging, bridge authorization, and optional encryption in one guided area.",
    view: "admin",
    tip: "Browser settings can override defaults, but production credentials belong in the private PHP config.",
  },
  {
    eyebrow: "03 · Protect",
    title: "Choose optional response protection",
    body: "The Security section of Setup explains encrypted response storage, the reversible 256-bit key, and bridge-token readiness in plain language.",
    view: "security",
    tip: "Keep encryption keys and bridge tokens out of Git, screenshots, logs, and shared prompts.",
  },
  {
    eyebrow: "04 · Verify",
    title: "Run the end-to-end connection check",
    body: "Debug Testing groups connection health, the dual-transport check, and the API Playground so troubleshooting stays in one place.",
    view: "health",
    tip: "Every failed check links back to the setting responsible for it.",
  },
  {
    eyebrow: "05 · Request",
    title: "Try a request safely in API Playground",
    body: "Begin with samples in Debug Testing, then use your configured database for finds, record reads, scripts, and optional after-request scripts.",
    view: "playground",
    tip: "Use Inspect JSON to send the latest payload or response directly to the prettifier.",
  },
  {
    eyebrow: "06 · Inspect and learn",
    title: "Review activity, normalize JSON, and use the docs",
    body: "Metrics includes bounded activity history, JSON Tools formats payloads, and Capabilities includes Quick Start plus the AI integration guide.",
    view: "capabilities",
    tip: "You can restart this tour at any time from the top bar.",
  },
];

const samples: Record<string, NormalizedResponse> = {
  "sample-odata": normalizeOData(sampleODataRaw, {
    operation: "Find records",
    database: "OpenBridge Demo",
    resource: "Customers",
  }),
  "sample-data-api": normalizeDataApi(sampleDataApiRaw, {
    operation: "Find records",
    database: "OpenBridge Demo",
    resource: "Customers",
  }),
};
const sampleTransactions: Transaction[] = [
  {
    id: "sample-odata",
    status: "success",
    operation: "Find records",
    transport: "odata",
    resource: "Customers",
    durationMs: 126,
    createdAt: new Date(Date.now() - 90_000).toISOString(),
    message: "Sample OData response available.",
    responsePreview: "Two native OData entities",
  },
  {
    id: "sample-data-api",
    status: "success",
    operation: "Find records",
    transport: "data-api",
    resource: "Customers",
    durationMs: 141,
    createdAt: new Date(Date.now() - 180_000).toISOString(),
    message: "Sample Data API response available.",
    responsePreview: "Two Data API records with fieldData",
  },
];

export default function App() {
  const [authenticated, setAuthenticated] = useState(false);
  const [currentUsername, setCurrentUsername] = useState(() => sessionStorage.getItem("openbridge-current-user") ?? "");
  const [offlineMode, setOfflineMode] = useState(() => localOfflineAccessEnabled && sessionStorage.getItem(LOCAL_OFFLINE_SESSION_KEY) === "true");
  const [checkingAuthentication, setCheckingAuthentication] = useState(true);
  const [setupRequired, setSetupRequired] = useState(false);
  const [view, setView] = useState<View>("overview");
  const [connectorGoal, setConnectorGoal] = useState<ConnectorGoal>("all");
  const [primaryConnectorId, setPrimaryConnectorId] = useState(loadPrimaryConnectorId);
  const [settings, setSettings] = useState<AdminSettings>(loadSettings);
  const [transactions, setTransactions] = useState<Transaction[]>(() => {
    const loaded = loadTransactions(loadSettings().logCapacity);
    const missingSamples = sampleTransactions.filter(
      (sample) => !loaded.some((item) => item.id === sample.id),
    );
    return [...missingSamples, ...loaded].slice(0, loadSettings().logCapacity);
  });
  const [helpOpen, setHelpOpen] = useState(false);
  const [responseModal, setResponseModal] = useState<ResponseModal | null>(
    null,
  );
  const [toast, setToast] = useState("");
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
  const [prettifierInput, setPrettifierInput] = useState("");
  const [tourStep, setTourStep] = useState(0);
  const [inspection, setInspection] = useState<{ title: string; json: string } | null>(null);
  const [detailView, setDetailView] = useState<"demo" | "docs" | "metrics" | null>(null);
  const [tourOpen, setTourOpen] = useState(false);
  const [topbarQuery, setTopbarQuery] = useState("");
  const [profileOpen, setProfileOpen] = useState(false);
  const [lightMode, setLightMode] = useState(false);
  const topbarSearchRef = useRef<HTMLInputElement>(null);
  const activitySessionGeneration = useRef(0);

  useEffect(() => {
    const focusSearch = (event: KeyboardEvent) => {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "k") {
        event.preventDefault();
        topbarSearchRef.current?.focus();
      }
    };
    window.addEventListener("keydown", focusSearch);
    return () => window.removeEventListener("keydown", focusSearch);
  }, []);
  useEffect(() => savePrimaryConnectorId(primaryConnectorId), [primaryConnectorId]);

  useEffect(
    () => {
      if (authenticated) saveTransactions(transactions, settings.logCapacity);
    },
    [authenticated, transactions, settings.logCapacity],
  );
  useEffect(() => {
    let active = true;
    const generation = activitySessionGeneration.current;
    if (localOfflineAccessEnabled && sessionStorage.getItem(LOCAL_OFFLINE_SESSION_KEY) === "true") {
      setAuthenticated(true);
      setCurrentUsername("admin");
      setSetupRequired(false);
      setCheckingAuthentication(false);
      return () => { active = false; };
    }
    const endpoint = loadSettings().backendUrl;
    getSetupStatus(endpoint)
      .then((status) => {
        if (active && generation === activitySessionGeneration.current) setSetupRequired(status.requiresSetup);
      })
      .catch(() => {
        // The packaged demo remains usable while SQLite initializes.
      })
      .finally(() => {
        checkAdminSession(endpoint)
      .then(async (valid) => {
        if (!active || generation !== activitySessionGeneration.current) return;
        setAuthenticated(valid);
        if (valid) {
          const [server, storedSettings, storedTransactions] = await Promise.all([
            loadServerDemoConfiguration(endpoint),
            loadStoredSettings(endpoint).catch(() => ({})),
            loadStoredTransactions(endpoint, 500).catch(() => []),
          ]);
          if (active && generation === activitySessionGeneration.current) {
            const serverConfiguration = server as ServerConfigurationWithUsername;
            setSettings((current) => ({
              ...current,
              ...storedSettings,
              ...(server.configured ? {
                serverUrl: server.serverUrl,
                database: server.database,
                defaultLayout: server.transport === "odata" ? server.table || server.layout : server.layout,
                username: serverConfiguration.username || current.username,
                transport: "odata",
              } : {}),
            }));
          }
          if (active && generation === activitySessionGeneration.current && storedTransactions.length) {
            setTransactions(storedTransactions
              .filter((item) => item.status !== "pending" && (item.transport === "data-api" || item.transport === "odata"))
              .map(storedTransactionToActivity));
          }
        }
      })
      .catch(() => {
        if (active && generation === activitySessionGeneration.current) setAuthenticated(false);
      })
      .finally(() => {
        if (active && generation === activitySessionGeneration.current) setCheckingAuthentication(false);
      });
      });
    return () => {
      active = false;
    };
  }, []);
  useEffect(() => {
    if (!toast) return;
    const timer = window.setTimeout(() => setToast(""), 3000);
    return () => clearTimeout(timer);
  }, [toast]);
  useEffect(() => {
    const close = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        setHelpOpen(false);
        setResponseModal(null);
        setTourOpen(false);
      }
    };
    window.addEventListener("keydown", close);
    return () => window.removeEventListener("keydown", close);
  }, []);
  useEffect(() => {
    if (tourOpen) setView(tourSteps[tourStep].view);
  }, [tourOpen, tourStep]);

  const configured = Boolean(
    settings.backendUrl &&
      settings.serverUrl &&
      settings.database &&
      settings.defaultLayout,
  );
  const stats = useMemo(() => {
    const success = transactions.filter(
      (item) => item.status === "success",
    ).length;
    const total = transactions.length;
    return {
      success,
      failure: total - success,
      total,
      rate: total ? Math.round((success / total) * 100) : 0,
      odata: transactions.filter((item) => item.transport === "odata").length,
      dataApi: transactions.filter((item) => item.transport === "data-api")
        .length,
    };
  }, [transactions]);
  const updateSettings = (partial: Partial<AdminSettings>) =>
    setSettings((current) => ({ ...current, ...partial }));
  const persistSettings = async () => {
    let existingVaultConfigured = false;
    if (!settings.password && fileMakerProfileFieldsReady(settings)) {
      try {
        existingVaultConfigured = (await getCredentialVaultStatus(settings.backendUrl)).configured;
      } catch (error) {
        const saved = saveSettings(settings);
        setSettings(saved);
        setTransactions((current) => current.slice(0, saved.logCapacity));
        setToast(explainSettingsSaveFailure(error));
        setToast("OpenBridge could not confirm whether FileMaker credentials are already stored. Enter the password and save again.");
        return false;
      }
    }
    const vaultProfile = buildCredentialVaultProfile(settings, existingVaultConfigured);
    let credentialsStored = false;
    try {
      if (vaultProfile) {
        await saveCredentialVault(settings.backendUrl, vaultProfile);
        credentialsStored = true;
      }
    } catch (error) {
      const saved = saveSettings(settings);
      setSettings(saved);
      setTransactions((current) => current.slice(0, saved.logCapacity));
      setToast(explainSettingsSaveFailure(error));
      setToast("FileMaker credentials were not saved. Review Setup FileMaker before testing the connection.");
      return false;
    }
    const saved = saveSettings(settingsAfterCredentialSave(settings, credentialsStored));
    setSettings(saved);
    setTransactions((current) => current.slice(0, saved.logCapacity));
    try {
      await saveStoredSettings(saved.backendUrl, toPublicStoredSettings(saved));
    } catch (error) {
      setToast("Saved locally. SQLite sync will resume when server storage is writable.");
      setToast(explainSettingsSaveFailure(error));
      return false;
    }
    try {
      await recordMetricEvent(saved.backendUrl, "settings_saved", { transport: saved.transport });
    } catch {
      // Metrics are useful, but a missed event should not block setup progress.
    }
    setToast(credentialsStored ? "Settings and encrypted credentials saved" : "Settings saved");
    setToast(
      credentialsStored
        ? "FileMaker credentials saved. Continue to Test Connection."
        : saved.serverUrl && saved.database && saved.defaultLayout && saved.username
        ? "Connection details saved. Enter the FileMaker password and save again before testing the connection."
        : saved.serverUrl && saved.database
        ? "Connection details saved. Use your PHP backend to test live FileMaker access."
        : "Server configuration is incomplete.",
    );
    return setupFileMakerCanAdvance(saved, credentialsStored);
  };

  const prepareDemo = async () => {
    const raw =
      settings.transport === "odata" ? sampleODataRaw : sampleDataApiRaw;
    const context = {
      operation: "Find records",
      database: settings.database || "OpenBridge Demo",
      resource: settings.defaultLayout || "Customers",
    };
    const normalized =
      settings.transport === "odata"
        ? normalizeOData(raw, context)
        : normalizeDataApi(raw, context);
    const transactionInput = {
      status: "success" as const,
      operation: "Find records",
      transport: settings.transport,
      resource: context.resource,
      durationMs: settings.transport === "odata" ? 126 : 141,
      message:
        settings.storeResponses
          ? "Response submitted for encrypted server retention."
          : "Response metadata retained.",
      ...(settings.storeResponses
        ? { responsePreview: `${normalized.returnedCount} records · server encrypted when available` }
        : {}),
    };
    const localTransaction = createTransaction(transactionInput);
    setTransactions((current) => [localTransaction, ...current].slice(0, settings.logCapacity));
    void recordStoredTransaction(settings.backendUrl, {
      ...transactionInput,
      database: context.database,
      ...(settings.storeResponses ? { rawResponse: JSON.stringify(normalized) } : {}),
    }).then((result) => {
      if (!result.recorded || !result.id) return;
      setTransactions((current) => current.map((item) => item.id === localTransaction.id ? {
        ...item,
        serverId: result.id,
        payloadStored: result.payloadStored,
        responsePreview: result.payloadStored ? `${normalized.returnedCount} records · stored server-side` : item.responsePreview,
        message: result.payloadStored ? "Encrypted response retained in packaged SQLite." : "Response metadata retained.",
      } : item));
    }).catch(() => undefined);
    setToast("Sample request added to Activity");
  };

  const openResponse = async (transaction: Transaction) => {
    const generation = activitySessionGeneration.current;
    if (samples[transaction.id]) {
      setResponseModal({ transaction, response: samples[transaction.id] });
      return;
    }
    if (transaction.payloadStored && transaction.serverId) {
      try {
        const payload = await loadStoredTransactionPayload(settings.backendUrl, transaction.serverId);
        if (generation !== activitySessionGeneration.current) return;
        setResponseModal({ transaction, response: payload });
      } catch (error) {
        if (generation !== activitySessionGeneration.current) return;
        setResponseModal({
          transaction,
          error: error instanceof Error ? error.message : "Unable to load the stored response from the server.",
        });
      }
      return;
    }
    if (!transaction.encryptedResponse) {
      setResponseModal({
        transaction,
        error: transaction.legacyPlaintext
          ? "This legacy transaction was stored before server-side encrypted payload viewing was available."
          : "No response body was stored for this transaction.",
      });
      return;
    }
    if (!settings.encryptionSecret) {
      setResponseModal({
        transaction,
        error:
          "Enter the encryption key in Setup → Security to decrypt this response.",
      });
      return;
    }
    try {
      if (!isEncryptedEnvelope(transaction.encryptedResponse))
        throw new Error("The stored response envelope is invalid.");
      const response = await decryptJson<NormalizedResponse>(
        transaction.encryptedResponse,
        settings.encryptionSecret,
      );
      if (generation !== activitySessionGeneration.current) return;
      setResponseModal({ transaction, response });
    } catch {
      if (generation !== activitySessionGeneration.current) return;
      setResponseModal({
        transaction,
        error:
          "Unable to decrypt this response. Check the secret and try again.",
      });
    }
  };

  const login = async (username: string, password: string) => {
    const normalizedUsername = username.trim().toLowerCase();
    const offlineCredentialsMatch = localOfflineAccessEnabled && normalizedUsername === "admin" && password === "1234";
    const generation = ++activitySessionGeneration.current;
    let valid = false;
    try {
      valid = await loginAdmin(settings.backendUrl, username, password);
    } catch (error) {
      if (!offlineCredentialsMatch) throw error;
    }
    if (!valid && offlineCredentialsMatch) {
      setAuthenticated(true);
      setOfflineMode(true);
      setCurrentUsername("admin");
      sessionStorage.setItem("openbridge-current-user", "admin");
      sessionStorage.setItem(LOCAL_OFFLINE_SESSION_KEY, "true");
      setSetupRequired(false);
      setToast("Local offline admin mode is active.");
      return true;
    }
    if (!valid) return false;
    const server = await loadServerDemoConfiguration(settings.backendUrl);
    if (server.configured) {
      const serverConfiguration = server as ServerConfigurationWithUsername;
      setSettings((current) => ({
        ...current,
        serverUrl: server.serverUrl,
        database: server.database,
        defaultLayout: server.transport === "odata" ? server.table || server.layout : server.layout,
        username: serverConfiguration.username || current.username,
        transport: "odata",
      }));
    }
    const storedActivity = await loadStoredTransactions(settings.backendUrl, settings.logCapacity).catch(() => []);
    if (generation !== activitySessionGeneration.current) return false;
    setTransactions(storedActivity
      .filter((item) => item.status !== "pending" && (item.transport === "data-api" || item.transport === "odata"))
      .map(storedTransactionToActivity));
    setAuthenticated(true);
    setOfflineMode(false);
    sessionStorage.removeItem(LOCAL_OFFLINE_SESSION_KEY);
    setCurrentUsername(normalizedUsername);
    sessionStorage.setItem("openbridge-current-user", normalizedUsername);
    setSetupRequired(false);
    void recordMetricEvent(settings.backendUrl, "admin_login", {}).catch(() => undefined);
    if (localStorage.getItem("openbridge-tour-complete-v1") !== "true") {
      setTourStep(0);
      setTourOpen(true);
    }
    return true;
  };
  const register = async (input: { username: string; email: string; password: string; displayName: string }) => {
    await registerAdmin(settings.backendUrl, input);
    setSetupRequired(false);
    setAuthenticated(true);
    setCurrentUsername(input.username.trim().toLowerCase());
    sessionStorage.setItem("openbridge-current-user", input.username.trim().toLowerCase());
    setToast("Your OpenBridge administrator account is ready.");
    void recordMetricEvent(settings.backendUrl, "first_admin_registered", {}).catch(() => undefined);
  };
  const logout = async () => {
    const confirmed = window.confirm(
      "Log out and clear OpenBridge browser settings, notes, metrics, legacy activity, and session secrets?",
    );
    if (!confirmed) return;
    activitySessionGeneration.current += 1;
    try {
      await logoutAdmin(settings.backendUrl);
    } catch {
      // Local data is still cleared if the PHP endpoint is temporarily unavailable.
    }
    clearOpenBridgeBrowserData();
    setAuthenticated(false);
    setOfflineMode(false);
    setCurrentUsername("");
    sessionStorage.removeItem("openbridge-current-user");
    sessionStorage.removeItem(LOCAL_OFFLINE_SESSION_KEY);
    setSettings({ ...DEFAULT_ADMIN_SETTINGS });
    setTransactions([]);
    setResponseModal(null);
    setInspection(null);
    setDetailView(null);
    setHelpOpen(false);
    setTourOpen(false);
    setPrettifierInput("");
    setView("overview");
  };
  const startTour = () => {
    setTourStep(0);
    setTourOpen(true);
  };
  const finishTour = () => {
    localStorage.setItem("openbridge-tour-complete-v1", "true");
    setTourOpen(false);
    setView("overview");
    setToast("Welcome tour complete");
  };
  const navigateFromTopbarSearch = (query: string) => {
    const value = query.trim().toLowerCase();
    const destinations: Array<[string, View]> = [
      ["database", "admin"], ["setup", "admin"], ["connection", "health"],
      ["connector", "connections"], ["quickbooks", "connections"], ["stripe", "connections"],
      ["shopify", "connections"], ["service", "connections"],
      ["test", "health"], ["json", "prettifier"], ["code", "coding"],
      ["playground", "coding"], ["help", "docs"], ["docs", "docs"],
      ["about", "about"], ["contact", "about"], ["metric", "metrics"],
      ["activity", "activity"], ["design", "design"],
    ];
    const match = destinations.find(([label]) => label.includes(value) || value.includes(label));
    if (!match) { setToast("Try Setup FileMaker, Integrations, Test Connection, Coding Playground, or Help."); return; }
    setView(match[1]);
    setTopbarQuery("");
    setProfileOpen(false);
  };
  if (checkingAuthentication)
    return <main className="login-shell"><section className="login-panel"><p className="eyebrow">OpenBridge admin</p><h1>Checking secure session…</h1></section></main>;
  if (setupRequired && !authenticated) return <FirstRunRegistration onRegister={register} />;
  if (!authenticated) return <Login onLogin={login} offlineLoginAvailable={localOfflineAccessEnabled} />;
  return (
    <main
      className={`app-shell ${sidebarCollapsed ? "sidebar-collapsed" : ""} ${lightMode ? "theme-light" : ""}`}
    >
      <aside className={`sidebar ${sidebarCollapsed ? "collapsed" : ""}`}>
        <div className="brand">
          <div className="brand-mark">O</div>
          <div className="brand-copy">
            <span>FileMaker</span>
            <strong>OpenBridge</strong>
          </div>
          <button
            className="collapse-button"
            aria-label={
              sidebarCollapsed ? "Expand sidebar" : "Collapse sidebar"
            }
            onClick={() => setSidebarCollapsed((value) => !value)}
          >
            {sidebarCollapsed ? "›" : "‹"}
          </button>
        </div>
        <nav className="nav-list" aria-label="OpenBridge navigation">
          <span className="nav-group-label">Connect</span>
          <Nav
            active={view === "overview"}
            label="Dashboard"
            icon="◈"
            onClick={() => setView("overview")}
          />
          <Nav
            active={["admin", "studio", "security"].includes(view)}
            label="Setup FileMaker"
            icon="⚙"
            onClick={() => setView("admin")}
          />
          <Nav
            active={view === "connections"}
            label="Advanced Integrations"
            icon="⌁"
            onClick={() => setView("connections")}
          />
          <Nav
            active={["health", "test"].includes(view)}
            label="Test Connection"
            icon="✓"
            onClick={() => setView("health")}
          />
          <span className="nav-group-divider" aria-hidden="true" />
          <span className="nav-group-label">Build &amp; inspect</span>
          <Nav
            active={view === "coding" || view === "playground"}
            label="Coding Playground"
            icon="</>"
            badge="Beta"
            onClick={() => setView("coding")}
          />
          {currentUsername === "admin" && (
            <Nav
              active={view === "design"}
              label="Visual Design Lab"
              icon="✦"
              badge="Admin"
              onClick={() => setView("design")}
            />
          )}
          <Nav
            active={view === "prettifier"}
            label="JSON Tools"
            icon="{}"
            onClick={() => setView("prettifier")}
          />
          <Nav
            active={["metrics", "activity"].includes(view)}
            label="Metrics & Activity"
            icon="↗"
            count={transactions.length}
            onClick={() => setView("metrics")}
          />
          <span className="nav-group-divider" aria-hidden="true" />
          <span className="nav-group-label">Learn</span>
          <Nav
            active={view === "demo"}
            label="See the Demo"
            icon="▶"
            badge="Showcase"
            onClick={() => setView("demo")}
          />
          <Nav
            active={["docs", "ai", "capabilities", "schema", "progress"].includes(view)}
            label="Docs & Help"
            icon="?"
            onClick={() => setView("docs")}
          />
          <span className="nav-group-divider" aria-hidden="true" />
          <Nav
            active={view === "about"}
            label="About"
            icon="A"
            tone="about"
            onClick={() => setView("about")}
          />
        </nav>
        <div className="sidebar-spacer" />
        <button className="rgc-cta" onClick={() => setHelpOpen(true)}>
          <span className="rgc-mini" aria-hidden="true">?</span>
          <span className="rgc-copy">
            <strong>Need Help?</strong>
            <span>Alex Seidler · FileMaker integration help</span>
          </span>
        </button>
      </aside>
      <section className="content">
        <TopBar
          view={view}
          currentUsername={currentUsername}
          query={topbarQuery}
          setQuery={setTopbarQuery}
          search={navigateFromTopbarSearch}
          profileOpen={profileOpen}
          setProfileOpen={setProfileOpen}
          setView={setView}
          startTour={startTour}
          logout={logout}
          lightMode={lightMode}
          setLightMode={setLightMode}
          searchInputRef={topbarSearchRef}
          primaryConnectorId={primaryConnectorId}
        />
        <ViewSubnav
          view={view}
          select={setView}
          startTour={startTour}
          viewMetrics={() => {
            setView("overview");
            window.setTimeout(() => document.getElementById("dashboard-metrics")?.scrollIntoView({ behavior: "smooth", block: "start" }), 0);
          }}
        />
        {offlineMode && (
          <section className="notice offline-mode-bar" role="status">
            <strong>Local offline admin mode</strong>
            <span>UI work is available. SQLite, saved settings, metrics, and live FileMaker actions still require the local PHP service.</span>
          </section>
        )}
        {!["overview", "connections", "docs", "ai"].includes(view) && !configured ? (
          <section className="notice warning-bar" role="alert">
            <div className="warning-copy">
              <span className="warning-icon">!</span>
              <div>
                <strong>Your FileMaker Server is not configured.</strong>
                <div className="supporting-text">
                Add the PHP endpoint, host, database, and default layout or
                table before making live requests.
                </div>
              </div>
            </div>
            <button className="button gold" onClick={() => setView("admin")}>
              Setup Connection
            </button>
          </section>
        ) : null}
        {view === "overview" && (
          <Overview
            stats={stats}
            goMetrics={() => setDetailView("metrics")}
            goSetup={() => setView("admin")}
            goDemo={() => setDetailView("demo")}
            goCoding={() => setView("coding")}
          />
        )}
        {view === "demo" && (
          <PresentationDemo
            goSetup={() => setView("admin")}
            goTest={() => setView("playground")}
            goMetrics={() => setDetailView("metrics")}
            goDocs={() => setDetailView("docs")}
            openSample={(transport) => {
              const raw = transport === "odata" ? sampleODataRaw : sampleDataApiRaw;
              setInspection({ title: `Sample ${transport === "odata" ? "OData" : "Data API"} response`, json: JSON.stringify(raw, null, 2) });
            }}
          />
        )}
        {["health", "test"].includes(view) && (
          <DebugHub
            active={view as "health" | "test"}
            settings={settings}
            select={(next) => setView(next === "playground" ? "coding" : next)}
            openSetup={(section) => setView(section)}
            inspect={(json) => {
              setInspection({ title: "Inspect response", json });
            }}
          />
        )}
        {view === "connections" && (
          <>
          <SavedConnections
            key={currentUsername}
            endpoint={settings.backendUrl}
            onEditFileMaker={() => setView("admin")}
            onRemovedFileMaker={() => {
              setSettings((current) => ({ ...current, password: "" }));
              setToast("Saved FileMaker credentials removed. Enter credentials in Setup FileMaker before using this saved connection again.");
              setToast("Saved FileMaker credentials removed");
            }}
          />
          <ConnectorCatalog
            goal={connectorGoal}
            setGoal={setConnectorGoal}
            primaryConnectorId={primaryConnectorId}
            choosePrimaryConnector={setPrimaryConnectorId}
            configureFileMaker={() => setView("admin")}
            continueToTest={() => setView("health")}
          />
          </>
        )}
        {(view === "coding" || view === "playground") && (
          <Suspense fallback={<section className="panel"><p className="section-kicker">Coding Playground</p><h3>Loading the code editor…</h3></section>}>
            <CodingPlayground settings={settings} />
          </Suspense>
        )}
        {["schema", "capabilities", "progress"].includes(view) && (
          <CapabilitiesHub
            active={view as "schema" | "capabilities" | "progress"}
            select={(next) => setView(next)}
            goDocs={() => setDetailView("docs")}
            settings={settings}
          />
        )}
        {["docs", "ai"].includes(view) && (
          <DocsHub
            initial={view === "ai" ? "ai" : "quick"}
          />
        )}
        {view === "about" && <AboutPage />}
        {view === "design" && currentUsername === "admin" && <DesignLab backendUrl={settings.backendUrl} />}
        {["activity", "metrics"].includes(view) && (
          <MetricsHub
            active={view as "activity" | "metrics"}
            select={(next) => setView(next)}
            transactions={transactions}
            open={openResponse}
            clear={() => setTransactions([])}
            exportLogs={(format) => downloadTransactionLog(transactions, format)}
          />
        )}
        {view === "prettifier" && <Prettifier initialValue={prettifierInput} />}
        {["admin", "studio", "security"].includes(view) && (
          <SetupHub
            active={view as "admin" | "studio" | "security"}
            select={(next) => setView(next)}
            settings={settings}
            update={updateSettings}
            save={async () => {
              const canAdvance = await persistSettings();
              if (view === "admin" && canAdvance) setView("health");
            }}
            prepare={prepareDemo}
          />
        )}
      </section>
      {helpOpen && <RgcModal close={() => setHelpOpen(false)} />}
      {detailView && (
        <Modal title={detailView === "demo" ? "OpenBridge demo" : detailView === "docs" ? "Documentation" : "Metrics & activity"} onClose={() => setDetailView(null)}>
          {detailView === "demo" && <PresentationDemo
            goSetup={() => { setDetailView(null); setView("admin"); }}
            goTest={() => { setDetailView(null); setView("playground"); }}
            goMetrics={() => setDetailView("metrics")}
            goDocs={() => setDetailView("docs")}
            openSample={(transport) => setInspection({ title: `Sample ${transport === "odata" ? "OData" : "Data API"} response`, json: JSON.stringify(transport === "odata" ? sampleODataRaw : sampleDataApiRaw, null, 2) })}
          />}
          {detailView === "docs" && <DocsHub initial="quick" />}
          {detailView === "metrics" && <Metrics stats={stats} transactions={transactions} />}
        </Modal>
      )}
      {inspection && (
        <Modal title={inspection.title} onClose={() => setInspection(null)}>
          <div className="inspection-actions">
            <button className="button secondary" onClick={() => {
              void navigator.clipboard.writeText(inspection.json)
                .then(() => setToast("Response copied"))
                .catch(() => setToast("Clipboard access was unavailable"));
            }}>Copy response</button>
          </div>
          <pre className="json-output inspection-code">{inspection.json}</pre>
        </Modal>
      )}
      {responseModal && (
        <ResponseViewer
          modal={responseModal}
          close={() => setResponseModal(null)}
          copied={() => setToast("Entire response copied")}
        />
      )}
      {tourOpen && (
        <FirstRunTour
          step={tourStep}
          close={() => setTourOpen(false)}
          back={() => setTourStep((current) => Math.max(0, current - 1))}
          next={() =>
            tourStep === tourSteps.length - 1
              ? finishTour()
              : setTourStep((current) => current + 1)
          }
        />
      )}
      {toast && (
        <div className="toast" role="status">
          {toast}
        </div>
      )}
    </main>
  );
}

function FirstRunTour({
  step,
  close,
  back,
  next,
}: {
  step: number;
  close: () => void;
  back: () => void;
  next: () => void;
}) {
  const item = tourSteps[step];
  const last = step === tourSteps.length - 1;
  return (
    <div className="modal-backdrop tour-backdrop" onMouseDown={close}>
      <section
        className="modal tour-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="tour-title"
        onMouseDown={(event) => event.stopPropagation()}
      >
        <div className="tour-brand">
          <img src={brandLogoUrl} alt="OpenBridge" />
        </div>
        <div className="tour-progress" aria-label={`Step ${step + 1} of ${tourSteps.length}`}>
          {tourSteps.map((tourStep, index) => (
            <span key={tourStep.title} className={index <= step ? "complete" : ""} />
          ))}
        </div>
        <p className="section-kicker">{item.eyebrow}</p>
        <h2 id="tour-title">{item.title}</h2>
        <p className="tour-copy">{item.body}</p>
        <div className="tour-tip">
          <strong>Good to know</strong>
          <span>{item.tip}</span>
        </div>
        <div className="tour-actions">
          <button className="text-button" onClick={close}>
            Skip for now
          </button>
          <div>
            <button className="button secondary" disabled={step === 0} onClick={back}>
              Back
            </button>
            <button className="button primary" onClick={next}>
              {last ? "Finish tour" : "Next step"}
            </button>
          </div>
        </div>
      </section>
    </div>
  );
}

function Login({
  onLogin,
  offlineLoginAvailable,
}: {
  onLogin: (username: string, password: string) => Promise<boolean>;
  offlineLoginAvailable: boolean;
}) {
  const [error, setError] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError("");
    setSubmitting(true);
    const form = new FormData(event.currentTarget);
    try {
      if (!await onLogin(String(form.get("username")), String(form.get("password"))))
        setError("Incorrect administrator credentials.");
    } catch (loginError) {
      setError(loginError instanceof Error ? loginError.message : "Secure login is unavailable.");
    } finally {
      setSubmitting(false);
    }
  };
  return (
    <main className="login-shell">
      <section className="login-panel">
        <div className="brand-mark">O</div>
        <p className="eyebrow">OpenBridge admin</p>
        <h1>FileMaker communication, clearly controlled.</h1>
        <p>
          Configure transports, inspect normalized responses, and protect local
          diagnostic history.
        </p>
        <form onSubmit={submit}>
          <label>
            Username
            <input
              name="username"
              defaultValue="demo"
              autoComplete="username"
            />
          </label>
          <label>
            Password
            <input
              name="password"
              type="password"
              autoComplete="current-password"
            />
          </label>
          {error && <p className="login-error">{error}</p>}
          <button className="button primary" disabled={submitting}>
            {submitting ? "Signing in securely…" : "Enter dashboard →"}
          </button>
        </form>
        <span className="supporting-text">
          PHP verifies a one-way password hash and returns an HttpOnly session
          cookie. The browser does not store the submitted login password.
        </span>
        <span className="supporting-text demo-credential-note">
          Public demo: alex / pass · demo / pass · Design Lab: admin / 1234
        </span>
        {offlineLoginAvailable && (
          <span className="supporting-text local-offline-note">
            Local development fallback: admin / 1234 opens UI-only offline mode if PHP is unavailable.
          </span>
        )}
      </section>
    </main>
  );
}

function FirstRunRegistration({
  onRegister,
}: {
  onRegister: (input: { username: string; email: string; password: string; displayName: string }) => Promise<void>;
}) {
  const [error, setError] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const password = String(form.get("password") ?? "");
    if (password.length < 12) {
      setError("Choose a password with at least 12 characters.");
      return;
    }
    if (password !== String(form.get("confirmPassword") ?? "")) {
      setError("The passwords do not match.");
      return;
    }
    setError("");
    setSubmitting(true);
    try {
      await onRegister({
        username: String(form.get("username") ?? ""),
        email: String(form.get("email") ?? ""),
        password,
        displayName: String(form.get("displayName") ?? ""),
      });
    } catch (registrationError) {
      setError(registrationError instanceof Error ? registrationError.message : "Account setup is unavailable.");
    } finally {
      setSubmitting(false);
    }
  };
  return (
    <main className="login-shell">
      <section className="login-panel first-run-panel">
        <div className="brand-mark">O</div>
        <p className="eyebrow">Welcome to OpenBridge</p>
        <h1>Create your local administrator account.</h1>
        <p>This account belongs to this packaged OpenBridge installation. Your email is saved locally for future account and usage reporting—not sent to another server in this version.</p>
        <form onSubmit={submit}>
          <label>Display name<input name="displayName" autoComplete="name" placeholder="Your name" /></label>
          <label>Username<input name="username" autoComplete="username" minLength={3} required placeholder="alex" /></label>
          <label>Email<input name="email" type="email" autoComplete="email" required placeholder="you@example.com" /></label>
          <label>Password<input name="password" type="password" autoComplete="new-password" minLength={12} required placeholder="At least 12 characters" /></label>
          <label>Confirm password<input name="confirmPassword" type="password" autoComplete="new-password" minLength={12} required /></label>
          {error && <p className="login-error">{error}</p>}
          <button className="button primary" disabled={submitting}>{submitting ? "Creating secure account…" : "Create OpenBridge account →"}</button>
        </form>
        <span className="supporting-text">Passwords are hashed on the PHP server. OpenBridge never stores the readable password in the browser.</span>
      </section>
    </main>
  );
}

function TopBar({
  view, currentUsername, query, setQuery, search, profileOpen, setProfileOpen,
  setView, startTour, logout, lightMode, setLightMode,
  searchInputRef,
  primaryConnectorId,
}: {
  view: View;
  currentUsername: string;
  query: string;
  setQuery: (value: string) => void;
  search: (value: string) => void;
  profileOpen: boolean;
  setProfileOpen: (value: boolean) => void;
  setView: (view: View) => void;
  startTour: () => void;
  logout: () => Promise<void>;
  lightMode: boolean;
  setLightMode: (value: boolean) => void;
  searchInputRef: RefObject<HTMLInputElement | null>;
  primaryConnectorId: string;
}) {
  const initials = currentUsername.slice(0, 2).toUpperCase() || "OB";
  const links: Array<[string, View]> = [["Setup FileMaker", "admin"], ["Test Connection", "health"], ["Advanced Integrations", "connections"]];
  const primaryConnector = connectorCatalog.find(({ id }) => id === primaryConnectorId);
  return (
    <header className="topbar" aria-label="OpenBridge quick navigation">
      {primaryConnector && <div className="topbar-connectors" aria-label="Primary integration"><button onClick={() => setView("connections")} title={`Open ${primaryConnector.name}`}>{primaryConnector.initials}</button></div>}
      <form className="topbar-search" onSubmit={(event) => { event.preventDefault(); search(query); }}>
        <span aria-hidden="true">⌕</span>
        <input ref={searchInputRef} value={query} onChange={(event) => setQuery(event.target.value)} list="openbridge-topbar-views" placeholder="Search OpenBridge…" aria-label="Search OpenBridge views" />
        <kbd>Ctrl K</kbd>
        <datalist id="openbridge-topbar-views">{links.map(([label]) => <option key={label} value={label} />)}</datalist>
      </form>
      <nav className="topbar-links" aria-label="Quick links">
        <span className="quick-actions-label">Quick actions</span>
        {links.map(([label, destination]) => <button key={label} className={view === destination ? "active" : ""} onClick={() => setView(destination)}>{label}</button>)}
      </nav>
      <button className="topbar-tour" onClick={startTour}><span aria-hidden="true">⚡</span> Get Started Fast!</button>
      <button className="topbar-theme" onClick={() => setLightMode(!lightMode)} aria-label={lightMode ? "Use dark theme" : "Use light theme"}>{lightMode ? "☾" : "☼"}</button>
      <span className="topbar-divider" aria-hidden="true" />
      <div className="profile-wrap">
        <button className="profile-button" onClick={() => setProfileOpen(!profileOpen)} aria-expanded={profileOpen} aria-haspopup="menu"><span className="profile-avatar">{initials}</span><span className="profile-copy"><b>{currentUsername || "OpenBridge"}</b><small>Administrator</small></span><span aria-hidden="true">⌄</span></button>
        {profileOpen && <div className="profile-menu" role="menu"><button onClick={() => { setView("admin"); setProfileOpen(false); }}>⚙ Settings</button><button onClick={() => { setView("about"); setProfileOpen(false); }}>? Help & Contact</button><button className="profile-logout" onClick={() => void logout()}>↪ Log out</button></div>}
      </div>
    </header>
  );
}

function ViewSubnav({ view, select, startTour, viewMetrics }: { view: View; select: (view: View) => void; startTour: () => void; viewMetrics: () => void }) {
  const guidedSetup: Array<[View, string]> = [["admin", "1 · Setup FileMaker"], ["health", "2 · Test connection"]];
  const groups: Record<string, Array<[View, string]>> = {
    overview: [["overview", "Overview"], ...guidedSetup],
    connections: [["connections", "Advanced integrations"], ["admin", "Setup FileMaker"], ["health", "Test connection"]],
    admin: guidedSetup,
    studio: guidedSetup,
    security: guidedSetup,
    health: guidedSetup,
    test: guidedSetup,
    coding: [["coding", "Code builder"], ["playground", "Saved workspaces"], ["docs", "Module guide"]],
    playground: [["coding", "Code builder"], ["playground", "Saved workspaces"], ["docs", "Module guide"]],
    metrics: [["metrics", "Metrics"], ["activity", "Activity log"], ["overview", "Dashboard"]],
    activity: [["metrics", "Metrics"], ["activity", "Activity log"], ["overview", "Dashboard"]],
    docs: [["docs", "Documentation"]],
    ai: [["ai", "Documentation"]],
    design: [["design", "Design Lab"], ["overview", "Live dashboard"], ["coding", "Code builder"]],
  };
  const items = groups[view] ?? [[view, title(view)]];
  return (
    <nav className="view-subnav" aria-label={`${title(view)} section navigation`}>
      <span className="view-subnav-label">Workspace</span>
      {items.map(([target, label]) => (
        <button key={`${target}-${label}`} className={target === view ? "active" : ""} onClick={() => target === "docs" && view === "overview" ? startTour() : target === "metrics" && view === "overview" ? viewMetrics() : select(target)} aria-current={target === view ? "page" : undefined}>
          {label}
        </button>
      ))}
    </nav>
  );
}

function Overview({
  stats,
  goMetrics,
  goSetup,
  goDemo,
  goCoding,
}: {
  stats: ReturnType<typeof makeStats>;
  goMetrics: () => void;
  goSetup: () => void;
  goDemo: () => void;
  goCoding: () => void;
}) {
  return (
    <>
      <section className="hero aurora-hero">
        <div>
          <p className="section-kicker">Connection intelligence</p>
          <h2>
            One clean OData bridge.
            <br />
            <em>Clear integration paths.</em>
          </h2>
          <p>
            Connect to FileMaker through OData in under two minutes. No Node.js
            setup or external application files are required for the PHP bridge.
          </p>
          <div className="hero-actions">
            <button className="button primary hero-primary-cta" onClick={goSetup}>
              <span className="hero-cta-arrow" aria-hidden="true">→</span>
              <span>Quick Start · Connect in Under 2 Minutes</span>
            </button>
            <button className="button secondary" onClick={goDemo}>Why use OpenBridge?</button>
          </div>
          <div className="trust-row" aria-label="OpenBridge security features">
            <span>HTTPS-ready</span>
            <span>Server-side credentials</span>
            <span>AES-GCM response encryption</span>
          </div>
        </div>
        <div className="hero-status">
          <div className="status-ring">Ready</div>
          <div>
            <span>Normalization contract</span>
            <strong>Records stay untouched</strong>
          </div>
        </div>
      </section>
      <RgcCommunityCard />
      <section className="quick-start-toolbar" aria-labelledby="quick-start-title">
        <div className="quick-start-heading">
          <div>
            <p className="section-kicker">Your fastest path to a working bridge</p>
            <h3 id="quick-start-title">Get connected and make your first request in under two minutes.</h3>
            <p>Choose where you want to begin. OpenBridge keeps the technical details organized while you work through one clear step at a time.</p>
          </div>
          <span className="quick-start-badge">3 simple starting points</span>
        </div>
        <div className="quick-path" aria-label="OpenBridge quick start">
          <button className="quick-action action-lime" onClick={goSetup}>
            <QuickActionIcon type="connect" />
            <span className="quick-action-copy"><strong>1 · Connect FileMaker</strong><small>Set up your server, database, OData table, and credentials in Setup FileMaker.</small><em>Open Setup →</em></span>
          </button>
          <button className="quick-action action-purple" onClick={goDemo}>
            <QuickActionIcon type="demo" />
            <span className="quick-action-copy"><strong>2 · View Live Demo</strong><small>Explore a simple OData example and test your connection without guessing where to start.</small><em>View the demo →</em></span>
          </button>
          <button className="quick-action action-orange" onClick={goCoding}>
            <QuickActionIcon type="build" />
            <span className="quick-action-copy"><strong>3 · Start Building</strong><small>Use the Coding Playground to create your first FileMaker connection with guided examples.</small><em>Open Coding Playground →</em>
            </span>
          </button>
        </div>
      </section>
      <WaveDivider />
      <section id="dashboard-metrics" className="bridge-health-shell" aria-label="Bridge health summary">
        <div className="bridge-health-heading">
          <div><p className="section-kicker">Operational overview</p><h3>Bridge Health</h3><p className="bridge-health-description">See how your connection is performing, which request types are being used, and how OpenBridge is storing its own system information.</p></div>
          <span className="live-chip">SQLite protected · {stats.total} requests</span>
        </div>
        <MetricCards stats={stats} />
        <section className="dashboard-grid">
        <div className="panel">
          <div className="panel-heading">
            <div>
              <p className="section-kicker">Outcome distribution</p>
              <h3>Transaction health</h3>
            </div>
            <button className="text-button" onClick={goMetrics}>
              Open metrics →
            </button>
          </div>
          <Donut success={stats.success} failure={stats.failure} />
        </div>
        <div className="panel">
          <p className="section-kicker">Storage model</p>
          <h3>Private SQLite diagnostics</h3>
          <p className="panel-description">
            OpenBridge stores its own settings, sessions, logs, modules, and
            workspaces in its protected SQLite system database. Browser storage
            is used only for short-lived interface state.
          </p>
          <div className="inline-warning">
            The database file is private to PHP and deliberately blocked from
            direct browser downloads.
          </div>
        </div>
        </section>
      </section>
    </>
  );
}

function QuickActionIcon({ type }: { type: "connect" | "demo" | "build" }) {
  return <span className={`quick-action-icon ${type}`} aria-hidden="true"><svg viewBox="0 0 48 48" role="presentation"><circle cx="24" cy="24" r="20" /><path d={type === "connect" ? "M15 24h17m-7-7 7 7-7 7" : type === "demo" ? "M18 16h12v16H18zm4 4h4m-4 5h4" : "M17 31 30 18m-8 0h8v8"} /></svg></span>;
}

function WaveDivider() {
  return <div className="wave-divider" aria-hidden="true"><svg viewBox="0 0 1200 100" preserveAspectRatio="none"><path className="wave-back" d="M0 57 C150 12 245 91 390 49 S650 8 790 54 1030 94 1200 36 V100 H0Z" /><path className="wave-glow" d="M0 68 C130 26 245 90 390 58 S650 20 790 60 1030 85 1200 45" /><path className="wave-front" d="M0 68 C130 26 245 90 390 58 S650 20 790 60 1030 85 1200 45" /><path className="wave-hot" d="M0 76 C150 44 242 86 390 65 S650 32 790 68 1045 77 1200 53" /></svg></div>;
}

function RgcCommunityCard() {
  return (
    <section className="rgc-community">
      <img src={brandLogoUrl} alt="OpenBridge" />
      <div>
        <p className="section-kicker">Created for the FileMaker community</p>
        <strong>OpenBridge</strong>
        <span>Created by Alex Seidler</span>
      </div>
      <div className="rgc-contact">
        <a href="mailto:alex@rgcdata.com">alex@rgcdata.com</a>
        <a href="https://www.linkedin.com/in/alex-seidler-9879583a2/" target="_blank" rel="noreferrer">LinkedIn</a>
      </div>
    </section>
  );
}
function makeStats(transactions: Transaction[]) {
  const success = transactions.filter(
    (item) => item.status === "success",
  ).length;
  const total = transactions.length;
  return {
    success,
    failure: total - success,
    total,
    rate: total ? Math.round((success / total) * 100) : 0,
    odata: transactions.filter((item) => item.transport === "odata").length,
    dataApi: transactions.filter((item) => item.transport === "data-api")
      .length,
  };
}

function storedTransactionToActivity(item: StoredTransaction): Transaction {
  return {
    id: item.id,
    serverId: item.id,
    status: item.status === "success" ? "success" : "failure",
    transport: item.transport as "data-api" | "odata",
    operation: item.operation,
    resource: item.resource,
    durationMs: item.durationMs,
    createdAt: item.createdAt,
    message: item.message,
    ...(item.responsePreview ? { responsePreview: item.responsePreview } : {}),
    payloadStored: item.payloadStored,
    legacyPlaintext: item.legacyPlaintext,
  };
}

function MetricCards({ stats }: { stats: ReturnType<typeof makeStats> }) {
  return (
    <section className="metric-grid">
      <Metric
        label="Successful"
        value={String(stats.success)}
        detail={`${stats.rate}% success rate`}
        tone="lime"
      />
      <Metric
        label="Failed"
        value={String(stats.failure)}
        detail="Review Activity for details"
        tone="coral"
      />
      <Metric
        label="OData"
        value={String(stats.odata)}
        detail="Native OData entities"
        tone="blue"
      />
      <Metric
        label="Total Requests"
        value={String(stats.total)}
        detail={stats.dataApi ? `${stats.dataApi} legacy request${stats.dataApi === 1 ? "" : "s"} included` : "OData activity logged"}
        tone="gold"
      />
    </section>
  );
}

function TransportTest({
  settings,
  inspect,
}: {
  settings: AdminSettings;
  inspect: (json: string) => void;
}) {
  const [result, setResult] = useState<TransportTestResult | null>(null);
  const [running, setRunning] = useState(false);
  const [error, setError] = useState("");
  const [copied, setCopied] = useState(false);
  const responseText = result ? JSON.stringify(result, null, 2) : "";
  const dataApi = result?.tests?.data_api;
  const odata = result?.tests?.odata;
  const availableTables = Array.isArray(odata?.available_tables)
    ? odata.available_tables.filter((item): item is string => typeof item === "string")
    : [];
  const metadataError = odata?.metadata_error;
  const odataFailureDetail =
    metadataError && typeof metadataError === "object"
      ? Object.entries(metadataError)
          .filter(([, value]) => typeof value === "string" && value.length > 0)
          .map(([key, value]) => `${key === "code" ? "FileMaker" : ""} ${String(value)}`.trim())
          .join(": ")
      : "";

  const run = async () => {
    setError("");
    setCopied(false);
    if (!settings.backendUrl) {
      setError("Add the PHP endpoint in Setup → Connection before running the test.");
      return;
    }

    setRunning(true);
    const diagnosticRequest = {
      action: "transport_test",
      endpoint: settings.backendUrl,
      limit: 3,
      note: settings.bridgeToken ? "Optional bridge token sent in a header and omitted here." : "Authenticated PHP admin session used; no bridge token needed.",
    };
    console.groupCollapsed("[OpenBridge TEMP] Sequential Data API + OData test");
    console.info("Sanitized backend request", diagnosticRequest);
    try {
      const next = await runTransportTest({
        endpoint: settings.backendUrl,
        bridgeToken: settings.bridgeToken,
        limit: 3,
      });
      setResult(next);
      console.info("Full sanitized backend response", next);
      if (!next.tests?.data_api?.ok)
        console.error("Data API test failed", next.tests?.data_api);
      if (!next.tests?.odata?.ok)
        console.error("OData test failed", next.tests?.odata);
      if (next.ok) console.info("Both FileMaker transports passed.");
    } catch (cause) {
      const message =
        cause instanceof DOMException && cause.name === "AbortError"
          ? "The sequential test exceeded 90 seconds. Check PHP and FileMaker timeouts."
          : cause instanceof Error
            ? cause.message
            : "The transport test failed before a response was returned.";
      setError(message);
      console.error("Transport test request failed", { ...diagnosticRequest, error: message });
    } finally {
      console.groupEnd();
      setRunning(false);
    }
  };

  const copy = async () => {
    if (!responseText) return;
    await navigator.clipboard.writeText(responseText);
    setCopied(true);
  };

  return (
    <section className="transport-test-layout">
      <div className="panel transport-test-hero">
        <div>
          <div className="row-between">
            <p className="section-kicker accent-kicker">Temporary live diagnostic</p>
            <span className="temporary-badge">Remove after validation</span>
          </div>
          <h3>Test Data API, then OData, against the same FileMaker database.</h3>
          <p className="panel-description">
            One click asks PHP to run both transports sequentially. It performs
            health checks, reads up to three records, discovers OData entity
            sets from <code>$metadata</code>, and returns the complete sanitized
            payload for inspection.
          </p>
        </div>
        <button className="button primary test-run-button" disabled={running} onClick={run}>
          {running ? "Testing both transports…" : "Run both transport tests"}
        </button>
      </div>

      <div className="inline-warning test-warning">
        This admin-only beta tool can display real record data in the page and
        browser console. Passwords, authorization headers, FileMaker session
        tokens, cookies, and the OpenBridge bridge token are intentionally omitted.
      </div>

      {error && <div className="inline-warning transport-test-error">{error}</div>}

      <div className="transport-result-grid">
        <article className={`panel transport-result ${dataApi?.ok ? "passed" : result ? "failed" : "idle"}`}>
          <div className="result-orb">D</div>
          <div>
            <p className="section-kicker">FileMaker Data API</p>
            <h3>{dataApi?.ok ? "Passed" : result ? "Needs attention" : "Waiting to run"}</h3>
            <p>Layout-based request using a short-lived server-side bearer session.</p>
          </div>
        </article>
        <article className={`panel transport-result ${odata?.ok ? "passed" : result ? "failed" : "idle"}`}>
          <div className="result-orb">O</div>
          <div>
            <p className="section-kicker">FileMaker OData v4</p>
            <h3>{odata?.ok ? "Passed" : result ? "Needs attention" : "Waiting to run"}</h3>
            <p>{odataFailureDetail || "Metadata discovery followed by a table/entity-set read."}</p>
          </div>
        </article>
      </div>

      {availableTables.length > 0 && (
        <div className="panel discovered-tables">
          <div>
            <p className="section-kicker">Discovered by OData $metadata</p>
            <h3>Available entity sets</h3>
          </div>
          <div className="table-chip-list">
            {availableTables.map((table) => <span key={table}>{table}</span>)}
          </div>
        </div>
      )}

      <section className="panel command-examples">
        <div className="panel-heading">
          <div>
            <p className="section-kicker">Basic PHP commands</p>
            <h3>Safe starting requests</h3>
          </div>
        </div>
        <div className="command-grid">
          <article>
            <strong>Data API · list three</strong>
            <pre>{`$bridge->playground('list', [
  'transport' => 'data_api',
  'layout' => 'Contact Details',
  'limit' => 3,
]);`}</pre>
          </article>
          <article>
            <strong>Data API · basic find</strong>
            <pre>{`$bridge->playground('find', [
  'transport' => 'data_api',
  'payload' => ['query' => [
    ['Last Name' => 'Smith']
  ]],
]);`}</pre>
          </article>
          <article>
            <strong>OData · list three</strong>
            <pre>{`$bridge->listODataRecords([
  'table' => 'YOUR_ENTITY_SET',
  'queryOptions' => ['$top' => 3],
]);`}</pre>
          </article>
          <article>
            <strong>OData · filtered find</strong>
            <pre>{`$bridge->findODataRecords(
  "Name eq 'Alex'",
  ['table' => 'YOUR_ENTITY_SET']
);`}</pre>
          </article>
          <article>
            <strong>Data API · run script</strong>
            <pre>{`$bridge->runScript(
  'Your Script Name',
  '{"source":"OpenBridge"}'
);`}</pre>
          </article>
          <article>
            <strong>OData · run script</strong>
            <pre>{`$bridge->runODataScript(
  'YourScriptName',
  ['source' => 'OpenBridge']
);`}</pre>
          </article>
        </div>
      </section>

      <section className="panel full-test-response">
        <div className="panel-heading">
          <div>
            <p className="section-kicker">Complete sanitized response</p>
            <h3>Backend payload and FileMaker responses</h3>
          </div>
          <div className="response-actions">
            <button className="button secondary" disabled={!result} onClick={copy}>
              {copied ? "Copied" : "Copy response"}
            </button>
            <button className="button secondary" disabled={!result} onClick={() => inspect(responseText)}>
              Inspect response
            </button>
          </div>
        </div>
        <pre className="json-output transport-test-output">
          {responseText || "Run the test to display both complete transport results."}
        </pre>
      </section>
    </section>
  );
}

function ConnectionHealthView({
  settings,
  fixConnection,
}: {
  settings: AdminSettings;
  fixConnection: () => void;
}) {
  const [result, setResult] = useState<ConnectionHealth | null>(null);
  const [running, setRunning] = useState(false);
  const [error, setError] = useState("");
  const run = async () => {
    setError("");
    const localChecks = [
      {
        name: "server" as const,
        ok: Boolean(settings.serverUrl),
        label: "Server address",
        detail:
          settings.serverUrl || "Add a FileMaker Server URL in Setup → Connection.",
      },
      {
        name: "database" as const,
        ok: Boolean(settings.database),
        label: "Database",
        detail: settings.database || "Database name is missing.",
      },
      {
        name: "resource" as const,
        ok: Boolean(settings.defaultLayout),
        label: settings.transport === "odata" ? "Table" : "Layout",
        detail: settings.defaultLayout || "Default layout or table is missing.",
      },
      {
        name: "api" as const,
        ok: Boolean(settings.backendUrl),
        label: "OpenBridge PHP endpoint",
        detail:
          settings.backendUrl
            ? "Endpoint is present; the authenticated PHP session authorizes browser requests."
            : "Complete Setup → Connection.",
      },
    ];
    if (localChecks.some((check) => !check.ok)) {
      setResult({
        ok: false,
        transport: settings.transport,
        checkedAt: new Date().toISOString(),
        latencyMs: null,
        checks: [
          ...localChecks,
          {
            name: "latency",
            ok: false,
            label: "Latency",
            detail: "Not measured until configuration preflight passes.",
          },
        ],
      });
      return;
    }
    setRunning(true);
    try {
      setResult(
        await checkConnectionHealth({
          endpoint: settings.backendUrl,
          bridgeToken: settings.bridgeToken,
          mode: settings.transport,
          serverUrl: settings.serverUrl,
          database: settings.database,
          resource: settings.defaultLayout,
        }),
      );
    } catch (reason) {
      setResult(null);
      setError(explainConnectionFailure(reason));
    } finally {
      setRunning(false);
    }
  };
  const checks = result?.checks ?? healthPlaceholders(settings.transport);
  return (
    <section className="health-layout">
      <div className="panel health-summary">
        <div>
          <p className="section-kicker">End-to-end diagnostics</p>
          <h3>One diagnostic. Clear next actions.</h3>
          <p className="panel-description">
            OpenBridge validates the secure PHP bridge, FileMaker
            authentication, selected API, database, layout or table, and
            latency. Credentials and bridge tokens remain session-only in the
            browser.
          </p>
        </div>
        <button
          className="button primary diagnostic-button"
          onClick={run}
          disabled={running}
        >
          {running ? "Running secure diagnostic…" : "Run secure diagnostic"}
        </button>
      </div>
      <div className="health-terminal" aria-live="polite">
        <div className="terminal-bar">
          <span />
          <span />
          <span />
          <strong>OpenBridge connection doctor</strong>
        </div>
        {error ? (
          <div className="terminal-error">
            <code>[ERROR] {error}</code>
            <button className="button gold" onClick={fixConnection}>
              Review connection settings
            </button>
          </div>
        ) : (
          <div className="terminal-lines">
            {checks.map((check) => (
              <div className="terminal-line" key={check.name}>
                <span
                  className={`terminal-status ${result ? (check.ok ? "pass" : "fail") : "idle"}`}
                >
                  {result ? (check.ok ? "PASS" : "FIX") : "WAIT"}
                </span>
                <div>
                  <strong>{check.label}</strong>
                  <p>{check.detail}</p>
                </div>
                {check.latencyMs !== undefined && (
                  <code>{check.latencyMs} ms</code>
                )}
                {result && !check.ok && (
                  <button
                    className="terminal-fix"
                    onClick={fixConnection}
                  >
                    Fix in Setup FileMaker →
                  </button>
                )}
              </div>
            ))}
          </div>
        )}
      </div>
      {result && (
        <div className={`health-result ${result.ok ? "healthy" : "attention"}`}>
          <div>
            <strong>
              {result.ok
                ? "All required checks passed."
                : "Configuration needs attention."}
            </strong>
            <span>
              {result.transport === "odata" ? "OData" : "Data API"} ·{" "}
              {new Date(result.checkedAt).toLocaleString()}
              {result.latencyMs !== null
                ? ` · ${result.latencyMs} ms total`
                : ""}
            </span>
          </div>
          {!result.ok && (
            <button className="button gold" onClick={fixConnection}>
              Open Setup
            </button>
          )}
        </div>
      )}
    </section>
  );
}

function healthPlaceholders(transport: Transport): ConnectionHealth["checks"] {
  return [
    {
      name: "server",
      ok: false,
      label: "Server address",
      detail: "HTTPS host responds.",
    },
    {
      name: "credentials",
      ok: false,
      label: "Credentials",
      detail: "FileMaker authentication succeeds.",
    },
    {
      name: "api",
      ok: false,
      label: `${transport === "odata" ? "OData" : "Data API"} availability`,
      detail: "The selected connector is enabled and authorized.",
    },
    {
      name: "database",
      ok: false,
      label: "Database",
      detail: "The hosted file is available to this account.",
    },
    {
      name: "resource",
      ok: false,
      label: transport === "odata" ? "Table" : "Layout",
      detail: "The configured resource exists and is accessible.",
    },
    {
      name: "latency",
      ok: false,
      label: "Latency",
      detail: "End-to-end response time is measured.",
    },
  ];
}

function playgroundTemplates(
  transport: Transport,
): Record<
  PlaygroundOperation,
  {
    label: string;
    method: string;
    description: string;
    payload: Record<string, unknown>;
  }
> {
  return {
    list: {
      label: "List the first records",
      method: "GET",
      description: "The smallest useful read request with paging.",
      payload:
        transport === "odata"
          ? { top: 10, skip: 0, count: true }
          : { limit: 10, offset: 1 },
    },
    find: {
      label: "Find active records",
      method: transport === "odata" ? "GET" : "POST",
      description: "A practical find—the most important request to customize.",
      payload:
        transport === "odata"
          ? { filter: "Status eq 'Active'", top: 10, count: true }
          : { query: [{ Status: "Active" }], limit: 10, offset: 1 },
    },
    get: {
      label: "Get one record",
      method: "GET",
      description: "Fetch a known FileMaker record or OData entity key.",
      payload: transport === "odata" ? { key: "1" } : { recordId: "1" },
    },
    script: {
      label: "Run a FileMaker script",
      method: "POST",
      description: "Run a server-side script with an optional parameter.",
      payload: {
        scriptName: "OpenBridge Demo",
        scriptParameter: { source: "playground" },
      },
    },
  };
}

function ApiPlayground({
  settings,
  inspect,
}: {
  settings: AdminSettings;
  inspect: (json: string) => void;
}) {
  const [operation, setOperation] = useState<PlaygroundOperation>("find");
  const [mode, setMode] = useState<"sample" | "live">("sample");
  const [resource, setResource] = useState(
    settings.defaultLayout || "Customers",
  );
  const [payload, setPayload] = useState(
    JSON.stringify(
      playgroundTemplates(settings.transport).find.payload,
      null,
      2,
    ),
  );
  const [afterScript, setAfterScript] = useState("");
  const [afterParameter, setAfterParameter] = useState("");
  const [response, setResponse] = useState("");
  const [error, setError] = useState("");
  const [running, setRunning] = useState(false);
  const templates = playgroundTemplates(settings.transport);
  const template = templates[operation];
  const choose = (key: PlaygroundOperation) => {
    setOperation(key);
    setPayload(JSON.stringify(templates[key].payload, null, 2));
    setError("");
    setResponse("");
  };
  useEffect(() => {
    setPayload(
      JSON.stringify(
        playgroundTemplates(settings.transport)[operation].payload,
        null,
        2,
      ),
    );
  }, [settings.transport, operation]);
  const run = async () => {
    try {
      const parameters = JSON.parse(payload) as Record<string, unknown>;
      setRunning(true);
      if (mode === "live") {
        if (!settings.backendUrl)
          throw new Error(
            "Add the PHP endpoint in Setup → Connection before running live requests.",
          );
        const live = await runPlaygroundRequest({
          endpoint: settings.backendUrl,
          bridgeToken: settings.bridgeToken,
          transport: settings.transport,
          operation,
          resource,
          parameters,
          ...(afterScript && operation !== "script"
            ? {
                afterScript: {
                  name: afterScript,
                  ...(afterParameter
                    ? { parameter: parseLooseJson(afterParameter) }
                    : {}),
                },
              }
            : {}),
        });
        setResponse(JSON.stringify(live, null, 2));
        setError("");
        return;
      }
      const raw =
        settings.transport === "odata" ? sampleODataRaw : sampleDataApiRaw;
      const context = {
        operation: template.label,
        database: settings.database || "OpenBridge Demo",
        resource: resource || "Customers",
      };
      const normalized =
        settings.transport === "odata"
          ? normalizeOData(raw, context)
          : normalizeDataApi(raw, context);
      setResponse(
        JSON.stringify(
          {
            sample: true,
            request: {
              transport: settings.transport,
              operation,
              method: template.method,
              resource,
              parameters,
              ...(afterScript && operation !== "script"
                ? {
                    afterScript: {
                      name: afterScript,
                      parameter: parseLooseJson(afterParameter),
                    },
                  }
                : {}),
            },
            response: normalized,
            ...(afterScript && operation !== "script"
              ? {
                  afterScriptResponse: {
                    messages: [
                      { code: "0", message: "Sample script completed." },
                    ],
                    response: { scriptResult: "OpenBridge sample result" },
                  },
                }
              : {}),
          },
          null,
          2,
        ),
      );
      setError("");
    } catch (reason) {
      setError(
        reason instanceof Error
          ? reason.message
          : "The request payload must be valid JSON.",
      );
    } finally {
      setRunning(false);
    }
  };
  return (
    <section className="playground-layout">
      <div className="panel template-panel">
        <p className="section-kicker">Basic requests</p>
        <h3>Start with something useful.</h3>
        <p className="panel-description">
          Choose a safe read, find, record, or script example. Finds are
          deliberately front and center because they are the core of most
          FileMaker web integrations.
        </p>
        <div className="template-list">
          {(Object.keys(templates) as PlaygroundOperation[]).map((key) => (
            <button
              className={operation === key ? "selected" : ""}
              key={key}
              onClick={() => choose(key)}
            >
              <strong>{templates[key].label}</strong>
              <span>{templates[key].description}</span>
            </button>
          ))}
        </div>
        <div className="playground-safety">
          <strong>Live beta allowlist</strong>
          <span>
            Read, find, get, and scripts only. No browser-triggered delete or
            arbitrary write operations.
          </span>
        </div>
      </div>
      <div className="panel playground-main">
        <div className="panel-heading">
          <div>
            <p className="section-kicker">Request workbench</p>
            <h3>API Playground</h3>
          </div>
          <div className="mode-switch">
            <button
              className={mode === "sample" ? "active" : ""}
              onClick={() => setMode("sample")}
            >
              Sample
            </button>
            <button
              className={mode === "live" ? "active" : ""}
              onClick={() => setMode("live")}
            >
              My database
            </button>
          </div>
        </div>
        <div className="playground-controls">
          <span className="method-chip">{template.method}</span>
          <label className="field">
            {settings.transport === "odata" ? "Table" : "Layout"}
            <input
              value={resource}
              onChange={(event) => setResource(event.target.value)}
            />
          </label>
        </div>
        <label className="field">
          Parameters
          <textarea
            className="json-editor compact"
            value={payload}
            onChange={(event) => setPayload(event.target.value)}
            spellCheck="false"
          />
        </label>
        {operation !== "script" && (
          <details className="after-script">
            <summary>Run a FileMaker script after this request</summary>
            <div className="field-grid">
              <label className="field">
                Script name
                <input
                  value={afterScript}
                  onChange={(event) => setAfterScript(event.target.value)}
                  placeholder="Optional post-request script"
                />
              </label>
              <label className="field">
                Script parameter
                <input
                  value={afterParameter}
                  onChange={(event) => setAfterParameter(event.target.value)}
                  placeholder='Text or JSON, e.g. {"source":"web"}'
                />
              </label>
            </div>
            <p>
              OpenBridge finishes the selected request first, then runs this
              server-side script and returns both responses separately.
            </p>
          </details>
        )}
        {error && <p className="inline-warning">{error}</p>}
        <div className="form-actions">
          <button className="button primary" onClick={run} disabled={running}>
            {running
              ? "Running…"
              : `Run ${mode === "live" ? "on my database" : "sample request"}`}
          </button>
        </div>
        {response && (
          <div className="playground-response">
            <div className="row-between">
              <strong>Request and response</strong>
              <button className="text-button" onClick={() => inspect(response)}>
                Inspect response
              </button>
            </div>
            <pre className="json-output compact">{response}</pre>
          </div>
        )}
        <p className="supporting-text playground-note">
          Live browser requests use the authenticated PHP admin session while
          FileMaker credentials stay server-side. Bridge tokens are optional
          for non-browser API clients.
        </p>
      </div>
    </section>
  );
}

function parseLooseJson(value: string): unknown {
  if (!value.trim()) return "";
  try {
    return JSON.parse(value);
  } catch {
    return value;
  }
}

function SchemaExplorer({ settings }: { settings: AdminSettings }) {
  const [selected, setSelected] = useState("Customers");
  const [liveStatus, setLiveStatus] = useState("");
  const [loadingLive, setLoadingLive] = useState(false);
  const schema = [
    {
      name: "Customers",
      type: "Table",
      fields: [
        "CustomerID · Number",
        "Name · Text",
        "Status · Text",
        "Email · Text",
      ],
    },
    {
      name: "Inventory",
      type: "Table",
      fields: [
        "ItemID · Number",
        "Description · Text",
        "Quantity · Number",
        "ModifiedAt · Timestamp",
      ],
    },
    {
      name: "API_Customers",
      type: "Layout",
      fields: ["CustomerID", "Name", "Status", "Email"],
    },
    {
      name: "OpenBridge Demo",
      type: "Script",
      fields: [
        "Parameter: JSON or text",
        "Result: JSON or text",
        "Runs on FileMaker Server",
      ],
    },
  ];
  const item = schema.find((entry) => entry.name === selected) ?? schema[0];
  const loadLiveSchema = async () => {
    setLoadingLive(true);
    setLiveStatus("");
    try {
      const snapshot = await loadSchemaSnapshot(settings.backendUrl, settings.transport);
      const entries = settings.transport === "odata"
        ? (Array.isArray(snapshot.tables) ? snapshot.tables : [])
        : ["layouts", "scripts"].flatMap((key) => {
            const item = snapshot[key];
            return Array.isArray(item) ? item : [];
          });
      setLiveStatus(entries.length
        ? `Live metadata loaded: ${entries.length} item${entries.length === 1 ? "" : "s"} found. The detailed browser stays intentionally read-only.`
        : "The bridge responded, but no readable schema items were returned. Check the selected FileMaker account permissions.");
    } catch (error) {
      setLiveStatus(error instanceof Error ? error.message : "Live schema request did not complete.");
    } finally {
      setLoadingLive(false);
    }
  };
  return (
    <section className="schema-layout">
      <div className="panel premium-hero">
        <span className="premium-badge">Premium · future</span>
        <p className="section-kicker">Interactive concept</p>
        <h3>Explore the solution before writing code.</h3>
        <p>
          The visual browser remains an illustrative preview, while the
          installed PHP bridge can now make a read-only live metadata request.
          It never changes your FileMaker solution or exposes credentials.
        </p>
        <button className="button secondary" onClick={loadLiveSchema} disabled={loadingLive || !settings.backendUrl}>
          {loadingLive ? "Loading live metadata…" : "Check live schema access"}
        </button>
        {liveStatus && <p className="supporting-text">{liveStatus}</p>}
      </div>
      <div className="schema-demo">
        <aside className="schema-tree">
          <div className="schema-tree-heading">
            <strong>OpenBridge Demo</strong>
            <span>{settingsLabelForSchema()}</span>
          </div>
          {schema.map((entry) => (
            <button
              className={selected === entry.name ? "active" : ""}
              key={entry.name}
              onClick={() => setSelected(entry.name)}
            >
              <span>
                {entry.type === "Table"
                  ? "▦"
                  : entry.type === "Layout"
                    ? "▤"
                    : "▶"}
              </span>
              <div>
                <strong>{entry.name}</strong>
                <span className="schema-type">{entry.type}</span>
              </div>
            </button>
          ))}
        </aside>
        <section className="schema-detail">
          <div className="row-between">
            <div>
              <p className="section-kicker">{item.type} preview</p>
              <h3>{item.name}</h3>
            </div>
            <span className="lock-chip">Future premium</span>
          </div>
          <div className="field-preview">
            {item.fields.map((field, index) => (
              <div key={field}>
                <code>{String(index + 1).padStart(2, "0")}</code>
                <strong>{field}</strong>
                <span>
                  {item.type === "Script"
                    ? "Callable metadata"
                    : "Original FileMaker name preserved"}
                </span>
              </div>
            ))}
          </div>
        </section>
      </div>
      <div className="inline-warning">
        Potential future feature: Data API layouts/scripts and OData EDMX
        metadata would be shown differently and labeled by source. OpenBridge
        will never silently rename fields.
      </div>
    </section>
  );
}

function settingsLabelForSchema() {
  return "Illustrative metadata · no live request";
}

function PresentationDemo({
  goSetup,
  goTest,
  goMetrics,
  goDocs,
  openSample,
}: {
  goSetup: () => void;
  goTest: () => void;
  goMetrics: () => void;
  goDocs: () => void;
  openSample: (transport: Transport) => void;
}) {
  const story = [
    ["01", "Connect", "Configure one protected OData connection to FileMaker.", goSetup],
    ["02", "Test", "Run a read-only OData request and inspect the normalized response.", goTest],
    ["03", "Observe", "Show latency, success rate, and bounded diagnostic activity.", goMetrics],
    ["04", "Integrate", "Choose one outside service or open copy-ready OData examples.", goDocs],
  ] as const;
  return (
    <section className="presentation-layout">
      <div className="panel presentation-hero">
        <div>
          <p className="section-kicker">Presentation mode</p>
          <h3>Show the OpenBridge story in five minutes.</h3>
          <p>
            One server-side bridge gives developers a friendlier FileMaker OData
            workflow, normalized responses, diagnostics, integration planning,
            and a browser control plane—without renaming FileMaker fields.
          </p>
        </div>
        <div className="presentation-callout">
          <strong>The one-line value proposition</strong>
          <span>Connect FileMaker once. Route clean OData through one understandable control panel.</span>
        </div>
      </div>
      <div className="demo-sequence" aria-label="Four-part presentation sequence">
        {story.map(([number, label, detail, action], index) => (
          <article key={number}>
            <span>{number}</span>
            <strong>{label}</strong>
            <p>{detail}</p>
            <button className="text-link" onClick={action}>Show this <b aria-hidden="true">→</b></button>
            {index < story.length - 1 && <i aria-hidden="true">→</i>}
          </article>
        ))}
      </div>
      <div className="docs-grid demo-comparison">
        <article className="panel">
          <p className="section-kicker">OData · table based</p>
          <h3>A clear filtered read</h3>
          <pre className="mini-code">{`$result = $fm->findODataRecords(\n  "Status eq 'Active' and startswith(LastName,'S')",\n  ['table' => 'Contacts', 'top' => 25]\n);`}</pre>
          <button className="button secondary" onClick={() => openSample("odata")}>Inspect sample response</button>
        </article>
        <article className="panel">
          <p className="section-kicker">OpenBridge Router</p>
          <h3>FileMaker → middleware → one integration</h3>
          <p className="panel-description">The browser chooses the workflow. PHP holds credentials, validates allowed actions, calls FileMaker and the selected service, normalizes results, and records safe activity summaries.</p>
          <button className="button secondary" onClick={goDocs}>See the integration model</button>
        </article>
      </div>
      <div className="security-facts">
        <article><strong>TLS in transit</strong><span>Production traffic must use HTTPS. Payload encryption does not replace TLS.</span></article>
        <article><strong>AES-256-GCM at rest</strong><span>Optional retained response bodies are handled through authenticated PHP storage.</span></article>
        <article><strong>Memory-first browser log</strong><span>The dashboard keeps activity in memory and reloads server summaries when PHP storage is available.</span></article>
      </div>
    </section>
  );
}

function Capabilities({ goDocs }: { goDocs: () => void }) {
  const rows = [
    [
      "Browse model",
      "Layouts, scripts, layout fields",
      "Tables and EDMX metadata",
    ],
    [
      "Read and find",
      "Records, ranges, compound finds",
      "$filter, $select, $orderby, $top, $skip",
    ],
    [
      "Write records",
      "Create, edit, duplicate, delete",
      "Create, patch, delete",
    ],
    [
      "Scripts",
      "Dedicated endpoint and request script hooks",
      "Script action with optional parameter",
    ],
    [
      "Containers",
      "Multipart upload to a layout field",
      "Container operations supported",
    ],
    [
      "Pagination",
      "offset / limit plus dataInfo counts",
      "$skip / $top and @odata.nextLink",
    ],
    [
      "Batch / schema changes",
      "Not a general batch or schema API",
      "OData batch and supported schema operations",
    ],
    [
      "Normalization",
      "Stable envelope; fieldData unchanged",
      "Stable envelope; entity properties unchanged",
    ],
  ];
  return (
    <section className="panel">
      <div className="panel-heading">
        <div>
          <p className="section-kicker">Transport guide</p>
          <h3>Capabilities at a glance</h3>
          <p className="panel-description">
            OpenBridge gives both transports the same outer response envelope
            without renaming fields or flattening records.
          </p>
        </div>
        <button className="button gold" onClick={goDocs}>
          Open docs &amp; samples
        </button>
      </div>
      <div className="capability-table" role="table">
        <div className="capability-row header" role="row">
          <strong>Operation</strong>
          <strong>Data API</strong>
          <strong>OData</strong>
        </div>
        {rows.map((row) => (
          <div className="capability-row" role="row" key={row[0]}>
            <strong>{row[0]}</strong>
            <span>{row[1]}</span>
            <span>{row[2]}</span>
          </div>
        ))}
      </div>
      <p className="inline-warning">
        Actual access still depends on FileMaker Server settings, the account
        privilege set, hosted-file configuration, and the selected layout or
        table.
      </p>
    </section>
  );
}

function DocsHub({ initial }: { initial: "quick" | "ai" }) {
  return <DocsPage initial={initial} />;
}

function InProgress() {
  return (
    <section className="progress-layout">
      <div className="panel progress-hero">
        <span className="progress-badge">New features in progress</span>
        <p className="section-kicker">OpenBridge roadmap</p>
        <h3>Designed with an open door—not a tangled dependency.</h3>
        <p>
          The core PHP, Data API, OData, admin, and future FileMaker Web Viewer
          paths stay compartmentalized so each can evolve without destabilizing
          the others.
        </p>
      </div>
      <div className="roadmap-grid">
        <article className="roadmap-card">
          <span>01</span>
          <div>
            <strong>Web Viewer Bridge</strong>
            <p>
              An isolated adapter for future FileMaker Web Viewer and
              ProofKit-style communication. Planned later; not coupled to the
              current browser/PHP bridge.
            </p>
          </div>
          <em>Research</em>
        </article>
        <article className="roadmap-card">
          <span>02</span>
          <div>
            <strong>Premium Schema Explorer</strong>
            <p>
              Permission-aware live metadata browsing, relationships, scripts,
              layouts, and typed OData entities.
            </p>
          </div>
          <em>Concept</em>
        </article>
        <article className="roadmap-card">
          <span>03</span>
          <div>
            <strong>Saved Request Templates</strong>
            <p>
              Team-managed request presets with validation, safe sharing, and
              environment overrides.
            </p>
          </div>
          <em>Planned</em>
        </article>
      </div>
    </section>
  );
}

function AboutPage() {
  return (
    <section className="about-layout">
      <div className="panel about-hero">
        <img src={brandLogoUrl} alt="OpenBridge" />
        <div>
          <p className="section-kicker">Free for the FileMaker community</p>
          <h3>Built by Alex Seidler.</h3>
          <p>
            Alex Seidler works across FileMaker, APIs, web applications,
            mobile solutions, and agent-assisted software development.
            OpenBridge is released as a practical starting point for teams
            that need FileMaker and modern applications to communicate clearly.
          </p>
        </div>
      </div>
      <div className="about-grid">
        <article className="panel">
          <p className="section-kicker">Why it exists</p>
          <h3>Make integration easier to understand.</h3>
          <p className="panel-description">
            The project grew from the proven restCWP PHP class and repeated
            real-world integration work. The beta adds a friendlier API,
            transport-aware diagnostics, consistent response normalization,
            and a visual control panel without changing FileMaker field names.
          </p>
        </article>
        <article className="panel">
          <p className="section-kicker">How it was created</p>
          <h3>Human direction, agentic implementation.</h3>
          <p className="panel-description">
            Alex defined the product, workflows, security boundaries, design
            language, and FileMaker requirements. An agentic coding workflow
            helped implement, review, refactor, test, document, and verify the
            React and PHP layers.
          </p>
        </article>
        <article className="panel about-contact-card">
          <p className="section-kicker">Work with Alex</p>
          <h3>Need OpenBridge adapted to your solution?</h3>
          <p className="panel-description">
            Alex is available for FileMaker, web, mobile, API, and
            cross-platform integration consulting.
          </p>
          <div className="docs-actions">
            <a className="button secondary" href="mailto:alex@rgcdata.com">Email Alex</a>
            <a className="button gold" href="https://www.linkedin.com/in/alex-seidler-9879583a2/" target="_blank" rel="noreferrer">LinkedIn</a>
          </div>
        </article>
      </div>
    </section>
  );
}

function SectionTabs<T extends string>({
  label,
  active,
  items,
  select,
}: {
  label: string;
  active: T;
  items: { value: T; label: string; detail: string }[];
  select: (value: T) => void;
}) {
  return (
    <nav className="section-tabs" aria-label={label}>
      {items.map((item, index) => (
        <button
          key={item.value}
          className={`${active === item.value ? "active" : ""} tab-accent-${index % 4}`}
          aria-current={active === item.value ? "page" : undefined}
          onClick={() => select(item.value)}
        >
          <strong>{item.label}</strong>
          <span>{item.detail}</span>
        </button>
      ))}
    </nav>
  );
}

function SetupHub({
  active,
  select,
  settings,
  update,
  save,
  prepare,
}: {
  active: "admin" | "studio" | "security";
  select: (value: "admin" | "studio" | "security") => void;
  settings: AdminSettings;
  update: (value: Partial<AdminSettings>) => void;
  save: () => void;
  prepare: () => Promise<void>;
}) {
  const tabs = [
    { value: "admin", label: "1 · Connection" },
    { value: "studio", label: "2 · Preferences" },
    { value: "security", label: "3 · Security" },
  ] as const;
  return (
    <section className="setup-sheet" aria-label="Setup FileMaker">
      <header className="setup-sheet-heading"><h2>Setup FileMaker</h2></header>
      <div className="setup-tabs" role="tablist" aria-label="Setup sections">
        {tabs.map((tab, index) => (
          <button
            key={tab.value}
            id={`setup-tab-${tab.value}`}
            type="button"
            role="tab"
            aria-selected={active === tab.value}
            aria-controls={`setup-panel-${tab.value}`}
            tabIndex={active === tab.value ? 0 : -1}
            onClick={() => select(tab.value)}
            onKeyDown={(event) => {
              let next = index;
              if (event.key === "ArrowRight") next = (index + 1) % tabs.length;
              else if (event.key === "ArrowLeft") next = (index + tabs.length - 1) % tabs.length;
              else if (event.key === "Home") next = 0;
              else if (event.key === "End") next = tabs.length - 1;
              else return;
              event.preventDefault();
              select(tabs[next].value);
              document.getElementById(`setup-tab-${tabs[next].value}`)?.focus();
            }}
          >{tab.label}</button>
        ))}
      </div>
      <div className="setup-tab-content" role="tabpanel" id={`setup-panel-${active}`} aria-labelledby={`setup-tab-${active}`} tabIndex={0}>
        {active === "admin" && <AdminSettings settings={settings} update={update} save={save} />}
        {active === "studio" && <SettingsStudio settings={settings} update={update} save={save} prepare={prepare} />}
        {active === "security" && <SecurityCenter settings={settings} update={update} save={save} />}
      </div>
    </section>
  );
}

function DebugHub({
  active,
  select,
  settings,
  openSetup,
  inspect,
}: {
  active: "health" | "test" | "playground";
  select: (value: "health" | "test" | "playground") => void;
  settings: AdminSettings;
  openSetup: (value: "admin" | "security") => void;
  inspect: (json: string) => void;
}) {
  return (
    <section className="hub-layout">
      <SectionTabs
        label="Debug testing tools"
        active={active}
        select={select}
        items={[
          { value: "health", label: "Connection Health", detail: "Validate the OData connection" },
          { value: "playground", label: "Read-only OData Test", detail: "Send one controlled sample request" },
        ]}
      />
      {active === "health" && (
        <ConnectionHealthView
          settings={settings}
          fixConnection={() => openSetup("admin")}
        />
      )}
      {active === "test" && <TransportTest settings={settings} inspect={inspect} />}
      {active === "playground" && <ApiPlayground settings={settings} inspect={inspect} />}
    </section>
  );
}

function CapabilitiesHub({
  active,
  select,
  goDocs,
  settings,
}: {
  active: "schema" | "capabilities" | "progress";
  select: (value: "schema" | "capabilities" | "progress") => void;
  goDocs: () => void;
  settings: AdminSettings;
}) {
  return (
    <section className="hub-layout">
      <SectionTabs
        label="Capabilities and roadmap"
        active={active}
        select={select}
        items={[
          { value: "capabilities", label: "Capabilities", detail: "Compare supported operations" },
          { value: "schema", label: "Schema Explorer", detail: "Premium feature preview" },
          { value: "progress", label: "Roadmap", detail: "Features in progress" },
        ]}
      />
      {active === "capabilities" && <Capabilities goDocs={goDocs} />}
      {active === "schema" && <SchemaExplorer settings={settings} />}
      {active === "progress" && <InProgress />}
    </section>
  );
}

function MetricsHub({
  active,
  select,
  transactions,
  open,
  clear,
  exportLogs,
}: {
  active: "metrics" | "activity";
  select: (value: "metrics" | "activity") => void;
  transactions: Transaction[];
  open: (transaction: Transaction) => void;
  clear: () => void;
  exportLogs: (format: "json" | "csv") => void;
}) {
  const installDate = useMemo(() => {
    const earliest = transactions
      .map((item) => new Date(item.createdAt))
      .filter((date) => !Number.isNaN(date.getTime()))
      .sort((a, b) => a.getTime() - b.getTime())[0];
    try {
      const saved = localStorage.getItem("openbridge-install-date-v1");
      if (saved) {
        const parsed = new Date(saved);
        if (!Number.isNaN(parsed.getTime())) return parsed;
      }
      const initial = earliest || new Date();
      localStorage.setItem("openbridge-install-date-v1", initial.toISOString());
      return initial;
    } catch {
      return earliest || new Date();
    }
  }, [transactions]);
  const weekOptions = useMemo(() => {
    const cursor = new Date(installDate);
    const day = cursor.getDay();
    cursor.setDate(cursor.getDate() - (day === 0 ? 6 : day - 1));
    cursor.setHours(0, 0, 0, 0);
    const end = new Date();
    const options: { value: string; label: string; start: Date }[] = [];
    while (cursor <= end) {
      const start = new Date(cursor);
      const finish = new Date(start);
      finish.setDate(finish.getDate() + 6);
      options.push({
        value: start.toISOString().slice(0, 10),
        label: `${start.toLocaleDateString(undefined, { month: "short", day: "numeric" })} – ${finish.toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" })}`,
        start,
      });
      cursor.setDate(cursor.getDate() + 7);
    }
    return options;
  }, [installDate]);
  const [fromWeek, setFromWeek] = useState(weekOptions[0]?.value || "");
  const [toWeek, setToWeek] = useState(weekOptions.at(-1)?.value || "");
  useEffect(() => {
    if (!fromWeek && weekOptions[0]) setFromWeek(weekOptions[0].value);
    if (weekOptions.at(-1) && !weekOptions.some((week) => week.value === toWeek)) setToWeek(weekOptions.at(-1)!.value);
    if (fromWeek && toWeek && toWeek < fromWeek) setToWeek(fromWeek);
  }, [fromWeek, toWeek, weekOptions]);
  const rangedTransactions = useMemo(() => {
    const start = weekOptions.find((week) => week.value === fromWeek)?.start;
    const selectedEnd = weekOptions.find((week) => week.value === toWeek)?.start;
    if (!start || !selectedEnd) return transactions;
    const end = new Date(selectedEnd);
    end.setDate(end.getDate() + 7);
    return transactions.filter((item) => {
      const created = new Date(item.createdAt);
      return created >= start && created < end;
    });
  }, [fromWeek, toWeek, transactions, weekOptions]);
  const rangeStats = useMemo(() => makeStats(rangedTransactions), [rangedTransactions]);
  return (
    <section className="hub-layout">
      <SectionTabs
        label="Metrics and activity"
        active={active}
        select={select}
        items={[
          { value: "metrics", label: "Metrics", detail: "Success, failure, volume, and transport mix" },
          { value: "activity", label: "Activity Log", detail: `${transactions.length} retained transaction${transactions.length === 1 ? "" : "s"}` },
        ]}
      />
      {active === "metrics" && (
        <>
          <div className="panel metrics-range">
            <div>
              <p className="section-kicker">Reporting period</p>
              <h3>Choose a week range</h3>
              <p className="panel-description">Weeks are available from this browser’s OpenBridge installation date.</p>
            </div>
            <label className="field">From week<select value={fromWeek} onChange={(event) => setFromWeek(event.target.value)}>{weekOptions.map((week) => <option key={week.value} value={week.value}>{week.label}</option>)}</select></label>
            <label className="field">Through week<select value={toWeek} onChange={(event) => setToWeek(event.target.value)}>{weekOptions.filter((week) => week.value >= fromWeek).map((week) => <option key={week.value} value={week.value}>{week.label}</option>)}</select></label>
          </div>
          <Metrics stats={rangeStats} transactions={rangedTransactions} />
        </>
      )}
      {active === "activity" && <Activity transactions={transactions} open={open} clear={clear} exportLogs={exportLogs} />}
    </section>
  );
}

function SecurityCenter({
  settings,
  update,
  save,
}: {
  settings: AdminSettings;
  update: (value: Partial<AdminSettings>) => void;
  save: () => void;
}) {
  const [copyStatus, setCopyStatus] = useState("");
  const copySecret = async (value: string, label: string) => {
    try {
      await navigator.clipboard.writeText(value);
      setCopyStatus(`${label} copied.`);
    } catch {
      setCopyStatus("Clipboard access was unavailable. Try copying again.");
    }
  };
  const generateKey = () =>
    { update({ encryptionSecret: generateEncryptionKey() }); setCopyStatus(""); };
  const generateToken = () => { update({ bridgeToken: generateEncryptionKey() }); setCopyStatus(""); };
  return (
    <section className="security-layout">
      <div className="panel security-hero">
        <div>
          <p className="section-kicker">Administrator-only controls</p>
          <h3>Security and protected storage</h3>
          <p>
            Use your existing FileMaker username and password in Connection.
            PHP manages the encryption key that protects saved credentials automatically.
            You do not need to generate a token to connect FileMaker.
          </p>
        </div>
        <span className="admin-badge">Admin</span>
      </div>
      {copyStatus && <p className="supporting-text" role="status">{copyStatus}</p>}
      <div className="security-status-grid">
        <SecurityStatus
          label="Response encryption"
          value={settings.storeResponses ? "Server-side retention on" : "Off · optional"}
          state={
            "ready"
          }
        />
        <SecurityStatus
          label="Browser session"
          value="PHP HttpOnly session cookie"
          state="ready"
        />
        <SecurityStatus
          label="Bridge authorization"
          value={settings.bridgeToken ? "Optional API token present" : "Admin session active"}
          state="ready"
        />
        <SecurityStatus
          label="Persistent secrets"
          value="None in localStorage"
          state="ready"
        />
      </div>
      <div className="settings-grid">
        <div className="panel">
          <p className="section-kicker">Encrypted diagnostics</p>
          <h3>Stored FileMaker responses · optional</h3>
          <p className="panel-description">
            Leave this off if you do not need to reopen response bodies later.
            When enabled, OpenBridge asks PHP to retain response bodies in the
            protected packaged database for the signed-in administrator.
          </p>
          <Toggle
            label="Store encrypted responses"
            detail="New response bodies are stored through authenticated PHP, not browser localStorage."
            checked={settings.storeResponses}
            onChange={(value) => update({ storeResponses: value })}
          />
          <label className="field">
            Legacy browser encryption key
            <input
              type="password"
              value={settings.encryptionSecret}
              onChange={(event) =>
                { update({ encryptionSecret: event.target.value.trim() }); setCopyStatus(""); }
              }
              placeholder="44-character Base64 key"
              autoComplete="off"
            />
          </label>
          <div className="key-actions">
            <button className="button secondary" onClick={generateKey}>
              Generate encryption key
            </button>
            <button className="button secondary" disabled={!settings.encryptionSecret} onClick={() => void copySecret(settings.encryptionSecret, "Legacy key")}>
              Copy encryption key
            </button>
            <span
              className={`key-status ${isValidEncryptionKey(settings.encryptionSecret) ? "valid" : ""}`}
            >
              {isValidEncryptionKey(settings.encryptionSecret)
                ? "Valid key"
                : "Only needed for older browser-encrypted response envelopes"}
            </span>
          </div>
          <p className="inline-warning">
            This legacy key is for older browser-encrypted diagnostic envelopes.
            New retained response bodies are opened through the authenticated
            PHP session and are not saved to browser localStorage.
          </p>
        </div>
        <div className="panel">
          <p className="section-kicker">PHP bridge access</p>
          <h3>Optional external-client token</h3>
          <p className="panel-description">
            Browser requests use the authenticated HttpOnly PHP session, so no
            token is required here. Generate one only for Go, scripts, or other
            non-browser clients; match it in private PHP config and keep it out
            of Git.
          </p>
          <label className="field">
            Optional external-client bridge token
            <input
              type="password"
              value={settings.bridgeToken}
              onChange={(event) => { update({ bridgeToken: event.target.value }); setCopyStatus(""); }}
              autoComplete="off"
              placeholder="Must match api.apiToken in PHP config"
            />
          </label>
          <div className="key-actions">
            <button className="button secondary" onClick={generateToken}>
              Generate bridge token
            </button>
            <button className="button secondary" disabled={!settings.bridgeToken} onClick={() => void copySecret(settings.bridgeToken, "Bridge token")}>
              Copy bridge token
            </button>
          </div>
          <p className="supporting-text">Generates 32 random bytes as a 44-character Base64 token. Generating or saving this draft does not activate server access; it must match the token configured in PHP.</p>
        </div>
      </div>
      <p className="inline-warning">
        The legacy PHP class logger defaults to <code>LOG_TYPE_NONE</code>.
        If a developer enables PHP debug logging, it writes plaintext diagnostic
        files on that server and may include request data; keep it disabled for
        sensitive production traffic until a protected server audit sink is configured.
      </p>
      <div className="panel security-explainer">
        <strong>Session implementation</strong>
        <p>
          PHP verifies the administrator password against a one-way hash and
          returns a Secure/HttpOnly/SameSite session cookie. React never stores
          the submitted login password. FileMaker passwords are encrypted in
          the PHP vault and removed from browser memory after saving. Browser
          <code>sessionStorage</code> is used only for optional diagnostic keys
          and bridge tokens.
          Zustand is not used because client state is not an authentication system.
        </p>
        <button className="button primary" onClick={save}>
          Save security settings
        </button>
      </div>
    </section>
  );
}

function SecurityStatus({
  label,
  value,
  state,
}: {
  label: string;
  value: string;
  state: "ready" | "attention";
}) {
  return (
    <article className={`security-status ${state}`}>
      <span>{state === "ready" ? "✓" : "!"}</span>
      <div>
        <strong>{label}</strong>
        <p>{value}</p>
      </div>
    </article>
  );
}

function SettingsStudio({
  settings,
  update,
  save,
  prepare,
}: {
  settings: AdminSettings;
  update: (value: Partial<AdminSettings>) => void;
  save: () => void;
  prepare: () => Promise<void>;
}) {
  return (
    <section className="studio-grid">
      <div className="panel">
        <div className="panel-heading">
          <div>
            <p className="section-kicker">Transport settings</p>
            <h3>OData is the active FileMaker route.</h3>
          </div>
          <span className="live-chip">Selected</span>
        </div>
        <div className="transport-options"><div className="transport-option selected"><span className="radio">●</span><span><strong>OData</strong><span className="supporting-text">Tables, filters, typed entities, metadata discovery, and OData pagination.</span></span><code>/fmi/odata/v4/{`{database}`}/{`{table}`}</code></div></div>
        <p className="inline-warning">
          OpenBridge’s original Data API engine remains available as a legacy
          library, but it is hidden from the current dashboard while v1 focuses
          on OData. Enable the <code>fmodata</code> privilege in FileMaker.
        </p>
      </div>
      <div className="panel diagnostics-panel">
        <div className="panel-heading">
          <div>
            <p className="section-kicker accent-kicker">Activity and samples</p>
            <h3>Control diagnostic history.</h3>
            <p className="panel-description">
            Encryption and bridge authorization are managed under Setup →
            Security.
            </p>
          </div>
        </div>
        <div className="diagnostic-group">
          <div className="diagnostic-heading">
            <span>01</span>
            <div>
              <strong>History retention</strong>
              <p>
                Choose how many newest diagnostic entries remain available
                before older entries are overwritten.
              </p>
            </div>
          </div>
          <label className="field">
            Log capacity
            <div className="range-row">
              <input
                type="range"
                min="10"
                max="2000"
                step="10"
                value={settings.logCapacity}
                onChange={(event) =>
                  update({
                    logCapacity: normalizeLogCapacity(event.target.value),
                  })
                }
              />
              <span className="range-value">{settings.logCapacity}</span>
            </div>
            <span className="supporting-text">Default 50 · Maximum 2,000</span>
          </label>
        </div>
        <div className="demo-explainer">
          <strong>What does “Add demo request” do?</strong>
          <p>
            It does not contact FileMaker. It loads the selected built-in
            sample, normalizes it, optionally encrypts it, and adds it to
            Activity so you can practice with the viewer.
          </p>
        </div>
        <div className="form-actions">
          <button className="button secondary" onClick={save}>
            Save settings
          </button>
          <button className="button primary" onClick={prepare}>
            Add demo request
          </button>
        </div>
      </div>
    </section>
  );
}

function AdminSettings({
  settings,
  update,
  save,
}: {
  settings: AdminSettings;
  update: (value: Partial<AdminSettings>) => void;
  save: () => void;
}) {
  return (
    <section className="setup-connection">
        <p className="setup-summary">Enter your FileMaker server and OData table. Save the connection, then use Test Connection.</p>
        <div className="setup-fields">
          <label className="field full">
            OpenBridge PHP endpoint
            <input
              value={settings.backendUrl}
              onChange={(event) => update({ backendUrl: event.target.value })}
              placeholder="/openbridge/api/openbridge-api.php"
            />
            <span className="supporting-text">
              Keep the default unless your PHP bridge is installed elsewhere.
            </span>
          </label>
          <label className="field full">
            Server URL
            <input
              value={settings.serverUrl}
              onChange={(event) => update({ serverUrl: event.target.value })}
              placeholder="https://filemaker.example.com"
            />
          </label>
          <label className="field">
            Database name
            <input
              value={settings.database}
              onChange={(event) => update({ database: event.target.value })}
            />
          </label>
          <label className="field">
            Default OData table / entity set
            <input
              value={settings.defaultLayout}
              onChange={(event) =>
                update({ defaultLayout: event.target.value })
              }
            />
            <span className="supporting-text">
              OData uses tables, not layouts. Individual requests can override this default table.
            </span>
          </label>
          <label className="field">
            FileMaker username
            <input
              value={settings.username}
              onChange={(event) => update({ username: event.target.value })}
              autoComplete="username"
            />
          </label>
          <label className="field">
            FileMaker password (encrypted on save)
            <input
              type="password"
              value={settings.password}
              onChange={(event) => update({ password: event.target.value })}
              autoComplete="current-password"
            />
          </label>
        </div>
        <details className="setup-notes">
        <summary>Connection notes (optional)</summary>
        <label className="field">
          Notes
          <textarea
            value={settings.notes}
            onChange={(event) => update({ notes: event.target.value })}
            placeholder="Deployment notes, layout mappings, server reminders…"
          />
        </label>
        <p className="supporting-text">Saved to your OpenBridge account. Keep passwords and sensitive client data out of notes.</p>
        </details>
        <div className="form-actions">
          <button className="button primary" onClick={save}>Save connection</button>
        </div>
    </section>
  );
}

function downloadTransactionLog(transactions: Transaction[], format: "json" | "csv") {
  const rows = transactions.map(({ id, serverId, status, operation, transport, resource, durationMs, createdAt, message, responsePreview, payloadStored, legacyPlaintext }) => ({
    id,
    serverId: serverId ?? "",
    status,
    operation,
    transport,
    resource,
    durationMs,
    createdAt,
    message,
    responsePreview: responsePreview ?? "",
    payloadStored: payloadStored === true,
    legacyPlaintext: legacyPlaintext === true,
  }));
  const content = format === "json" ? JSON.stringify(rows, null, 2) : ["id,serverId,status,operation,transport,resource,durationMs,createdAt,message,responsePreview,payloadStored,legacyPlaintext", ...rows.map((row) => Object.values(row).map((value) => `"${String(value).replaceAll('"', '""')}"`).join(","))].join("\n");
  const blob = new Blob([content], { type: format === "json" ? "application/json" : "text/csv" });
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = `openbridge-transaction-log.${format}`;
  anchor.click();
  URL.revokeObjectURL(url);
}

function Activity({
  transactions,
  open,
  clear,
  exportLogs,
}: {
  transactions: Transaction[];
  open: (item: Transaction) => void;
  clear: () => void;
  exportLogs: (format: "json" | "csv") => void;
}) {
  const [filters, setFilters] = useState<TransactionFilters>({
    search: "",
    status: "all",
    transport: "all",
  });
  const [visible, setVisible] = useState(25);
  const sentinel = useRef<HTMLDivElement>(null);
  const filtered = useMemo(
    () => filterTransactions(transactions, filters),
    [transactions, filters],
  );
  useEffect(() => setVisible(25), [filters]);
  useEffect(() => {
    const node = sentinel.current;
    if (!node || visible >= filtered.length) return;
    const observer = new IntersectionObserver(
      (entries) => {
        if (entries[0]?.isIntersecting)
          setVisible((count) => Math.min(count + 25, filtered.length));
      },
      { rootMargin: "180px" },
    );
    observer.observe(node);
    return () => observer.disconnect();
  }, [filtered.length, visible]);
  return (
    <section className="panel">
      <div className="panel-heading">
        <div>
          <p className="section-kicker">Secure activity</p>
          <h3>Transaction log</h3>
          <p className="panel-description">
            Click a row to inspect sample responses or server-retained payloads
            available to the signed-in administrator.
          </p>
        </div>
        <div className="activity-export" aria-label="Export transaction logs">
          <span>Export safe summaries</span>
          <button className="button secondary" disabled={!transactions.length} onClick={() => exportLogs("json")}>JSON</button>
          <button className="button secondary" disabled={!transactions.length} onClick={() => exportLogs("csv")}>CSV</button>
        </div>
        <button
          className="button danger"
          disabled={!transactions.length}
          onClick={clear}
        >
          Clear log
        </button>
      </div>
      <div className="activity-toolbar">
        <input
          className="search-input"
          aria-label="Search activity"
          placeholder="Search date, operation, resource…"
          value={filters.search ?? ""}
          onChange={(event) =>
            setFilters((current) => ({
              ...current,
              search: event.target.value,
            }))
          }
        />
        <select
          aria-label="Filter by status"
          value={filters.status}
          onChange={(event) =>
            setFilters((current) => ({
              ...current,
              status: event.target.value as TransactionFilters["status"],
            }))
          }
        >
          <option value="all">All outcomes</option>
          <option value="success">Successful</option>
          <option value="failure">Failed</option>
        </select>
        <select
          aria-label="Filter by transport"
          value={filters.transport}
          onChange={(event) =>
            setFilters((current) => ({
              ...current,
              transport: event.target.value as TransactionFilters["transport"],
            }))
          }
        >
          <option value="all">All APIs</option>
          <option value="odata">OData</option>
          <option value="data-api">Data API</option>
        </select>
        <button
          className="button secondary"
          onClick={() =>
            setFilters({ search: "", status: "all", transport: "all" })
          }
        >
          Reset
        </button>
      </div>
      <div className="activity-list">
        {filtered.slice(0, visible).map((item) => (
          <button
            className="activity-row"
            key={item.id}
            onClick={() => open(item)}
          >
            <span className={`outcome ${item.status}`}>
              {item.status === "success" ? "✓" : "!"}
            </span>
            <span className="activity-main">
              <strong>{item.operation}</strong>
              <span>
                {item.resource} · {item.message}
              </span>
            </span>
            <span className={`transport-badge ${item.transport}`}>
              {item.transport === "odata" ? "OData" : "Data API"}
            </span>
            <span className={`status-badge ${item.status}`}>{item.status}</span>
            <time>{new Date(item.createdAt).toLocaleString()}</time>
          </button>
        ))}
      </div>
      {!filtered.length && (
        <div className="empty-state">
          <strong>No matching transactions</strong>
          <p>Adjust the filters or prepare a demo request.</p>
        </div>
      )}
      <div ref={sentinel} className="load-sentinel">
        {visible < filtered.length
          ? "Scroll to load more…"
          : `${filtered.length} of ${transactions.length} entries shown`}
      </div>
    </section>
  );
}

function inspectJsonBrackets(value: string) {
  const stack: { character: "{" | "["; line: number; column: number }[] = [];
  const pairs: Record<string, string> = { "}": "{", "]": "[" };
  let quoted = false;
  let escaped = false;
  let line = 1;
  let column = 0;
  for (const character of value) {
    if (character === "\n") {
      line += 1;
      column = 0;
      continue;
    }
    column += 1;
    if (quoted) {
      if (escaped) escaped = false;
      else if (character === "\\") escaped = true;
      else if (character === '"') quoted = false;
      continue;
    }
    if (character === '"') {
      quoted = true;
      continue;
    }
    if (character === "{" || character === "[") stack.push({ character, line, column });
    if (character === "}" || character === "]") {
      const opening = stack.pop();
      if (!opening || opening.character !== pairs[character]) {
        return { ok: false, message: `Unexpected ${character} at line ${line}, column ${column}` };
      }
    }
  }
  if (quoted) return { ok: false, message: "An unterminated string is still open" };
  const opening = stack.pop();
  if (opening) {
    const expected = opening.character === "{" ? "}" : "]";
    return { ok: false, message: `Missing ${expected} for ${opening.character} opened at line ${opening.line}, column ${opening.column}` };
  }
  return { ok: true, message: "Curly braces and square brackets are balanced" };
}

function countTextMatches(value: string, query: string) {
  if (!query) return 0;
  let count = 0;
  let cursor = 0;
  const haystack = value.toLocaleLowerCase();
  const needle = query.toLocaleLowerCase();
  while ((cursor = haystack.indexOf(needle, cursor)) !== -1) {
    count += 1;
    cursor += Math.max(needle.length, 1);
  }
  return count;
}

function highlightedJson(value: string, query: string) {
  if (!query) return value;
  const parts = [];
  const haystack = value.toLocaleLowerCase();
  const needle = query.toLocaleLowerCase();
  let cursor = 0;
  let match = haystack.indexOf(needle);
  while (match !== -1) {
    parts.push(value.slice(cursor, match));
    parts.push(<mark key={`${match}-${parts.length}`}>{value.slice(match, match + query.length)}</mark>);
    cursor = match + query.length;
    match = haystack.indexOf(needle, cursor);
  }
  parts.push(value.slice(cursor));
  return parts;
}

function Prettifier({ initialValue }: { initialValue?: string }) {
  const starting = initialValue || '{\n  "hello": "OpenBridge"\n}';
  const [raw, setRaw] = useState(starting);
  const [pretty, setPretty] = useState(starting);
  const [error, setError] = useState("");
  const [search, setSearch] = useState("");
  const bracketStatus = useMemo(() => inspectJsonBrackets(raw), [raw]);
  const searchMatches = useMemo(() => countTextMatches(pretty, search), [pretty, search]);
  useEffect(() => {
    if (initialValue) {
      setRaw(initialValue);
      setPretty(initialValue);
    }
  }, [initialValue]);
  const format = () => {
    try {
      setPretty(JSON.stringify(JSON.parse(raw), null, 2));
      setError("");
    } catch {
      setError("That is not valid JSON yet.");
    }
  };
  const minify = () => {
    try {
      setPretty(JSON.stringify(JSON.parse(raw)));
      setError("");
    } catch {
      setError("That is not valid JSON yet. Check the bracket diagnostic below.");
    }
  };
  return (
    <section className="json-tools-layout">
      <div className="panel json-toolbelt">
        <div>
          <p className="section-kicker">Local developer utilities</p>
          <h3>Format, validate, search, and inspect JSON.</h3>
          <p className="panel-description">Nothing pasted here is transmitted or stored by OpenBridge.</p>
        </div>
        <div className={`bracket-status ${bracketStatus.ok ? "valid" : "attention"}`}>
          <span>{bracketStatus.ok ? "✓" : "!"}</span>
          <div><strong>{bracketStatus.ok ? "Brackets balanced" : "Bracket issue"}</strong><p>{bracketStatus.message}</p></div>
        </div>
      </div>
      <section className="json-grid">
      <div className="panel">
        <div className="panel-heading">
          <div>
            <p className="section-kicker">Paste locally</p>
            <h3>Raw JSON</h3>
          </div>
          <div className="response-actions">
            <button className="button secondary" onClick={() => { setRaw(""); setPretty(""); setError(""); }}>Clear</button>
            <button className="button secondary" onClick={minify}>Minify</button>
            <button className="button primary" onClick={format}>Prettify</button>
          </div>
        </div>
        <textarea
          className="json-editor"
          value={raw}
          onChange={(event) => setRaw(event.target.value)}
        />
      </div>
      <div className="panel">
        <div className="panel-heading">
          <div>
            <p className="section-kicker">Formatted locally</p>
            <h3>Pretty JSON</h3>
          </div>
          <button
            className="button secondary"
            onClick={() => navigator.clipboard.writeText(pretty)}
          >
            Copy
          </button>
        </div>
        <label className="json-search">
          <span>Quick search</span>
          <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Find a field name or value…" />
          <strong>{search ? `${searchMatches} match${searchMatches === 1 ? "" : "es"}` : "Type to highlight matches"}</strong>
        </label>
        {error && <p className="inline-warning">{error}</p>}
        <pre className="json-output searchable-json">{highlightedJson(pretty, search)}</pre>
      </div>
      </section>
    </section>
  );
}
function Metrics({ stats, transactions }: { stats: ReturnType<typeof makeStats>; transactions: Transaction[] }) {
  const bars = [
    { label: "OData", value: stats.odata, tone: "" },
    { label: "Data API", value: stats.dataApi, tone: "" },
    { label: "Success", value: stats.success, tone: "" },
    { label: "Failed", value: stats.failure, tone: "failure" },
  ];
  const max = Math.max(...bars.map((bar) => bar.value), 1);
  const latency = transactions.filter((item) => Number.isFinite(item.durationMs)).slice(-8);
  const maxLatency = Math.max(...latency.map((item) => item.durationMs), 1);
  return (
    <>
      <MetricCards stats={stats} />
      <section className="chart-grid">
        <div className="panel">
          <p className="section-kicker">API volume</p>
          <h3>Transport comparison</h3>
          <p className="panel-description">
            Counts reflect visible Activity rows for this session plus any
            server summaries loaded through the signed-in PHP session.
          </p>
          <div className="chart-bars">
            {bars.map((bar) => (
              <div
                key={bar.label}
                className={`chart-bar ${bar.tone}`}
                style={{ height: `${bar.value === 0 ? 6 : Math.max(16, (bar.value / max) * 90)}%` }}
                title={`${bar.label}: ${bar.value}`}
              >
                <strong>{bar.value}</strong>
                <span>{bar.label}</span>
              </div>
            ))}
          </div>
        </div>
        <div className="panel">
          <p className="section-kicker">Outcome distribution</p>
          <h3>Success and failure</h3>
          <Donut success={stats.success} failure={stats.failure} />
        </div>
        <div className="panel developer-signal-panel">
          <p className="section-kicker">Developer signal</p>
          <h3>Request speed over time</h3>
          <p className="panel-description">A quick view of the latest request durations. Taller bars mean a slower request.</p>
          <div className="latency-chart" aria-label="Recent request duration chart">
            {latency.length ? latency.map((item) => <div className="latency-bar-wrap" key={item.id} title={`${item.durationMs} ms · ${item.operation}`}><span className={item.durationMs > 500 ? "slow" : ""} style={{ height: `${Math.max(12, (item.durationMs / maxLatency) * 100)}%` }} /><small>{item.durationMs}ms</small></div>) : <p className="empty-state">Run a request to see timing here.</p>}
          </div>
        </div>
      </section>
    </>
  );
}

function RgcModal({ close }: { close: () => void }) {
  return (
    <div className="modal-backdrop rgc-backdrop" onMouseDown={close}>
      <section
        className="modal rgc-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="rgc-title"
        onMouseDown={(event) => event.stopPropagation()}
      >
        <div className="rgc-glow glow-one" />
        <div className="rgc-glow glow-two" />
        <div className="modal-heading">
          <div className="rgc-modal-brand">
            <img className="rgc-modal-logo" src={brandLogoUrl} alt="OpenBridge" />
            <div>
              <p className="section-kicker">Alex Seidler</p>
              <h2 id="rgc-title">Need help connecting the pieces?</h2>
            </div>
          </div>
          <button className="modal-close" aria-label="Close" onClick={close}>
            ×
          </button>
        </div>
        <p>
          I released OpenBridge free to help the community connect FileMaker
          with web applications and websites more easily. If you want help
          configuring it or building the larger solution around it, I am
          available for hire and consultation.
        </p>
        <div className="service-tags">
          <span>FileMaker & FM 26</span>
          <span>Agentic coding</span>
          <span>Web applications</span>
          <span>Android / iOS</span>
          <span>QuickBooks</span>
          <span>Salesforce</span>
          <span>Cross-platform integrations</span>
        </div>
        <div className="proof-card">
          <strong>Integration experience you can verify.</strong>
          <p>
            Alex works across FileMaker, web, mobile, and connected business
            platforms, with a focus on practical integrations that people can
            maintain.
          </p>
        </div>
        <p>
          Alex Seidler specializes in practical FileMaker integrations that
          work across the systems your organization already uses.
        </p>
        <a
          className="button gold rgc-primary"
          href="https://www.linkedin.com/in/alex-seidler-9879583a2/"
          target="_blank"
          rel="noreferrer"
        >
          Connect on LinkedIn
        </a>
      </section>
    </div>
  );
}
function ResponseViewer({
  modal,
  close,
  copied,
}: {
  modal: ResponseModal;
  close: () => void;
  copied: () => void;
}) {
  const value = modal.response ? JSON.stringify(modal.response, null, 2) : "";
  const copy = async () => {
    if (value) {
      await navigator.clipboard.writeText(value);
      copied();
    }
  };
  return (
    <Modal title={modal.transaction.operation} onClose={close}>
        <div className="response-meta">
          <span className={`transport-badge ${modal.transaction.transport}`}>
            {modal.transaction.transport === "odata" ? "OData" : "Data API"}
          </span>
          <span className={`status-badge ${modal.transaction.status}`}>
            {modal.transaction.status}
          </span>
          <span className="supporting-text">
            {modal.transaction.resource} ·{" "}
            {new Date(modal.transaction.createdAt).toLocaleString()}
          </span>
        </div>
        {modal.error ? (
          <p className="inline-warning">{modal.error}</p>
        ) : (
          <>
            <div className="row-between">
              <p className="panel-description">
                Normalized outer structure; original record fields remain
                untouched.
              </p>
              <button className="button secondary" onClick={copy}>
                Copy entire response
              </button>
            </div>
            <pre className="json-output response-code">{value}</pre>
          </>
        )}
    </Modal>
  );
}
function DesignLab({ backendUrl }: { backendUrl: string }) {
  const swatches = [
    ["Gold", "button-gold", "Guided setup / premium primary"],
    ["Maroon", "button-maroon", "Logout / destructive exit"],
    ["Action", "button-action", "Success path / continue"],
    ["Focus", "button-focus", "Creative tool / featured area"],
    ["Split", "button-split", "Purple + lime 50/50 experiment"],
    ["Cobalt", "button-cobalt", "Technical secondary action"],
    ["Cyan", "button-cyan", "Live data / connection state"],
    ["Violet", "button-violet", "Builder / advanced workspace"],
    ["Ember", "button-ember", "Important attention action"],
    ["Rose", "button-rose", "Careful or human-help action"],
    ["Slate", "button-slate", "Quiet utility action"],
  ] as const;
  const [databaseTest, setDatabaseTest] = useState<{ status: "idle" | "running" | "pass" | "fail"; message: string }>({ status: "idle", message: "No database check run yet." });
  const runDatabaseTest = async (mode: "status" | "full" | "metric") => {
    setDatabaseTest({ status: "running", message: mode === "full" ? "Running SQLite integrity, schema, table, and reversible-write checks…" : "Checking the packaged SQLite boundary…" });
    try {
      const status = await getSetupStatus(backendUrl);
      const stored = await loadStoredSettings(backendUrl);
      const transactions = await loadStoredTransactions(backendUrl, 5);
      const diagnostics = mode === "full" ? await runDatabaseDiagnostics(backendUrl) : undefined;
      if (mode === "metric") await recordMetricEvent(backendUrl, "design_lab_database_test", { result: "pass", scope: "sqlite_only" });
      const result = {
        databaseReady: status.databaseReady,
        demoAvailable: status.demoAvailable,
        storedSettingKeys: Object.keys(stored).length,
        recentTransactionSummaries: transactions.length,
        metricWritten: mode === "metric",
        ...(diagnostics ? { diagnostics } : {}),
      };
      console.log("[OpenBridge DB test]", result);
      setDatabaseTest({ status: status.databaseReady && (!diagnostics || diagnostics.ready) ? "pass" : "fail", message: JSON.stringify(result, null, 2) });
    } catch (error) {
      console.error("[OpenBridge DB test]", error);
      setDatabaseTest({ status: "fail", message: error instanceof Error ? error.message : "SQLite test failed." });
    }
  };
  return (
    <section className="design-lab" aria-label="OpenBridge design exploration">
      <section className="panel design-hero">
        <div>
          <p className="section-kicker accent-kicker">Design Lab · safe exploration</p>
          <h2>Give the control plane a more obvious pulse.</h2>
          <p className="panel-description">Admin-only visual workshop. These ideas can be tested here without changing the live dashboard until a direction is chosen.</p>
        </div>
        <span className="step-chip">10 ACTION STYLES · 3 LAYOUTS</span>
      </section>

      <section className="design-section" aria-labelledby="design-buttons-title">
        <div className="design-section-heading">
          <div><p className="section-kicker">Action language</p><h3 id="design-buttons-title">All ten named button styles</h3></div>
          <p>Hover each one. Every style keeps its position, adds the glistening sweep, and has a visible keyboard focus state.</p>
        </div>
        <div className="design-button-grid">
          {swatches.map(([name, className, use]) => (
            <article key={className} className="design-button-card">
              <button className={`button button-variant ${className} design-button-sample`}>{name} button</button>
              <strong>{name}</strong><span>{use}</span>
            </article>
          ))}
        </div>
      </section>

      <section className="panel design-database" aria-labelledby="design-database-title">
        <div className="design-section-heading">
          <div><p className="section-kicker">Runtime verification</p><h3 id="design-database-title">SQLite database test bench</h3></div>
          <span className={`db-test-status ${databaseTest.status}`}>{databaseTest.status === "idle" ? "Ready" : databaseTest.status}</span>
        </div>
        <p className="panel-description">These checks use the authenticated PHP API and packaged SQLite boundary. The full suite verifies integrity, foreign keys, WAL mode, schema version, required tables, directory permissions, and a real write that is immediately rolled back. Nothing exposes credentials or retained payloads.</p>
        <div className="design-db-actions">
          <button className="button button-variant button-cyan" onClick={() => void runDatabaseTest("status")} disabled={databaseTest.status === "running"}>Quick database check</button>
          <button className="button button-variant button-action" onClick={() => void runDatabaseTest("full")} disabled={databaseTest.status === "running"}>Run full SQLite test suite</button>
          <button className="button button-variant button-gold" onClick={() => void runDatabaseTest("metric")} disabled={databaseTest.status === "running"}>Write one test metric</button>
        </div>
        <pre className="design-test-output" aria-live="polite">{databaseTest.message}</pre>
      </section>

      <section className="design-section" aria-labelledby="design-nav-title">
        <div className="design-section-heading"><div><p className="section-kicker">Navigation directions</p><h3 id="design-nav-title">Three menu concepts</h3></div><p>Recommendation: keep the current sidebar, then add selective visual hierarchy rather than reinventing navigation.</p></div>
        <div className="nav-concept-grid">
          <article className="nav-concept signal-nav"><p>Option A · Signal rail</p><div className="mock-nav"><b>◈ Dashboard</b><span>⚙ Setup <i>01</i></span><span>✓ Test connection</span><span>⌘ Code builder <i>Beta</i></span><span>↗ Metrics</span></div><h4>Current sidebar, upgraded</h4><small>Best fit. Grouped sections and a gold active signal without changing the learned navigation pattern.</small></article>
          <article className="nav-concept command-nav"><p>Option B · Command dock</p><div className="mock-command"><b>OpenBridge <em>⌘ K</em></b><span>Dashboard</span><span>Build</span><span>Monitor</span><span>Learn</span><button>Start setup →</button></div><h4>Top command dock</h4><small>A compact top bar with a command palette. Strong, but more disruptive on the existing dashboard.</small></article>
          <article className="nav-concept orbit-nav"><p>Option C · Workspace rail</p><div className="mock-orbit"><aside><b>O</b><span>◈</span><span>⚙</span><span>⌘</span><span>↗</span></aside><main><b>Dashboard</b><span>Workspace</span><span>Activity</span><span>Docs</span></main></div><h4>Ultra-collapsed rail</h4><small>Great on smaller screens or a focused coding workspace; less beginner-friendly as the default.</small></article>
        </div>
      </section>

      <section className="design-section" aria-labelledby="design-layout-title">
        <div className="design-section-heading"><div><p className="section-kicker">Dashboard regrouping</p><h3 id="design-layout-title">Turn six cards into one understandable system</h3></div><p>Each mockup combines successful, failed, OData, Data API, outcome distribution, and storage model into a deliberate group.</p></div>
        <div className="layout-concept-grid">
          <article className="layout-concept command-center"><div className="concept-label"><b>A</b><span>Command Center · recommended</span></div><div className="system-shell"><header><span>Bridge health</span><strong>98% healthy</strong><i>Live</i></header><div className="system-kpis"><span><b>24</b>Successful</span><span><b>2</b>Failed</span><span><b>15</b>OData</span><span><b>11</b>Data API</span></div><div className="system-body"><div className="mini-chart"><small>Outcome distribution</small><div className="chart-bars-preview"><i /><i /><i className="short" /><i className="short" /></div></div><div className="storage-preview"><small>Private system storage</small><b>SQLite protected</b><span>Sessions · logs · modules</span></div></div></div><h4>One “Bridge Health” module</h4><small>Metrics become the top strip; the chart and storage explain the system below. It reads as one story.</small></article>
          <article className="layout-concept mission-control"><div className="concept-label"><b>B</b><span>Mission Control</span></div><div className="mission-shell"><header><span>OpenBridge telemetry</span><i>● Stable</i></header><div className="mission-main"><div className="mission-ring">98<small>%</small></div><div><b>Reliable connection layer</b><span>26 total requests · 2 need review</span><div className="mission-pills"><i>OData 15</i><i>Data API 11</i></div></div></div><footer>Private SQLite storage · encrypted payloads optional</footer></div><h4>One standout status object</h4><small>Most futuristic. Strong for a product demo; leaves room for a real activity timeline next to it.</small></article>
          <article className="layout-concept flow-stack"><div className="concept-label"><b>C</b><span>Flow Stack</span></div><div className="flow-shell"><div className="flow-item lime"><b>24</b><span>Successful requests</span><i>Healthy</i></div><div className="flow-item coral"><b>2</b><span>Requests needing review</span><i>Review</i></div><div className="flow-item blue"><b>26</b><span>Transport activity</span><i>15 OData · 11 Data API</i></div><div className="flow-note">▣ Private SQLite storage keeps OpenBridge settings, logs, modules, and workspaces together.</div></div><h4>Readable event-flow stack</h4><small>The least “dashboard-ish” option. It feels like a live system console and stays excellent on mobile.</small></article>
        </div>
      </section>

      <section className="design-section" aria-labelledby="design-surface-title">
        <div className="design-section-heading"><div><p className="section-kicker">Container treatment</p><h3 id="design-surface-title">Surface effects worth testing</h3></div><p>Use these sparingly—one primary effect per page keeps the high-tech look intentional.</p></div>
        <div className="surface-effect-grid">
          <article className="surface-effect glass-surface"><b>01</b><h4>Frosted telemetry glass</h4><span>Soft depth, quiet data-heavy pages.</span></article>
          <article className="surface-effect scan-surface"><b>02</b><h4>Scanning signal border</h4><span>Best for live connection or testing states.</span></article>
          <article className="surface-effect aurora-surface"><b>03</b><h4>Aurora field</h4><span>Premium hero or featured workspace.</span></article>
          <article className="surface-effect circuit-surface"><b>04</b><h4>Circuit grid</h4><span>Technical, restrained, good behind metrics.</span></article>
        </div>
      </section>
    </section>
  );
}

function explainConnectionFailure(reason: unknown): string {
  const message = reason instanceof Error ? reason.message : "Connection health check failed.";
  const lower = message.toLowerCase();
  if (lower.includes("401") || lower.includes("403") || lower.includes("auth") || lower.includes("credential")) {
    return `${message} Check the FileMaker account name, password, OData privilege, and database access in Setup FileMaker.`;
  }
  if (lower.includes("404") || lower.includes("not found")) {
    return `${message} Check the PHP endpoint, database name, and OData table in Setup FileMaker.`;
  }
  if (lower.includes("timeout") || lower.includes("network") || lower.includes("fetch") || lower.includes("connect")) {
    return `${message} Confirm the OpenBridge PHP endpoint and FileMaker Server HTTPS address in Setup FileMaker.`;
  }
  if (lower.includes("sqlite") || lower.includes("storage") || lower.includes("503")) {
    return `${message} Confirm the packaged db folder is writable by PHP, then return to Setup FileMaker.`;
  }
  return `${message} Open Setup FileMaker and review the server, database, table, and account fields.`;
}

function explainSettingsSaveFailure(reason: unknown): string {
  const message = reason instanceof Error ? reason.message : "Settings save failed.";
  const lower = message.toLowerCase();
  if (lower.includes("vault") || lower.includes("credential") || lower.includes("encrypt")) {
    return `${message} FileMaker credentials stayed in the form so you can retry after checking server-side vault setup.`;
  }
  if (lower.includes("sqlite") || lower.includes("storage") || lower.includes("database") || lower.includes("writable") || lower.includes("503")) {
    return `${message} Confirm the packaged db folder is writable by PHP before continuing setup.`;
  }
  if (lower.includes("401") || lower.includes("403") || lower.includes("session") || lower.includes("csrf")) {
    return `${message} Sign in again, then return to Setup FileMaker.`;
  }
  return `${message} Setup did not advance because OpenBridge could not confirm server-side storage.`;
}

function Nav({
  active,
  label,
  icon,
  count,
  badge,
  tone,
  onClick,
}: {
  active: boolean;
  label: string;
  icon: string;
  count?: number;
  badge?: string;
  tone?: "about";
  onClick: () => void;
}) {
  return (
    <button
      className={`nav-item ${active ? "active" : ""} ${tone ? `nav-${tone}` : ""}`}
      aria-current={active ? "page" : undefined}
      title={label}
      onClick={onClick}
    >
      <span className="nav-icon">{icon}</span>
      <span className="nav-text">{label}</span>
      {badge && <span className="nav-badge">{badge}</span>}
      {count !== undefined && <em>{count}</em>}
    </button>
  );
}
function Toggle({
  label,
  detail,
  checked,
  onChange,
}: {
  label: string;
  detail: string;
  checked: boolean;
  onChange: (value: boolean) => void;
}) {
  return (
    <button
      className="toggle-row"
      role="switch"
      aria-checked={checked}
      onClick={() => onChange(!checked)}
    >
      <span>
        <strong>{label}</strong>
        <span className="supporting-text">{detail}</span>
      </span>
      <span className={`switch ${checked ? "on" : ""}`}>
        <b />
      </span>
    </button>
  );
}
function Metric({
  label,
  value,
  detail,
  tone,
}: {
  label: string;
  value: string;
  detail: string;
  tone: string;
}) {
  return (
    <div className={`metric ${tone}`}>
      <span>{label}</span>
      <strong>{value}</strong>
      <span className="supporting-text">{detail}</span>
    </div>
  );
}
function Donut({ success, failure }: { success: number; failure: number }) {
  const total = success + failure;
  const percent = total ? (success / total) * 100 : 0;
  return (
    <>
      <div className="donut-wrap">
        <svg
          className="donut"
          viewBox="0 0 42 42"
          role="img"
          aria-label={`${Math.round(percent)} percent successful`}
        >
          <circle
            className="donut-track"
            cx="21"
            cy="21"
            r="15.9155"
            fill="transparent"
            strokeWidth="5"
          />
          <circle
            className="donut-success"
            cx="21"
            cy="21"
            r="15.9155"
            fill="transparent"
            strokeWidth="5"
            strokeDasharray={`${percent} ${100 - percent}`}
            strokeDashoffset="25"
          />
          <circle
            className="donut-failure"
            cx="21"
            cy="21"
            r="15.9155"
            fill="transparent"
            strokeWidth="5"
            strokeDasharray={`${100 - percent} ${percent}`}
            strokeDashoffset={`${25 - percent}`}
          />
        </svg>
        <div className="donut-center">
          <strong>{total ? `${Math.round(percent)}%` : "—"}</strong>
          <span className="supporting-text">success</span>
        </div>
      </div>
      <div className="legend">
        <span>
          <i className="legend-dot success" />
          {success} successful
        </span>
        <span>
          <i className="legend-dot failure" />
          {failure} failed
        </span>
      </div>
    </>
  );
}
function title(view: View) {
  if (view === "connections") return "Advanced Integrations";
  if (view === "demo") return "Presentation Demo";
  if (["admin", "studio", "security"].includes(view)) return "Setup";
  if (["health", "test"].includes(view)) return "Test Your Connection";
  if (["coding", "playground"].includes(view)) return "Coding Playground";
  if (["schema", "capabilities", "progress"].includes(view)) return "Capabilities";
  if (["docs", "ai"].includes(view)) return "Docs & Help";
  if (view === "design") return "Design Lab";
  if (view === "about") return "About OpenBridge";
  if (["activity", "metrics"].includes(view)) return "Metrics";
  if (view === "prettifier") return "JSON Tools";
  return "Dashboard";
}
