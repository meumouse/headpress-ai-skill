# Add-on template

A minimal, working Headpress add-on with one module ("quote requests") that shows every common
extension point: settings schema (public + private), a public config value, a store-scope route with
`args()`/`schema()`, captcha, rate limits and real error statuses, a Store API product extension, a
custom event, a background job, a health check — plus the matching storefront SDK plugin.

```
acme-store-addon.php          header, PSR-4 autoloader, HPOS, boot on plugins_loaded (11)
src/Plugin.php                registers modules on Headpress/Modules/Register (try/catch per module)
src/Modules/Quotes/Quotes_Module.php
src/Modules/Quotes/Rest/Quote_Post.php
scripts/build.mjs             version check → allowlist → release/<slug>-<version>.zip ("/" separators)
storefront/acme-plugin.ts     goes to the storefront repo (src/lib/headpress/), not into the plugin ZIP
```

To start a real add-on:

1. Copy the folder, rename `Acme` (namespace), `acme` (prefix: module ids, routes, error codes, meta,
   hooks), `acme-store-addon` (slug, text domain, file name) and `ACME_STORE_ADDON_*` constants.
2. Add `package.json` (`"version"` equal to the plugin header), `CHANGELOG.md`, `AGENTS.md`.
3. Replace the Quotes module with the store's modules; keep one module per feature so each toggles on
   its own. Module ids are permanent once the storefront uses them.
4. Activate on the dev site → Headpress → Modules → enable → check `GET /wp-json/headpress/v1/store/config`
   shows `modules.acme_quotes`, and `POST /wp-json/headpress/v1/store/acme/quotes` answers (422/404/201).
5. Storefront: copy `storefront/acme-plugin.ts`, add it to the client's `plugins`, add the route to
   the mock server, build the UI in `src/features/<domain>`.
6. Smoke test on a dev site with the module enabled: `wp eval-file wp-content/plugins/<slug>/tests/smoke.php`
   (creates and deletes its own test product and quote; adapt it to the real modules).
7. `node scripts/build.mjs` and install the ZIP on staging. Keep `tests/` out of the ZIP allowlist.
