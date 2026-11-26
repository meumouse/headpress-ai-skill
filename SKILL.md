---
name: headpress-store
description: Build a headless WooCommerce store end to end with WordPress + WooCommerce + the Headpress plugin (backend, REST headpress/v1, Store API extensions, headless mode, revalidation webhooks) and the Headpress Next.js storefront starter + @meumouse.com/headpress SDK (frontend), including custom WordPress add-on plugins that integrate with Headpress for client-specific features, Store API payment gateways, migration from a classic theme store, Docker deploy and go-live. Use this skill whenever the user mentions Headpress, a headless WooCommerce / Next.js store, the storefront starter, the Headpress SDK, `extensions.headpress`, `headpress/v1`, Cart-Token, a "storefront" for WordPress, migrating a WooCommerce theme store to headless, or wants a new plugin/module "integrated with Headpress" — even if they only ask about one piece (a checkout, a payment method, a deploy, a CORS error, a route map, an add-on).
---

# Headpress headless store — from zero to deploy

This skill is the field guide for building and operating a store on this stack:

```
 shoppers / crawlers ──▶ site.com  (Next.js storefront: starter + SDK)      ◀── only indexable host
                           │  public reads (server, cached)   ▲ POST /api/revalidate (HMAC webhook)
                           ▼  cart/checkout/account (browser) │
                         admin.site.com (WordPress + WooCommerce + Headpress + add-ons + gateways)
                           • wp-admin, /wp-json (headpress/v1, wc/store/v1, plugin namespaces)
                           • public frontend 301 → storefront, X-Robots-Tag: noindex
```

It condenses the canonical docs of three repositories plus lessons from a real production cutover.
The repositories stay the source of truth — this skill tells you **what to read, in what order, and
what goes wrong**. Versions below were current in Nov 2026 (Headpress 1.8.0, SDK 0.1.2, starter 0.2.1);
always check the installed versions before relying on a detail.

## Where the knowledge lives

| Piece | Repository | Read first |
|---|---|---|
| Backend plugin **Headpress** | `github.com/meumouse/headpress` (WordPress plugin, PHP 8.1, Vue 3 admin) | `AGENTS.md` (architecture, contracts), `DEVELOPERS.md` (public add-on API + hooks table §11), `docs/api/*.md` (one file per module), `examples/headpress-addon-example/` |
| **SDK** `@meumouse.com/headpress` | `github.com/meumouse/headpress-sdk-js` (pnpm monorepo, npm package, shadcn registry) | `AGENTS.md`, `README.md`, `docs/guia-pt-BR.md`, `registry/` |
| **Storefront starter** | `github.com/meumouse/storefront-starter-headpress-nextjs` (Next.js 16, React 19, Tailwind 4) | `AGENTS.md`, `docs/ARCHITECTURE.md`, `docs/WORDPRESS_SETUP.md`, `docs/INTEGRATIONS.md`, `docs/STORE_API.md`, `docs/EMAILS.md`, `docs/DESIGN_SYSTEM.md` |

The SDK monorepo also has `apps/starter` — that is the SDK's own demo. **Start new stores from the
standalone starter repository.**

Known doc drift (verify before quoting): Headpress `AGENTS.md` still names the SDK `@headpress/sdk`
(real name: `@meumouse.com/headpress`), lists an `Addons/` directory and `headpress_register_event()`
that do not exist (add-ons are plain modules; add events with the `Headpress/Events/Catalog` filter).
The starter's `AGENTS.md` mentions `src/i18n/messages/` — dictionaries are actually `src/i18n/<domain>.ts`.

## Reference files — load the one you need

