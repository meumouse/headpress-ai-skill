# Custom add-on plugins integrated with Headpress

Use this when a store needs something no core module covers, or when bridging a third-party plugin.
Canonical: Headpress `DEVELOPERS.md` (hooks table §11), `AGENTS.md` §5–§8 and §13.4,
`examples/headpress-addon-example/`. A ready-to-copy skeleton is in `assets/addon-template/`.

## Contents
1. Decide: add-on, core, or storefront?
2. Plugin skeleton and boot
3. The module class
4. Settings schema
5. REST routes
6. Store API data, cart actions, checkout input
7. Events, revalidation, jobs, emails, health checks, captcha, rate limits
8. Storefront side: SDK plugin + types
9. Admin UI (only when the schema form is not enough)
10. Bridging a third-party WordPress plugin
11. Migrating from a legacy plugin
12. Testing, build and release
13. Naming and prefixes

---

## 1. Decide first

- **Store-specific** (a quote form, a client discount rule, a carrier's tracking, an ERP sync, a
  personalization step, lead capture) → an **add-on plugin** owned by the store project, prefixed
  with the project prefix.
- **Generic** (every store would want it) → a PR to Headpress core. Don't bury generic features in a
  client add-on.
- **Presentation only** → the storefront, no plugin needed.
- One add-on per store usually holds several modules (`<prefix>_leads`, `<prefix>_tracking`, …), each
  toggleable on its own. Payment gateways are usually their own plugin (see `payment-gateways.md`).

## 2. Plugin skeleton and boot

```
<slug>/
  <slug>.php            header + constants + autoloader + boot on plugins_loaded
  src/Plugin.php        registers modules on Headpress/Modules/Register (try/catch per module)
  src/Modules/<Area>/<Area>_Module.php
  src/Modules/<Area>/Rest/<Name>_<Method>.php
  src/Support/          small helpers (settings wrapper, errors, rate gate)
  assets/admin/         optional panel JS (plain, uses window.headpress.vue)
  languages/
  tests/                plain-PHP unit tests + WP-CLI integration harness
  scripts/build.mjs     version check → tests → stage allowlist → zip
  CHANGELOG.md, README.md, AGENTS.md (+ CLAUDE.md pointing to it)
```

Main file essentials:

- Header: `Requires Plugins: headpress, woocommerce`, `Requires PHP: 8.1`, `Requires at least: 6.8`,
  `WC requires at least`, `Text Domain: <slug>`; if it ships through MDS, `Update URI`.
- Declare HPOS compatibility on `before_woocommerce_init`
  (`FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true )`).
- **Use a tiny `spl_autoload_register` PSR-4 loader** (or Composer with a normal — not authoritative —
  classmap, always built into the ZIP). A Composer authoritative classmap plus one file uploaded by
  hand took down every REST route in production.
- On `plugins_loaded`: if `! function_exists( 'headpress_register_module' )`, show an admin notice and
  stop. Otherwise boot. Register modules before Headpress collects them on `init` priority 1 — either
  `headpress_register_module( $module )` or `add_action( 'Headpress/Modules/Register', fn( $registry ) => $registry->add( $module ) )`.
- Wrap each module registration in try/catch and log through
  `headpress()->container()->get( 'logger' )` — one broken module must never take the others down.

## 3. The module class

Extend `MeuMouse\Headpress\Modules\Abstract_Module`. Only `id()` and `label()` are required.

| Method | Purpose |
|---|---|
| `id()` | Stable snake_case id with the project prefix (`<prefix>_leads`). **Public contract — never rename.** |
| `label()`, `description()`, `icon()` (Boxicons name, or URL), `category()` (`storefront`, `commerce`, `marketing`, `privacy`, `other`) | Panel card |
| `requirements()` | Dependency keys (`woocommerce`, `rank-math`, `yoast`, `wc-custom-installments`, `hubgo`, `acf`, `joinotify`, `brazilian-market`, or your own — §10). Nested array = any of. |
| `default_enabled()` | `false` for client modules — enable them deliberately, one wave at a time |
| `sections()`, `settings()` | Field definitions (§4) |
| `register( Container $c )` | Runs for every registered module: services (`$c->set( '<prefix>_x', fn() => new X() )`), health checks, privacy exporters/erasers |
| `boot()` | Hooks — runs **only when available and enabled** |
| `routes()` | Route class names, registered only while active (disabled → `404 rest_no_route`) |
| `store_api_extensions()` | `extensions.headpress.<id>` parts |
| `public_config( array $settings )`, `public_config_schema()` | Computed public values next to the public settings in `modules.<id>` |

