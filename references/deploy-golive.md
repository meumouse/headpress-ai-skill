# Deploy, migration and go-live

## Contents
1. Production topology
2. Storefront image and CI
3. Reverse proxy, CDN and client IPs
4. Caching in production
5. Monitoring
6. Migrating a classic WooCommerce store (strategy)
7. Cutover runbook
8. Smoke test
9. Rollback and decommission

---

## 1. Production topology

- `site.com` → Next.js container (the only indexable host). `admin.site.com` → WordPress container.
- Typical single-VPS layout: a Docker panel (Swarm/Traefik based) running WordPress (nginx + PHP-FPM),
  MariaDB, Redis (WP object cache on one db index, storefront cache on another / key prefix), a real
  cron runner (`DISABLE_WP_CRON`), the storefront container, all behind Cloudflare.
- Keep DB/Redis on the internal network only. Panels may regenerate nginx/php.ini/start scripts —
  re-check hardening after panel updates.

## 2. Storefront image and CI

Starter `Dockerfile` (3 stages, `node:24-alpine`): `deps` (`npm ci`) → `builder` (`ARG`/`ENV` for each
`NEXT_PUBLIC_*`, `NEXT_TELEMETRY_DISABLED=1`, `npm run build`) → `runner` (`NODE_ENV=production`,
`PORT=3000`, `HOSTNAME=0.0.0.0`, non-root user, copies `public`, `.next/standalone`, `.next/static`,
`CMD ["node","server.js"]`).

```bash
docker build \
  --build-arg NEXT_PUBLIC_WP_URL=https://admin.site.com \
  --build-arg NEXT_PUBLIC_SITE_URL=https://site.com \
  --build-arg NEXT_PUBLIC_STORE_LOCALE=pt-BR \
  -t storefront .
docker run -p 3000:3000 -e HEADPRESS_SECRET_KEY=… -e HEADPRESS_WEBHOOK_SECRET=… storefront
```

Rules learned in production:

- `NEXT_PUBLIC_*` → **build args** (CI repository *variables*); server secrets → **runtime env** in
  the host only. Never pass a secret as `ARG` (it stays in image history) — use BuildKit secrets
  (`RUN --mount=type=secret`) for build-time tokens such as source-map upload.
- **Fail the build** when `NEXT_PUBLIC_WP_URL` or `NEXT_PUBLIC_SITE_URL` is empty (otherwise the
  image targets the mock / bakes `localhost` into canonicals and sitemaps).
- If the project uses `patch-package` or a custom `.npmrc`, `COPY patches .npmrc` **before** `npm ci`.
- CI (e.g. GitHub Actions → GHCR): build on push to main (`latest`, `sha-…`) and on `v*` tags (semver
  tags for rollback); deploy by pulling a tag.
- `npm run build` prerenders the static and ISR routes (layout, home, sitemap…) by **reading
  WordPress**. Build the production image only when the production API host answers. Otherwise those
  pages ship in the "unavailable" state and stay that way until their first revalidation.
- `NEXT_PUBLIC_*` differ between staging and production, so each environment needs its own image.
  Never promote the staging image to production.
- There is no built-in health endpoint — add a cheap `/api/health` if the platform needs one.
- Security headers in `next.config.ts` `headers()`: HSTS, `X-Content-Type-Options`, `X-Frame-Options`,
  `Referrer-Policy`, `Permissions-Policy`; start CSP as **Report-Only** with a report collector that
  logs origin + path only.

## 3. Reverse proxy, CDN and client IPs

- WordPress: `define( 'HEADPRESS_TRUSTED_PROXIES', true );` (private ranges + Cloudflare) or explicit
  CIDRs. Headpress walks `X-Forwarded-For` from the right, skipping trusted hops.
- Storefront: compute the shopper IP with the SDK `clientIp(headers, { cloudflare: true, trustedProxies })`
  — never the leftmost XFF entry — and forward it with the secret key (`X-Headpress-Client-IP` +
  `X-Headpress-Secret`).
- **The starter does not do this out of the box.** `src/app/api/auth/[...action]/route.ts` calls
  `createAuthHandler({ baseUrl, publishableKey, secretKey })` without `clientIp`. Behind Cloudflare,
  add `clientIp: { cloudflare: true }` (plus `trustedProxies` for a load balancer). Do the same in
  every `createServerHeadpress({ clientIp })` that forwards shopper actions. Otherwise login and OTP
  rate limits see Cloudflare's IPs.