| File | Load when |
|---|---|
| `references/contracts.md` | Anything that crosses the WP ↔ storefront boundary: REST scopes and keys, store config, `extensions.headpress`, cart actions, checkout data, Cart-Token, CORS, webhook + tag vocabulary, events, error/money conventions. |
| `references/wordpress-setup.md` | Installing and configuring the backend: plugins, Headpress settings, route map, modules, API keys, `wp-config.php` constants, server tuning and hardening, headless mode/SEO. |
| `references/storefront.md` | Working on the Next.js side: starter structure and rules, env vars, SDK wiring, pages/routes, theming, i18n, payments registry, emails, caching/outages, SEO, analytics. |
| `references/addon-plugins.md` | Building a **custom WordPress plugin integrated with Headpress** (client-specific features, bridges to third-party plugins), and the matching SDK plugin on the storefront. Template in `assets/addon-template/`. |
| `references/payment-gateways.md` | Making a gateway work in the headless (Store API) checkout, on both sides. |
| `references/deploy-golive.md` | Docker/CI, hosting topology, reverse proxy + Cloudflare, migration from a classic store, cutover runbook, smoke test, rollback, monitoring. |
| `references/pitfalls.md` | Before shipping anything, and whenever something "mysteriously" breaks. Real incidents and their fixes. |

## Golden rules (they apply to every phase)

1. **The server decides, the storefront shows.** Prices, Pix/boleto discounts, installments, interest,
   shipping, free-shipping progress, split shares, stock and review eligibility are computed by
   WordPress/Headpress and *displayed* by the storefront. Never `price * 0.9` or `price / 12` in the UI.
   If a value is missing, show nothing and add it to the server (store config, Store API extension or
   a route). A UI that promises a discount the server does not apply is a consumer-law problem.
2. **API first.** Every capability is a REST route or Store API extension before it is a screen. The
   Headpress admin panel and the storefront are both clients of the same contracts.
3. **Don't duplicate WooCommerce.** Catalog, cart and checkout are the Store API (`wc/store/v1`).
   Headpress and add-ons *extend* it (`extensions.headpress.<module>`) — they never re-implement it.
   Don't copy data another plugin already puts in the Store API; link to it.
4. **Extend, don't edit the core.** Client-specific features live in an **add-on plugin** that
   registers Headpress modules through the public API. If the add-on would need to edit Headpress,
   Headpress is missing an extension point — add it there (separate change, versioned).
5. **Contracts are cross-repo.** Module ids, store-config shape, route shapes, `extensions.headpress`
   keys, the route map and the webhook tags are consumed by other repositories. Never rename a module
   id; treat shape changes as breaking (version them, update `tests/contracts/`, the SDK `contract/` +
   codegen, and changelogs).
6. **Never hurt the storefront's SEO.** Never set `blog_public = 0` / "Discourage search engines" on
   the WordPress host; never `Disallow: /` there. Headless mode de-indexes WP with headers and 301s.
7. **Money is integers in minor units** end to end (`Money` in the SDK). No floats.
8. **Secrets never reach the browser** (`visibility: secret`, `HEADPRESS_SECRET_KEY` server-only, no
   secrets in payment-method `config`). Card data never touches the storefront server or WordPress —
   gateways tokenize in the browser.
9. **An outage is not a 404.** "Does not exist" → `notFound()`; "WordPress did not answer" → an
   unavailable state. A 404 during an outage de-indexes pages.
10. **Repository hygiene:** code, comments, docs and commits in English (user-facing strings go through
    i18n); no AI attribution / co-author lines; don't commit, push, publish or run destructive commands
    unless asked. Follow each repo's own style (Headpress: WPCS, TABs, `array()`, `Headpress/Area/Name`
    hooks; starter: 2 spaces, double quotes, one imperative-sentence commit messages).

## The workflow — zero to deploy

Work in phases. Each phase ends with a check you can actually run. Don't jump to the storefront
before the backend answers `GET /wp-json/headpress/v1/store/config`.

### Phase 0 — Discovery (decide before building)

Collect, and write down in the project's plan file:

- **Domains:** storefront on the apex (`site.com`), WordPress on a subdomain (`admin.site.com`). Same
  registrable domain avoids third-party-cookie surprises, though the stack needs no WP cookies.
- **Locale & currency** (one locale per deployment in the starter), selling countries.
- **Route map** — the URL pattern of every page type (product, category, tag, page, post, cart,
  checkout, order received, account, password reset, order pay…). For a migration, **keep the old
  URLs** whenever possible; every change needs a 301.
