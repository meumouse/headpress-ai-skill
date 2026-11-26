# Storefront (Next.js starter + SDK)

Canonical: starter `AGENTS.md` and `docs/*`; SDK `README.md`, `AGENTS.md`. The starter's `AGENTS.md`
has a managed block: Next.js 16 differs from what you remember — read
`node_modules/next/dist/docs/` before writing framework code (e.g. middleware is now `src/proxy.ts`).

## Contents
1. Getting started
2. Layers and directory map
3. Environment variables
4. Talking to WordPress (SDK wiring, safe reads, sessions)
5. Rules for changes (styling, text, components)
6. Adding pages and routes
7. Caching, revalidation and outages
8. SEO
9. Analytics and consent
10. Emails
11. Ready-made components (shadcn registry)
12. Store-specific code (add-on routes, SDK plugin)
13. Testing and verification

---

## 1. Getting started

```bash
git clone https://github.com/meumouse/storefront-starter-headpress-nextjs.git my-store
cd my-store && npm install
npm run demo        # mock WordPress on 127.0.0.1:8787 + next dev on :3000 — no WP needed
cp .env.example .env.local   # then point NEXT_PUBLIC_WP_URL at the real backend
npm run dev
npm run check:routes -- https://admin.site.com
```

Stack: Next 16 (App Router, `output: "standalone"`), React 19, TypeScript strict
(`noUncheckedIndexedAccess`), Tailwind 4 (`@tailwindcss/postcss`), Vitest, TanStack Query, zod,
React Email, `@meumouse.com/headpress`. Node ≥ 22.18 (Docker uses 24-alpine).

