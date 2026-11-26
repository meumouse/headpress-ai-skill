# WordPress backend setup

Canonical: Headpress `AGENTS.md` §3, §9, §10; starter `docs/WORDPRESS_SETUP.md`.

## Contents
1. Requirements and plugins
2. Headpress → Storefront (URL, origins, route map, keys, webhook)
3. Modules — what to enable and why
4. `wp-config.php` constants
5. Headless mode and SEO safety
6. Server tuning (PHP-FPM, object cache, cron, uploads)
7. Hardening checklist
8. Verification commands

---

## 1. Requirements and plugins

| Requirement | Floor |
|---|---|
| PHP | 8.1 |
| WordPress | 6.8 |
| WooCommerce | 9.0 (soft dependency of Headpress; commerce modules need it). HPOS-safe. |
| Headpress | Latest ZIP. 1.3+ for shipping quotes, price previews, `commerce.policies`, contract export; the SDK warns below `MIN_HEADPRESS_VERSION`. |

Typical plugin set (all optional except Headpress + WooCommerce):

- **SEO:** Rank Math (enable *Headless CMS Support*) or Yoast. Headpress reads them through their own
  PHP/REST API (never an HTTP loopback) and rewrites every URL to the storefront.
- **Installments / Pix price:** Parcelas Customizadas (`wc-custom-installments`, + Pro). Turn on its
  **Advanced → Headless → Headless mode** (Headpress also forces it when headless mode is active).
- **Shipping:** HubGo (public routes `hubgo/v1/shipping/calculate`, `/address/autocomplete`,
  `/address/resolve`), or plain WooCommerce shipping methods (Headpress `POST /store/shipping/quote`).
- **Checkout fields (Brazil):** Brazilian Market on WooCommerce (CPF/CNPJ, number, neighborhood,
  birth date) bridged by Headpress `checkout_fields`, **or** Headpress presets — not both for the same field.
- **Gateways** that support the Store API checkout (see `payment-gateways.md`).
- **WhatsApp:** Joinotify (passwordless OTP channel and event flows).
- **ACF / Secure Custom Fields** if content uses custom fields (Headpress `acf` module).
- **Email delivery:** either the storefront renders emails (Headpress `emails` module → webhook) or a
  `wp_mail` transport plugin.

Never install a plugin by copying single files over SSH: install from its release ZIP (see pitfalls).

## 2. Headpress → Storefront

1. **Storefront URL** — `https://site.com` (dev: `http://localhost:3000`).
2. **Allowed origins** — the storefront origin(s); keep *Strict origins* on. Include
   `http://localhost:3000` only while developing.
3. **Route map** — one pattern per object type, tokens `{slug}`, `{path}`, `{order_id}`,
   `{order_key}`, `{key}`, `{login}`, `{token}`. It drives sitemap, SEO canonicals, redirects of the
   WP front end, search results, email/WhatsApp links, payment links and revalidation paths.
   - The defaults are rarely right for an existing store — **set every key explicitly**.
   - Product categories and pages may share the root (`/{slug}`): the most specific match wins; the
     `route_collisions` health check lists slugs that clash.
   - Keys the storefront has no page for go under *Routes without a storefront page*
     (`storefront.disabled_routes`) — they drop out of the map, sitemap and path resolution.
   - Changing a pattern after launch breaks links already sent → add compatibility redirects on the
     storefront (`next.config.ts`).
   - Verify with the starter's `npm run check:routes -- https://admin.site.com`.
4. **Other REST namespaces for the storefront** — every plugin namespace the browser calls
   (`hubgo/v1`, a gateway's namespace if it doesn't self-register).
