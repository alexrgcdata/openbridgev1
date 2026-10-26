import { useEffect, useState, type ReactNode } from 'react'
import './docs.css'

function CodeExample({ language, children }: { language: string; children: string }) {
  const [message, setMessage] = useState('')
  return <figure className="docs-code"><figcaption><span>{language}</span><button type="button" onClick={() => {
    void navigator.clipboard.writeText(children).then(() => setMessage('Copied')).catch(() => setMessage('Copy unavailable'))
  }}>Copy code</button><span role="status">{message}</span></figcaption><pre><code>{children}</code></pre></figure>
}

const phpRead = `<?php
// In a PHP page beside the installed /openbridge/ folder:
require_once __DIR__ . '/openbridge/openbridge.php';

// Load the trusted FileMaker connection saved in OpenBridge.
$fm = OpenBridge::odata();
$result = $fm->findRecords("Status eq 'Active'", [
    'table' => 'Contacts',
    'queryOptions' => ['top' => 25],
]);

if (!$result['ok']) {
    throw new RuntimeException('FileMaker request failed.');
}

foreach ($result['records'] as $contact) {
    echo htmlspecialchars(
        (string) ($contact['Name'] ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    ) . '<br>';
}`

const phpEndpoint = `<?php
// Your application: /api/contacts.php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Replace this guard with YOUR application's sign-in check.
// OpenBridge's administrator session does not sign users into your app.
if (empty($_SESSION['app_user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Sign in to continue.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

require_once dirname(__DIR__) . '/openbridge/openbridge.php';

try {
    $fm = OpenBridge::odata();
    // Fixed table, fields, filter and limit are chosen by your server.
    $result = $fm->findRecords("Status eq 'Active'", [
        'table' => 'Contacts',
        'queryOptions' => ['top' => 25, 'select' => 'Name,Status'],
    ]);
    if (!$result['ok']) {
        throw new RuntimeException('Upstream request failed.');
    }
    // Return only the fields your users are allowed to see.
    $records = array_map(static fn (array $row): array => [
        'Name' => (string) ($row['Name'] ?? ''),
        'Status' => (string) ($row['Status'] ?? ''),
    ], $result['records']);
    echo json_encode(['ok' => true, 'records' => $records], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(502);
    echo json_encode(['error' => 'Contacts are temporarily unavailable.']);
}`

const jsRead = `// Browser JavaScript — calls YOUR PHP endpoint, not FileMaker.
export async function getContacts() {
  const response = await fetch('/api/contacts.php', {
    method: 'GET',
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  });
  const body = await response.json();
  if (!response.ok || body.ok !== true || !Array.isArray(body.records)) {
    throw new Error(body.error || 'Unable to load contacts.');
  }
  return body.records;
}

// Handle failures in your page's loading/error UI.
// Render names with textContent (or JSX), not raw innerHTML.
const contacts = await getContacts();`

const tsRead = `type Contact = { Name: string; Status: string };

function isContact(value: unknown): value is Contact {
  if (typeof value !== 'object' || value === null) return false;
  return 'Name' in value && typeof value.Name === 'string'
    && 'Status' in value && typeof value.Status === 'string';
}

export async function getContacts(signal?: AbortSignal): Promise<Contact[]> {
  const response = await fetch('/api/contacts.php', {
    credentials: 'same-origin', signal,
    headers: { Accept: 'application/json' },
  });
  const body: unknown = await response.json();
  if (!response.ok || typeof body !== 'object' || body === null
      || !('ok' in body) || body.ok !== true
      || !('records' in body) || !Array.isArray(body.records)
      || !body.records.every(isContact)) {
    throw new Error('Unable to load contacts.');
  }
  return body.records;
}

// In React, call this from your data-loading layer or an effect.
// If using an effect, pass an AbortController's signal and abort on cleanup.`

type Chapter = { id: string; title: string; content: ReactNode }
type DocTab = { id: string; label: string; title: string; description: string; chapters: Chapter[] }