- Exposing the VPS: a **Cloudflare Tunnel** (`cloudflared`) needs no open ports. Without one, firewall
  80/443 to Cloudflare's ranges only.
- Cloudflare security features in front of WordPress: *Bot Fight Mode* (free plan) cannot be skipped per path and
  blocks server-to-server calls. Those are storefront → WP reads, the WP → `/api/revalidate` webhook
  and gateway callbacks, so keep it off. Use WAF custom rules with *Skip* for those paths and IPs
  instead. Turn off Rocket Loader, Email Obfuscation and APO on both hosts.
- Behind Traefik the app sees `http://` in `request.url`: derive the `Secure` cookie flag from
  `X-Forwarded-Proto` / the configured site URL, not from `request.url`.
- Proxy body-size limit ≥ the largest upload (review videos: 64 MB).
- Cloudflare does not cache HTML by default (`cf-cache-status: DYNAMIC`). If you add a Cache Rule
  honoring `s-maxage`, exclude `/checkout`, `/account`, `/api`, cart and token pages.
- Media: if images move to a CDN/bucket host, add it to `images.remotePatterns`.

## 4. Caching in production

- Next Data Cache is per deployment — wiped on deploy. Use Redis SWR for WordPress reads (long
  stale-if-error for the store config) so a deploy during a WP outage still renders.
- The revalidation webhook purges Redis keys for the received tags, then `revalidateTag`/`revalidatePath`.
- Provide an authenticated manual purge (script or admin token) for emergencies.

## 5. Monitoring

- Error tracking on both sides (Sentry protocol — self-hosted GlitchTip works): Next.js
  `instrumentation.ts` `onRequestError`, browser SDK through an own tunnel route (ad blockers/CSP),
  `tracePropagationTargets: []` (trace headers break WP CORS preflights), `sendDefaultPii: false`,
  scrub CPF, email, phone, Cart-Token, order keys and tokens in paths in `beforeSend`. WordPress:
  WooCommerce log handler, REST 5xx (`rest_post_dispatch`), `action_scheduler_failed_execution`.
- Upstream failures (WP unreachable) reported as warnings with a stable fingerprint, not one issue per URL.
- External uptime monitor on both hosts; heartbeats for cron jobs.
- Daily during the first weeks: Headpress → Webhooks (failed deliveries), gateway webhook logs,
  Action Scheduler failures, Search Console coverage (404s, redirect errors).

## 6. Migrating a classic WooCommerce store

- **Strangler pattern:** the old bridge/theme-era plugins and the new Headpress + add-on run side by
  side; each storefront release moves one area: (a) HTTP client, config, revalidation → (b) SEO and
  redirects → (c) tracking, consent, attribution → (d) auth (BFF, OTP, Google) → (e) account, orders,
  wishlist → (f) checkout, split payments, fields → (g) leads, coupons, surveys, reviews → (h)
  shipping and installments. Ship auth and checkout **alone**.
- `NEXT_PUBLIC_*` are build-time, so there are no runtime feature flags — switching versions is a redeploy.
- Before starting: snapshot the old API responses and write an old → new route map.
- **URLs:** export every indexed URL (products, categories incl. nested/paginated, tags, posts, pages,
  filter query strings, builder leftovers). Keep slugs; 301 everything else in `next.config.ts`
  (structural rules) or the SEO plugin (per-object rules applied by `proxy.ts`). Strip legacy filter
  query params with a 301; 410 for dead builder URLs.
- **Data:** the add-on imports legacy settings, user meta and lists idempotently, never deleting the
  old data (rollback stays possible).
- **Third-party links already sent** (order emails, WhatsApp messages, payment links, cart recovery,
  unsubscribe) need compatibility redirects to the new routes. The easy win is to keep WooCommerce's
  current paths in the route map: `checkout/order-received/{order_id}?key=`, `my-account/view-order/`,
  `my-account/lost-password/?key=&login=`, `checkout/order-pay/`. Use the store's real slugs, which
  are often localized (`finalizar-compra`, `minha-conta`). If the paths do change, 301 them **keeping
  the query string**: `key`, `login` and `order` are what make the link work.

### When WordPress gives up the apex domain

The usual migration: the classic store lives on `site.com` and WordPress moves to `admin.site.com`
while Next.js takes `site.com`.

- Change only `home`/`siteurl` (WP-CLI) and search-replace **media URLs only**
  (`//site.com/wp-content/` → `//admin.site.com/wp-content/`). Leave content links to `site.com`:
  they are storefront URLs now.