Cross-module access goes through the container (`headpress()->container()->get( 'modules' )->is_active( 'consent' )`),
never another module's option. Useful services: `logger`, `settings`, `modules`, `events`, `jobs`,
`public_config`, `storefront.routes`, `storefront.urls`, `store_api.router`, `accounts.data`,
`emails.dispatcher`, `split_payments`, `joinotify`. Helpers: `headpress_get_setting( $module, $key, $fallback )`,
`headpress_storefront_url( $post_or_term_or_path )`, `headpress_emit_event()`. Never `get_option( 'headpress_settings' )`.

Refuse a toggle when unsafe: `Headpress/Modules/Validate_Toggle` (`WP_Error|null $error, string $id, bool $enabled`).

## 4. Settings schema

Declared once; Headpress generates defaults, sanitization, validation (422 with per-field messages),
the admin form (**no JavaScript needed**), the public config and its JSON Schema.

```php
array(
	'key'         => 'quote_email',           // ^[a-z][a-z0-9_]*$
	'type'        => 'email',                 // text|textarea|number|toggle|select|multiselect|list|color|url|email|repeater|object|secret|code
	'label'       => __( 'Quotes inbox', '<slug>' ),
	'description' => __( 'Where quote requests are sent.', '<slug>' ),
	'default'     => '',
	'visibility'  => 'private',               // public → /store/config · private → admin only · secret → encrypted, write-only
	'section'     => 'general',
	'required'    => false,
	'depends_on'  => array( 'enabled' => true ), // UI only
	'validate'    => null,                    // callable( $value, $field, $input ): true|string|WP_Error
)
```

- Native JSON types (toggles are `bool` — no `'yes'`/`'no'`). `number` takes `integer`, `min`, `max`,
  `step`; `select`/`multiselect` take `options` or `options_callback`; `repeater` takes `fields`,
  `max_items`, `item_label`; `object` takes `fields` (partial updates merge).
- `secret`: the panel only sees `{ is_set, is_locked }`; overridable by the constant
  `HEADPRESS_<MODULE>_<KEY>` in `wp-config.php`. Never put a secret in `public`.
- Add fields to another module with `Headpress/Settings/Fields/<module_id>`; react to saves with
  `Headpress/Settings/Saved` (`$module, $new, $old, $changed_keys`).
- Fields that accept HTML/JS (code injection) must reject users without `unfiltered_html`.

## 5. REST routes

One class per method + route, extending `MeuMouse\Headpress\Rest\Abstract_Route` (constructor gets
the `Container` as `$this->container`):

```php
class Quote_Post extends Abstract_Route {
	protected string $route   = '/store/<prefix>/quotes';
	protected string $methods = 'POST';
	protected string $scope   = 'store';              // DEFAULT IS 'admin' — always set it
	public function args(): array   { /* JSON Schema params with sanitize/validate — never read $_POST */ }
	public function schema(): array { /* draft-07 response schema — mandatory for store/customer/server */ }
	public function handle( WP_REST_Request $request ) { /* array | WP_REST_Response | WP_Error */ }
}
```

- Paths: `/store/<prefix>/…` (public), `/store/customers/me/…` with `$scope = 'customer'`,
  `/server/<prefix>/…` with `$scope = 'server'` (secret key — for the storefront server, never the
  browser). Override `permission()` only for third-party webhooks that verify themselves.
- Extra middleware: `MeuMouse\Headpress\Rest\Middleware\Cache` (ETag + public cache) for public GETs;
  personal responses `Cache-Control: no-store, private`.
- Success → the resource itself; errors → `new WP_Error( '<prefix>_<code>', $msg, array( 'status' => 4xx ) )`.
  Never 200 with an error body. Don't swallow upstream failures into "empty" answers (a 404 turned into
  `{ exists: false }` hid a broken integration for weeks) — propagate a real error (502).
- Routes in your own namespace (`<slug>/v1`) are fine for webhooks; add it to CORS only if the browser
  calls it: `Headpress/Storefront/Cors_Namespaces`.

## 6. Store API data, cart actions, checkout input

```php
public function store_api_extensions(): array {
	return array(
		array(
			'endpoint'    => 'product',             // product | cart | cart-item | checkout
			'description' => 'Personalization options of the product.',
			'data'        => fn( $product ) => array( 'personalizable' => (bool) $product->get_meta( '_<prefix>_personalizable' ) ),
			'schema'      => fn() => array( 'personalizable' => array( 'type' => 'boolean' ) ),
		),
	);
}
// boot(): a cart action, POST /wc/store/v1/cart/extensions { namespace: "headpress", data: { action: "<prefix>_x.apply", … } }
$this->container->get( 'store_api.router' )->add( '<prefix>_x.apply', function( array $data ) { /* throw RouteException to fail */ } );
// boot(): checkout input sent as extensions.headpress.<prefix>_x
add_action( 'Headpress/Checkout/Data', function( \WC_Order $order, array $data ) { /* validate + $order->update_meta_data() */ }, 10, 2 );
```