5. **API keys** — create a secret key `hp_sk_…` (copy it now, it's shown once) for the storefront's
   server env (`HEADPRESS_SECRET_KEY`); optionally a publishable key `hp_pk_…` and turn on *Require
   publishable key*.
6. **Revalidation webhook** — URL `https://site.com/api/revalidate`, turn on *Send revalidation
   webhooks*, copy the secret into `HEADPRESS_WEBHOOK_SECRET`. Use *Send test* and watch Headpress →
   Webhooks. Storefront-rendered emails reuse the same secret at `/api/emails/send`.
7. **Security** — *Trusted proxies* (CIDRs) and *Trust Cloudflare* when the WordPress host is
   proxied by Cloudflare. These are the panel equivalent of `HEADPRESS_TRUSTED_PROXIES` (§4).

**Filling the Storefront URL changes the live WordPress shop.** With the `woocommerce` module's *Use
storefront URLs for order received, cart and checkout* toggle (`woocommerce.storefront_urls`, default
**on**), WooCommerce's cart, checkout, order-received, view-order and My Account URLs point to the
storefront as soon as the URL is set. That happens even with headless mode off, in wp-admin emails
too. On a store that still sells through a classic theme, either set the URL only inside the cutover
window or turn that toggle off until then (see `deploy-golive.md` §7).

**Settings export/import.** Headpress → Settings → *Export settings* downloads every module's settings
and the active modules as JSON (`GET /headpress/v1/admin/settings/export`). Importing it on another
site (`POST /headpress/v1/admin/settings/import`) validates every module before writing anything. Use
it to rehearse on staging and apply the same configuration in production. Treat the file as
sensitive: it may hold secret fields such as the webhook secret. Never commit it. After importing,
replace staging values: storefront URL, origins and webhook URL.

**Panel labels in Portuguese (pt-BR).** Users of a pt-BR admin see these names. Quote them in that
language when the user writes in Portuguese:

| Setting key | English label | pt-BR label |
|---|---|---|
| menu `Storefront` | Storefront | Vitrine |
| `storefront.storefront_url` | Storefront URL | URL da vitrine |
| `storefront.allowed_origins` | Additional allowed origins | Origens adicionais permitidas |
| `storefront.strict_origins` | Strict origins | Origens restritas |
| `storefront.cors_namespaces` | Other REST namespaces for the storefront | Outros namespaces REST para a vitrine |
| `storefront.disabled_routes` | Routes without a storefront page | Rotas sem página na loja |
| `storefront.custom_routes` | Custom routes | Rotas personalizadas |
| `storefront.trusted_proxies` / `trust_cloudflare` | Trusted proxies / Trust Cloudflare | Proxies confiáveis / Confiar no Cloudflare |
| `storefront.require_publishable_key` | Require a publishable key | Exigir uma chave publicável |
| `storefront.revalidation_enabled` | Send revalidation webhooks | Enviar webhooks de revalidação |
| `storefront.webhook_url` / `webhook_secret` | Webhook URL / Webhook secret | URL do webhook / Segredo do webhook |
| `headless.redirect_status` | Redirect type | Tipo de redirecionamento |
| `headless.allowlist` | Paths that stay on WordPress | Caminhos que permanecem no WordPress |
| `headless.noindex_header` | Send X-Robots-Tag: noindex | Enviar X-Robots-Tag: noindex |
| `headless.allow_order_pay` | Keep the WooCommerce "Pay for order" page on WordPress | Manter a página "Pagar pedido" do WooCommerce no WordPress |
| `woocommerce.storefront_urls` | Use storefront URLs for order received, cart and checkout | Use URLs da loja para pedido recebido, carrinho e checkout |

Labels change between versions. For anything not listed, look the English string up in
`languages/headpress-pt_BR.po` (or `-es_ES.po`) of the installed Headpress.

## 3. Modules

Enable only what the storefront uses — every enabled module adds data to the config and routes.

| Module | Notes |
|---|---|
| `woocommerce` | On by default with Woo. Store policies (free-shipping threshold, min order → `400 headpress_min_order_amount`), unpaid order limit (`429 headpress_too_many_pending_orders`), order attribution, optional abandoned-cart capture. |
| `headless` | Redirect WP front end to the storefront. Start at **302**, switch to **301** after verifying gateway returns. |
| `seo` | Rank Math/Yoast/core head, sitemap data, redirects. |
| `content` | Pages/posts rendered, menus with storefront URLs, preview. Create menus at the locations the storefront reads (starter: `primary`, `footer`). |
| `checkout_fields` | Presets or the external-fields bridge (needed for Brazilian Market metas to keep feeding ERP/NF-e). |
| `installments`, `shipping` | Bridges to Parcelas Customizadas / HubGo. Unavailable until the plugin's version constant is defined (`WC_CUSTOM_INSTALLMENTS_VERSION`, `HUBGO_VERSION`). |
| `accounts`, `passwordless`, `google_sign_in`, `identity` | Customer auth (JWT + refresh), OTP by WhatsApp/email, Google, CPF/CNPJ identity. |
| `captcha` | reCAPTCHA on login/register/reset/OTP and add-on routes (`Headpress/Captcha/Routes`). Strongly recommended with WhatsApp OTP (cost abuse). |
| `wishlist`, `address_book`, `saved_payment_methods`, `reviews`, `search`, `recommendations`, `banners` | Storefront features. `reviews` with media needs `upload_max_filesize`/`post_max_size` ≥ 64M and a matching proxy body limit. |
| `split_payments` | One order paid in parts (gateway `headpress_split`). |
| `consent`, `ga4`, `meta_pixel`, `pinterest` | Consent categories/version; server-side purchase (MP / CAPI) on `order.paid`, consent-gated. Secrets (`api_secret`, `access_token`) are write-only. |
| `feeds` | Google Merchant / Meta / TikTok / Pinterest feeds, JSON search index. |
| `storage` | Media in S3-compatible bucket + CDN. |
| `emails` | Transactional emails rendered by the storefront (`Headpress/Emails/Catalog`), with *Route wp_mail*. |

## 4. `wp-config.php` constants

```php
define( 'HEADPRESS_TRUSTED_PROXIES', true );   // behind Traefik/Nginx/LB + Cloudflare ('private', 'cloudflare', CIDRs)
define( 'DISABLE_WP_CRON', true );             // when a real cron (system cron / Cronicle) calls wp-cron.php
define( 'WP_DEBUG_LOG', '/path/outside/docroot/debug.log' ); // never a public debug.log
// Secrets may come from constants instead of the panel: HEADPRESS_<MODULE>_<KEY>, e.g.
define( 'HEADPRESS_GA4_API_SECRET', '…' );     // the panel then shows the field locked
```

Without trusted proxies, every request looks like the proxy's IP: rate limits (OTP, login, relayed
analytics at 60/min) hit the whole store at once.

