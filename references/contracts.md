# Contracts between WordPress and the storefront

Everything here is consumed across repositories. Canonical sources: Headpress `AGENTS.md` §6–§8,
§11 and `docs/api/*.md`; SDK `contract/` (JSON Schema snapshots + `routes.json`).

## Contents
1. Namespaces and scopes
2. Public store config
3. Store API extensions (`extensions.headpress`)
4. Client → server data (cart actions, checkout data, checkout fields)
5. Session: Cart-Token and customer tokens
6. CORS and rate limiting
7. Revalidation webhook and tag vocabulary
8. Events
9. Response, error and money conventions
10. Keeping the contract in sync (SDK codegen)

---

## 1. Namespaces and scopes

| Namespace | Owner | Use |
|---|---|---|
| `wc/store/v1` | WooCommerce Store API | Catalog, cart, checkout — the commerce contract |
| `headpress/v1` | Headpress + add-on modules | Config, SEO, content, accounts, reviews, search, payments, add-on routes |
| plugin namespaces (`hubgo/v1`, `paypal-store-api/v1`, …) | Third-party plugins | Must be added to Headpress CORS to be called from the browser |
| `wp/v2` | WordPress core | Avoid; only where Headpress has no route yet (e.g. post lists) |

`headpress/v1` scopes (set by a route class's `$scope`; **default is `admin`**):

| Scope | Prefix | Auth |
|---|---|---|
| `store` | `/store/*` | Public. `X-Headpress-Key: hp_pk_…` when *Require publishable key* is on |
| `customer` | `/store/customers/me*` | store + `Authorization: Bearer <access JWT>` (accounts module); responses `private, no-store` |
| `server` | `/server/*` | `Authorization: Bearer hp_sk_…` — server-to-server only, never from a browser |
| `admin` | `/admin/*` | `manage_headpress` capability + `X-WP-Nonce` (Vue panel only; never CORS) |

Keys: `hp_pk_…` publishable (public, identifies the storefront), `hp_sk_…` secret (shown once,
stored hashed). Disabled modules' routes return `404 rest_no_route` — the storefront treats that as
"feature off", not as an error.

Customer-scoped data about orders is authorized by the order key (`?key=wc_order_…`) or the signed-in
customer — **never by id alone**.

## 2. Public store config — `GET /headpress/v1/store/config`

Fetched by the storefront at build/revalidation time, cached under tag `headpress:config`.
Shape (abridged):

```json
{
  "schema_version": 1,
  "version": "c1f3e9a2",               // payload hash, sent as ETag (If-None-Match → 304)
  "store":   { "name", "description", "language": "pt-BR", "timezone", "headpress_version",
               "urls": { "storefront", "backend", "rest", "store_api" } },
  "routes":  { "product": "/produto/{slug}", "product_cat": "/categoria/{slug}", "page": "/{slug}",
               "post": "/blog/{slug}", "cart": "/carrinho", "checkout": "/checkout",
               "order_received": "/pedido/{order_id}?key={order_key}", "account": "/minha-conta", … },
  "commerce":{ "currency": { "code", "symbol", "minor_unit", "decimal_separator", … },
               "prices_include_tax", "selling_countries", "units", "pages", "policies": { … } },
  "modules": { "<module_id>": { "enabled": true, …public settings…, …public_config()… } }
}
```

- `modules` lists **enabled and available** modules only. A missing module = feature off.
- `routes` is the **route map** from Headpress → Storefront. A key missing from `routes` is a
  disabled route — SDK route helpers return `null`; never invent a path.
- Money in the config is in minor units (integers).
- `GET /store/config/schema` returns its JSON Schema (generated from field definitions).
- Cached server-side in transient `headpress_store_config`; invalidated on `settings.updated`, module
  toggles and relevant WooCommerce options. An add-on that derives config from another plugin's
  option must flush it: `add_action( 'update_option_<their_option>', array( $container->get( 'public_config' ), 'flush_and_notify' ) );`
- Filters: `Headpress/Store/Config` (final payload), each module's `public_config()`.

## 3. Store API extensions — `extensions.headpress.<module_id>`

- One namespace, `headpress`; each module contributes a sub-key. Endpoints: `product`, `cart`,
  `cart-item`, `checkout`.
- Modules return definitions from `store_api_extensions()`:
  `array( 'endpoint' => 'cart', 'description' => '…', 'data' => callable, 'schema' => callable )`
  (`'writable' => true` on `checkout` to accept input). Headpress registers them on `init` priority 20
  and isolates failures (a throwing callback yields `{}` and is logged).
- Callbacks run for every product in a list — keep them cheap. Heavy data belongs on a route.
- Other plugins keep their own namespaces (`extensions["wc-custom-installments"]`, a gateway's own
  namespace). Headpress links to them (e.g. `modules.installments.store_api_key`), never copies.
- Known parts: `installments.price_preview` (Pix/boleto price + highlighted installment, products),
  `shipping.free_shipping` + per-rate delivery estimate (cart), `checkout_fields.external` (cart),
  `acf` (products), `reviews`, `search`… see `docs/api/store-api.md`.

## 4. Client → server data

**Cart actions.** The Store API allows one update callback per namespace. Headpress registers one for
`headpress` and dispatches on `data.action = "<module_id>.<action>"`:

```http
POST /wp-json/wc/store/v1/cart/extensions
Cart-Token: <token>
{ "namespace": "headpress", "data": { "action": "store_notice.dismiss" } }
```

Register handlers with `$container->get( 'store_api.router' )->add( '<module>.<action>', callable )`;
throw `Automattic\WooCommerce\StoreApi\Exceptions\RouteException` to fail (unknown action →
`400 headpress_unknown_action`). SDK: `hp.cart.action("headpress", { action, … })`.

**Checkout data.** The storefront sends `extensions.headpress` in `POST /wc/store/v1/checkout`;
`Store_Api\Checkout_Data` persists it on `woocommerce_store_api_checkout_update_order_from_request`
and fires `do_action( 'Headpress/Checkout/Data', $order, $data )`. Reserved keys: `attribution`
(GA client/session ids, `fbp`/`fbc`/`epik`, `utm_*`, click ids, landing path, referrer, WooCommerce
order-attribution keys) and `consent` (granted categories + version). **It is public input** —
validate it, never trust it for pricing.

**Checkout fields.** Custom fields use `woocommerce_register_additional_checkout_field()` (Headpress
ids `headpress/<key>`; add-ons use their own prefix `<prefix>/<key>`). Fields other plugins add to the
*classic* checkout (`woocommerce_checkout_fields`, e.g. Brazilian Market's CPF/number/neighborhood) are
ignored by the Store API — Headpress `checkout_fields` bridges them through
`extensions.headpress.checkout_fields.values` and saves them where the classic checkout would
(`_billing_cpf` etc.), which keeps ERP/NF-e integrations working.

## 5. Session

- **Cart:** the `Cart-Token` header (JWT) returned by `/cart` and `/checkout` responses. The client
  always replaces its stored token with the latest one. It replaces the Store API `Nonce`. The SDK
  stores it in a first-party cookie (`hp_cart`, `cookieCartToken()`) or localStorage.
- **Customer:** accounts module. Short-lived access JWT (kept in memory) + rotating refresh token
  (30 days, reuse detection revokes all sessions, 30 s race grace). In the starter the refresh token
  lives in an **httpOnly cookie set by the storefront's own BFF** (`/api/auth/[...action]`,
  `createAuthHandler`), never in JS. Signing in merges the guest cart into the customer's and returns
  the customer's Cart-Token.
- Tokens are never issued to users with `edit_posts`, `manage_woocommerce` or `manage_headpress`
  (staff can't sign in on the storefront with their admin account — use a customer account to test).
- Add-on routes outside `/headpress/v1/store/` and `/wc/store/` that need the Bearer customer must be
  added with `Headpress/Accounts/Token_Routes`.
- **Never** ship `woocommerce_store_api_disable_nonce_check`.

## 6. CORS and rate limiting

- Headpress sends CORS headers for the configured storefront origins on `headpress/v1`, `wc/store`
  and the extra namespaces from the setting *Other REST namespaces for the storefront*
  (`storefront.cors_namespaces`) or the filter `Headpress/Storefront/Cors_Namespaces`. Every plugin
  namespace the **browser** calls must be listed. `headpress/v1/admin/*` never gets CORS.
- It does **not** use `allowed_http_origins` (that would expose admin-ajax with cookies).
- Since WooCommerce 11.3.1 the Store API sends `Access-Control-Allow-Credentials` only to
  `allowed_http_origins` → the storefront must call it **without credentials**
  (`credentials: "omit"`, the SDK default). Do not "fix" this by adding credentials in Headpress.
- `Cart-Token` must be in allowed **and** exposed headers (Headpress handles it at `PHP_INT_MAX`).
- Don't let browser tracing libraries add `sentry-trace`/`baggage` to WP requests — preflights fail.
  Set `tracePropagationTargets: []`.
- **Rate limits and client IP:** when the storefront server calls WP on behalf of a shopper, send
  `X-Headpress-Client-IP` together with `X-Headpress-Secret: hp_sk_…` (the BFF does this when
  `HEADPRESS_SECRET_KEY` is set). Behind a proxy/CDN, define trusted proxies
  (`HEADPRESS_TRUSTED_PROXIES` — CIDRs, `private`, `cloudflare`, or `true` for both). Never read
  `X-Forwarded-For[0]` / `CF-Connecting-IP` directly anywhere.

## 7. Revalidation webhook

Headpress → `POST https://site.com/api/revalidate` (queued job, debounced ~5 s, retried 30 s / 2 min /
10 min / 30 min with the same delivery id, every attempt logged on Headpress → Webhooks):

```http
X-Headpress-Event: product.updated          (or "batch")
X-Headpress-Delivery: evt_…
X-Headpress-Timestamp: 1790000000
X-Headpress-Signature: sha256=HMAC_SHA256(timestamp + "." + body, webhook_secret)

{ "id": "evt_…", "event": "product.updated", "events": ["product.updated"],
  "tags": ["product:123", "product:slug:camiseta", "product:list"], "paths": ["/produto/camiseta"],
  "sent_at": "…" }
```

Receivers reject signatures older than 5 minutes and de-duplicate by delivery id (the SDK's
`createRevalidateRoute` / `createWebhookHandler` do both). Answer non-2xx to get a retry.

Tag vocabulary: `headpress:config` · `{post_type}:{id}` · `{post_type}:slug:{slug}` ·
`{post_type}:list` · `term:{taxonomy}:{id}` · `term:{taxonomy}:slug:{slug}` · `term:{taxonomy}:list`
· `menu:{location}` · `menu:id:{id}` · `seo:redirects` · `seo:sitemap` · `feed:{id}` · `banners` ·
`banners:{placement}` · `banner:{id}` · `reviews:product:{id}`. Add-ons add tags/paths for their
events with `Headpress/Revalidation/Tags` / `Headpress/Revalidation/Paths`
(`array $tags, string $event, array $payload`).

The same signing scheme is used for **storefront-rendered emails** (`POST /api/emails/send`, body
`{ template, to, data, idempotency_key }`; 2xx final, 503 retried after 1 min/10 min/1 h, other 4xx
permanent). Templates are registered in WP with `Headpress/Emails/Catalog`.

## 8. Events

Dotted names from `Events\Event_Catalog` (`product.updated`, `product.stock_changed`,
`product.back_in_stock`, `order.created`, `order.paid`, `order.status_changed`, `cart.abandoned`,
`review.approved`, `payment.paid`, `account.registered`, `banner.updated`, …). Emit with
`headpress_emit_event( 'name', array( 'id' => 12 ) )` → `do_action( 'Headpress/Events/<name>', $payload )`.
Register new names with `Headpress/Events/Catalog` (`name => description`). Payloads carry **ids and
slugs only**, never personal data. Network side effects (webhooks, Measurement Protocol, CAPI) are
**queued jobs**, never inline in a save request.

## 9. Conventions

- Success returns the resource itself (no `{status:"success"}` envelope), `200/201/204`. Lists return
  arrays + `X-WP-Total` / `X-WP-TotalPages`, `page`/`per_page` (max 100).
- Errors are `WP_Error` with a prefixed code and a **real HTTP status** (`headpress_*` in core,
  `<prefix>_*` in add-ons): 400 invalid param, 401, 403, 404, 409 conflict, 422 validation
  (`data.fields`), 424 integration unavailable, 429 rate limited (+ `Retry-After`), 502 upstream.
  Never an error with HTTP 200.
- Dates ISO 8601 with offset. Money in minor units. Public GETs send `Cache-Control` + `ETag`;
  personal responses `no-store, private`. Every Headpress response sends `X-Robots-Tag: noindex`.
- `v1` is additive-only; removals/renames/type changes need `v2`.
- Store API reads in the SDK are camelCase (`Product`, `Cart`); Headpress account payloads keep the
  contract's snake_case; money becomes `Money`.

## 10. Keeping the contract in sync

- Headpress: `wp headpress contract export --dir=<dir>` writes `store-config.schema.json` + `routes.json`
  (every `store/*`, customer and `server/*` route must implement `schema()`). Snapshots in
  `tests/contracts/`; updating them (`npm run test -- --update-snapshots`) is deliberate and needs a
  CHANGELOG entry.
- SDK: `pnpm contract:pull --from <dir> --plugin <headpress dir>` (or `--url <site>`), then
  `pnpm codegen` (regenerates `src/generated/store-config.ts`; never edit by hand), commit both, add
  a changeset. CI runs `pnpm codegen --check`.
- Add-on modules are **not** typed in the SDK core: type them in the store repo by declaration merging
  (`ModuleConfigMap`, `ErrorDataMap`) — see `addon-plugins.md`.