- Put WordPress in maintenance (**503**, not a redirect) for the minutes between the DNS/domain
  switch and the storefront answering. A temporary 301 from `site.com` to `admin.` gets cached by
  browsers and causes loops after go-live.
- Keep requests that still arrive at `site.com` for WordPress working during the transition: gateway
  IPNs for Pix/boleto created before the switch (`/wc-api/*`, `?wc-api=`), ERP/NF-e integrations and
  `/wp-json/*` callers. Send them to the WP origin with a Cloudflare Origin Rule or a `next.config.ts`
  rewrite. Redirect `/wp-admin`, `/wp-login.php` and `/wp-content/*` to `admin.`.
- Run WP cron through WP-CLI or system cron (`DISABLE_WP_CRON`), not over HTTP on the old host.
- DNS: before touching the apex records, inventory the **mail records** (MX, SPF/DKIM/DMARC TXT,
  `mail`/`webmail`/autodiscover CNAMEs that point at the old server). Lower the TTL a day ahead.

## 7. Cutover runbook

Pre-cutover:
1. Tag and build release ZIPs of every plugin; install **from ZIP**.
2. Messaging templates that need provider approval (WhatsApp) requested days ahead; OTP falls back to
   email meanwhile (`424 headpress_channel_unavailable` with `fallback_channels`).
3. Back up the database and `wp-content/plugins`.
4. Confirm production versions (WP, PHP, WooCommerce, each plugin — bridges stay unavailable until
   the bridged plugin defines its version constant).
5. Rehearse the whole cutover on staging. Export the Headpress settings from there to import in
   production (`wordpress-setup.md` §2).
6. Write a go/no-go list with an abort condition for each cutover step, and who decides.

**Keep the live shop consistent until the switch.** While the classic theme still sells, production
must not get the Storefront URL with `woocommerce.storefront_urls` on, nor headless mode. Either
would send cart, checkout and account links to pages that don't exist yet (`wordpress-setup.md` §2).
Prepare and test everything on staging. In production, apply those settings inside the cutover
window, behind a maintenance page when the domain also changes.

Cutover, in order:
1. Install/update Headpress, the add-on and plugins. Let the add-on import legacy data.
2. Headpress → API keys: `hp_sk_…` (+ `hp_pk_…` if required).
3. Headpress → Storefront: URL, origins, **route map** (every key explicit), routes without a page,
   extra CORS namespaces, webhook URL + secret, webhook sending on. Allowed origins hold the
   production storefront origin **only**: remove `localhost` and staging origins.
4. Enable modules in waves (core commerce + SEO + content → accounts/passwordless/captcha → wishlist/
   reviews/address book → checkout fields → consent + analytics → add-on modules).
5. Build the production image now that the API host answers (§2), with production `NEXT_PUBLIC_*`.
   Set the storefront runtime env (`HEADPRESS_SECRET_KEY`, `HEADPRESS_WEBHOOK_SECRET`, Redis, email)
   and deploy.
6. `npm run check:routes -- https://admin.site.com`.
7. **Immediately** disable legacy tracking (old pixel/plugin) — two systems with different `event_id`
   schemes double-count every purchase.
8. Headless redirects: keep 302 while testing gateway returns (`order-pay`, `/wc-api/`, hosted 3DS),
   then switch to **301**.

## 8. Smoke test

- Sign in by password, Google and OTP; session survives reload; guest cart merges.
- Wishlist, address edit, password change, password-reset email link lands on the storefront.
- Checkout with each gateway (Pix, card, boleto, split); order-received opened on another device;
  coupon with and without opt-in; free order.
- Public order tracking.
- Save a product in wp-admin → delivery appears in Headpress → Webhooks → price updates on the storefront.
- Review with photo and video (upload limits).
- One test purchase visible exactly once in GA4, Meta and Pinterest (test codes).
- `curl -I` old URLs → one 301 hop to the right page; WP host → `x-robots-tag: noindex`.

## 9. Rollback and decommission

- Rollback while legacy plugins are still active: redeploy the previous image tag and re-enable the
  old tracking. Because legacy data was never deleted, nothing needs restoring.
- Decommission after days of stability: confirm zero calls to the old namespaces in access logs, move
  remaining features (e.g. enable core `split_payments` before deactivating an old one), remove code
  snippets, **rotate every secret that touched old environments** (REST consumer keys, internal keys,
  cache tokens, old messaging provider keys), archive the old repository.
