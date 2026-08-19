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

## Phase 3 — done (2026-08-18, second session)

**140 tests / 443 assertions green**, `pint --test` clean. Run with
`vendor/bin/pest.bat --no-coverage`.

- **3.1 harness.** Twill 3.6 boots under Testbench 11 — the plan's only
  High-severity risk did not materialise and its "smoke tests only" fallback is
  not needed. Two SQLite traps, both documented in `tests/TestCase.php`: the test
  connection must be *named* `sqlite` (Twill migrations compare the connection
  NAME, not the driver), and migrations must run via `artisan migrate --path`
  rather than `loadMigrationsFrom()`, whose teardown rollback hits Twill's
  `add_id_to_related::down()` dropping a SQLite PRIMARY KEY. A third: Twill's
  compiled assets are not shipped via Composer, so every admin view 500s until
  `Cache::forever('twill-manifest', [])` is seeded.
- **3.2 fixture CMS.** `tests/Fixtures/`: translatable `Article` (+translation,
  slug, repository), non-translatable `Singleton` (the `extra_fields` path),
  `PlainArticle` (no media roles), and four component blocks — plain, inline
  repeater, nested editor, and `fixture-banner` which is allowed in the
  singleton's editor only so the per-editor guard can be tested with a real
  block. **Deviation:** the plan said register blocks via
  `twill.block_editor.directories.source.blocks`; that path only discovers
  *blade* blocks, and `BlockSchemaService` reflects `getForm()` on *component*
  blocks, so they are registered with `TwillBlocks::registerManualBlock()`.
- **3.3** all 12 tests ported. `ContentRoundTripTest` and `PayloadGuardsTest`
  needed the most rework, as predicted.
- **3.4** new coverage: MCP gate, widget composer, asset route, runtime config
  defaults, prompt composer.
- **3.5** `.github/workflows/tests.yml` — matrix PHP 8.3/8.4 × Laravel 12/13 ×
  mcp 0.5/0.9, a no-mcp/no-passport job (`FeatureWithoutMcp` suite, 103 tests),
  and a Pint job. **CI cannot run yet** — see the blocker below.

### Four bugs the new tests found, all fixed

1. **`browsers` registry key mismatch.** `ModuleRegistry::describe()` advertises
   a browser's target as `module`; `PayloadBuilder` read `model` and died with
   `Undefined array key "model"`. Never caught because pomofit sets
   `'browsers' => []` on every module, so the path had never run. Now accepts
   either, preferring an explicit `model`.
2. **MCP guard vs routes registered in different phases.** The `twill-mcp` guard
   was filled in `register()` while the routes were registered in `boot()` — the
   same gate evaluated at two moments. When they disagreed the routes existed
   without their guard, so every MCP request 500'd with
   `Auth guard [twill-mcp] is not defined` instead of the connector staying
   dormant. Both now happen in `boot()`, which also makes the `passport.guard`
   fill genuinely order-independent.
3. **The approver-login redirect was left behind in the extraction.** It lived in
   pomofit's `bootstrap/app.php`. Without it an admin approving a connector is
   sent to the *customer* login and loops — the test plan's Test 1.2, which it
   rates as safety-grade. Now registered by `McpServiceProvider`.
4. **The worked prompt example still named pomofit's fields.** Blocks were
   generalised in Phase 2 but `fields`/`medias` still said `seo_title`,
   `seo_description`, `seo_image`. An agent following it on a module without
   those columns got "Unknown field" back. Both levels now derive from the
   example module's real columns and media roles, and are omitted when there are
   none.

## The plugin-support dependency is gone (2026-08-19)

`yotech-ai/twill-plugin-support` was never published, so requiring it meant
`composer install` could not run for anyone. The Plugins-page code is now
vendored at `src/PluginPage/` under `TwillAi\PluginPage`, matching what
twill-cms-redirect (`TwillRedirects\PluginPage`) and twill-cms-seo-suite
(`TwillSeo\PluginPage`) already did. Files were copied from the redirect package
so all three stay byte-equivalent apart from the namespace.

With that, the package has **no unpublished dependencies**, the `repositories`
entry is gone, CI needs no sibling checkout, and Packagist is unblocked.

Covered by `tests/Feature/Package/PluginsPageTest.php`, which pins the two
container-key strings as the interop contract they are.

### Fixed at the same time

The `FeatureWithoutMcp` testsuite overlapped the `Feature` suite, and PHPUnit
warns when a file belongs to two suites. With `failOnWarning="true"` that made
the default `vendor/bin/pest` run exit non-zero while still printing
"140 passed" — easy to miss by reading only the summary. Suites are now
non-overlapping: `Unit`, `Package`, `TwillAi`, `Mcp`.

## Not done

- **Phase 4** — CHANGELOG, `docs/connecting-claude.md`, de-pomofit'ing
  `docs/runbook.md` and `docs/test-plan.md`. Note the earlier claim that both
  contain pomofit references was wrong: `runbook.md` has none (it has one
  *easy-to-spain* reference, line 116) and `test-plan.md` has one literal
  `pomofit.test`. The real problem is that test-plan.md's worked examples are all
  easy-to-spain (NIE numbers, Spanish residency), and that it tells testers the
  Part 3 safety rules are "covered by automated tests" — true of pomofit when it
  was written, and true of this package again only as of this session.
- **Phase 5** — adoption in pomofit, quiz-cms, easy-to-spain. Not started.

## Deviations from the plan (intentional — do not "fix" these back)

1. **`passport.guard` is not auto-set.** Task 1.5 says fill it "only if unset",
   but Passport always ships a `web` default, so the rule could never fire — and
   forcing it is unsafe because the setting is global to Passport and would break
   a host that serves its own customer API with it. It is a documented host step;
   `twill-ai:doctor` reports it with the one-line fix. The MCP suite performs
   that host step itself (`McpTestCase`), and the approval-screen test is what
   proves the step is necessary.
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

Vendored at `src/PluginPage/` under `TwillAi\PluginPage`. Every Yotech plugin
carries its own copy under its own namespace rather than sharing a package, so
each one installs standalone. It adds a "Plugins" entry to the Twill nav, right
of Media Library, listing every installed Yotech plugin.

Interop contract — these container key strings must never change:
`yotech.twill-plugins.registry` and `yotech.twill-plugins.page-owner`.
First provider to register owns the page and creates it; later ones only add
their manifest. Because the keys are plain strings holding PHP built-ins, copies
under different namespaces cooperate — that is the whole reason vendoring works
and why nothing may be added to a manifest that is not a scalar.

Known copies to keep in step if the mechanism ever changes:
`TwillAi\PluginPage`, `TwillRedirects\PluginPage`, `TwillSeo\PluginPage`.

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
