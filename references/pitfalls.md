# Pitfalls — real incidents and their fixes

Read before shipping, and whenever something breaks "for no reason". Grouped by symptom. Most
entries happened in production; a few were caught while reviewing generated code and cutover plans.

## The whole store / all of REST is down

- **One class uploaded by hand + Composer authoritative classmap → every REST route 500.** Install
  plugins from their release ZIP only; prefer a small PSR-4 `spl_autoload_register` loader in add-ons.
- **Infinite redirect loop took the storefront down:** the SEO plugin answered the head request with a
  301 to the same page and the client followed it forever. The SDK no longer follows redirects
  (`headpress_unexpected_redirect`) — never re-enable redirect following on WP calls.
- **PHP-FPM worker exhaustion:** a headless page makes several REST calls in parallel; 5 workers were
  not enough. Raise `pm.max_children`, add Redis object cache, remove dev drop-ins.
- **A redirect resolver that waited on WP for every request** added latency during outages; cache the
  failure and back off (the SDK resolver retries after ~15 s).

## CORS, cookies, sessions

- **Store API rejects credentialed requests (WooCommerce ≥ 11.3.1).** Call it with
  `credentials: "omit"`. Don't add `Access-Control-Allow-Credentials` in WP to "fix" it.
- **Preflight fails on checkout** because the browser error/trace SDK added `sentry-trace`/`baggage`
  headers → `tracePropagationTargets: []`.
- **A plugin's REST namespace works in curl but not in the browser** → add it to *Other REST
  namespaces for the storefront* / `Headpress/Storefront/Cors_Namespaces`.
- **`Cart-Token` missing in JS** → it must be in the exposed headers (Headpress does it; a proxy that
  strips/overrides CORS headers breaks it).
- **Refresh cookie not set in production** → the BFF derived `Secure` from `request.url`, which is
  `http://` behind the proxy. Use the forwarded proto / site URL.
- **Expired Cart-Token 401 treated as "session expired"** and triggered a login refresh. Cart and
  customer auth are separate; the SDK renews the cart token itself.
- **Staff account can't sign in on the storefront** — by design (tokens are refused for users with
  `edit_posts`/`manage_woocommerce`). Test with a customer account.
- **Plugin code using `get_current_user_id()` at `woocommerce_init`** sees no user under JWT auth
  (the user is resolved later, in `determine_current_user` for REST). Resolve the user lazily.
- **"Cart not found" from new storefront code** → it read the Cart-Token from an invented cookie
  name, or wrote it as an httpOnly cookie the browser client can't read. Use the starter's client
  (`hp.cart.action`, `cookieCartToken()` / `hp_cart`). See `addon-plugins.md` §8.

## Rate limits, abuse, IPs

- **Rate limits trusted `X-Forwarded-For[0]`** — anyone can spoof it. Use rightmost-untrusted-hop and
  trusted proxies only.
- **Without `HEADPRESS_TRUSTED_PROXIES`** every request has the proxy IP → one shopper's OTP attempts
  or the analytics relay's 60/min limit block the whole store.
- **Storefront login/OTP rate limits hit everyone behind Cloudflare** → the starter's
  `createAuthHandler` had no `clientIp` option. Add `clientIp: { cloudflare: true }`.
- **WhatsApp OTP cost pumping** (like SMS pumping): daily caps per channel, allowed countries, captcha
  on `otp_start`. Send OTPs directly (not queued), with email fallback.
- **Welcome-coupon abuse:** guard "first order" by CPF, phone, email and account, not one of them.
- **Stock locked by unpaid Pix orders:** limit unpaid orders per account/email/CPF/IP and align hold
  times with payment validity.

## Money, prices, payments

- **UI hard-coded "10% off on Pix" and "12x interest-free"** while the server used other rules →
  legal exposure. Show only server-computed values (price preview, installments simulation); show
  nothing when absent.
- **Split-payment discounts computed with floats in the browser** → rounding mismatches. Server-side
  quote, integer minor units.
- **Currency defaulted to USD** when the config was not loaded yet — always format from
  `commerce.currency`.
