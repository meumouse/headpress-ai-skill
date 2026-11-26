# Headpress AI skill

**English** · [Português (Brasil)](README.pt-BR.md) · [Español](README.es-ES.md)

An agent skill (`headpress-store`) that teaches AI coding assistants how to build a **headless
WooCommerce store** on the Headpress stack **from zero to deploy**, and how to extend it with custom
add-on plugins for client-specific features.

The stack it covers:

| Piece | Repository | Role |
|---|---|---|
| **Headpress** | [`meumouse/headpress`](https://github.com/meumouse/headpress) | WordPress plugin: REST `headpress/v1`, Store API extensions (`extensions.headpress`), headless mode, revalidation webhooks, modules |
| **SDK** `@meumouse.com/headpress` | [`meumouse/headpress-sdk-js`](https://github.com/meumouse/headpress-sdk-js) | TypeScript client, Next.js helpers, plugin API, shadcn registry |
| **Storefront starter** | [`meumouse/storefront-starter-headpress-nextjs`](https://github.com/meumouse/storefront-starter-headpress-nextjs) | Next.js 16 + React 19 + Tailwind 4 storefront |

The skill condenses the canonical docs of those three repositories plus the lessons of a real
production migration (a classic-theme WooCommerce store moved to headless). The repositories remain
the source of truth: the skill tells the agent **what to read, in what order, what to build where,
and what goes wrong**.

Versions current when the skill was written (Nov 2026): Headpress 1.8.0, SDK 0.1.2, starter 0.2.1.

---

## Contents

- [What the skill does](#what-the-skill-does)
- [Installation](#installation)
- [Usage](#usage)
- [Repository layout](#repository-layout)
- [The workflow](#the-workflow)
- [Golden rules](#golden-rules)
- [Add-on template](#add-on-template)
- [Evals](#evals)
- [Packaging](#packaging)
- [Maintaining the skill](#maintaining-the-skill)

---

## What the skill does

When a task touches the Headpress stack, the agent loads `SKILL.md` and, on demand, the reference
file for the part it is working on. With it, the agent can:

- **Plan the project:** domains, locale, route map, gateways, shipping, installments, checkout
  fields, SEO, analytics and consent, hosting. A table says where each feature belongs: public
  config, Store API extension, cart action, checkout field, REST route, bridge module, storefront
  or Headpress core.
- **Set up the backend:** plugins, Headpress → Storefront settings (storefront URL, origins, route
  map, CORS namespaces, webhook, API keys), modules, `wp-config.php` constants, server tuning,
  hardening, and headless mode that never hurts SEO.
- **Build the storefront:** starter structure and layering rules, env vars (build-time versus
  runtime), SDK wiring, pages and routes, theming, i18n, payments registry, emails, caching,
  outage handling, SEO and analytics.
- **Write custom add-on plugins** that register Headpress modules with settings, routes, Store API
  extensions, cart actions, events, jobs, health checks, captcha, rate limits and admin screens.
  The matching SDK plugin (`definePlugin()` plus declaration merging) is built at the same time.
- **Integrate payment gateways** with the Store API checkout, both in WordPress and in the
  storefront: hosted redirect, Pix or boleto with polling, and card fields tokenized in the browser.
- **Deploy and go live:** a Docker image and CI, reverse proxy and Cloudflare, migration waves, a
  cutover runbook, a smoke test, a rollback plan, monitoring and decommissioning.
- **Avoid known failures:** a catalogue of real incidents grouped by symptom (CORS, sessions,
  redirects, SEO, cache, payments, deploy, security).

The skill triggers when the user mentions Headpress, a headless WooCommerce or Next.js store, the
starter, the SDK, `extensions.headpress`, `headpress/v1`, Cart-Token, a storefront migration, or a
plugin "integrated with Headpress". It also triggers on a question about a single piece, such as a
checkout, a payment method, a deploy, a CORS error or an add-on.

---

## Installation

The skill folder must be named after the skill: **`headpress-store`**.

### Claude Code: all projects (user level)

```powershell
git clone https://github.com/meumouse/headpress-ai-skill.git "$env:USERPROFILE\.claude\skills\headpress-store"
```

```bash
git clone https://github.com/meumouse/headpress-ai-skill.git ~/.claude/skills/headpress-store
```

### Claude Code: one project

Clone or copy it into `.claude/skills/headpress-store/` at the root of the project, for example the
storefront or add-on repository. Commit it there if the whole team should get it.

### Claude apps (claude.ai / desktop)

Build the `.skill` package (see [Packaging](#packaging)) and upload it in the app's Skills
settings.

### Other agents (Codex, Cursor, Copilot…)

Point the agent at the skill from the project's `AGENTS.md` (or the tool's rules file), for example:

```markdown
For anything about the Headpress stack, read `path/to/headpress-store/SKILL.md` first and the
reference file it points to for the task.
```

### Updating

```powershell
git -C "$env:USERPROFILE\.claude\skills\headpress-store" pull
```

---

## Usage

There is nothing to call: describe the task and the agent loads the skill. Example prompts:

- "Start a new headless store with Headpress for `loja.com.br`, with Pix and card via Mercado Pago."
- "Create a plugin integrated with Headpress that lets the shopper write a gift message in the cart."
- "Write the deploy and cutover runbook: WordPress behind Cloudflare, storefront in Docker."
- "After updating WooCommerce the storefront cart throws a CORS error. How do I fix it?"
- "Migrate this classic-theme WooCommerce store to the Headpress storefront without losing SEO."
- "Make the PagBank gateway work in the headless checkout."

In Claude Code you can also invoke it explicitly with `/headpress-store`.

---

## Repository layout

```
SKILL.md                         entry point: topology, repos, golden rules, phases 0–7, definition of done
references/
  contracts.md                   WP ↔ storefront contracts: REST scopes and keys, store config,
                                 extensions.headpress, cart actions, checkout data, Cart-Token, CORS,
                                 rate limits, revalidation webhook and tag vocabulary, events, conventions
  wordpress-setup.md             backend install and configuration, modules, wp-config constants,
                                 headless mode and SEO safety, server tuning, hardening, curl checks
  storefront.md                  starter structure and rules, env vars, SDK wiring, routes, caching,
                                 SEO, analytics, emails, registry components, SDK plugins, testing
  addon-plugins.md               custom add-on plugins: skeleton, module API, settings schema, routes,
                                 Store API, events, jobs, health checks, admin UI, third-party bridges,
                                 legacy migration importer, SDK plugin, tests and build
  payment-gateways.md            Store API payment processing, public method config, extra routes,
                                 webhooks, storefront payment providers, polling and retry, security
  deploy-golive.md               Docker/CI, proxy and Cloudflare, caching, monitoring, migration waves,
                                 cutover runbook, smoke test, rollback and decommission
  pitfalls.md                    real incidents and fixes, grouped by symptom
assets/
  addon-template/                a working "Acme" add-on (quote requests) to copy for new add-ons
evals/                           test cases for the skill (see Evals)
```

`SKILL.md` stays short and tells the agent which reference to open. The references are loaded only
when the task needs them, so the skill costs little context on small questions.

---

## The workflow

`SKILL.md` drives every project through eight phases. Each phase ends with a check the agent can run:

| Phase | What happens | Check |
|---|---|---|
| 0. Discovery | Domains, locale, route map, gateways, shipping, checkout fields, SEO, analytics, hosting; where each feature belongs | Decisions written in the project's plan file |
| 1. Backend | WordPress + WooCommerce + Headpress + integrations, Storefront settings, modules, API keys | `GET /wp-json/headpress/v1/store/config` returns the route map; health checks clean |
| 2. Storefront | Starter, `npm run demo`, env, routes, branding through tokens, store pages | `check:routes` and `npm run verify` pass; no CORS errors |
| 3. Integrations | Payments, shipping, installments, analytics, emails | One sandbox order per gateway; one purchase per analytics tool |
| 4. Add-on | Client-specific features as Headpress modules in a separate plugin, plus the SDK plugin | Modules toggle routes on and off; release ZIP installs on a clean site |
| 5. SEO and migration | SEO head, redirects, robots and sitemap, legacy URL map, data import | Old URL crawl: every URL is 200 or one 301 hop |
| 6. Deploy | Docker image, build-time versus runtime env, CI tags, trusted proxies, Redis | Image runs with runtime secrets only |
| 7. Go-live | Cutover runbook, smoke test, 302 → 301, legacy tracking off, monitoring, decommission | Smoke test green; webhooks delivered |

The skill ends with a **definition of done** checklist for the whole project.

---

## Golden rules

These apply to every phase. Full wording is in `SKILL.md`.

1. **The server decides, the storefront shows.** Prices, discounts, installments, shipping and stock
   are computed by WordPress and only displayed by the storefront.
2. **API first.** Every capability is a REST route or a Store API extension before it is a screen.
3. **Don't duplicate WooCommerce.** Extend the Store API (`extensions.headpress.<module>`); never
   re-implement it.
4. **Extend, don't edit the core.** Client-specific features go in an add-on plugin. A missing
   extension point is added to Headpress itself, as a separate, versioned change.
5. **Contracts are cross-repo.** Module ids, config shape, route shapes, extension keys and webhook
   tags are consumed by other repositories. A shape change is a breaking change.
6. **Never hurt the storefront's SEO.** Never `blog_public = 0` on the WordPress host.
7. **Money is integers in minor units**, end to end.
8. **Secrets never reach the browser**, and card data never touches the servers.
9. **An outage is not a 404.** A missing object gives `notFound()`; an unreachable backend gives an
   unavailable state.
10. **Repository hygiene.** Write in English, add no AI attribution, follow each repository's style,
    and run no destructive command unless asked.

---

## Add-on template

`assets/addon-template/` is a minimal add-on with one module (**quote requests**). It shows every
common extension point:

| File | Shows |
|---|---|
| `acme-store-addon.php` | Plugin header with `Requires Plugins: headpress, woocommerce`, PSR-4 autoloader, HPOS declaration, boot on `plugins_loaded` (11), notice when Headpress is missing |
| `src/Plugin.php` | Module registration on `Headpress/Modules/Register`, try/catch per module so one broken module never takes the site down |
| `src/Modules/Quotes/Quotes_Module.php` | Settings schema (public + private), computed `public_config`, Store API product extension, custom event, background job, health check, captcha route |
| `src/Modules/Quotes/Rest/Quote_Post.php` | Store-scope route with `args()` + `schema()`, rate limits, real error statuses (404 / 422 / 429), `Cache-Control: no-store` |
| `storefront/acme-plugin.ts` | SDK `definePlugin()` with typed method, i18n messages and `ModuleConfigMap` declaration merging |
| `scripts/build.mjs` | Release ZIP: version check (header = constant = `package.json`), CHANGELOG check, allowlist, `/` separators |
| `tests/smoke.php` | `wp eval-file` smoke test: config visibility, 400/404/201 paths, Store API extension, self-cleanup |

To start a real add-on, follow `assets/addon-template/README.md`. In short, rename the `Acme`
namespace, the `acme` prefix, the `acme-store-addon` slug and the `ACME_STORE_ADDON_*` constants.
Then replace the Quotes module with the store's modules and run the smoke test on a dev site.

> The template passes `php -l` but has not yet been run against a live WordPress site. Run
> `tests/smoke.php` on a dev site before relying on it.

---

## Evals

`evals/` holds test cases in the layout read by `claude plugin eval`. Each case is a folder with:

```
evals/<case-name>/
  prompt.md      run settings + the user's prompt
  case.yaml      name and a description of a good result
  graders/       one verifiable statement per file
```

| Case | Prompt (summary) | What the graders check |
|---|---|---|
| `gift-message-addon` | Build a plugin integrated with Headpress for a 200-character gift message in the cart, shown on the order and in emails, plus the storefront code | Headpress module registration, cart write path via Store API / Cart-Token, `extensions.headpress` read path, server-side validation, HPOS order meta in admin and emails, no cookies to the Store API, English code, the starter's own client / Cart-Token store / components / i18n |
| `cloudflare-docker-golive-runbook` | Deploy and cutover runbook: WordPress behind Cloudflare, storefront in Docker | `blog_public` untouched, trusted proxies, build-time `NEXT_PUBLIC_*`, revalidation webhook secret, 301 map, Cloudflare cache bypass for dynamic routes, order smoke test, rollback, storefront origin in CORS, classic shop intact until the switch, already-sent email links, no dev origins in production |
| `store-api-cors-after-woo-update` | Cart CORS error after updating WooCommerce to 11.3.1 | Correct diagnosis (no credentials for the storefront origin), `credentials: "omit"` + Cart-Token, no unsafe "fix", storefront origin in Headpress, Cart-Token in allowed and exposed headers, a way to confirm the fix |

Run them with Claude Code (v2.1.269 or later) from the repository root. Each case runs with and
without the skill, and the report is written under `evals/results/`:

```powershell
claude plugin eval . --allow-tools Write,Edit,Bash
```

These are real, billed model runs. When a case is run inside the Headpress repository, the agent
can read the repository's own docs, so a case can pass without the skill. Use the with/without
comparison to see what the skill adds.

You can also run the cases with the `skill-creator` skill, which writes results to a sibling
`headpress-store-workspace/` folder and opens a review viewer.

---

## Packaging

A `.skill` file is a ZIP with the skill folder at its root. Build it from Git so only tracked files
go in, leaving out the README and the evals:

```powershell
git archive --format=zip --prefix=headpress-store/ -o headpress-store.skill HEAD SKILL.md references assets
```

---

## Maintaining the skill

- **Keep it in sync with the repositories.** When a Headpress, SDK or starter contract changes,
  update the matching reference file. This covers module ids, the store-config shape, routes,
  `extensions.headpress` keys, webhook tags and env vars. Also bump the versions quoted at the top
  of `SKILL.md`.
- **Known doc drift** in the source repositories is listed in `SKILL.md` ("Where the knowledge
  lives"). Remove an entry once the source repository is fixed:
  - Headpress `AGENTS.md` names the SDK `@headpress/sdk`; the published name is `@meumouse.com/headpress`.
  - Headpress `AGENTS.md` lists an `Addons/` directory and `headpress_register_event()`, which do not exist.
  - The starter's `AGENTS.md` points to `src/i18n/messages/`; dictionaries are `src/i18n/<domain>.ts`.
- **New incidents go to `references/pitfalls.md`.** Group each by symptom and include the cause and
  the fix. Write it generically: no client names, domains, prefixes or credentials, because this
  skill is shared.
- **Verify before writing.** Every hook, filter, function, route and env var named in the skill must
  exist in the source. Check with `grep` in the repository instead of writing from memory.
- **Keep `SKILL.md` lean.** It is loaded on every trigger. Details belong in `references/`, and
  `SKILL.md` only says when to open each one.
- **Re-run the evals** after a meaningful change, and add a case when a new kind of task keeps
  coming up.
- Everything in this repository is written in English, with no AI attribution lines in files or
  commits. The only exceptions are the translated READMEs (`README.pt-BR.md`, `README.es-ES.md`).
  Keep them in sync whenever `README.md` changes.