const tabs: DocTab[] = [
  { id: 'quick', label: 'Getting started', title: 'Connect FileMaker to your application.', description: 'Install the bridge once. Let PHP handle FileMaker requests, then use the results in your website or application.', chapters: [
    { id: 'how', title: 'How OpenBridge fits together', content: <><p>OpenBridge provides a PHP client for FileMaker and a dashboard for configuring and testing it. Your business records stay in FileMaker. OpenBridge’s SQLite file stores its own accounts, settings and diagnostics.</p><div className="docs-flow" aria-label="Request flow"><span>Your website / app</span><b aria-hidden="true">→</b><span>Your PHP endpoint</span><b aria-hidden="true">→</b><span>FileMaker</span></div><p>PHP runs on your web server and holds the credentials. JavaScript or TypeScript runs in the browser and calls your PHP endpoint over HTTPS. The browser does not need the FileMaker password or an administrator token.</p></> },
    { id: 'install', title: '1. Install the complete folder', content: <><p>Upload the prepared OpenBridge distribution to a PHP-capable website. The examples below assume it lives at <code>/openbridge/</code>. Adjust the include paths if you choose another location.</p><CodeExample language="Installed files">{`website/
  openbridge/
    index.html          # Admin dashboard
    openbridge.php      # PHP entry point for your application
    openbridge.js       # Browser helpers
    api/                # PHP engine and private configuration
    db/                 # Protected OpenBridge SQLite storage
    assets/             # Dashboard assets
    docs/               # Reference documentation
  api/
    contacts.php        # Your own application endpoint`}</CodeExample><p>PHP needs cURL, PDO with SQLite, and sodium or OpenSSL for encrypted storage. PHP must be able to write to the protected database and private-key directories. Keep their access-denial rules in place. A deployed distribution does not need Node.js to serve the dashboard.</p></> },
    { id: 'configure', title: '2. Configure your FileMaker connection', content: <><ol><li>Enable OData on your FileMaker Server and grant <code>fmodata</code> to the integration account’s privilege set.</li><li>Open the installed OpenBridge dashboard and create your administrator account when prompted. Public demo accounts are for demonstration only.</li><li>In <strong>Setup FileMaker → Connection</strong>, enter the HTTPS server address, exact hosted database name, OData table/entity set, and FileMaker credentials.</li><li>Save the connection, then run <strong>Test Connection</strong>. Start with an account that can read only the tables and fields you need.</li></ol><p>The table is an OData entity-set name, not a Data API layout. Use the names exposed by your FileMaker server. Saving settings does not enable FileMaker privileges for you.</p></> },
    { id: 'use', title: '3. Call OpenBridge from your code', content: <><p>For a PHP-rendered page, use the <strong>PHP</strong> tab and include <code>openbridge.php</code>. For React, TypeScript or browser JavaScript, use the <strong>JavaScript / TS</strong> tab: create a small authenticated PHP endpoint, then fetch it from your frontend.</p><p>Examples use a fictional <code>Contacts</code> table with <code>Name</code> and <code>Status</code> fields. Replace those names with your own exact FileMaker names. Examples are documentation; this page does not execute them.</p></> },
  ] },
  { id: 'php', label: 'PHP', title: 'Call FileMaker from PHP.', description: 'Use the packaged factory to load the saved connection and make server-side requests.', chapters: [
    { id: 'read', title: 'Include the library and find records', content: <><p>Place this PHP page beside your installed OpenBridge folder. <code>OpenBridge::odata()</code> loads a trusted server-side default connection. It does not read the visiting browser’s settings.</p><CodeExample language="PHP · OData read">{phpRead}</CodeExample><p>Choose the default vault owner in trusted server configuration if the installation has multiple administrators. If no saved connection can be selected unambiguously, configure explicit server-side defaults rather than choosing a user from an incoming browser request.</p></> },
    { id: 'options', title: 'Choose a database, table or page', content: <><CodeExample language="PHP · overrides">{`$fm = OpenBridge::odata([
    'database' => 'CRM.fmp12',
    'table' => 'Contacts',
]);

$page = $fm->getRecords([
    'queryOptions' => [
        'top' => 25,
        'skip' => 0,
        'select' => 'Name,Status',
        'orderby' => 'Name asc',
    ],
]);

$matches = $fm->findRecords("Status eq 'Active'", [
    'queryOptions' => ['top' => 25],
]);
$one = $fm->getRecord(42); // Use the key/ROWID expected by YOUR table.`}</CodeExample><p>Use <code>top</code> and <code>skip</code> to request bounded pages. A record key is not a row’s position in a list. Limit database/table choices in your application code; never pass an arbitrary incoming URL or host to the FileMaker client.</p><p>When building a filter from a text value, escape apostrophes by doubling them: <code>O'Brien</code> becomes <code>O''Brien</code> inside an OData string literal. Allowlist field names and supported operations separately.</p></> },
    { id: 'dataapi', title: 'Using the Data API instead', content: <><p>The dashboard is OData-first, but the PHP Data API client remains available. It uses layouts and FileMaker find arrays rather than OData tables and filters. The account needs <code>fmrest</code>, and the Data API must be enabled.</p><CodeExample language="PHP · Data API">{`$fm = OpenBridge::dataApi([
    'database' => 'CRM.fmp12',
    'layout' => 'Contact Details',
]);
$result = $fm->findRecords([
    'query' => [['Status' => 'Active']],
    'limit' => 25,
]);
// Data API records preserve fieldData, portalData, recordId and modId.
foreach ($result['records'] as $record) {
    $fields = $record['fieldData'];
}`}</CodeExample><p>Keep any transport-specific credentials in protected server configuration. Per-call overrides do not grant privileges your FileMaker account does not already have.</p></> },
  ] },
  { id: 'javascript', label: 'JavaScript / TS', title: 'Use OpenBridge in a web application.', description: 'Your browser code calls an application endpoint. PHP calls FileMaker and returns only the allowed data.', chapters: [
    { id: 'endpoint', title: '1. Create your PHP endpoint', content: <><p>Create <code>/api/contacts.php</code> in your website. This read-only example expects your application to set <code>app_user_id</code> after signing in. Replace that guard with your existing authentication and permission checks.</p><CodeExample language="PHP · /api/contacts.php">{phpEndpoint}</CodeExample><p>This is your application route, not the OpenBridge administrator API. OpenBridge’s dashboard login does not automatically authenticate customers of your website. Keep tables, operations, and returned fields restricted to what that user may access.</p></> },
    { id: 'js', title: '2. Fetch it with JavaScript', content: <><p>The example uses the same website origin for the page and PHP endpoint. No FileMaker password or bridge token belongs in this request.</p><CodeExample language="Browser JavaScript">{jsRead}</CodeExample><p>Display a loading state, handle errors, and render returned text safely. If the application is installed in a subfolder, update the endpoint URL to match it.</p></> },
    { id: 'ts', title: 'TypeScript: validate the response', content: <><p>A TypeScript type alone does not validate JSON from a server. This version checks the returned records before using them.</p><CodeExample language="TypeScript">{tsRead}</CodeExample></> },
    { id: 'helpers', title: 'What openbridge.js does', content: <><p>The packaged ES module exports browser-safe helpers such as <code>normalizeOData</code> and <code>normalizeDataApi</code>. They format a response that your server has already fetched. Importing the module does not connect to FileMaker or authenticate your application.</p><CodeExample language="JavaScript · optional normalization">{`import { normalizeOData } from '/openbridge/openbridge.js';

// rawOData is an OData response already obtained by your server.
const result = normalizeOData(rawOData, {
  operation: 'Find contacts',
  database: 'CRM.fmp12',
  resource: 'Contacts',
});
// Do not normalize your own already-normalized endpoint response again.`}</CodeExample><p>When using source TypeScript in this checkout, the public entry point is <code>src/openbridge.ts</code>. The shipped JavaScript module works with ordinary ES module imports; a build tool is optional for that import.</p></> },
  ] },
  { id: 'reference', label: 'Reference', title: 'Methods and response format.', description: 'The OData client wraps the FileMaker engine while preserving the original field names and values.', chapters: [
    { id: 'methods', title: 'OData PHP methods', content: <div className="docs-table-wrap"><table><thead><tr><th>Method</th><th>Purpose</th></tr></thead><tbody>{[
      ['getRecords(options)', 'Read a page. Pass table directly; nest top, skip, select and orderby inside queryOptions.'],
      ['findRecords(filter, options)', 'Read records matching an OData filter expression.'],
      ['getRecord(key, options)', 'Read one entity by the key/ROWID supported by the table.'],
      ['getMetadata(options)', 'Inspect OData metadata and discovered tables.'],
      ['healthCheck(options)', 'Check configuration, authentication and resource access.'],
      ['createRecord(fields, options)', 'Create an entity. This changes FileMaker data.'],
      ['editRecord(key, fields, options)', 'Update an entity. This changes FileMaker data.'],
      ['deleteRecord(key, options)', 'Delete an entity. This changes FileMaker data.'],
      ['runScript(name, parameter)', 'Run a FileMaker script; effects depend on the script.'],
    ].map(([method, meaning]) => <tr key={method}><td><code>{method}</code></td><td>{meaning}</td></tr>)}</tbody></table><p>These methods exist in the PHP client. They still require the corresponding FileMaker permissions and server support. The beginner Playground keeps writes and scripts as code-only operations.</p></div> },
    { id: 'response', title: 'Read the normalized result', content: <><CodeExample language="Response shape · illustrative">{`{
  "ok": true,
  "transport": "odata",
  "operation": "find",
  "database": "CRM.fmp12",
  "resource": "Contacts",
  "records": [{ "Name": "Avery Labs", "Status": "Active" }],
  "returnedCount": 1,
  "totalCount": null,
  "nextLink": null,
  "messages": [],
  "meta": {},
  "raw": {}
}`}</CodeExample><p><code>returnedCount</code> counts records in this response. <code>totalCount</code> can be null if the upstream response does not provide a total. <code>nextLink</code> can describe another page; your server should validate and handle pagination rather than forwarding arbitrary upstream URLs from the browser.</p><p>OData rows retain their native fields. Data API rows retain their <code>fieldData</code> structure. <code>raw</code> contains the captured upstream response and can include more information than your frontend needs. Return a narrow field projection from public application endpoints.</p></> },
  ] },
  { id: 'security', label: 'Security', title: 'Keep credentials on the server.', description: 'Use your application’s authentication for its users and OpenBridge’s administrator session for its dashboard.', chapters: [
    { id: 'credentials', title: 'Passwords, keys and tokens', content: <><dl className="docs-definitions"><dt>FileMaker credentials</dt><dd>Enter the existing integration account’s username and password in Setup. PHP stores reusable credentials in its encrypted server vault.</dd><dt>Installation encryption key</dt><dd>Managed by PHP and kept separately from SQLite in protected configuration or a private key file. Back up the matching key with the database. Losing it can make encrypted data unrecoverable.</dd><dt>Administrator session</dt><dd>The dashboard uses a PHP HttpOnly session cookie. The browser does not need a separate bridge token for normal dashboard use.</dd><dt>Optional bridge token</dt><dd>For trusted external server clients. A generated draft must match private PHP configuration before it grants access. Never embed it in browser code.</dd><dt>Legacy browser key</dt><dd>The 44-character Base64 field is for older browser-encrypted diagnostic responses. It is not the password for FileMaker or the current server vault.</dd></dl></> },
    { id: 'production', title: 'Application checklist', content: <><ul><li>Use HTTPS and your own application authentication and authorization.</li><li>Restrict operations, tables and fields on the server. Protect write endpoints against cross-site request forgery.</li><li>Keep FileMaker passwords, service tokens and private keys out of JavaScript, public environment variables, repositories and logs.</li><li>Block direct web access to <code>db/</code>, private configuration and key files. Verify the rules on the actual host.</li><li>Start with read-only access. Test writes and scripts against disposable data before enabling them for users.</li><li>Retain response bodies only when needed, with appropriate access and retention limits.</li></ul></> },
  ] },
  { id: 'help', label: 'Troubleshooting', title: 'Find the failing connection step.', description: 'Separate browser, PHP, authentication and FileMaker errors before changing configuration.', chapters: [
    { id: 'errors', title: 'Common problems', content: <div className="docs-table-wrap"><table><thead><tr><th>What you see</th><th>What to check</th></tr></thead><tbody>{[
      ['401 / sign-in required', 'Your application endpoint needs its own valid user session. For dashboard requests, sign into OpenBridge again.'],
      ['403 / access denied', 'Check the FileMaker account privileges, API permission, table/field access and any application allowlist.'],
      ['404 or HTML instead of JSON', 'Check the PHP endpoint path and hosting document root. Confirm PHP executes instead of being downloaded.'],
      ['Cannot load contacts / 502', 'Run the dashboard connection check. Confirm the hosted database, entity-set name, server certificate and PHP cURL support.'],
      ['SQLite or vault unavailable', 'Check pdo_sqlite, sodium/OpenSSL, directory permissions and the matching installation key.'],
      ['Empty result', 'Check the exact field names, filter values, found-set permissions and whether the request succeeded with zero matching rows.'],
      ['Cross-origin browser error', 'Prefer a same-origin PHP route. If separate origins are required, configure narrowly allowed origins and credentials deliberately.'],
    ].map(([problem, help]) => <tr key={problem}><td>{problem}</td><td>{help}</td></tr>)}</tbody></table></div> },
    { id: 'limits', title: 'What is verified', content: <><p>These examples follow the local PHP factories and browser exports. A successful frontend build or sample response does not prove your FileMaker server is reachable. Verify real connection health, read permissions, record keys and representative results on your installation.</p><p>Outside-service entries in Advanced Integrations may be planned rather than implemented. They do not become connected merely by selecting them.</p></> },
  ] },
  { id: 'ai', label: 'AI handoff', title: 'Give your coding assistant the right context.', description: 'Share the public integration contract and the requested behavior. Keep credentials out of prompts.', chapters: [
    { id: 'handoff', title: 'A useful starting prompt', content: <><CodeExample language="Copyable prompt">{`Read OPENBRIDGE-AGENTIC.md and the installed public documentation.
Inspect openbridge/openbridge.php, restCWP/odataCWP.php and src/openbridge.ts
when working from the source checkout; use the equivalent packaged files
for an installed distribution.

Build a read-only application endpoint using OpenBridge::odata().
Keep authentication and FileMaker credentials on the PHP server.
Allow only the required tables, fields and operations.
Have TypeScript/JavaScript call that endpoint and validate its JSON result.
Preserve FileMaker field names exactly. Do not expose raw diagnostic payloads.
Do not run writes, scripts or deployment without explicit authorization.
Report local checks separately from live FileMaker evidence.`}</CodeExample><p>Describe your table and field names using non-sensitive examples. Supply secrets through protected server configuration, never through a prompt or browser bundle.</p></> },
  ] },
]

export function DocsPage({ initial = 'quick' }: { initial?: 'quick' | 'ai' }) {
  const [active, setActive] = useState<string>(initial)
  useEffect(() => setActive(initial), [initial])
  const current = tabs.find((tab) => tab.id === active) || tabs[0]
  return <section className="docs-book" aria-label="OpenBridge documentation">
    <header className="docs-book-header"><span>OpenBridge / Documentation</span><h2>Build with OpenBridge.</h2><p>Installation, working code examples, and a reference for your FileMaker integration.</p></header>
    <div className="docs-book-tabs" role="tablist" aria-label="Documentation sections">{tabs.map((tab, index) => <button type="button" role="tab" key={tab.id} id={`docs-tab-${tab.id}`} aria-selected={current.id === tab.id} aria-controls={`docs-panel-${tab.id}`} tabIndex={current.id === tab.id ? 0 : -1} onClick={() => setActive(tab.id)} onKeyDown={(event) => {
      let next = index
      if (event.key === 'ArrowRight') next = (index + 1) % tabs.length
      else if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length
      else if (event.key === 'Home') next = 0
      else if (event.key === 'End') next = tabs.length - 1
      else return
      event.preventDefault(); setActive(tabs[next].id); document.getElementById(`docs-tab-${tabs[next].id}`)?.focus()
    }}>{tab.label}</button>)}</div>
    <div className="docs-book-layout" role="tabpanel" id={`docs-panel-${current.id}`} aria-labelledby={`docs-tab-${current.id}`} tabIndex={0}>
      <aside className="docs-contents"><strong>On this page</strong><nav aria-label="On this page">{current.chapters.map((chapter) => <button type="button" key={chapter.id} onClick={() => document.getElementById(`doc-${current.id}-${chapter.id}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' })}>{chapter.title}</button>)}</nav></aside>
      <article className="docs-article"><header><h3>{current.title}</h3><p className="docs-lead">{current.description}</p></header>{current.chapters.map((chapter) => <section key={chapter.id} id={`doc-${current.id}-${chapter.id}`}><h4>{chapter.title}</h4>{chapter.content}</section>)}</article>
    </div>
  </section>
}
