# Session handoff — 2026-08-18

State of `yotech-ai/twill-cms-ai-assistant` after the extraction session. Read this
first if you are picking the work up fresh.

## What this package is

The Twill AI content assistant + optional MCP connector, extracted from pomofit's
`app/TwillAi` (44 PHP) and `app/Mcp` (18 PHP) into a standalone Composer package.

Source plan: `C:\Users\Jeffrey\Herd\pomofit\docs\superpowers\plans\2026-08-17-twill-ai-composer-package.md`
Read it — it holds the locked decisions (D1–D9), the risk table and Phases 3–5.
Its status line still says "NOT STARTED"; that is deliberate, pomofit was never
touched (a global constraint of the plan).

## Naming (user decision, overrides plan D1)

| | |
|---|---|
| Local folder | `C:\Users\Jeffrey\Herd\twill-cms-ai-assistent` (Dutch spelling, as created) |
| Composer name | `yotech-ai/twill-cms-ai-assistant` (English) |
| Namespace | `TwillAi\` — kept short so `App\TwillAi\` → `TwillAi\` was a pure prefix drop |

## Done — plan Phases 1 and 2, plus Plugins-page wiring

- All 62 source files moved and renamespaced; zero `App\` references remain.
- `TwillAiServiceProvider` rewritten. It extends the shared plugin base class and
  self-registers routes, views, migrations, publish tags, navigation, the private
  upload disk, the `twill-ai` queue connection and (MCP only) the `twill-mcp`
  auth guard. Runtime config defaults fill **absent keys only**; filled keys are
  recorded and reported by `twill-ai:install` / `twill-ai:doctor`.
- MCP is gated twice — `config('twill-ai.mcp.enabled')` **and**
  `class_exists(Laravel\Mcp\Server::class)` — and its servers are registered from
  `Mcp\McpServiceProvider` because laravel/mcp only auto-loads the host's own
  `routes/ai.php`. Route/handle/throttle are config-driven.
- `Models/TwillUser` + `Concerns/ActsAsOAuthUser` replace the old
  `App\Models\TwillUser` fallback.
- **Phase 2 seams:** new `Services/PromptComposer` generates the worked JSON
  payload, editor guidance, block-order example, relation example and MCP opening
  from the module registry, with `twill-ai.prompts.*` overrides. The floating
  widget now rides a view composer on `twill::layouts.main` (no vendor override).
  Built assets are served from a route with ETag + immutable caching. New
  `twill-ai:install`; `twill-ai:doctor` extended with host-wiring checks.
- Config ships as a generic stub — no site-specific fact, empty `modules` registry.
- Registered on the shared Plugins page (see below).

Two commits, clean tree, **no git remote and no tag** — the plan requires explicit
user approval before creating the public repo, and it has not been given.

## Not done

- **Phase 3 — test harness.** Nothing ported. `tests/` does not exist and
  `require-dev` (Testbench, Pest) is declared but never exercised. The plan flags
  Task 3.1 (Twill under Testbench) as its highest risk; build and prove the
  harness before porting the 12 tests. This is the natural next session.
- **Phase 4** — CHANGELOG, `docs/connecting-claude.md`, de-pomofit'ing
  `docs/runbook.md` and `docs/test-plan.md` (both copied over verbatim and still
  contain pomofit references — the only place any remain). Then repo + Packagist.
- **Phase 5** — adoption in pomofit, quiz-cms, easy-to-spain. Not started.

## Deviations from the plan (intentional — do not "fix" these back)

1. **`passport.guard` is not auto-set.** Task 1.5 says fill it "only if unset",
   but Passport always ships a `web` default, so the rule could never fire — and
   forcing it is unsafe because the setting is global to Passport and would break
   a host that serves its own customer API with it. It is a documented host step;
   `twill-ai:doctor` reports it with the one-line fix.
2. **`laravel/passport` pinned `^13.7.1`**, not `^13.7`. See the CVE note below.
3. **`area17/twill: ^3.5`** on the shared support package (plan said nothing);
   verified the TwillNavigation/TwillRoutes APIs used are byte-identical at the
   3.5.0 and 3.6.0 tags, so easy-to-spain can adopt without a Twill upgrade.

## Security note carried forward

`laravel/passport` <= 13.7.0 carries **CVE-2026-39976** (advisory
PKSA-wc55-9qj2-7v4h, High): TokenGuard authenticates an unrelated user for
client-credentials tokens. Fixed in 13.7.1. That is the exact component the MCP
connector authenticates on. **pomofit and easy-to-spain run this MCP in
production** — check `composer show laravel/passport` on both.

## The shared Plugins page

Lives in a separate package: `C:\Users\Jeffrey\Herd\twill-plugin-support`
(`yotech-ai/twill-plugin-support`, namespace `Yotech\TwillPluginSupport\`, tagged
v1.0.0, local git only). It adds a "Plugins" entry to the Twill nav, right of
Media Library, listing every installed Yotech plugin.

Interop contract — these container key strings must never change:
`yotech.twill-plugins.registry` and `yotech.twill-plugins.page-owner`.
First provider to register owns the page; later ones only add their manifest.
Because the keys are plain strings holding PHP built-ins, a package carrying its
own vendored copy still cooperates. Proven: `twill-cms-redirects` v2.0.0 still has
a vendored copy and coexists fine — migrating it onto the shared package is
optional cleanup (would be v2.1.0), not a prerequisite.

## How this was verified (no automated tests exist yet)

A scratch Laravel 13.25 + Twill 3.6.0 app with all three packages path-repo'd:

- Provider boots; all 9 migrations ran under their **original filenames** (D3, the
  property that keeps the three production installs from re-migrating).
- MCP gate: dormant with laravel/mcp absent (the quiz-cms shape); with
  mcp 0.9.4 + passport 13.7.6 it registers the guard, 8 oauth/mcp routes, and the
  server builds with 8 tools and generated instructions containing no "pomofit".
- Widget composer: hidden for guests, present on an ordinary admin page,
  suppressed on `twill.ai.*` so it cannot double-mount.
- Asset route: 200 with `immutable` cache + ETag, 304 on revalidation.

Two gotchas for whoever verifies next:
- Twill admin pages return **500** in a scratch app because Twill's compiled admin
  assets (`vendor/area17/twill/dist/`) are not shipped via Composer. Not a package
  bug. Verify through composers/units rather than rendered pages.
- `php artisan tinker --execute` **hangs** on scripts containing anonymous classes.
  Bootstrap the app in a standalone PHP script instead.
- The plan's own note holds: if a run idles >90s, kill stray php processes and retry.

## Constraints still binding

- Never widen a safety invariant. No publish, no delete tool, ever. Drafts-only and
  no-deletion are enforced in `PayloadBuilder` and the tool lists, not config.
- Preserve every migration filename exactly (D3).
- The package must never ship a site-specific fact.
- Do not create the public GitHub repo without explicit approval.
- Phases 1–4 must not touch the pomofit repo.
