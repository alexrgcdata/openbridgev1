# Getting started

1. Install the source or generated folder using [installation.md](installation.md).
2. Create the first OpenBridge administrator account.
3. Enable OData on FileMaker Server and grant `fmodata` to a dedicated account.
4. In Setup FileMaker, enter the HTTPS server address, exact hosted database name,
   OData entity-set/table, username and password. Save the connection.
5. Run Test Connection and confirm the table is readable.
6. For PHP pages, include `openbridge/openbridge.php` and use
   `OpenBridge::odata()`. For React/JS/TS, create an authenticated application
   PHP endpoint and call it from the browser.

See the complete [PHP and browser guide](using-openbridge-libraries.md).

The browser never connects directly to FileMaker with reusable credentials.
The PHP factories load trusted saved defaults or explicit server-side overrides.
Your application remains responsible for its own authentication, permissions,
allowed tables, returned fields and write protections.

Sample names in the docs are synthetic. Replace them with exact FileMaker names.
A sample response or passing build is not live FileMaker verification.