- **Payment gateways** and how each works headless (see `references/payment-gateways.md`): hosted
  redirect, offline (Pix/boleto/transfer with polling), or card form tokenized in the browser.
- **Shipping** (HubGo / WooCommerce methods / vendor routes), **installments** (Parcelas
  Customizadas), **checkout fields** (CPF/CNPJ, number, neighborhood — Brazilian Market plugin or
  Headpress presets), **SEO plugin** (Rank Math / Yoast), **analytics** (GA4, Meta, Pinterest) and
  consent (LGPD/GDPR), **emails** (storefront-rendered via webhook, or WordPress `wp_mail`).
- **Client-specific features** that no core module covers → they go into the add-on (Phase 4).
  For each one, decide where it belongs with the table below.
- **Hosting:** where WP runs (VPS + Docker panel, managed WP), where the Node storefront runs
  (Docker image), CDN/proxy (Cloudflare), Redis.

**Where does a feature belong?**

| The need | Put it in |
|---|---|
| A setting the storefront must read | A field with `visibility: public` on a module → `modules.<id>` in `GET /store/config` |
| Data attached to products / cart / checkout | `store_api_extensions()` of a module → `extensions.headpress.<module>` |
| A cart mutation (dismiss, apply, set preference) | A cart action `"<module>.<action>"` on the `store_api.router` |
| Something the shopper sends at checkout | `woocommerce_register_additional_checkout_field()` or `extensions.headpress` in `POST /checkout` (validate it — it is public input) |
| A new endpoint | A route class under `headpress/v1` (`/store/<prefix>/…`, `/store/customers/me/…`, `/server/<prefix>/…`) |
| Bridge to a third-party WP plugin | A module with `requirements()` on that plugin (built-in or custom dependency key) |
| Store-only UI, copy, layout | The storefront (`src/features/<domain>`), reading the config/extension |
| A generic capability every store would want | Headpress core (separate PR in the Headpress repo), not the add-on |

### Phase 1 — Backend (WordPress)

Follow `references/wordpress-setup.md`. Summary: WordPress ≥ 6.8, PHP ≥ 8.1, WooCommerce ≥ 9.0,
Headpress (requires a ZIP from MDS / the repo build), the integration plugins, then in **Headpress →
Storefront**: storefront URL, allowed origins (add `http://localhost:3000` while developing), route
map, extra CORS namespaces, webhook URL + secret, API keys (`hp_sk_…`, optional `hp_pk_…`). Enable
modules deliberately. Keep headless redirects at **302** until the storefront is verified.

Check: `curl -s https://admin.site.com/wp-json/headpress/v1/store/config | jq .routes` returns your
route map; `curl -I https://admin.site.com/` redirects to the storefront; Headpress → About → health
checks show no errors.

### Phase 2 — Storefront

Follow `references/storefront.md`. Summary: clone the starter, `npm install`, `npm run demo` (mock
WordPress on :8787, no WP needed) to see a working store; then `.env.local` from `.env.example`
pointing `NEXT_PUBLIC_WP_URL` at the real backend, align `src/config/routes.ts` with the route map,
rebrand through tokens (`src/styles/tokens.css`, fonts in `layout.tsx`, `src/config/site.ts`), add
store pages under `src/app/(store)`.

Check: `npm run check:routes -- https://admin.site.com` passes, no CORS errors in the browser console,
prices/installments/shipping match WooCommerce, `npm run verify` (lint + typecheck + test + build) passes.

### Phase 3 — Integrations (payments, shipping, installments, analytics, emails)

Payments: `references/payment-gateways.md`. Each gateway = a WordPress plugin that processes the Store
API checkout + publishes its public client config via `Headpress/Store/Payment_Method_Config/<id>` +
a storefront provider in `src/integrations/<gateway>/` registered in
`src/features/checkout/payments/registry.ts`. Shipping/installments are Headpress modules bridging
HubGo / Parcelas Customizadas; turn on Parcelas Customizadas → Advanced → Headless. Analytics are
consent-gated, purchases sent server-side on `order.paid` with a shared `event_id`.

