# Coding Playground Module Authoring

This guide is the quick path for adding one new Playground module at a time without breaking the registry.

## The rule of one file

Put each module in its own JSON file.

Use one stable file name per module, such as `src/data/playground-modules/list-records.json`.
Keep the file focused on one teaching idea so it is easy to review, test, and version.

## What must stay stable

- `id` should never change once a module ships.
- `version` starts at `1` and only increases when the contract changes.
- Keep the `id` lowercase kebab-case.
- Keep the module file name aligned with the `id`.

## Plain-English author checklist

1. Start from the module template below.
2. Fill in the beginner-facing fields first: `beginnerName`, `summary`, `purpose`, `learningGoals`, and `expectedResult`.
3. Add the developer-facing detail: `developerName`, `explanation`, and `commonMistakes`.
4. Choose the right safety level and run mode before adding code.
5. Add typed inputs with obvious labels, short help text, defaults, and sample values.
6. Fill in all four variant keys:
   - `php:data-api`
   - `php:odata`
   - `javascript:data-api`
   - `javascript:odata`
7. Mark each variant as either:
   - `supported` with a real `template`
   - `unsupported` with an honest `reason`
8. Add beginner comments and advanced comments.
9. Add a test fixture id and the placeholder names the template should expose.
10. Add links only when they help the learner.
11. Run validation before you open a pull request or copy the module into the app.

## Template

```json
{
  "id": "list-records",
  "version": 1,
  "status": "draft",
  "category": "read",
  "order": 10,
  "tags": ["records", "list", "beginner"],
  "method": "GET",
  "beginnerName": "List Records",
  "developerName": "List Records with safe read-only defaults",
  "summary": "Show how to fetch records from FileMaker.",
  "purpose": "Teach a beginner how to request data safely.",
  "difficulty": "beginner",
  "learningGoals": [
    "Understand the request shape",
    "See how placeholders map to the template",
    "Learn the difference between supported and unsupported variants"
  ],
  "explanation": "Explain the flow in plain English.",
  "commonMistakes": [
    "Using the wrong transport",
    "Forgetting a required input",
    "Putting secrets into the module file"
  ],
  "expectedResult": "A safe read-only request that returns records.",
  "safety": {
    "level": "read-only",
    "sideEffects": false,
    "runMode": "read-only-live",
    "confirmationText": "This module only reads data."
  },
  "support": {
    "languages": ["php", "javascript"],
    "transports": ["data-api", "odata"],
    "prerequisites": ["A configured FileMaker base URL"]
  },
  "inputs": [
    {
      "id": "layout",
      "label": "Layout name",
      "help": "The FileMaker layout to read from.",
      "type": "text",
      "required": true,
      "defaultValue": "Contacts",
      "sample": "Contacts"
    },
    {
      "id": "limit",
      "label": "Record limit",
      "help": "The maximum number of records to request.",
      "type": "integer",
      "required": true,
      "defaultValue": 10,
      "sample": 10,
      "validation": { "min": 1, "max": 1000 }
    }
  ],
  "variants": {
    "php:data-api": {
      "status": "supported",
      "template": "// Data API example\n$result = $bridge->getRecords(['_limit' => {{integer:limit}}]);"
    },
    "php:odata": {
      "status": "unsupported",
      "reason": "This example does not have an OData version yet."
    },
    "javascript:data-api": {
      "status": "supported",
      "template": "const resource = {{js:layout}};\nconsole.log(resource);"
    },
    "javascript:odata": {
      "status": "unsupported",
      "reason": "This example does not have an OData version yet."
    }
  },
  "comments": {
    "beginner": [
      "Keep this short and calm for new users."
    ],
    "advanced": [
      "Mention any transport-specific tradeoffs here."
    ]
  },
  "tests": {
    "fixtureId": "list-records",
    "expectedPlaceholders": [
      "{{integer:limit}}",
      "{{js:layout}}"
    ],
    "safeOperation": "list"
  },
  "links": [
    "docs/quick-start.md"
  ]
}
```

## Placeholder rules

- Use typed placeholders only.
- Available kinds are `php`, `phpDouble`, `odataPhpDouble`,
  `odataFieldPhpDouble`, `js`, `odataJs`, `odataFieldJs`, `integer`, and
  `raw`. Prefer the narrowest correct kind; `raw`
  should be reserved for trusted generated fragments rather than user text.
- Keep placeholder names short and readable.
- Match every placeholder in the template to an input in `inputs`.
- Do not hide a secret in a placeholder.
- Keep placeholder samples fake and safe.

## Safety and run modes

- `read-only-live` means the module can safely hit a live system for read operations.
- `code-only` means the module should stay as an example and not execute live requests.
- `disabled` means the module is documented but not runnable yet.
- `read-only` safety means the module should not change data.
- `side-effect` safety means the module may change state and should be treated carefully.
- `restricted` means the module is limited or sensitive and should require extra caution.

## Unsupported variants

If a language/transport pair is not ready, do not fake it.

Write `status: "unsupported"` and explain why in the `reason` field.
That keeps the registry honest and teaches beginners what is actually available.

## Validation commands

Run these from the project root:

```bash
pnpm lint
pnpm test
pnpm build
pnpm validate:phase2
```

If you only changed module files, `pnpm test` and `pnpm build` are usually the most important checks after linting.
