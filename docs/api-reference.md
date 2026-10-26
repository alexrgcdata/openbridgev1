# PHP method and response reference

Include `openbridge/openbridge.php` from a source checkout, or
`openbridge.php` from the installed folder. The public factories are:

- `OpenBridge::odata($overrides = [])`: normalized OData client.
- `OpenBridge::dataApi($overrides = [])`: normalized Data API client.
- `OpenBridge::fromDefaults($overrides = [])`: legacy raw-response client.

Trusted overrides can select database, layout/table and transport options. Do not
forward untrusted request objects into these factories. Saved-default selection
uses trusted server configuration or one unambiguous administrator vault entry.

## OData client

| Call | Purpose |
| --- | --- |
| `getRecords($options)` / `listRecords($options)` | Read a collection page |
| `findRecords($filter, $options)` | Read using an OData filter string |
| `getRecord($key, $options)` | Read an entity using its key/ROWID |
| `getMetadata($options)` | Inspect OData metadata and tables |
| `healthCheck($options)` | Check authentication, database and table access |
| `createRecord($fields, $options)` | Create an entity; changes FileMaker |
| `editRecord($key, $fields, $options)` | Update an entity; changes FileMaker |
| `deleteRecord($key, $options)` | Delete an entity; changes FileMaker |
| `runScript($name, $parameter)` | Run a script with script-dependent side effects |

Pass `table`/`database` directly. Nest `top`, `skip`, `select`, `orderby`, `count`
and other supported OData query options under `queryOptions`. `select` is a
comma-separated field list, not an array. OData filter values double apostrophes;
field names and allowed expressions must be validated separately.

OData records retain their native properties. Data API find calls instead use
FileMaker query arrays and layouts, and returned records retain `fieldData`,
`portalData`, `recordId` and `modId`. Record keys are not list positions.

## Normalized response

`ok`, `transport`, `operation`, `database`, `resource`, `records`, `returnedCount`,
`totalCount`, `nextLink`, `messages`, `meta`, `raw`.

`returnedCount` is this response's row count. A total/next link may be null.
Preserve field names and check `ok`. Raw upstream payloads can contain additional
data: project a narrow allowed field set before responding to browser users.

See [working examples](using-openbridge-libraries.md). These are PHP method
contracts, not proof of permission or compatibility on a particular FileMaker host.