Keep product callbacks cheap (they run per product in lists). Use WooCommerce CRUD for orders (HPOS).
Prices still come from WooCommerce: change them with WooCommerce hooks (fees via
`woocommerce_cart_calculate_fees`, coupons), never from storefront input.

## 7. Events, revalidation, jobs, emails, health, captcha, rate limits

```php
add_filter( 'Headpress/Events/Catalog', fn( $c ) => $c + array( '<prefix>.quote_created' => 'A quote was requested. Payload: id.' ) );
headpress_emit_event( '<prefix>.quote_created', array( 'id' => $quote_id ) );       // ids/slugs only
add_filter( 'Headpress/Revalidation/Tags', function( array $tags, string $event, array $payload ) {
	return '<prefix>.banner_updated' === $event ? array_merge( $tags, array( '<prefix>:banners' ) ) : $tags;
}, 10, 3 );
$this->container->get( 'jobs' )->schedule_once( '<prefix>_sync_order', 0, array( $order_id ) ); // network calls in jobs
add_filter( 'Headpress/Jobs/Hooks', fn( $h ) => array_merge( $h, array( '<prefix>_daily_sync' ) ) ); // recurring hooks, unscheduled on deactivation
add_filter( 'Headpress/Health/Checks', fn( $checks ) => $checks /* + array( 'id','label','status' => 'ok|info|warning|error','message' ) */ );
add_filter( 'Headpress/Captcha/Routes', fn( $r ) => $r + array( '/headpress/v1/store/<prefix>/quotes' => '<prefix>_quote' ) ); // token in X-Headpress-Captcha
```

- Rate limits: `MeuMouse\Headpress\Security\Rate_Limiter::hit( $bucket, $limit, $window )` (per client
  IP) and `hit_subject( $bucket, $subject, $limit, $window )` (per email/phone/order); both return
  `false` when exceeded → answer 429 with `retry_after`. Client IP only via `Rate_Limiter::client_ip()`.
- Transactional emails rendered by the storefront: `Headpress/Emails/Catalog`
  (`'<template_id>' => array( 'label', 'audience', 'events' => array( '<event>' ), 'replaces' => array( '<wc_email_id>' ), 'build' => callable )`)
  + a matching template in the storefront.
- Extend customer/order payloads: `Headpress/Accounts/Customer`, `Headpress/Accounts/Order`.
- Personal data in your tables → register privacy exporters/erasers in `register()`.
- Outgoing requests: opt-in setting, timeout, in a job, documented.

## 8. Storefront side: SDK plugin + types

In the storefront repo (not in the SDK):

```ts
// src/lib/headpress/<prefix>-plugin.ts
import { definePlugin } from "@meumouse.com/headpress";

export const store = definePlugin({
  name: "<prefix>",                                   // hp.<prefix>.*
  setup: ({ request }) => ({
    requestQuote: (body: { name: string; email: string; product_id: number }, captcha: string) =>
      request<{ id: number }>("/store/<prefix>/quotes", { body, captcha, module: "<prefix>_quotes" }),
    // Cart-bound add-on routes: send and refresh the shopper's Cart-Token.
    applyGift: (code: string) => request("/store/<prefix>/gift", { body: { code }, cartToken: true, module: "<prefix>_gifts" }),
  }),
  messages: { "pt-BR": { "<prefix>_quote_closed": "Os pedidos de orçamento estão fechados." } },
});

// src/lib/headpress/<prefix>-types.ts — declaration merging for the add-on's config and error data
declare module "@meumouse.com/headpress" {
  interface ModuleConfigMap {
    "<prefix>_quotes": { enabled: true; min_quantity: number };
  }
}
```

Add the plugin to `clientOptions().plugins`. `module: "<prefix>_quotes"` makes the SDK fail fast
with `headpress_module_disabled` when the module is off. Plugins can also translate add-on error codes
(`onError`) and observe requests (`onRequest` / `onResponse`). Add the routes to the mock server so the
demo keeps working.

## 9. Admin UI

Settings need no JS. For more (a preview panel, a custom field type, a full screen):

- Enqueue on `Headpress/Admin/Enqueue_Scripts` and push to the queue
  `(window.headpressAddons = window.headpressAddons || []).push( api => … )`.
