# OpenBridge demo data

These UTF-8, comma-separated files are fictional practice data for importing into FileMaker or another development database. The email domains use `example.test`; they are not real contacts.

## Suggested FileMaker tables

### People

Import `people.csv` into a table named `People`.

| Field | Suggested FileMaker type | Notes |
| --- | --- | --- |
| `person_id` | Text | Primary key; keep unique and stable. Do not use the row number. |
| `first_name`, `last_name`, `email`, `phone`, `company`, `job_title`, `city`, `state`, `country`, `status` | Text | Preserve the CSV field names for straightforward API examples. |

### Inventory

Import `inventory.csv` into a table named `Inventory`.

| Field | Suggested FileMaker type | Notes |
| --- | --- | --- |
| `item_id` | Text | Primary key; keep unique and stable. |
| `sku`, `item_name`, `category`, `description`, `currency`, `location`, `status` | Text | `description` may be a Text field with a larger character limit. |
| `quantity_on_hand`, `reorder_level` | Number | Whole-number quantities. |
| `unit_cost` | Number | Use two-decimal display formatting if desired. |

Recommended layouts are `People` and `Inventory`, matching the table names. If you use separate layouts, expose the same field names or update the OpenBridge request settings accordingly.

## Import steps

1. In FileMaker Pro, create a new development file and create the `People` and `Inventory` tables.
2. Add the fields above, using the suggested types. Set `person_id` and `item_id` as unique primary-key fields.
3. Choose **File > Import Records > File**, select the appropriate CSV, and choose comma as the separator.
4. Confirm that the first row contains field names and map each column to the matching field.
5. Review the imported records, then create layouts named `People` and `Inventory`.
6. Enable the FileMaker Data API or OData only in a development/server environment where you control access. OpenBridge can select a transport, but it cannot enable FileMaker privileges for you.

## Demo connection values

The following are placeholders for a local or hosted demo environment only:

- Username: `rgcdata_demo`
- Password: `beta_demo`

**DEMO ONLY.** Change or remove these credentials before exposing any server or database publicly. Do not use them for production, and do not commit real credentials to a repository.

FileMaker Pro must create and save the actual `.fmp12` file. This folder intentionally contains CSV and documentation only; it does not contain a FileMaker database file.