Check: one test order per gateway in sandbox, order-received page opened on another device, purchase
counted exactly once in each analytics tool.

### Phase 4 — Custom add-on plugin(s)

When the store needs something specific (quote requests, a lead form, a custom discount rule, an ERP
sync, tracking from a carrier, a personalization step), build an add-on plugin — see
`references/addon-plugins.md` and copy `assets/addon-template/`. In short: a separate plugin with
`Requires Plugins: headpress, woocommerce`, modules extending `MeuMouse\Headpress\Modules\Abstract_Module`
registered on `plugins_loaded`, settings declared as field definitions (the admin UI is generated),
routes extending `Abstract_Route` with `args()` + `schema()`, a project prefix (`<prefix>_` module
ids, `<prefix>_*` error codes, `/store/<prefix>/…` routes), and on the storefront an SDK
`definePlugin()` + declaration merging for typed access.

Check: the add-on's modules appear in Headpress → Modules, toggling one makes its routes 404
(`rest_no_route`) / reappear, `modules.<id>` shows up in the store config, its harness tests pass,
the release ZIP installs on a clean site.

### Phase 5 — SEO, content and migration data

SEO head via `seoMetadata()` (Rank Math/Yoast through Headpress, URLs already rewritten to the
storefront), SEO-plugin redirects applied in `src/proxy.ts`, structural legacy redirects in
`next.config.ts`, storefront-owned `robots.ts` and sitemap. For a migration: export the old URL list
(products, categories, posts, pages, paginated archives, filter query strings) and map every one;
import legacy settings/user meta idempotently in the add-on (never delete legacy data).

Check: crawl the old URL list against the new storefront — every URL is 200 or a single 301 hop, no
redirect chains, no 404s that used to be indexed.

### Phase 6 — Deploy

`references/deploy-golive.md`. Storefront as a Docker image (`output: "standalone"`, Node 24 alpine,
non-root), `NEXT_PUBLIC_*` passed as **build args** (they are inlined at build time), server secrets as
**runtime env** only. CI builds and pushes an image per commit/tag for rollback. Behind a proxy,
configure trusted proxies on both sides (`HEADPRESS_TRUSTED_PROXIES` in `wp-config.php`). Redis for
the storefront cache if available.

### Phase 7 — Go-live and operate

Run the cutover runbook and smoke test in `references/deploy-golive.md`. After verification, switch
headless redirects to **301**. Turn off any legacy tracking the same minute the storefront goes live
(otherwise purchases double-count). Watch Headpress → Webhooks deliveries, gateway webhooks, error
monitoring and Search Console for the first weeks. Decommission legacy plugins only after logs show
zero traffic to their namespaces, and rotate secrets that touched old environments.

## Definition of done (whole project)

- [ ] `GET /store/config` serves the right `routes`, `commerce` and `modules`; no secret/private value in it.
- [ ] Storefront `check:routes` passes; `npm run verify` passes; no CORS error in the console.
- [ ] Catalog, cart (guest and signed-in, guest cart merges on sign-in), checkout for every gateway, order received, account area, password reset — all tested on the real backend.
- [ ] Saving a product in wp-admin produces a delivered webhook and the storefront updates within seconds.
- [ ] WordPress host: front-end 301s to the storefront, `X-Robots-Tag: noindex`, `blog_public` untouched, sitemap served by the storefront.
- [ ] Every legacy URL resolves (200 or one 301). Old links already sent by email/WhatsApp still work.
- [ ] Analytics: consent banner gates tags; one purchase per order per tool; server-side purchase only after payment.
- [ ] Storefront survives WordPress being down (cached pages render, no 404 storm).
- [ ] Security: no public backups/logs, DB/Redis not exposed, rate limits see the real client IP, security headers set.
- [ ] Add-on: release ZIP built by its script, installed from ZIP (never by copying single files), tests green.
- [ ] Changelogs updated in every repo touched; "action required in WordPress" steps written down for the operator.
