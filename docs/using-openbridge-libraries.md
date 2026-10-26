# PHP, JavaScript and TypeScript integration

PHP runs on the server and holds FileMaker credentials. Browser JS/TS calls your
own authenticated PHP endpoint. The OpenBridge admin login does not automatically
authenticate customers of your application. Examples use synthetic Contacts,
Name and Status identifiers; replace them with your exact FileMaker names.

## PHP page

For an installed folder beside this PHP page, use the saved server-side defaults:

```php
<?php
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
}
```

In a source checkout, the include is also openbridge/openbridge.php relative to
the project root. For multiple administrators, select trusted vault defaults in
server configuration rather than accepting a user ID from an incoming request.
OData query options belong inside queryOptions; use comma-separated select fields.

## Your application endpoint

Create /api/contacts.php alongside the installed /openbridge/ folder. Replace the
app_user_id session guard with your application's real sign-in and authorization
checks. This example intentionally returns 401 until that authentication exists.
It permits only a fixed read and returns a narrow field projection.

```php
<?php
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
}
```

## Browser JavaScript

Use a same-origin route. Handle loading/failure in your UI and render returned
text with JSX or textContent, never raw innerHTML. No FileMaker token is required
in browser source.

```js
// Browser JavaScript — calls YOUR PHP endpoint, not FileMaker.
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
const contacts = await getContacts();
```

## TypeScript

Validate returned JSON at runtime. Pass an AbortController signal when fetching
from a React effect, and abort it during cleanup.

```ts
type Contact = { Name: string; Status: string };

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
// If using an effect, pass an AbortController's signal and abort on cleanup.
```

## Browser library

The packaged openbridge.js is an ES module containing normalization and other
browser-safe helpers. Importing it does not connect directly to FileMaker or grant
access to the dashboard. The source TypeScript entry is src/openbridge.ts.

## Data API

Use OpenBridge::dataApi with an explicit trusted layout override. Data API finds
accept query arrays; OData finds accept filter strings and tables. Consult the
[reference](api-reference.md) and PHP class signatures before using a write or script.

These examples are syntax/type-checked documentation, not live FileMaker evidence.