- `api`: `vue` (Headpress's Vue — **never bundle Vue**; mark it external if building with Vite),
  `registerModulePanel( moduleId, comp )`, `registerFieldComponent( type, comp )`,
  `registerAdminPage( pageId, comp )`, `request( method, path, body )` (nonce included), `i18n`,
  `ui` (BaseCard, BaseButton, DataTable, TabBar, ModalDialog, ConfirmDialog, FormField, BaseInput,
  BaseSelect, SwitchInput, StatTile, TrendChart… — check `api.ui` exists), `router`, `toasts`.
- No template compiler at runtime: render functions (`api.vue.h`) or precompiled SFCs. Tailwind is
  scoped to `#headpress-app`. **Use the panel components, not raw `<button>/<input>/<table>`.**
- Full screens: `Headpress/Admin/Pages` (`id => { slug, title, page_title, description, panel, position, parent }`,
  `parent: 'woocommerce'` to live under WooCommerce) + `registerAdminPage`.

## 10. Bridging a third-party WordPress plugin

1. Module with `requirements()` on the plugin. Custom key:
   ```php
   add_filter( 'Headpress/Dependencies/Is_Active', fn( $active, $key ) => '<plugin-key>' === $key ? defined( 'THEIR_VERSION' ) : $active, 10, 2 );
   add_filter( 'Headpress/Dependencies/Labels', function( $l ) { $l['<plugin-key>'] = 'Their Plugin'; return $l; } );
   ```
   Detect at runtime (constant/class); never `require` their files.
2. Read the plugin through its **public PHP API**; confirm every symbol exists in its source.
3. Turn off its theme/frontend rendering through its headless filter if it has one.
4. Expose computed public config + compact Store API parts; **link** to its own `extensions.<ns>`
   (e.g. `store_api_key`) instead of copying.
5. Flush the store config on its option changes (`update_option_<option>` → `public_config->flush_and_notify`).
6. Add its REST namespace to CORS if the browser calls it.
7. Keep its webhook endpoints reachable in headless mode (`Headpress/Headless/Should_Redirect`).
8. If several interchangeable services exist, define a provider interface (Headpress has registrars
   for SEO, feeds, saved payment methods, search sources, storage, OTP channels).

## 11. Migrating from a legacy plugin

- Run old and new side by side (strangler pattern); a single "ownership" switch decides which one
  handles a shared runtime path, so nothing runs twice.
- An idempotent importer (state option + lock transient) maps old options → Headpress settings through
  the settings repository (`$container->get( 'settings' )->save( $module, $values )`), moves user meta in
  batches (only where the target is empty), and never deletes legacy data. Provide a "Reimport" screen
  or WP-CLI command.
- Keep legacy hook names/data shapes that other plugins depend on until those plugins migrate.
- Snapshot the old API's responses before you start — they are the regression baseline.

## 12. Testing, build and release

- Plain-PHP unit tests for pure classes (no WordPress), plus an integration harness run by
  `wp eval-file tests/integration/run.php` on a local site with the plugins linked (Windows:
  `mklink /J`). Mock outbound HTTP with `pre_http_request`, capture mail with `pre_wp_mail`, clean up
  everything you create, restore settings you change.
- Validate your settings definitions through Headpress's real field normalizer when `HEADPRESS_DIR`
  is defined, so schema mistakes fail in CI rather than in the panel.
- `npm run lint:php` with a PHP 8.1 binary (no syntax newer than 8.1).
- Build script: refuse when versions disagree (header, constant, `package.json`, changelog section),
  run tests, stage an **allowlist** (`<slug>.php`, `src/`, `assets/`, `languages/`), zip nested under
  the slug folder with `/` separators (`\` separators break WordPress installs). Install from the ZIP.
- Keep a changelog with a "Configuration / action required" block per release.
- Licensing (if distributed through MDS): the license gates **updates and downloads only**, never
  features.

## 13. Naming and prefixes

| Thing | Pattern |
|---|---|
| PHP namespace | `<Vendor>\<Plugin>\…`, one class per file, PSR-4 |
| Module ids | `<prefix>_<area>` (snake_case, stable) |
| Routes | `/headpress/v1/store/<prefix>/…`, `/server/<prefix>/…`; own namespace `<slug>/v1` for webhooks |
| Error codes | `<prefix>_<snake_case>` |
| Hooks you add | `<slug>/<area>/<name>` (follow the add-on's style; Headpress's own are `Headpress/Area/Name`) |
| Options / tables / cron hooks | `<prefix>_…`, `{$wpdb->prefix}<prefix>_…` |
| Checkout field ids | `<prefix>/<key>` |
| Text domain | the plugin slug |

Code style: follow the surrounding repo. Headpress-style is WPCS, TABs, `array()`, `defined( 'ABSPATH' ) || exit;`,
docblocks with `@since`/`@version`, `$wpdb->prepare()` with `%i` for table names. Everything in English;
user-facing strings through `__()`.