- **Pix expiring in 30 minutes cancelled high-ticket orders** → validity aligned with
  `woocommerce_hold_stock_minutes` (e.g. 24 h).
- **Hosted gateway `redirectUrl` sent shoppers to WordPress pages** → rewrite to the storefront route
  map, only follow redirects outside `/checkout`.
- **Purchase counted when the Pix was generated, not paid** → track on payment confirmation.
- **Discontinued PSP product** (an older PayPal card iframe API in Brazil) silently stopped offering
  funding → keep a second card gateway and a "try another method" flow that reuses the order.
- **Double-escaped currency symbols in gateway titles** (`R&#36;`) — store plain text titles, decode once.
- **No checkout timeout** → slow gateway, shopper clicks again, duplicate orders. ~60 s timeout, no
  automatic retry, disable the button while placing.

## SEO

- **Route map defaults didn't match the store** → wrong canonicals, sitemap and email links. Set every
  key; verify with `check:routes`.
- **Unknown slugs returned 200** because `loading.tsx` streamed first → resolve existence before
  streaming to send a real 404.
- **An outage rendered 404s** → search engines dropped pages. "Didn't answer" ≠ "doesn't exist".
- **`permanent: true` in Next is 308** — use `statusCode: 301` where tools expect 301.
- **WordPress sitemap lacked storefront-only pages** → the storefront builds its sitemap (using
  Headpress sitemap data + its own pages).
- Never `blog_public = 0`, never `Disallow: /` on the WP host.

## Build and deploy

- **`patch-package` silently skipped in Docker** ("No patch files found") because `patches/` was copied
  after `npm ci`. Copy `patches/` and `.npmrc` first.
- **`NEXT_PUBLIC_SITE_URL` empty in CI** baked `localhost` into canonicals — fail the build.
- **`NEXT_PUBLIC_WP_URL` unset** silently pointed production at the mock — fail the build.
- **A new `NEXT_PUBLIC_*` added to `env.ts` only** — it must also be a Dockerfile `ARG`/`ENV` and a CI build-arg.
- **Cache shape changed but key prefix didn't** → old entries crashed new code. Version cache keys.
- **Image built before the production API answered** → prerendered pages shipped in the
  "unavailable" state. Build after `admin.` is live.
- **Classic shop's cart/checkout links broke days before go-live** → the Storefront URL had been
  filled in production, and `woocommerce.storefront_urls` (on by default) rewrote WooCommerce's
  URLs. Set it inside the cutover window.
- **Browsers looped after go-live** → a temporary 301 from the apex to `admin.` during the domain
  switch got cached. Use a 503 maintenance page in that window.
- Local dev against a self-signed WP: set `NODE_TLS_REJECT_UNAUTHORIZED=0` at process start (Node 24
  ignores it when set later, e.g. in `instrumentation.ts`); WooCommerce `wc/v3` Basic auth refuses plain HTTP.
- ZIPs built on Windows with `\` path separators fail to install — zip with `/`.

## Silent failures

- **An integration returning 404 was swallowed as "not found"** (`{ exists: false }`) — propagate
  upstream errors as 502 so they surface.
- **Abandoned-cart capture died when the store went headless** (it hooked the classic checkout). Any
  feature tied to theme/classic-checkout hooks must be re-checked: capture through the Store API.
- **Legacy tracking left on after cutover** doubled every purchase in Meta/Pinterest.
- **Analytics tag loaded twice** (code + injection field).

## Security

- Public backup dumps and `debug.log` in the docroot (nginx ignores `.htaccess`), cached by the CDN.
  Deny rules, move outside docroot, purge CDN, rotate credentials and salts.
- DB/Redis ports open to the internet; `DB_HOST` pointing at the public IP; `root@'%'`.
- `chmod -R 777` in a container start script.
- Anonymous `wp/v2/users` enumeration.
- "CORS protects this endpoint" — it doesn't (curl and bots ignore CORS). Protect with auth, keys,
  captcha and rate limits.
- Logs containing personal data (emails, CPF, tokens in URLs) — log ids and counts only.