## 5. Headless mode and SEO safety

- Never set `blog_public = 0`; never "Discourage search engines"; never `Disallow: /` in the WP
  robots.txt (crawlers must follow the 301s). Headpress adds `X-Robots-Tag: noindex, nofollow` to what
  WP still serves and points `Sitemap:` to the storefront.
- Never filter `home_url` / `site_url` globally — wp-admin, REST and gateways depend on them.
  Headpress rewrites permalinks, emails and "View product" links instead.
- The allowlist keeps reachable: wp-admin, `wp-login.php`, REST, `admin-ajax.php`, `wp-cron.php`,
  `?wc-ajax=`, `/wc-api/*` (gateway IPN/webhooks), `checkout/order-pay/*` when enabled, feed files,
  `robots.txt`. A gateway webhook on a **custom rewrite endpoint** must be allowlisted by its plugin
  (`Headpress/Headless/Should_Redirect` → false for that request) — otherwise it gets redirected and
  payments never confirm.
- Order-received and payment return URLs are rewritten to the storefront route map automatically.

## 6. Server tuning (lessons from production)

- **PHP-FPM workers:** a headless page fires several REST calls in parallel; 5 workers exhaust fast.
  Start around `pm.max_children = 12` and size by RAM.
- **OPcache** ≥ 256 MB on a plugin-heavy site (watch for restarts).
- **Persistent object cache** (Redis) — big win for REST TTFB.
- **Remove dev drop-ins** in production (Query Monitor's `db.php`).
- **Real cron** instead of WP-Cron; clean orphan cron hooks left by an old theme/plugins.
- **Stock hold vs. Pix expiry:** long Pix/boleto windows need `woocommerce_hold_stock_minutes` aligned
  (e.g. 1440) or orders get cancelled while the shopper pays; pair with Headpress's unpaid-order limit
  (per account / email / CPF / IP) so unpaid orders can't lock stock.
- **Database memory limits** on containerized MariaDB/MySQL.

## 7. Hardening checklist

- [ ] No public backups, dumps or logs in the docroot (nginx ignores `.htaccess` — write nginx `deny`
      rules), and purge the CDN cache if they were ever exposed; then rotate DB passwords and salts.
- [ ] DB / Redis ports not exposed publicly; `DB_HOST` on the internal network name; no `root@'%'`.
- [ ] SSH keys only + fail2ban; no `chmod -R 777` in container start scripts.
- [ ] `wp/v2/users` not enumerable anonymously.
- [ ] Panel-generated config files (some Docker panels regenerate nginx/php.ini/start scripts) — keep
      hardening where the panel won't overwrite it, and re-check after panel updates.
- [ ] Gateway webhooks verified (signature or re-fetch from the PSP API), idempotent.
- [ ] Every outgoing integration opt-in, with timeouts, queued.

## 8. Verification

```bash
curl -s https://admin.site.com/wp-json/headpress/v1/store/config | jq '{routes, modules: (.modules|keys)}'
curl -sI https://admin.site.com/produto/some-product      # 302/301 → https://site.com/produto/some-product
curl -sI https://admin.site.com/wp-json/                  # x-robots-tag: noindex
curl -sI -H "Origin: https://site.com" https://admin.site.com/wp-json/wc/store/v1/cart  # access-control-allow-origin, expose Cart-Token
wp headpress contract export --dir=./contract-export       # contract snapshot for the SDK / reviews
```

Headpress → About → health checks: no `error`; warnings explained (302 redirect status is expected
until go-live).