The mock (`mock-server/`, built on the SDK's `mockHeadpress`) answers the same contract as a real store
(demo login `demo@example.com` / `demo-password`, coupons `WELCOME10`, `FREE100`, CEP `01310-100`,
CPF `529.982.247-25`, OTP `123456`). `mock-server/data/config.mjs` is the best reference for the full
`store/config` shape. Keep the mock working when you add features — it is how others develop without WP.

## 2. Layers and directory map

Import direction: `app` → `features` → `integrations` → `lib`.

```
src/app/                 routes only (metadata, server reads, composition)
  layout.tsx             revalidate = 300, fonts, StoreProviders
  (store)/…              shop, search, category/[slug], tag/[slug], product/[slug], blog, cart,
                         [...path] (CMS pages), account/(area)/…, account/(auth)/…
  (checkout)/checkout, (checkout)/checkout/order-received
  api/auth/[...action]   BFF (createAuthHandler)
  api/revalidate         webhook receiver (createRevalidateRoute)
  api/emails/{send,preview}
  robots.ts, sitemap.ts
src/proxy.ts             SEO-plugin redirects (createRedirectResolver + redirectResponse)
src/components/ui/       design system (button, dialog, field, …) — imports only lib/utils
src/components/layout/   Header, Footer, MobileNav, StoreProviders, StoreUnavailable
src/features/<domain>/   account, analytics, cart, catalog, checkout (+ payments/), content, emails,
                         recommendations, reviews, search
src/integrations/<plugin>/  one folder per WP plugin/gateway (installments, shipping, cielo, paypal, …)
src/lib/                 env.ts (public), env.server.ts ("server-only"), headpress/{browser,server,options,safe-read}.ts, money.ts
src/config/routes.ts     STOREFRONT_ROUTES, UNROUTED_KEYS, paths  (no imports — Node loads it)
src/config/site.ts       store name fallback, menu locations, perPage, filter attributes
src/i18n/<domain>.ts     dictionaries, all languages side by side
src/styles/tokens.css    design tokens (--ds-*, oklch)
```

## 3. Environment variables

`NEXT_PUBLIC_*` are **inlined at build time**: changing one needs a rebuild, and every new one must be
added to `src/lib/env.ts`, the Dockerfile (`ARG` + `ENV`) and the CI `build-args`. Read public vars only
via `env.ts`, server vars only via `env.server.ts` (importing it client-side fails the build).

| Var | Scope | Meaning |
|---|---|---|
| `NEXT_PUBLIC_WP_URL` | build | WordPress URL, no trailing slash. **Unset = silently targets the mock** — fail the build in CI if empty. Also feeds `images.remotePatterns`. |
| `NEXT_PUBLIC_SITE_URL` | build | Storefront URL for canonicals, sitemap, `metadataBase`. Empty → `localhost` baked into SEO; fail CI if empty. |
| `NEXT_PUBLIC_HEADPRESS_PUBLISHABLE_KEY` | build | `hp_pk_…` when the store requires it. |
| `NEXT_PUBLIC_STORE_LOCALE` | build | `en` / `pt-BR` / `es` — one locale per deployment. |
| `HEADPRESS_SECRET_KEY` | runtime | `hp_sk_…` for the auth BFF (forwards the shopper IP so rate limits are per shopper). |
| `HEADPRESS_WEBHOOK_SECRET` | runtime | Verifies `/api/revalidate` and `/api/emails/send`; unset → every delivery refused. |
| `EMAIL_PROVIDER`, `RESEND_API_KEY`, `EMAIL_FROM`, `EMAIL_REPLY_TO`, `EMAIL_LOGO_URL` | runtime | Storefront-rendered emails (`log` sends nothing). |

Common additions in real stores: `REDIS_URL` / `REDIS_KEY_PREFIX` (cache), captcha site key, GTM id,
search-console verification tokens, error-monitoring DSN.

## 4. Talking to WordPress

**Always through the SDK** (`hp.catalog`, `hp.cart`, `hp.checkout`, `hp.auth`, `hp.customer`,
`hp.content`, `hp.seo`, `hp.request(path, { api, module, … })`). No hand-built `fetch` to WP.

- `src/lib/headpress/options.ts`: shared options — `baseUrl`, `publishableKey`, `locale`,
  `messages`, `plugins: [nextCache()]`, `timeoutMs`.
- `browser.ts`: singleton with `session: bffSession({ basePath: "/api/auth" })` and
  `cartToken: cookieCartToken()`. All personal calls (cart, checkout, account, payments) run in the
  **browser**, directly against WordPress.
- `server.ts`: singleton with **no session and no Cart-Token** — public reads only. Never share a
  session-carrying client across requests (if you need per-request server calls, create one per
  request: `createServerHeadpress({ secretKey, clientIp })` from `/server`).
- `safe-read.ts`: `safeRead(read)` → `{ ok: true, data }` | `{ ok: false, error }`. `null` data →
  `notFound()`; `ok: false` → `<StoreUnavailable/>` (never 404). `readOr(fallback, read)` for optional
  data (menus, reviews, recommendations).
- Errors are `HeadpressError { code, status, data }`; show them with `hp.formatError()` /
  `useFormatError()`. The SDK never follows redirects (`headpress_unexpected_redirect`) and replays
  once after a shared session refresh on 401.
- Public GETs go without a Bearer token (`auth: "none"` default) — Headpress disables caching for
  signed-in requests.
- Feature flags = `config.modules.*`: `useCapability("reviews")` (client) or
  `hp.capabilities.get("reviews")` (server). Module off → feature renders nothing.

## 5. Rules for changes

- **Server decides, storefront shows** — no price/discount/installment/shipping math. Use
  `extensions.headpress.installments.price_preview`, `extensions.headpress.shipping`,
  `hp.checkout.shippingQuote()`, `hp.checkout.installments()`, `hp.checkout.quoteSplit()`.
- **Styling:** colors, radii, shadows only from `src/styles/tokens.css` (utilities like
  `bg-surface text-ink rounded-card shadow-card`); no hex or Tailwind palette colors in components.
  Rebrand = tokens + fonts in `layout.tsx` + `src/config/site.ts`. "If a rebrand needs a component
  change, a token is missing." Email colors live separately in `src/features/emails/brand.ts`.
- **Text:** every visible string from `@/i18n` (`t.<domain>.<key>`), in all locales — `defineMessages()`
  makes a missing translation a type error. Icon-only buttons get an `aria-label` from the dictionary.
- **Components:** Server Components by default; `"use client"` at the smallest boundary. UI
  primitives in `components/ui`, domain components in `features/<domain>/components`.
- **Style:** 2 spaces, double quotes, semicolons, trailing commas, `@/` imports; PascalCase component
  files, `useX.ts` hooks, kebab-case otherwise; comments explain *why*.
- **Privacy:** personal data never in the Data Cache (server = public reads only).

## 6. Adding pages and routes

1. Create the route under `src/app/(store)/…` or `(checkout)/…` — a thin `page.tsx` that reads data
   (server) and renders feature components.
2. If it corresponds to a Headpress route key, add it to `STOREFRONT_ROUTES` and `paths` in
   `src/config/routes.ts`, set **the same pattern** in Headpress → Storefront, run
   `npm run check:routes`. Keys with no page go into `UNROUTED_KEYS` and Headpress's *Routes without a
   storefront page*.
3. Root-level catch-alls (`/[slug]` for categories at the root) make every unknown single segment hit
   that page — keep a list of static root segments and assert at build time that it matches `src/app`.
4. Unknown slugs must produce a **real 404 status**. With `loading.tsx` streaming, the page has already
   sent 200 — resolve existence earlier (in `proxy.ts` or without a loading boundary) when SEO matters.

## 7. Caching, revalidation and outages

- Root `revalidate = 300` (ISR, 5 min); slug pages render on request from cached reads; `nextCache()`
  maps SDK cache tags to `fetch(…, { next: { tags } })`. Customer reads and mutations are `no-store`.
- `/api/revalidate` verifies the HMAC, de-duplicates, then `revalidateTag(tag, { expire: 0 })` and
  `revalidatePath()` per the plan. Pass `config: hp.config` and `redirects` resolver to
  `createRevalidateRoute` so config and redirect caches refresh too.
- **The Next Data Cache is wiped on every deploy.** A deploy while WP is down leaves nothing to fall
  back on. Production stores put reads behind **Redis stale-while-revalidate** (SDK `swr()` +
  `redisCache()` from `/server`, or an own layer), with long stale-if-error for the config (days), and
  the webhook purges Redis before revalidating tags. When a cached value's **shape** changes, change
  its key prefix.
- Timeouts: ~15–20 s for reads; **~60 s for `checkout.place()`** (slow gateways) and never auto-retry
  a checkout (duplicate orders).
- The store must open with WordPress down.

## 8. SEO

- `generateMetadata` → `seoMetadata(getServerClient(), { path }, fallback)` (normalized head from
  Rank Math/Yoast with storefront URLs); JSON-LD via `jsonLdScripts` / product JSON-LD helper.
- `src/proxy.ts`: `createRedirectResolver(hp.seo)` applies the SEO plugin's redirects; it keeps the
  last good set when WP fails and backs off (no latency during outages).
- Structural/legacy redirects live in `next.config.ts` `redirects()`. Next's `permanent: true` is
  **308** — use `statusCode: 301` where a 301 matters.
- `robots.ts` disallows `/account`, `/cart`, `/checkout`, `/search`, `/api` and token pages;
  `sitemap.ts` lists the catalog (`hp.seo.sitemapIndex()` / `sitemap()` for large catalogs). Add
  storefront-only pages to the sitemap yourself.
- Private/tokenized pages: `robots: { index: false }` and `X-Robots-Tag: noindex`. Tag pages are often
  `noindex, follow` and out of the sitemap to avoid duplicates.
- `next/image`: `remotePatterns` come from `NEXT_PUBLIC_WP_URL`'s host — add the CDN/media host if
  media is served elsewhere. `dangerouslyAllowLocalIP` only in development.

## 9. Analytics and consent

`@meumouse.com/headpress/analytics`: `createConsent`, `createTracker(providersFromConfig(config))`,
`createRelay`, `createAttribution`, `recordConsent`, `checkoutAttribution` / `checkoutConsent`
(sent in `place()`), `trackPurchase`, `purchaseEventId`.

- No tag loads before consent; the banner shows only when the `consent` module is on; bumping the
  consent version in WP re-prompts.
- **Purchase is server-side** (GA4 Measurement Protocol, Meta/Pinterest CAPI) on `order.paid`, with
  `event_id = purchase_<order_id>`; the browser fires the same id from
  `GET /store/orders/{id}/analytics?key=` — and only once the payment is confirmed (not when a Pix is
  merely generated).
- Load GTM/GA once (not both in code and through an injection field).
- Exclude tokenized pages (cart recovery, unsubscribe) from page views and attribution.

## 10. Emails

Template = `defineTemplate()` (id, zod schema, subject, render, example) in
`src/features/emails/templates/`, listed in `registry.ts`, previewed at
`/api/emails/preview?template=<id>` (dev only). Register the same id in WordPress via
`Headpress/Emails/Catalog` (`{ label, audience, events: ['order.paid'], replaces: ['customer_processing_order'], build: callable }`
— see `docs/EMAILS.md`). Email images: PNG/JPEG only. Log template ids and counts, never personal data.

## 11. Ready-made components (shadcn registry)

`npx shadcn@latest add https://raw.githubusercontent.com/meumouse/headpress-sdk-js/main/registry/public/r/<item>.json`
— items: `headpress-price`, `-search-dialog`, `-product-reviews`, `-review-form`, `-otp-login`,
`-pix-instructions`, `-shipping-calculator`, `-installments`, `-split-payment-picker`,
`-address-form`, `-cookie-consent`. They need `HeadpressProvider` above them, take `labels`/
`className` props and render nothing while their module is off. The code is copied into the project —
restyle it with the store's tokens.

## 12. Store-specific code

Routes of the store's own add-on plugin are reached through an **SDK plugin** in the storefront repo,
not by editing the SDK — see `addon-plugins.md` §8. Payment gateways go in `src/integrations/<gateway>/`
— see `payment-gateways.md`. If the SDK has a real gap, fix it in the SDK repo (changeset + publish);
never patch `node_modules` (mark temporary workarounds `// TODO(sdk): …`). Third-party npm bugs may
be patched with `patch-package` — then the Dockerfile must `COPY patches` (and `.npmrc`) **before**
`npm ci`.

## 13. Testing and verification

- `npm run verify` = lint + typecheck + test + build. "Done" means it passes.
- Unit tests: Vitest with the SDK's fake transport —
  `createHeadpress({ baseUrl: "https://wp.test", fetch: mockHeadpress(routes).fetch, cartToken: memoryCartToken("t") })`.
- `npm run check:routes -- <wp url>` compares the live route map with `src/config/routes.ts`.
- Manual first-connection checklist (`docs/WORDPRESS_SETUP.md`): no CORS errors, prices/installments/
  shipping match WooCommerce, each gateway has a form and works in sandbox, webhook updates a product,
  sign-in survives reload, guest cart merges, recovery/unsubscribe links work.
- Changelog: Keep a Changelog; `package.json` version = latest changelog title; note "Action required
  in WordPress" for each release that needs backend changes.
